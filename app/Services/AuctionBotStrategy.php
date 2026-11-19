<?php

namespace OGame\Services;

use Illuminate\Support\Facades\Date;
use OGame\Models\Auction;
use OGame\Models\Auction\AuctionLot;
use OGame\Models\User;
use Throwable;

/**
 * Bidding strategy for computer controlled empires (docs/AUCTION-SPEC.md section 1.4).
 *
 * Bots bid exactly like players: through AuctionService::placeBid(), with dark matter they really hold and
 * at the minimum legal amount. There is no second code path. They bid sparsely and late (never in the first
 * half of the auction, never inside the anti-snipe window), so they cannot dominate the auction or trigger
 * extensions.
 *
 * Transaction rule (spec 4.2): consider() must be called outside any database transaction, and must never be
 * wrapped in one that holds a users or planets lock, because placeBid() locks auctions -> users itself.
 */
class AuctionBotStrategy
{
    /** Metal a bot considers one dark matter to be worth. */
    public const int DM_METAL_RATE = 2500;

    /** Chance (percent) that a bot even looks at the auction during a turn. */
    public const int TURN_CHANCE_PERCENT = 25;

    /** Highest share of its dark matter balance a bot will put into one bid. */
    public const float MAX_BALANCE_SHARE = 0.4;

    /** Bots stay out until this share of the auction duration has elapsed ("bid late"). */
    public const float MIN_ELAPSED_SHARE = 0.5;

    /**
     * Valuation ceiling multiplier per personality.
     *
     * @var array<string, float>
     */
    private const PERSONALITY_FACTOR = [
        'miner' => 1.2,
        'balanced' => 1.0,
        'raider' => 0.9,
        'turtle' => 0.7,
        'pirate' => 0.8,
    ];

    public function __construct(
        private AuctionService $auctions,
        private SettingsService $settings,
    ) {
    }

    /**
     * Possibly place one bid for this bot. Called from BotService::takeTurn() after its normal turn. Never throws.
     *
     * @param User $bot
     * @param float|null $roll Random roll in [0, 1) deciding whether the bot acts this turn; null draws one. Tests inject it.
     */
    public function consider(User $bot, float|null $roll = null): void
    {
        try {
            $this->considerBid($bot, $roll);
        } catch (Throwable $e) {
            logger()->warning("Auction bot {$bot->id} skipped: {$e->getMessage()}");
        }
    }

    private function considerBid(User $bot, float|null $roll): void
    {
        if (!$bot->is_bot || (int)$this->settings->get('auction_bots_enabled', 0) <= 0 || !$this->auctions->isEnabled()) {
            return;
        }

        $roll ??= mt_rand() / (mt_getrandmax() + 1);
        if ($roll >= self::TURN_CHANCE_PERCENT / 100) {
            return;
        }

        $auction = $this->auctions->current();
        if ($auction === null) {
            return;
        }

        // Never bid on our own lead (placeBid would reject it anyway).
        if ($auction->leader_user_id !== null && (int)$auction->leader_user_id === $bot->id) {
            return;
        }

        if (!$this->isBiddingWindow($auction)) {
            return;
        }

        $bid = $this->auctions->minimumNextBid($auction);
        if ($bid > $this->valuationCeiling($auction, $bot)) {
            return;
        }

        // Fresh balance read: the in-memory model may be stale. placeBid re-checks under the user lock regardless.
        $balance = (int)User::whereKey($bot->id)->value('dark_matter');
        if ($bid > (int)floor($balance * self::MAX_BALANCE_SHARE)) {
            return;
        }

        $this->auctions->placeBid($bot, $auction->id, $bid);
    }

    /**
     * Late but not final: after MIN_ELAPSED_SHARE of the duration, and before the anti-snipe window opens.
     */
    private function isBiddingWindow(Auction $auction): bool
    {
        $now = (int)Date::now()->timestamp;
        $snipeWindow = (int)$this->settings->get('auction_snipe_window_seconds', 300);

        if ($now >= $auction->ends_at - $snipeWindow) {
            return false;
        }

        $duration = max(1, $auction->original_ends_at - $auction->starts_at);

        return ($now - $auction->starts_at) >= (int)floor($duration * self::MIN_ELAPSED_SHARE);
    }

    /**
     * Most dark matter this bot thinks the lot is worth.
     */
    private function valuationCeiling(Auction $auction, User $bot): int
    {
        $lot = AuctionLot::fromPayload($auction->lot_key, $auction->lot_payload);
        $factor = self::PERSONALITY_FACTOR[(string)$bot->bot_personality] ?? 1.0;

        return (int)floor($lot->metalEquivalent() / self::DM_METAL_RATE * $factor);
    }
}
