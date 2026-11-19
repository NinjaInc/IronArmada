<?php

namespace OGame\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use LogicException;
use OGame\Enums\DarkMatterTransactionType;
use OGame\Models\Auction;
use OGame\Models\Auction\BidResult;
use OGame\Models\AuctionBid;
use OGame\Models\DarkMatterTransaction;
use OGame\Models\Planet;
use OGame\Models\User;
use Throwable;

/**
 * Armada Salvage Auction core (docs/AUCTION-SPEC.md sections 3.2 and 4).
 *
 * All dark matter movement for auctions lives here and goes through DarkMatterService::debit()/credit(),
 * so every movement has a dark_matter_transactions row. Every write path takes locks in one global order:
 * the auctions row, then users rows in ascending id, then the planets row.
 */
class AuctionService
{
    /** Largest value an unsigned 32 bit column (auctions.current_bid, auction_bids.amount) can hold. */
    private const int MAX_BID = 4294967295;

    public function __construct(
        private DarkMatterService $darkMatter,
        private SettingsService $settings,
        private AuctionLotCatalog $catalog,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->intSetting('auction_enabled', 0) !== 0;
    }

    /**
     * Active auction or null. Read-only, no lock.
     */
    public function current(): Auction|null
    {
        return Auction::where('status', Auction::STATUS_ACTIVE)->orderByDesc('id')->first();
    }

