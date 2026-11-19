<?php

namespace Tests\Traits;

use Illuminate\Support\Facades\Date;
use OGame\Enums\DarkMatterTransactionType;
use OGame\Models\Auction;
use OGame\Models\AuctionBid;
use OGame\Models\DarkMatterTransaction;
use OGame\Models\User;
use OGame\Services\AuctionService;
use OGame\Services\SettingsService;

/**
 * Shared helpers for the Armada Salvage Auction feature tests (docs/AUCTION-SPEC.md section 8).
 *
 * Use from a class extending IsolatedAccountTestCase and call setUpAuctions() from setUp() after parent::setUp().
 */
trait ManagesAuctions
{
    protected AuctionService $auctions;

    private int $auctionSequence = 0;

    protected function setUpAuctions(): void
    {
        // Other (non transactional) tests or a dev database may hold auctions: start from a clean slate.
        // Rolled back with the test transaction.
        Auction::query()->delete();

        $settings = resolve(SettingsService::class);
        $settings->set('auction_enabled', 1);
        $settings->set('auction_open_hour_utc', 18);
        $settings->set('auction_duration_minutes', 1320);
        $settings->set('auction_snipe_window_seconds', 300);
        $settings->set('auction_snipe_extension_seconds', 300);
        $settings->set('auction_max_extension_seconds', 3600);
        $settings->set('auction_min_increment_abs', 50);
        $settings->set('auction_min_increment_pct', 5);
        $settings->set('auction_bots_enabled', 0);

        $this->auctions = resolve(AuctionService::class);
    }

    /**
     * Pin the clock to a UTC wall-clock time, e.g. '2024-01-01 18:00:00'.
     */
    protected function travelToUtc(string $dateTime): void
    {
        $this->travelTo(Date::parse($dateTime, 'UTC'));
    }

    protected function travelToTimestamp(int $timestamp): void
    {
        $this->travelTo(Date::createFromTimestamp($timestamp));
    }

    protected function nowTs(): int
    {
        return (int)Date::now()->timestamp;
    }

    /**
     * Inserts an active auction directly (bypasses openIfDue so the clock stays free).
     *
     * @param array<string, mixed> $overrides
     */
    protected function makeAuction(array $overrides = []): Auction
    {
        $this->auctionSequence++;
        $now = $this->nowTs();
        $endsAt = $overrides['ends_at'] ?? $now + 3600;

        $attributes = array_merge([
            'auction_date' => Date::parse('2023-01-01', 'UTC')->addDays($this->auctionSequence)->format('Y-m-d'),
            'status' => Auction::STATUS_ACTIVE,
            'active_slot' => 1,
            'lot_key' => 'crate_metal',
            'lot_payload' => ['resources' => ['metal' => 1000, 'crystal' => 0, 'deuterium' => 0], 'units' => []],
            'starting_bid' => 600,
            'min_increment_abs' => 50,
            'min_increment_pct' => 5,
            'current_bid' => 0,
            'leader_user_id' => null,
            'bid_count' => 0,
            'starts_at' => $now - 3600,
            'ends_at' => $endsAt,
            'original_ends_at' => $endsAt,
            'extension_count' => 0,
            'final_call_sent' => false,
        ], $overrides);

        return Auction::create($attributes);
    }

    /**
     * A plain user (no planets) with the given dark matter balance.
     */
    protected function makeBidder(int $darkMatter): User
    {
        $user = User::withoutEvents(fn (): User => User::factory()->create());
        $this->setDarkMatter($user->id, $darkMatter);

        return User::findOrFail($user->id);
    }

    protected function setDarkMatter(int $userId, int $darkMatter): void
    {
        User::whereKey($userId)->update(['dark_matter' => $darkMatter]);
    }

    protected function balance(int|User $user): int
    {
        $id = $user instanceof User ? $user->id : $user;

        return (int)User::findOrFail($id)->dark_matter;
    }

    /**
     * Sum of auction_bid + auction_refund ledger rows for one auction, using the description convention
     * the service writes ("Auction #N bid" / "Auction #N refund (...)").
     */
    protected function ledgerSum(int $auctionId): int
    {
        return (int)DarkMatterTransaction::whereIn('type', [
            DarkMatterTransactionType::AUCTION_BID->value,
            DarkMatterTransactionType::AUCTION_REFUND->value,
        ])->where('description', 'like', "Auction #{$auctionId} %")->sum('amount');
    }

    protected function heldSum(int $auctionId): int
    {
        return (int)AuctionBid::where('auction_id', $auctionId)->whereNull('refunded_at')->sum('amount');
    }

    protected function ledgerCount(int $userId, DarkMatterTransactionType $type, int $auctionId): int
    {
        return DarkMatterTransaction::where('user_id', $userId)
            ->where('type', $type->value)
            ->where('description', 'like', "Auction #{$auctionId} %")
            ->count();
    }

    /**
     * Asserts the service invariants (I1 to I5), the independent ledger identity (I4) and dark matter conservation:
     * what the given users hold plus what is held in escrow equals what they started with.
     *
     * @param array<int, int> $userIds
     */
    protected function assertAuctionHealthy(int $auctionId, array $userIds = [], int|null $expectedTotal = null): void
    {
        $this->assertSame([], $this->auctions->checkInvariants($auctionId));
        $this->assertSame(-$this->heldSum($auctionId), $this->ledgerSum($auctionId), 'Ledger sum must equal minus the held amount.');

        if ($expectedTotal !== null) {
            $balances = 0;
            foreach ($userIds as $userId) {
                $balances += $this->balance($userId);
            }
            $this->assertSame($expectedTotal, $balances + $this->heldSum($auctionId), 'Dark matter must be conserved.');
        }
    }
}
