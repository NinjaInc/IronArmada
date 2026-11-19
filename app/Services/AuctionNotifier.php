<?php

namespace OGame\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMessages\Abstracts\GameMessage;
use OGame\GameMessages\AuctionOutbid;
use OGame\GameMessages\AuctionWon;
use OGame\Models\Auction;
use OGame\Models\User;
use Throwable;

/**
 * Sends the Armada Salvage Auction notifications: Discord posts (plain English, via DiscordNotifier) and the two
 * in-game messages (outbid, won). Everything here is best-effort: a failure is logged and swallowed. Nothing here
 * takes a database lock or runs inside the bid/settle transactions; it is only called from the NotifyAuctionEvent
 * job, which is dispatched after commit.
 */
class AuctionNotifier
{
    public function __construct(
        private readonly DiscordNotifier $discord,
        private readonly PlayerServiceFactory $playerServiceFactory,
    ) {
    }

    /**
     * Dispatches a named event to the matching method. $context carries event-specific data
     * (outbid: outbid_user_id and amount).
     *
     * @param array<string, mixed> $context
     */
    public function handle(string $event, Auction $auction, array $context = []): void
    {
        match ($event) {
            'opened' => $this->opened($auction),
            'final_call' => $this->finalCall($auction),
            'extended' => $this->extended($auction),
            'won' => $this->won($auction),
            'unsold' => $this->unsold($auction),
            'outbid' => $this->outbid(
                $auction,
                (int)($context['outbid_user_id'] ?? 0),
                (int)($context['amount'] ?? $auction->current_bid),
            ),
            default => Log::warning("AuctionNotifier: unknown event '{$event}' for auction #{$auction->id}"),
        };
    }

    public function opened(Auction $auction): void
    {
        $this->post(
            ':package: Salvage Auction open: **' . $this->lotName($auction) . '**. Bidding starts at '
            . number_format($auction->starting_bid) . ' DM. Hammer falls <t:' . $auction->ends_at . ':R>.'
        );
    }

    public function finalCall(Auction $auction): void
    {
        $leader = $this->username($auction->leader_user_id);
        $this->post(
            ':hourglass: Final call on **' . $this->lotName($auction) . '**: '
            . number_format($auction->current_bid) . ' DM' . ($leader !== null ? ' by **' . $leader . '**' : '') . '.'
        );
    }

    public function extended(Auction $auction): void
    {
        // Only the first anti-snipe extension is announced.
        if ($auction->extension_count !== 1) {
            return;
        }

        $this->post(':crossed_swords: Bidding war on **' . $this->lotName($auction) . '**! Clock extended.');
    }

    /**
     * Discord announcement plus the in-game message to the winner.
     */
    public function won(Auction $auction): void
    {
        $winnerId = $auction->winner_user_id;
        $price = (int)($auction->final_price ?? $auction->current_bid);

        $this->post(
            ':hammer: SOLD! **' . $this->lotName($auction) . '** to **' . ($this->username($winnerId) ?? 'unknown')
            . '** for ' . number_format($price) . ' DM.'
        );

        if ($winnerId !== null) {
            $this->sendMessage($winnerId, AuctionWon::class, $auction, $price);
        }
    }

    public function unsold(Auction $auction): void
    {
        $this->post(':wastebasket: No bids. **' . $this->lotName($auction) . '** was scrapped.');
    }

    /**
     * In-game message to the commander who was just outbid. No Discord post (it would spam the channel).
     */
    public function outbid(Auction $auction, int $outbidUserId, int $amount): void
    {
        if ($outbidUserId <= 0) {
            return;
        }

        $this->sendMessage($outbidUserId, AuctionOutbid::class, $auction, $amount);
    }

    private function post(string $message): void
    {
        try {
            $this->discord->send($message);
        } catch (Throwable $e) {
            Log::warning('AuctionNotifier: Discord post failed: ' . $e->getMessage());
        }
    }

    /**
     * @param class-string<GameMessage> $messageClass
     */
    private function sendMessage(int $userId, string $messageClass, Auction $auction, int $amount): void
    {
        try {
            $user = User::find($userId);
            if ($user === null || UserPreferencesService::get($user, 'auctioneerNotifications') !== 1) {
                return;
            }

            $player = $this->playerServiceFactory->make($userId);
            (new MessageService($player))->sendSystemMessageToPlayer($player, $messageClass, [
                'lot' => $this->lotName($auction),
                'amount' => number_format($amount),
                'link' => $this->auctionUrl(),
            ]);
        } catch (Throwable $e) {
            Log::warning("AuctionNotifier: in-game message for user #{$userId} failed: " . $e->getMessage());
        }
    }

    private function lotName(Auction $auction): string
    {
        $key = 't_merchant.auction.lots.' . $auction->lot_key;
        $name = __($key, [], 'en');

        return $name === $key ? $auction->lot_key : $name;
    }

    private function username(int|null $userId): string|null
    {
        if ($userId === null) {
            return null;
        }

        return User::find($userId)?->username;
    }

    private function auctionUrl(): string
    {
        // The controller route lands in a later task; fall back to the spec'd path until then.
        return Route::has('merchant.auction.index') ? route('merchant.auction.index') : url('/merchant/auction');
    }
}
