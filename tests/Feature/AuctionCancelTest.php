<?php

namespace Tests\Feature;

use OGame\Enums\DarkMatterTransactionType;
use OGame\Models\Auction;
use OGame\Models\AuctionBid;
use OGame\Models\DarkMatterTransaction;
use Tests\IsolatedAccountTestCase;
use Tests\Traits\ManagesAuctions;

/**
 * Armada Salvage Auction: admin cancel (docs/AUCTION-SPEC.md sections 4.6 and 8).
 */
class AuctionCancelTest extends IsolatedAccountTestCase
{
    use ManagesAuctions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuctions();
    }

    public function testCancelRefundsTheLeaderExactly(): void
    {
        $auction = $this->makeAuction();
        $leader = $this->makeBidder(5000);
        $this->assertTrue($this->auctions->placeBid($leader, $auction->id, 600)->accepted);
        $this->assertSame(4400, $this->balance($leader));

        $this->assertTrue($this->auctions->cancel($auction->id, 'test'));

        $this->assertSame(5000, $this->balance($leader));
        $auction->refresh();
        $this->assertSame(Auction::STATUS_CANCELLED, $auction->status);
        $this->assertNull($auction->active_slot);
        $this->assertSame($this->nowTs(), $auction->settled_at);
        $this->assertNull($auction->winner_user_id);
        $this->assertSame(0, AuctionBid::where('auction_id', $auction->id)->whereNull('refunded_at')->count());

        $refund = DarkMatterTransaction::where('user_id', $leader->id)
            ->where('type', DarkMatterTransactionType::AUCTION_REFUND->value)
            ->sole();
        $this->assertSame(600, $refund->amount);
        $this->assertSame(5000, $refund->balance_after);
        $this->assertSame("Auction #{$auction->id} refund (cancelled)", $refund->description);
        $this->assertSame(0, $this->ledgerSum($auction->id));
        $this->assertAuctionHealthy($auction->id, [$leader->id], 5000);
    }

    public function testCancelRefundsOnlyOnce(): void
    {
        $auction = $this->makeAuction();
        $leader = $this->makeBidder(5000);
        $this->auctions->placeBid($leader, $auction->id, 600);

        $this->assertTrue($this->auctions->cancel($auction->id, 'first'));
        $this->assertFalse($this->auctions->cancel($auction->id, 'second'));
        $this->assertFalse($this->auctions->cancel($auction->id, 'third'));

        $this->assertSame(5000, $this->balance($leader));
        $this->assertSame(1, $this->ledgerCount($leader->id, DarkMatterTransactionType::AUCTION_REFUND, $auction->id));
        $this->assertAuctionHealthy($auction->id, [$leader->id], 5000);
    }

    public function testCancelAfterOutbidRefundsOnlyTheCurrentLeader(): void
    {
        $auction = $this->makeAuction();
        $first = $this->makeBidder(5000);
        $second = $this->makeBidder(5000);
        $this->auctions->placeBid($first, $auction->id, 600);
        $this->auctions->placeBid($second, $auction->id, 700);

        $this->assertTrue($this->auctions->cancel($auction->id, 'test'));

        $this->assertSame(5000, $this->balance($first));
        $this->assertSame(5000, $this->balance($second));
        // First: bid + outbid refund. Second: bid + cancel refund. Nobody is refunded twice.
        $this->assertSame(1, $this->ledgerCount($first->id, DarkMatterTransactionType::AUCTION_REFUND, $auction->id));
        $this->assertSame(1, $this->ledgerCount($second->id, DarkMatterTransactionType::AUCTION_REFUND, $auction->id));
        $this->assertSame(0, AuctionBid::where('auction_id', $auction->id)->whereNull('refunded_at')->count());
        $this->assertAuctionHealthy($auction->id, [$first->id, $second->id], 10000);
    }

    public function testCancelWithoutBidsSucceedsAndMovesNoDarkMatter(): void
    {
        $auction = $this->makeAuction();

        $this->assertTrue($this->auctions->cancel($auction->id, 'test'));

        $auction->refresh();
        $this->assertSame(Auction::STATUS_CANCELLED, $auction->status);
        $this->assertNull($auction->active_slot);
        $this->assertSame(0, DarkMatterTransaction::where('description', 'like', "Auction #{$auction->id} %")->count());
        $this->assertAuctionHealthy($auction->id);
    }

    public function testCancelAfterEndsAtButBeforeSettlementStillRefunds(): void
    {
        $auction = $this->makeAuction();
        $leader = $this->makeBidder(5000);
        $this->auctions->placeBid($leader, $auction->id, 600);
        $this->travelToTimestamp($auction->ends_at + 60);

        $this->assertTrue($this->auctions->cancel($auction->id, 'late'));

        $this->assertSame(5000, $this->balance($leader));
        $this->assertFalse($this->auctions->settleIfDue($auction->id), 'A cancelled auction cannot settle.');
        $this->assertAuctionHealthy($auction->id, [$leader->id], 5000);
    }

    public function testClosedAuctionsCannotBeCancelled(): void
    {
        $settled = $this->makeAuction();
        $winner = $this->makeBidder(5000);
        $this->auctions->placeBid($winner, $settled->id, 600);
        $this->travelToTimestamp($settled->ends_at);
        $this->assertTrue($this->auctions->settleIfDue($settled->id));

        $unsold = $this->makeAuction();
        $this->travelToTimestamp($unsold->ends_at);
        $this->assertTrue($this->auctions->settleIfDue($unsold->id));

        $this->assertFalse($this->auctions->cancel($settled->id, 'no'));
        $this->assertFalse($this->auctions->cancel($unsold->id, 'no'));
        $this->assertFalse($this->auctions->cancel(999999999, 'no'));

        // The winner keeps paying for the lot: nothing was refunded.
        $this->assertSame(4400, $this->balance($winner));
        $this->assertSame(Auction::STATUS_SETTLED, $settled->fresh()->status);
        $this->assertSame(Auction::STATUS_UNSOLD, $unsold->fresh()->status);
        $this->assertAuctionHealthy($settled->id, [$winner->id], 5000);
    }

    public function testCancelFreesTheActiveSlotAndRejectsFurtherBids(): void
    {
        $auction = $this->makeAuction();
        $bidder = $this->makeBidder(5000);
        $this->assertTrue($this->auctions->cancel($auction->id, 'test'));

        $this->assertSame('closed', $this->auctions->placeBid($bidder, $auction->id, 600)->errorKey);
        $this->assertNull($this->auctions->current());

        // The slot is free again: another auction may be active.
        $next = $this->makeAuction();
        $this->assertSame(1, (int)$next->active_slot);
        $this->assertSame($next->id, $this->auctions->current()?->id);
    }

    public function testCancelledAuctionShowsInHistory(): void
    {
        $auction = $this->makeAuction();
        $this->auctions->cancel($auction->id, 'test');

        $this->assertSame([$auction->id], $this->auctions->recent()->pluck('id')->all());
    }
}
