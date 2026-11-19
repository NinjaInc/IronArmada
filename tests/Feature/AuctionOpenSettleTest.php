<?php

namespace Tests\Feature;

use OGame\GameConstants\AuctionLots;
use OGame\Models\Auction;
use OGame\Services\AuctionLotCatalog;
use OGame\Services\SettingsService;
use Tests\IsolatedAccountTestCase;
use Tests\Traits\ManagesAuctions;

/**
 * Armada Salvage Auction: daily opening and settlement (docs/AUCTION-SPEC.md sections 1.2, 4.4, 4.7 and 8).
 *
 * 2024-01-01 is a Monday, 2024-01-07 a Sunday. Default window: opens 18:00 UTC, runs 22 hours.
 */
class AuctionOpenSettleTest extends IsolatedAccountTestCase
{
    use ManagesAuctions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuctions();
    }

    public function testNothingOpensBeforeTheOpenHour(): void
    {
        $this->travelToUtc('2024-01-01 17:59:59');

        $this->assertNull($this->auctions->openIfDue());
        $this->assertSame(0, Auction::count());
    }

    public function testOpensOneAuctionAtTheOpenHour(): void
    {
        $this->travelToUtc('2024-01-01 18:00:00');
        $startsAt = $this->nowTs();

        $auction = $this->auctions->openIfDue();

        $this->assertNotNull($auction);
        $this->assertSame(Auction::STATUS_ACTIVE, $auction->status);
        $this->assertSame(1, (int)$auction->active_slot);
        $this->assertSame('2024-01-01', $auction->auction_date);
        $this->assertSame($startsAt, $auction->starts_at);
        $this->assertSame($startsAt + 1320 * 60, $auction->ends_at);
        $this->assertSame($auction->ends_at, $auction->original_ends_at);
        $this->assertSame(0, $auction->current_bid);
        $this->assertSame(0, $auction->bid_count);
        $this->assertNull($auction->leader_user_id);
        $this->assertSame(0, $auction->extension_count);
        $this->assertSame(resolve(AuctionLotCatalog::class)->startingBid($auction->lot_key), $auction->starting_bid);
        $this->assertSame(50, $auction->min_increment_abs);
        $this->assertSame(5, $auction->min_increment_pct);
        $this->assertArrayHasKey('resources', $auction->lot_payload);
        $this->assertArrayHasKey('units', $auction->lot_payload);
        $this->assertSame($auction->id, $this->auctions->current()?->id);
        $this->assertSame([], $this->auctions->checkInvariants($auction->id));
    }

    public function testOpeningTwiceCreatesNoSecondAuction(): void
    {
        $this->travelToUtc('2024-01-01 18:00:00');
        $this->assertNotNull($this->auctions->openIfDue());

        $this->travelToUtc('2024-01-01 18:01:00');
        $this->assertNull($this->auctions->openIfDue());
        $this->assertNull($this->auctions->openIfDue());

        $this->assertSame(1, Auction::count());
    }

    public function testOpeningHonoursTheConfiguredHourAndDuration(): void
    {
        $settings = resolve(SettingsService::class);
        $settings->set('auction_open_hour_utc', 9);
        $settings->set('auction_duration_minutes', 60);

        $this->travelToUtc('2024-01-01 08:59:59');
        $this->assertNull($this->auctions->openIfDue());

        $this->travelToUtc('2024-01-01 09:00:00');
        $auction = $this->auctions->openIfDue();
        $this->assertNotNull($auction);
        $this->assertSame($auction->starts_at + 3600, $auction->ends_at);
    }

    public function testNothingOpensWhenFeatureDisabled(): void
    {
        resolve(SettingsService::class)->set('auction_enabled', 0);
        $this->travelToUtc('2024-01-01 18:00:00');

        $this->assertNull($this->auctions->openIfDue());
        $this->assertSame(0, Auction::count());
    }

    public function testNothingOpensWhileAnotherAuctionIsActive(): void
    {
        $this->travelToUtc('2024-01-01 18:00:00');
        $first = $this->auctions->openIfDue();
        $this->assertNotNull($first);

        // Next day's open hour, but the first auction has not been settled yet (I6).
        $this->travelToUtc('2024-01-02 18:30:00');
        $this->assertNull($this->auctions->openIfDue());
        $this->assertSame(1, Auction::where('active_slot', 1)->count());
    }

    public function testNothingOpensOnceTodaysWindowHasPassed(): void
    {
        resolve(SettingsService::class)->set('auction_duration_minutes', 60);

        // The scheduler was down for the whole one hour window: do not open an auction that is already over.
        $this->travelToUtc('2024-01-01 20:00:00');

        $this->assertNull($this->auctions->openIfDue());
        $this->assertSame(0, Auction::count());
    }

    public function testSameDayDoesNotReopenAfterTheAuctionClosed(): void
    {
        $this->travelToUtc('2024-01-01 18:00:00');
        $auction = $this->auctions->openIfDue();
        $this->assertNotNull($auction);
        $this->assertTrue($this->auctions->cancel($auction->id, 'test'));

        $this->travelToUtc('2024-01-01 22:00:00');
        $this->assertNull($this->auctions->openIfDue());
        $this->assertSame(1, Auction::count());
    }

    public function testSettleBeforeEndsAtIsANoOp(): void
    {
        $auction = $this->makeAuction();
        $bidder = $this->makeBidder(5000);
        $this->auctions->placeBid($bidder, $auction->id, 600);

        $this->travelToTimestamp($auction->ends_at - 1);

        $this->assertFalse($this->auctions->settleIfDue($auction->id));
        $this->assertSame(0, $this->auctions->settleAllDue());
        $auction->refresh();
        $this->assertSame(Auction::STATUS_ACTIVE, $auction->status);
        $this->assertSame(1, (int)$auction->active_slot);
        $this->assertNull($auction->winner_user_id);
    }

    public function testNoBidsClosesUnsold(): void
    {
        $auction = $this->makeAuction();
        $this->travelToTimestamp($auction->ends_at);

        $this->assertTrue($this->auctions->settleIfDue($auction->id));

        $auction->refresh();
        $this->assertSame(Auction::STATUS_UNSOLD, $auction->status);
        $this->assertNull($auction->active_slot);
        $this->assertNull($auction->winner_user_id);
        $this->assertNull($auction->final_price);
        $this->assertSame($auction->ends_at, $auction->settled_at);
        $this->assertSame([], $this->auctions->checkInvariants($auction->id));
        $this->assertCount(0, $this->auctions->unclaimedFor($this->currentUserId));
    }

    public function testBidsCloseAsSettledWithWinnerAndPrice(): void
    {
        $auction = $this->makeAuction();
        $loser = $this->makeBidder(5000);
        $winner = $this->makeBidder(5000);
        $this->auctions->placeBid($loser, $auction->id, 600);
        $this->auctions->placeBid($winner, $auction->id, 700);

        $this->travelToTimestamp($auction->ends_at);
        $this->assertTrue($this->auctions->settleIfDue($auction->id));

        $auction->refresh();
        $this->assertSame(Auction::STATUS_SETTLED, $auction->status);
        $this->assertNull($auction->active_slot);
        $this->assertSame($winner->id, (int)$auction->winner_user_id);
        $this->assertSame(700, $auction->final_price);
        $this->assertNull($auction->claimed_at);

        // Settlement moves no dark matter: the winning bid stays held.
        $this->assertSame(5000, $this->balance($loser));
        $this->assertSame(4300, $this->balance($winner));
        $this->assertAuctionHealthy($auction->id, [$loser->id, $winner->id], 10000);
        $this->assertSame([$auction->id], $this->auctions->unclaimedFor($winner->id)->pluck('id')->all());
    }

    public function testSettleIsIdempotent(): void
    {
        $auction = $this->makeAuction();
        $winner = $this->makeBidder(5000);
        $this->auctions->placeBid($winner, $auction->id, 600);
        $this->travelToTimestamp($auction->ends_at);

        $this->assertTrue($this->auctions->settleIfDue($auction->id));
        $settledAt = $auction->fresh()->settled_at;
        $this->travelToTimestamp($auction->ends_at + 500);
        $this->assertFalse($this->auctions->settleIfDue($auction->id));
        $this->assertSame(0, $this->auctions->settleAllDue());

        $this->assertSame($settledAt, $auction->fresh()->settled_at);
        $this->assertSame(4400, $this->balance($winner));
        $this->assertAuctionHealthy($auction->id, [$winner->id], 5000);
    }

    public function testExtendedAuctionSettlesOnlyAtItsNewEnd(): void
    {
        $auction = $this->makeAuction();
        $winner = $this->makeBidder(5000);
        $originalEnd = $auction->ends_at;
        $this->travelToTimestamp($originalEnd - 30);
        $this->auctions->placeBid($winner, $auction->id, 600);
        $newEnd = $auction->fresh()->ends_at;
        $this->assertGreaterThan($originalEnd, $newEnd);

        $this->travelToTimestamp($originalEnd);
        $this->assertFalse($this->auctions->settleIfDue($auction->id));

        $this->travelToTimestamp($newEnd);
        $this->assertTrue($this->auctions->settleIfDue($auction->id));
        $this->assertSame($winner->id, (int)$auction->fresh()->winner_user_id);
    }

    public function testNextDayOpensAfterSettlement(): void
    {
        $this->travelToUtc('2024-01-01 18:00:00');
        $first = $this->auctions->openIfDue();
        $this->assertNotNull($first);

        // 16:00 UTC next day: the first auction closes, but the next one opens only at 18:00.
        $this->travelToTimestamp($first->ends_at);
        $this->assertSame(1, $this->auctions->settleAllDue());
        $this->assertNull($this->auctions->openIfDue());
        $this->assertNull($this->auctions->current());

        $this->travelToUtc('2024-01-02 18:00:00');
        $second = $this->auctions->openIfDue();

        $this->assertNotNull($second);
        $this->assertSame('2024-01-02', $second->auction_date);
        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->lot_key, $second->lot_key, 'The same lot never repeats on consecutive days.');
        $this->assertSame(1, (int)$second->active_slot);
        $this->assertSame($second->id, $this->auctions->current()?->id);
        $this->assertSame([$first->id], $this->auctions->recent()->pluck('id')->all());
    }

    public function testSundayDrawsAGrandLot(): void
    {
        $this->travelToUtc('2024-01-07 18:00:00');

        $auction = $this->auctions->openIfDue();

        $this->assertNotNull($auction);
        $this->assertSame('2024-01-07', $auction->auction_date);
        $this->assertArrayHasKey($auction->lot_key, AuctionLots::GRAND_LOTS);
        $this->assertStringStartsWith('grand_', $auction->lot_key);
    }

    public function testWeekdayDoesNotDrawAGrandLot(): void
    {
        foreach (['2024-01-01', '2024-01-02', '2024-01-03', '2024-01-04', '2024-01-05', '2024-01-06'] as $day) {
            Auction::query()->delete();
            $this->travelToUtc($day . ' 18:00:00');

            $auction = $this->auctions->openIfDue();

            $this->assertNotNull($auction, $day);
            $this->assertArrayHasKey($auction->lot_key, AuctionLots::LOTS, $day);
        }
    }

    public function testSchedulerOutageAfterEndSettlesLateWithoutLosingTheBid(): void
    {
        $this->travelToUtc('2024-01-01 18:00:00');
        $auction = $this->auctions->openIfDue();
        $this->assertNotNull($auction);
        $winner = $this->makeBidder(5000);
        $this->travelToUtc('2024-01-01 19:00:00');
        $this->assertTrue($this->auctions->placeBid($winner, $auction->id, $auction->starting_bid)->accepted);

        // The scheduler was down over the end (16:00 on Jan 2): it comes back at 17:30, before the next open.
        $this->travelToUtc('2024-01-02 17:30:00');
        $this->assertSame('closed', $this->auctions->placeBid($winner, $auction->id, 10000)->errorKey, 'Past ends_at but unsettled: closed to bids.');
        $this->assertSame(1, $this->auctions->settleAllDue());

        $auction->refresh();
        $this->assertSame(Auction::STATUS_SETTLED, $auction->status);
        $this->assertSame($winner->id, (int)$auction->winner_user_id);
        $this->assertSame($auction->starting_bid, $auction->final_price);
        $this->assertAuctionHealthy($auction->id, [$winner->id], 5000);
    }

    public function testSchedulerOutageOverSeveralDaysSettlesFirstThenOpensTodaysAuctionOnly(): void
    {
        $this->travelToUtc('2024-01-01 18:00:00');
        $stale = $this->auctions->openIfDue();
        $this->assertNotNull($stale);

        // Down for three days. The stale auction still holds the single active slot.
        $this->travelToUtc('2024-01-04 19:00:00');
        $this->assertNull($this->auctions->openIfDue());
        $this->assertSame(Auction::STATUS_ACTIVE, $stale->fresh()->status);

        // Tick order: settle everything due, then open.
        $this->assertSame(1, $this->auctions->settleAllDue());
        $today = $this->auctions->openIfDue();

        $this->assertNotNull($today);
        $this->assertSame('2024-01-04', $today->auction_date);
        $this->assertSame(Auction::STATUS_UNSOLD, $stale->fresh()->status);
        // Missed days are not backfilled.
        $this->assertSame(2, Auction::count());
        $this->assertSame(1, Auction::where('active_slot', 1)->count());
    }

    public function testSettleAllDueSettlesNothingWhenNothingIsDue(): void
    {
        $this->makeAuction();

        $this->assertSame(0, $this->auctions->settleAllDue());
        $this->assertFalse($this->auctions->settleIfDue(999999999));
    }
}
