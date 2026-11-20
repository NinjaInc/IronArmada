<?php

namespace OGame\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use OGame\Jobs\NotifyAuctionEvent;
use OGame\Models\Auction;
use OGame\Models\Auction\BidResult;
use OGame\Models\AuctionBid;
use OGame\Services\AuctionService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use Throwable;

/**
 * Armada Salvage Auction HTTP layer (docs/AUCTION-SPEC.md sections 3.3 and 3.4).
 *
 * No AuctionService call here runs inside a DB transaction of this controller (lock order, spec 4.2).
 */
class AuctionController extends OGameController
{
    private const int MAX_BID = 2000000000;

    /**
     * GET /merchant/auction: full page.
     */
    public function index(PlayerService $player, AuctionService $auctions): View
    {
        $this->setBodyId('traderOverview');

        return view('ingame.merchant.auction', [
            'state' => $this->buildState($player, $auctions),
            'planets' => $this->planetOptions($player),
            'history' => $auctions->recent(7),
            'darkMatter' => $player->getUser()->dark_matter,
        ]);
    }

    /**
     * GET /ajax/merchant/auction/state: polling JSON.
     */
    public function state(PlayerService $player, AuctionService $auctions): JsonResponse
    {
        return response()->json($this->buildState($player, $auctions));
    }