    /**
     * Last N closed auctions for the history strip.
     *
     * @return Collection<int, Auction>
     */
    public function recent(int $limit = 7): Collection
    {
        return Auction::where('status', '!=', Auction::STATUS_ACTIVE)->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * Pure function of the auction row: max(current + max(abs, ceil(current * pct / 100)), starting),
     * rounded up to the next 10 DM.
     */
    public function minimumNextBid(Auction $auction): int
    {
        $current = $auction->current_bid;
        $percentStep = intdiv($current * $auction->min_increment_pct + 99, 100);
        $minimum = max($current + max($auction->min_increment_abs, $percentStep), $auction->starting_bid);

        return intdiv($minimum + 9, 10) * 10;
    }

    /**
     * Opens today's auction if due and none is active. Idempotent: the unique auction_date and active_slot
     * make a second concurrent call a no-op.
     */
    public function openIfDue(): Auction|null
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $now = (int)Date::now()->timestamp;
        $dayStart = (int)Date::now()->utc()->startOfDay()->timestamp;
        $auctionDate = Date::createFromTimestamp($dayStart, 'UTC')->format('Y-m-d');
        $openHour = min(23, max(0, $this->intSetting('auction_open_hour_utc', 18)));
        $startsAt = $dayStart + $openHour * 3600;
        $endsAt = $startsAt + max(1, $this->intSetting('auction_duration_minutes', 1320)) * 60;

        if ($now < $startsAt || $now >= $endsAt || $this->current() !== null) {
            return null;
        }
        if (Auction::where('auction_date', $auctionDate)->exists()) {
            return null;
        }

        $previousKey = Auction::orderByDesc('auction_date')->value('lot_key');
        $lot = $this->catalog->draw($auctionDate, is_string($previousKey) ? $previousKey : null);

        try {
            return Auction::create([
                'auction_date' => $auctionDate,
                'status' => Auction::STATUS_ACTIVE,
                'active_slot' => 1,
                'lot_key' => $lot->key,
                'lot_payload' => $lot->toPayload(),
                'starting_bid' => $this->catalog->startingBid($lot->key),
                'min_increment_abs' => $this->intSetting('auction_min_increment_abs', 50),
                'min_increment_pct' => $this->intSetting('auction_min_increment_pct', 5),
                'current_bid' => 0,
                'leader_user_id' => null,
                'bid_count' => 0,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'original_ends_at' => $endsAt,
                'extension_count' => 0,
                'final_call_sent' => false,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another run opened it first (auction_date or active_slot unique index): treat as already open.
            return null;
        }
    }

    /**
     * Places a bid. Debits the bidder and refunds the previous leader in ONE transaction.
     * Never throws for business-rule failures; returns a BidResult with errorKey instead.
     */
    public function placeBid(User $bidder, int $auctionId, int $amount): BidResult
    {
        if (!$this->isEnabled()) {
            return $this->reject('disabled', null);
        }

        return DB::transaction(function () use ($bidder, $auctionId, $amount): BidResult {
            // Lock 1: the auction row serialises every bid, settle, claim and cancel on this auction.
            $auction = Auction::whereKey($auctionId)->lockForUpdate()->first();
            $now = (int)Date::now()->timestamp;

            if ($auction === null || $auction->status !== Auction::STATUS_ACTIVE || $now >= $auction->ends_at) {
                return $this->reject('closed', $auction);
            }
            if ($auction->leader_user_id !== null && (int)$auction->leader_user_id === $bidder->id) {
                return $this->reject('already_leading', $auction);
            }
            // An amount beyond the unsigned 32 bit column can never be afforded.
            if ($amount > self::MAX_BID) {
                return $this->reject('insufficient_dm', $auction);
            }
            if ($amount < $this->minimumNextBid($auction)) {
                return $this->reject('too_low', $auction);
            }

            $previousLeaderId = $auction->leader_user_id !== null ? (int)$auction->leader_user_id : null;
            $previousAmount = (int)$auction->current_bid;

            // Lock 2: bidder and previous leader, ascending id.
            $ids = array_values(array_unique(array_filter([$bidder->id, $previousLeaderId])));
            sort($ids);
            $locked = User::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $lockedBidder = $locked->get($bidder->id);
            if ($lockedBidder === null || !$this->mayBid($lockedBidder)) {
                return $this->reject('not_allowed', $auction);
            }
            // The balance is read from the row locked above; debit() re-checks it under the same lock.
            if ($lockedBidder->dark_matter < $amount) {
                return $this->reject('insufficient_dm', $auction);
            }

            $previousLeader = null;
            if ($previousLeaderId !== null) {
                $previousLeader = $locked->get($previousLeaderId);
                if ($previousLeader === null) {
                    // The FK nulls leader_user_id on account deletion, so this means corrupt state: abort everything.
                    throw new LogicException("Auction #{$auction->id}: leader {$previousLeaderId} is missing.");
                }
            }

            $this->darkMatter->debit(
                $lockedBidder,
                $amount,
                DarkMatterTransactionType::AUCTION_BID->value,
                "Auction #{$auction->id} bid"
            );

            if ($previousLeader !== null) {
                $this->darkMatter->credit(
                    $previousLeader,
                    $previousAmount,
                    DarkMatterTransactionType::AUCTION_REFUND->value,
                    "Auction #{$auction->id} refund (outbid)"
                );
                $refundedRows = AuctionBid::where('auction_id', $auction->id)
                    ->whereNull('refunded_at')
                    ->update(['refunded_at' => $now]);
                if ($refundedRows !== 1) {
                    // I1: exactly one bid may be held. Abort rather than commit a broken escrow.
                    throw new LogicException("Auction #{$auction->id}: expected 1 held bid, found {$refundedRows}.");
                }
            }

            $extended = false;
            if ($auction->ends_at - $now <= $this->intSetting('auction_snipe_window_seconds', 300)) {
                $newEnd = min(
                    $now + $this->intSetting('auction_snipe_extension_seconds', 300),
                    $auction->original_ends_at + $this->intSetting('auction_max_extension_seconds', 3600)
                );
                if ($newEnd > $auction->ends_at) {
                    $auction->ends_at = $newEnd;
                    $auction->extension_count++;
                    $extended = true;
                }
            }

            AuctionBid::create([
                'auction_id' => $auction->id,
                'user_id' => $bidder->id,
                'amount' => $amount,
                'placed_at' => $now,
                'extended_end' => $extended,
                'is_bot' => $lockedBidder->is_bot,
            ]);

            $auction->current_bid = $amount;
            $auction->leader_user_id = $bidder->id;
            $auction->bid_count++;
            $auction->save();

            return new BidResult(
                true,
                '',
                $auction->current_bid,
                $this->minimumNextBid($auction),
                $auction->ends_at,
                $extended,
                $previousLeaderId,
                $previousLeaderId !== null ? $previousAmount : 0,
            );
        });
    }

    /**
     * Settles one auction if it is active and ends_at <= now. Idempotent. Returns true if this call settled it.
     * Settlement moves no dark matter: the winning bid is already held.
     */
    public function settleIfDue(int $auctionId): bool
    {
        $winnerUserId = null;

        $settled = DB::transaction(function () use ($auctionId, &$winnerUserId): bool {
            $auction = Auction::whereKey($auctionId)->lockForUpdate()->first();
            $now = (int)Date::now()->timestamp;
            if ($auction === null || $auction->status !== Auction::STATUS_ACTIVE || $now < $auction->ends_at) {
                return false;
            }

            $auction->active_slot = null;
            $auction->settled_at = $now;
            if ($auction->leader_user_id === null) {
                $auction->status = Auction::STATUS_UNSOLD;
            } else {
                $auction->status = Auction::STATUS_SETTLED;
                $auction->winner_user_id = $auction->leader_user_id;
                $auction->final_price = $auction->current_bid;
                $winnerUserId = $auction->leader_user_id;
            }
            $auction->save();

            return true;
        });

        if ($settled && $winnerUserId !== null) {
            $this->autoClaimForBot($auctionId, $winnerUserId);
        }

        return $settled;
    }

    /**
     * Settles every due auction (normally 0 or 1). Returns the number settled.
     */
    public function settleAllDue(): int
    {
        $now = (int)Date::now()->timestamp;
        $ids = Auction::where('status', Auction::STATUS_ACTIVE)->where('ends_at', '<=', $now)->pluck('id');

        $count = 0;
        foreach ($ids as $id) {
            if ($this->settleIfDue((int)$id)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Delivers a won lot to a planet the winner owns. Idempotent via claimed_at under a row lock.
     *
     * @return string '' on success, or a t_merchant.auction.error.* suffix
     */
    public function claim(PlayerService $winner, int $auctionId, int $planetId): string
    {
        return $this->claimForUser($winner->getId(), $auctionId, $planetId);
    }

    /**
     * Admin: cancels the active auction and refunds the held bid. Returns false if it is not active.
     */
    public function cancel(int $auctionId, string $reason): bool
    {
        $cancelled = DB::transaction(function () use ($auctionId): bool {
            $auction = Auction::whereKey($auctionId)->lockForUpdate()->first();
            if ($auction === null || $auction->status !== Auction::STATUS_ACTIVE) {
                return false;
            }

            $now = (int)Date::now()->timestamp;
            $held = AuctionBid::where('auction_id', $auction->id)->whereNull('refunded_at')->get();

            if ($held->count() > 1) {
                throw new LogicException("Auction #{$auction->id}: more than one held bid.");
            }
            $heldBid = $held->first();
            if ($heldBid !== null) {
                // Lock 2: the held bidder (single user, so ascending order is trivial).
                $bidder = User::whereKey($heldBid->user_id)->lockForUpdate()->first();
                if ($bidder === null) {
                    throw new LogicException("Auction #{$auction->id}: held bidder {$heldBid->user_id} is missing.");
                }
                $this->darkMatter->credit(
                    $bidder,
                    $heldBid->amount,
                    DarkMatterTransactionType::AUCTION_REFUND->value,
                    "Auction #{$auction->id} refund (cancelled)"
                );
                $heldBid->refunded_at = $now;
                $heldBid->save();
            }

            $auction->status = Auction::STATUS_CANCELLED;
            $auction->active_slot = null;
            $auction->settled_at = $now;
            $auction->save();

            return true;
        });

        if ($cancelled) {
            logger()->info("Auction #{$auctionId} cancelled: {$reason}");
        }

        return $cancelled;
    }

    /**
     * Winnings the player has not claimed yet.
     *
     * @return Collection<int, Auction>
     */
    public function unclaimedFor(int $userId): Collection
    {
        return Auction::where('status', Auction::STATUS_SETTLED)
            ->where('winner_user_id', $userId)
            ->whereNull('claimed_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Diagnostic used by tests and the race command: returns a list of invariant violations (empty = healthy).
     * Evaluates I1 to I5 plus the active_slot half of I6.
     *
     * @return array<int, string>
     */
    public function checkInvariants(int $auctionId): array
    {
        $auction = Auction::find($auctionId);
        if ($auction === null) {
            return ["Auction #{$auctionId} does not exist."];
        }

        $violations = [];
        $held = AuctionBid::where('auction_id', $auctionId)->whereNull('refunded_at')->get();
        $heldSum = (int)$held->sum('amount');
        $isActive = $auction->status === Auction::STATUS_ACTIVE;

        if ($isActive) {
            // I1
            if ((int)$auction->active_slot !== 1) {
                $violations[] = 'I6: active auction without active_slot = 1.';
            }
            if ((int)$auction->bid_count === 0) {
                if ($held->count() !== 0 || $auction->leader_user_id !== null) {
                    $violations[] = 'I1: no bids but a held bid or leader exists.';
                }
            } elseif ($held->count() !== 1) {
                $violations[] = "I1: expected exactly 1 held bid, found {$held->count()}.";
            } else {
                /** @var AuctionBid $bid */
                $bid = $held->first();
                if ((int)$bid->user_id !== (int)$auction->leader_user_id) {
                    $violations[] = 'I1: held bid user is not the leader.';
                }
                if ((int)$bid->amount !== (int)$auction->current_bid) {
                    $violations[] = 'I1: held bid amount differs from current_bid.';
                }
            }
        } else {
            if ($auction->active_slot !== null) {
                $violations[] = 'I6: closed auction still holds active_slot.';
            }
            if ($auction->status === Auction::STATUS_SETTLED) {
                // I2
                /** @var AuctionBid|null $bid */
                $bid = $held->first();
                if ($held->count() !== 1 || $bid === null) {
                    $violations[] = "I2: expected exactly 1 held bid, found {$held->count()}.";
                } else {
                    if ((int)$bid->user_id !== (int)$auction->winner_user_id) {
                        $violations[] = 'I2: held bid user is not the winner.';
                    }
                    if ((int)$bid->amount !== (int)$auction->final_price) {
                        $violations[] = 'I2: held bid amount differs from final_price.';
                    }
                }
            } elseif ($held->count() !== 0) {
                // I3
                $violations[] = "I3: {$auction->status} auction still holds {$held->count()} bid(s).";
            }
        }

        // I4: ledger sum equals minus the held amount. "Auction #N " (trailing space) cannot match #N0.
        $ledger = (int)DarkMatterTransaction::whereIn('type', [
            DarkMatterTransactionType::AUCTION_BID->value,
            DarkMatterTransactionType::AUCTION_REFUND->value,
        ])->where('description', 'like', "Auction #{$auctionId} %")->sum('amount');
        if ($ledger !== -$heldSum) {
            $violations[] = "I4: ledger sum {$ledger} does not equal minus held amount {$heldSum}.";
        }

        // I5
        if ($auction->claimed_at !== null) {
            if ($auction->status !== Auction::STATUS_SETTLED) {
                $violations[] = 'I5: claimed_at set on an auction that is not settled.';
            }
            if ($auction->claimed_planet_id === null) {
                $violations[] = 'I5: claimed_at set without claimed_planet_id.';
            }
        }

        return $violations;
    }

    /**
     * Claim implementation keyed by user id, so bot auto-claims need no PlayerService.
     */
    private function claimForUser(int $userId, int $auctionId, int $planetId): string
    {
        return DB::transaction(function () use ($userId, $auctionId, $planetId): string {
            // Lock 1: the auction row.
            $auction = Auction::whereKey($auctionId)->lockForUpdate()->first();
            if ($auction === null || $auction->status !== Auction::STATUS_SETTLED || (int)$auction->winner_user_id !== $userId) {
                return 'not_yours';
            }
            if ($auction->claimed_at !== null) {
                return 'already_claimed';
            }

            // Lock 3: the planets row (no users rows are needed: a claim moves no dark matter).
            $planet = Planet::whereKey($planetId)
                ->where('user_id', $userId)
                ->where('destroyed', 0)
                ->lockForUpdate()
                ->first();
            if ($planet === null) {
                return 'bad_planet';
            }

            $payload = $auction->lot_payload;
            $resources = is_array($payload['resources'] ?? null) ? $payload['resources'] : [];
            $units = is_array($payload['units'] ?? null) ? $payload['units'] : [];

            // Atomic col = col + n increments, never a save() of a possibly stale planet model.
            $increments = [];
            foreach (['metal', 'crystal', 'deuterium'] as $column) {
                $amount = (int)($resources[$column] ?? 0);
                if ($amount > 0) {
                    $increments[$column] = $amount;
                }
            }
            foreach ($units as $machineName => $amount) {
                $amount = (int)$amount;
                if ($amount > 0) {
                    // Whitelist: throws RuntimeException for anything that is not a ship (rolls everything back).
                    $column = ObjectService::getShipObjectByMachineName((string)$machineName)->machine_name;
                    $increments[$column] = ($increments[$column] ?? 0) + $amount;
                }
            }
            if ($increments !== []) {
                Planet::whereKey($planet->id)->incrementEach($increments);
            }

            $auction->claimed_at = (int)Date::now()->timestamp;
            $auction->claimed_planet_id = $planet->id;
            $auction->save();

            return '';
        });
    }

    /**
     * Bots have no UI, so a bot winner gets the lot on its homeworld right after settlement.
     * Runs after the settlement transaction committed; failures are logged, never thrown.
     */
    private function autoClaimForBot(int $auctionId, int $winnerUserId): void
    {
        try {
            $winner = User::find($winnerUserId);
            if ($winner === null || !$winner->is_bot) {
                return;
            }
            $homeworld = Planet::where('user_id', $winnerUserId)
                ->where('planet_type', 1)
                ->where('destroyed', 0)
                ->orderBy('id')
                ->first();
            if ($homeworld === null) {
                return;
            }
            $this->claimForUser($winnerUserId, $auctionId, $homeworld->id);
        } catch (Throwable $e) {
            logger()->error("Auction #{$auctionId}: bot auto-claim failed: {$e->getMessage()}");
        }
    }

    private function mayBid(User $user): bool
    {
        if ($user->vacation_mode || $user->isBanned()) {
            return false;
        }

        return !$user->is_bot || $this->intSetting('auction_bots_enabled', 0) !== 0;
    }

    private function reject(string $errorKey, Auction|null $auction): BidResult
    {
        return new BidResult(
            false,
            $errorKey,
            $auction !== null ? $auction->current_bid : 0,
            $auction !== null ? $this->minimumNextBid($auction) : 0,
            $auction !== null ? $auction->ends_at : 0,
            false,
            null,
            0,
        );
    }

    private function intSetting(string $key, int $default): int
    {
        return (int)$this->settings->get($key, $default);
    }
}