    /**
     * POST /ajax/merchant/auction/bid {auction_id:int, amount:int, _token}
     */
    public function bid(Request $request, PlayerService $player, AuctionService $auctions): JsonResponse
    {
        // Explicit token check that never crashes on a missing token (same style as the building controllers).
        if (!hash_equals((string)$request->session()->token(), (string)$request->input('_token'))) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid token.',
            ]);
        }

        // Normalise like MerchantController::executeTrade: strip thousands separators and spaces.
        $rawAmount = $request->input('amount');
        if (is_string($rawAmount)) {
            $rawAmount = str_replace([',', ' '], '', $rawAmount);
        }

        $validator = Validator::make(
            ['auction_id' => $request->input('auction_id'), 'amount' => $rawAmount],
            [
                'auction_id' => ['required', 'integer', 'min:1'],
                'amount' => ['required', 'integer', 'min:1', 'max:' . self::MAX_BID],
            ]
        );
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => (string)$validator->errors()->first(),
            ], 422);
        }
        $auctionId = (int)$request->input('auction_id');
        $amount = (int)$rawAmount;

        // Cheap gates that never write to the database.
        if (!$auctions->isEnabled()) {
            return $this->bidFailure($player, $auctions, $this->errorMessage('disabled'));
        }
        $user = $player->getUser();
        if ($user->vacation_mode || $user->isBanned()) {
            return $this->bidFailure($player, $auctions, $this->errorMessage('not_allowed'));
        }

        // Reject any id that is not the current auction before the service runs: a bid on a non-existent id
        // would take a PK gap lock that blocks openIfDue().
        $current = $auctions->current();
        if ($current === null || $current->id !== $auctionId) {
            return $this->bidFailure($player, $auctions, $this->errorMessage('closed'));
        }

        try {
            $result = $auctions->placeBid($user, $auctionId, $amount);
        } catch (Throwable $e) {
            logger()->error("Auction bid failed (auction #{$auctionId}, user #{$user->id}): {$e->getMessage()}");

            return $this->bidFailure($player, $auctions, $this->tryAgainMessage());
        }

        if (!$result->accepted) {
            return $this->bidFailure($player, $auctions, $this->errorMessage($result->errorKey, [
                'minimum' => number_format($result->minimumNextBid, 0, '.', ','),
                'amount' => number_format($amount, 0, '.', ','),
            ]));
        }

        $this->dispatchNotifications($auctionId, $result);

        // placeBid debited a freshly locked User instance; reload the request-scoped one so the state below
        // reports the post-bid balance (final review F2).
        $user->refresh();

        return response()->json([
            'success' => true,
            'message' => __('t_merchant.auction.bid_placed', ['amount' => number_format($amount, 0, '.', ',')]),
            'state' => $this->buildState($player, $auctions),
        ]);
    }

    /**
     * POST /merchant/auction/claim {auction_id:int, planet_id:int}
     */
    public function claim(Request $request, PlayerService $player, AuctionService $auctions): RedirectResponse
    {
        $back = redirect()->route('merchant.auction.index');

        // Deliberately not gated by isEnabled(): a winner who already paid must always be able to claim, even
        // while the feature is switched off (final review F4).
        $validator = Validator::make($request->all(), [
            'auction_id' => ['required', 'integer', 'min:1'],
            'planet_id' => ['required', 'integer', 'min:1'],
        ]);
        if ($validator->fails()) {
            return $back->with('auction_error', (string)$validator->errors()->first());
        }

        try {
            $error = $auctions->claim($player, (int)$request->input('auction_id'), (int)$request->input('planet_id'));
        } catch (Throwable $e) {
            logger()->error("Auction claim failed (user #{$player->getId()}): {$e->getMessage()}");

            return $back->with('auction_error', $this->tryAgainMessage());
        }

        if ($error !== '') {
            return $back->with('auction_error', $this->errorMessage($error));
        }

        return $back->with('auction_success', __('t_merchant.auction.claimed'));
    }

    /**
     * Builds the state contract of spec 3.3. Settles a due auction first (idempotent, lock-guarded).
     *
     * @return array<string, mixed>
     */
    private function buildState(PlayerService $player, AuctionService $auctions): array
    {
        $now = (int)Date::now()->timestamp;
        $user = $player->getUser();

        $state = [
            'enabled' => $auctions->isEnabled(),
            'server_now' => $now,
            'auction' => null,
            'recent_bids' => [],
            'dark_matter' => (int)$user->dark_matter,
            'unclaimed' => [],
        ];

        // Settlement and the unclaimed list keep working while the feature is switched off, so held bids are not
        // stuck and paid winners can still claim (final review F4). Only bidding and opening are gated.
        $auction = $auctions->current();
        if ($auction !== null && $auction->ends_at <= $now) {
            try {
                // Only the caller that actually transitioned the row notifies (final review F1).
                if ($auctions->settleIfDue($auction->id)) {
                    $this->dispatchSettled($auction->id);
                }
            } catch (Throwable $e) {
                logger()->warning("Auction #{$auction->id} settle on read failed: {$e->getMessage()}");
            }
            $auction = $auctions->current();
        }

        if (!$state['enabled']) {
            // Disabled: an auction that is not yet due stays out of the bidding UI.
            $auction = null;
        }

        if ($auction !== null) {
            $payload = $auction->lot_payload;
            $state['auction'] = [
                'id' => $auction->id,
                'status' => $auction->status,
                'lot_key' => $auction->lot_key,
                'lot' => [
                    'resources' => is_array($payload['resources'] ?? null) ? $payload['resources'] : [],
                    'units' => is_array($payload['units'] ?? null) ? $payload['units'] : [],
                ],
                'starting_bid' => $auction->starting_bid,
                'current_bid' => $auction->current_bid,
                'minimum_next_bid' => $auctions->minimumNextBid($auction),
                'bid_count' => $auction->bid_count,
                'leader_name' => $auction->leader_user_id !== null ? $auction->leader?->username : null,
                'you_lead' => $auction->leader_user_id !== null && (int)$auction->leader_user_id === $user->id,
                'ends_at' => $auction->ends_at,
                'extension_count' => $auction->extension_count,
            ];
            // Usernames only, never user ids.
            $state['recent_bids'] = AuctionBid::with('user')
                ->where('auction_id', $auction->id)
                ->orderByDesc('id')
                ->limit(10)
                ->get()
                ->map(fn (AuctionBid $bid): array => [
                    'name' => $bid->user?->username,
                    'amount' => $bid->amount,
                    'placed_at' => $bid->placed_at,
                ])
                ->all();
        } elseif ($state['enabled']) {
            $state['next_opens_at'] = $this->nextOpensAt($now);
        }

        $state['unclaimed'] = $auctions->unclaimedFor($user->id)
            ->map(fn (Auction $won): array => ['auction_id' => $won->id, 'lot_key' => $won->lot_key])
            ->values()
            ->all();

        return $state;
    }

    /**
     * Next daily opening (UTC) as a timestamp, from the same setting AuctionService uses.
     */
    private function nextOpensAt(int $now): int
    {
        $hour = (int)resolve(SettingsService::class)->get('auction_open_hour_utc', 18);
        $hour = min(23, max(0, $hour));
        $dayStart = (int)Date::now()->utc()->startOfDay()->timestamp;
        $opens = $dayStart + $hour * 3600;

        return $opens > $now ? $opens : $opens + 86400;
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function planetOptions(PlayerService $player): array
    {
        $options = [];
        foreach ($player->planets->allPlanets() as $planet) {
            $options[] = [
                'id' => $planet->getPlanetId(),
                'name' => $planet->getPlanetName(),
            ];
        }

        return $options;
    }

    /**
     * Dispatches the won/unsold notification for an auction this request just settled.
     */
    private function dispatchSettled(int $auctionId): void
    {
        try {
            $closed = Auction::find($auctionId);
            if ($closed === null) {
                return;
            }
            NotifyAuctionEvent::dispatch($closed->status === Auction::STATUS_SETTLED ? 'won' : 'unsold', $closed->id);
        } catch (Throwable $e) {
            logger()->warning("Auction #{$auctionId}: settle notification dispatch failed: {$e->getMessage()}");
        }
    }

    private function dispatchNotifications(int $auctionId, BidResult $result): void
    {
        try {
            if ($result->outbidUserId !== null) {
                NotifyAuctionEvent::dispatch('outbid', $auctionId, [
                    'outbid_user_id' => $result->outbidUserId,
                    'amount' => $result->currentBid,
                ]);
            }
            if ($result->extended) {
                NotifyAuctionEvent::dispatch('extended', $auctionId);
            }
        } catch (Throwable $e) {
            // The bid is already committed; notifications are best effort.
            logger()->warning("Auction #{$auctionId}: notification dispatch failed: {$e->getMessage()}");
        }
    }

    private function bidFailure(PlayerService $player, AuctionService $auctions, string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'state' => $this->buildState($player, $auctions),
        ]);
    }

    /**
     * @param array<string, string> $replace
     */
    private function errorMessage(string $errorKey, array $replace = []): string
    {
        $key = 't_merchant.auction.error.' . $errorKey;

        return Lang::has($key) ? __($key, $replace) : $this->tryAgainMessage();
    }

    /**
     * No dedicated string exists yet (t_merchant.auction.error.try_again is missing): the key is used as soon as
     * it is added, with a plain English fallback until then.
     */
    private function tryAgainMessage(): string
    {
        return Lang::has('t_merchant.auction.error.try_again')
            ? __('t_merchant.auction.error.try_again')
            : 'Something went wrong. Please try again.';
    }
}
