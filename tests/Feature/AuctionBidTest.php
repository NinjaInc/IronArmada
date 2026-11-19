<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use OGame\Enums\DarkMatterTransactionType;
use OGame\Models\Auction;
use OGame\Models\AuctionBid;
use OGame\Models\Ban;
use OGame\Models\DarkMatterTransaction;
use OGame\Models\User;
use OGame\Services\SettingsService;
use Tests\IsolatedAccountTestCase;
use Tests\Traits\ManagesAuctions;

/**
 * Armada Salvage Auction: bidding rules (docs/AUCTION-SPEC.md sections 1.2, 4.3 and 8).
 */
class AuctionBidTest extends IsolatedAccountTestCase
{
    use ManagesAuctions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuctions();
    }

    public function testMinimumNextBidBoundaries(): void
    {
        // [starting_bid, abs, pct, current_bid, expected minimum]
        $cases = [
            'no bid yet: starting bid' => [600, 50, 5, 0, 600],
            'starting bid rounds up to 10' => [605, 50, 5, 0, 610],
            'absolute step wins on small bids' => [600, 50, 5, 600, 650],
            'absolute and percent step tie' => [600, 50, 5, 1000, 1050],
            'percent step wins, rounded up' => [600, 50, 5, 1010, 1070],
            'large bid exact percent' => [600, 50, 5, 10000, 10500],
            'large bid percent ceil then round to 10' => [600, 50, 5, 10001, 10510],
            'zero increments still strictly above current' => [0, 0, 0, 0, 10],
            'zero increments, current 10' => [0, 0, 0, 10, 20],
            'zero absolute, percent only' => [100, 0, 10, 1000, 1100],
        ];

        foreach ($cases as $label => [$starting, $abs, $pct, $current, $expected]) {
            $auction = new Auction([
                'starting_bid' => $starting,
                'min_increment_abs' => $abs,
                'min_increment_pct' => $pct,
                'current_bid' => $current,
            ]);
            $this->assertSame($expected, $this->auctions->minimumNextBid($auction), $label);
            $this->assertGreaterThan($current, $this->auctions->minimumNextBid($auction), $label);
        }
    }

    public function testBidBelowMinimumIsRejectedAndChangesNothing(): void
    {
        $auction = $this->makeAuction();
        $bidder = $this->makeBidder(5000);

        $result = $this->auctions->placeBid($bidder, $auction->id, 590);

        $this->assertFalse($result->accepted);
        $this->assertSame('too_low', $result->errorKey);
        $this->assertSame(600, $result->minimumNextBid);
        $this->assertSame(5000, $this->balance($bidder));
        $this->assertSame(0, AuctionBid::where('auction_id', $auction->id)->count());
        $this->assertSame(0, DarkMatterTransaction::where('user_id', $bidder->id)->count());
        $this->assertNull($auction->fresh()->leader_user_id);
        $this->assertAuctionHealthy($auction->id, [$bidder->id], 5000);
    }

    public function testFirstBidAtStartingBidIsAccepted(): void
    {
        $auction = $this->makeAuction();
        $bidder = $this->makeBidder(5000);

        $result = $this->auctions->placeBid($bidder, $auction->id, 600);

        $this->assertTrue($result->accepted);
        $this->assertSame('', $result->errorKey);
        $this->assertSame(600, $result->currentBid);
        $this->assertSame(650, $result->minimumNextBid);
        $this->assertNull($result->outbidUserId);
        $this->assertSame(0, $result->refundedAmount);
        $this->assertFalse($result->extended);

        $this->assertSame(4400, $this->balance($bidder));

        $transactions = DarkMatterTransaction::where('user_id', $bidder->id)->get();
        $this->assertCount(1, $transactions);
        $this->assertSame(DarkMatterTransactionType::AUCTION_BID->value, $transactions[0]->type);
        $this->assertSame(-600, $transactions[0]->amount);
        $this->assertSame(4400, $transactions[0]->balance_after);
        $this->assertSame("Auction #{$auction->id} bid", $transactions[0]->description);

        $auction->refresh();
        $this->assertSame($bidder->id, (int)$auction->leader_user_id);
        $this->assertSame(600, $auction->current_bid);
        $this->assertSame(1, $auction->bid_count);

        $bid = AuctionBid::where('auction_id', $auction->id)->sole();
        $this->assertSame($bidder->id, $bid->user_id);
        $this->assertSame(600, $bid->amount);
        $this->assertNull($bid->refunded_at);
        $this->assertFalse($bid->is_bot);
        $this->assertFalse($bid->extended_end);

        $this->assertAuctionHealthy($auction->id, [$bidder->id], 5000);
    }

    public function testOutbidRefundsPreviousLeaderInFull(): void
    {
        $auction = $this->makeAuction();
        $first = $this->makeBidder(5000);
        $second = $this->makeBidder(5000);

        $this->assertTrue($this->auctions->placeBid($first, $auction->id, 600)->accepted);
        $result = $this->auctions->placeBid($second, $auction->id, 700);

        $this->assertTrue($result->accepted);
        $this->assertSame($first->id, $result->outbidUserId);
        $this->assertSame(600, $result->refundedAmount);

        $this->assertSame(5000, $this->balance($first));
        $this->assertSame(4300, $this->balance($second));

        $this->assertSame(1, $this->ledgerCount($first->id, DarkMatterTransactionType::AUCTION_BID, $auction->id));
        $this->assertSame(1, $this->ledgerCount($first->id, DarkMatterTransactionType::AUCTION_REFUND, $auction->id));
        $refund = DarkMatterTransaction::where('user_id', $first->id)
            ->where('type', DarkMatterTransactionType::AUCTION_REFUND->value)
            ->sole();
        $this->assertSame(600, $refund->amount);
        $this->assertSame(5000, $refund->balance_after);
        $this->assertSame("Auction #{$auction->id} refund (outbid)", $refund->description);

        $firstBid = AuctionBid::where('auction_id', $auction->id)->where('user_id', $first->id)->sole();
        $this->assertNotNull($firstBid->refunded_at);
        $secondBid = AuctionBid::where('auction_id', $auction->id)->where('user_id', $second->id)->sole();
        $this->assertNull($secondBid->refunded_at);

        $auction->refresh();
        $this->assertSame($second->id, (int)$auction->leader_user_id);
        $this->assertSame(700, $auction->current_bid);
        $this->assertSame(2, $auction->bid_count);

        $this->assertAuctionHealthy($auction->id, [$first->id, $second->id], 10000);
    }

    public function testLongOutbidChainConservesDarkMatter(): void
    {
        $auction = $this->makeAuction(['ends_at' => $this->nowTs() + 100000]);
        $users = [$this->makeBidder(100000), $this->makeBidder(100000), $this->makeBidder(100000)];
        $ids = array_map(static fn (User $user): int => $user->id, $users);

        for ($i = 0; $i < 12; $i++) {
            $bidder = $users[$i % 3];
            $minimum = $this->auctions->minimumNextBid($auction->fresh());
            $result = $this->auctions->placeBid($bidder, $auction->id, $minimum);

            $this->assertTrue($result->accepted, "bid #{$i}");
            $this->assertAuctionHealthy($auction->id, $ids, 300000);
        }

        // Exactly one held bid, every other bid refunded exactly once.
        $this->assertSame(1, AuctionBid::where('auction_id', $auction->id)->whereNull('refunded_at')->count());
        $this->assertSame(11, AuctionBid::where('auction_id', $auction->id)->whereNotNull('refunded_at')->count());
        $refunds = DarkMatterTransaction::where('type', DarkMatterTransactionType::AUCTION_REFUND->value)
            ->where('description', 'like', "Auction #{$auction->id} %")->count();
        $this->assertSame(11, $refunds);
    }

    public function testLeaderCannotOutbidThemself(): void
    {
        $auction = $this->makeAuction();
        $bidder = $this->makeBidder(5000);
        $this->assertTrue($this->auctions->placeBid($bidder, $auction->id, 600)->accepted);

        $result = $this->auctions->placeBid($bidder, $auction->id, 5000);

        $this->assertFalse($result->accepted);
        $this->assertSame('already_leading', $result->errorKey);
        $this->assertSame(4400, $this->balance($bidder));
        $this->assertSame(1, AuctionBid::where('auction_id', $auction->id)->count());
        $this->assertSame(600, $auction->fresh()->current_bid);
        $this->assertAuctionHealthy($auction->id, [$bidder->id], 5000);
    }

    public function testInsufficientDarkMatterIsRejectedWithoutPartialDebit(): void
    {
        $auction = $this->makeAuction();
        $leader = $this->makeBidder(5000);
        $poor = $this->makeBidder(699);
        $this->assertTrue($this->auctions->placeBid($leader, $auction->id, 600)->accepted);

        $result = $this->auctions->placeBid($poor, $auction->id, 700);

        $this->assertFalse($result->accepted);
        $this->assertSame('insufficient_dm', $result->errorKey);
        $this->assertSame(699, $this->balance($poor));
        $this->assertSame(0, DarkMatterTransaction::where('user_id', $poor->id)->count());
        $this->assertSame(0, AuctionBid::where('auction_id', $auction->id)->where('user_id', $poor->id)->count());

        // The leader keeps the lead and the held bid, and is not refunded.
        $auction->refresh();
        $this->assertSame($leader->id, (int)$auction->leader_user_id);
        $this->assertSame(600, $auction->current_bid);
        $this->assertSame(4400, $this->balance($leader));
        $this->assertSame(0, $this->ledgerCount($leader->id, DarkMatterTransactionType::AUCTION_REFUND, $auction->id));
        $this->assertAuctionHealthy($auction->id, [$leader->id, $poor->id], 5699);
    }

    public function testBidBeyondSignedIntegerRangeIsRejectedAsInsufficient(): void
    {
        $auction = $this->makeAuction();
        $bidder = $this->makeBidder(2147483647);

        $result = $this->auctions->placeBid($bidder, $auction->id, 2147483648);

        $this->assertFalse($result->accepted);
        $this->assertSame('insufficient_dm', $result->errorKey);
        $this->assertSame(2147483647, $this->balance($bidder));
    }

    public function testBidOutsideSnipeWindowDoesNotExtend(): void
    {
        $auction = $this->makeAuction(['ends_at' => $this->nowTs() + 3600]);
        $bidder = $this->makeBidder(5000);

        $result = $this->auctions->placeBid($bidder, $auction->id, 600);

        $this->assertTrue($result->accepted);
        $this->assertFalse($result->extended);
        $auction->refresh();
        $this->assertSame($auction->original_ends_at, $auction->ends_at);
        $this->assertSame(0, $auction->extension_count);
    }

    public function testBidInsideSnipeWindowExtendsEnd(): void
    {
        $auction = $this->makeAuction();
        $bidder = $this->makeBidder(5000);
        $endsAt = $auction->ends_at;
        $this->travelToTimestamp($endsAt - 60);

        $result = $this->auctions->placeBid($bidder, $auction->id, 600);

        $this->assertTrue($result->accepted);
        $this->assertTrue($result->extended);
        $this->assertSame($endsAt - 60 + 300, $result->endsAt);

        $auction->refresh();
        $this->assertSame($endsAt - 60 + 300, $auction->ends_at);
        $this->assertSame($endsAt, $auction->original_ends_at);
        $this->assertSame(1, $auction->extension_count);
        $this->assertTrue(AuctionBid::where('auction_id', $auction->id)->sole()->extended_end);
        $this->assertAuctionHealthy($auction->id, [$bidder->id], 5000);
    }

    public function testBidExactlyAtSnipeWindowEdgeExtends(): void
    {
        $auction = $this->makeAuction();
        $bidder = $this->makeBidder(5000);
        $this->travelToTimestamp($auction->ends_at - 300);

        $this->assertTrue($this->auctions->placeBid($bidder, $auction->id, 600)->extended);
    }

    public function testBidIsStillAcceptedAtOldEndAfterExtension(): void
    {
        $auction = $this->makeAuction();
        $first = $this->makeBidder(5000);
        $second = $this->makeBidder(5000);
        $originalEnd = $auction->ends_at;

        $this->travelToTimestamp($originalEnd - 10);
        $this->assertTrue($this->auctions->placeBid($first, $auction->id, 600)->extended);

        // Past the original end, inside the extension: still open, and settlement is not due.
        $this->travelToTimestamp($originalEnd + 5);
        $this->assertFalse($this->auctions->settleIfDue($auction->id));
        $this->assertTrue($this->auctions->placeBid($second, $auction->id, 700)->accepted);
        $this->assertAuctionHealthy($auction->id, [$first->id, $second->id], 10000);
    }

    public function testRepeatedLateBidsNeverPassTheExtensionCap(): void
    {
        $auction = $this->makeAuction();
        $users = [$this->makeBidder(1000000), $this->makeBidder(1000000)];
        $ids = [$users[0]->id, $users[1]->id];
        $cap = $auction->original_ends_at + 3600;

        $capReached = false;
        for ($i = 0; $i < 60; $i++) {
            $auction->refresh();
            $this->travelToTimestamp($auction->ends_at - 10);
            $minimum = $this->auctions->minimumNextBid($auction);
            $result = $this->auctions->placeBid($users[$i % 2], $auction->id, $minimum);

            $this->assertTrue($result->accepted, "bid #{$i} must be accepted until the capped end");
            $this->assertLessThanOrEqual($cap, $result->endsAt);
            $this->assertLessThanOrEqual($cap, $auction->fresh()->ends_at);
            $this->assertAuctionHealthy($auction->id, $ids, 2000000);

            if ($auction->fresh()->ends_at === $cap) {
                $capReached = true;
                // One more bid at the cap: accepted, but no further extension.
                $auction->refresh();
                $this->travelToTimestamp($cap - 10);
                $next = $this->auctions->placeBid($users[($i + 1) % 2], $auction->id, $this->auctions->minimumNextBid($auction));
                $this->assertTrue($next->accepted);
                $this->assertFalse($next->extended);
                $this->assertSame($cap, $next->endsAt);
                break;
            }
        }

        $this->assertTrue($capReached, 'The extension cap should be reached within 60 bids.');
        $this->assertSame($cap, $auction->fresh()->ends_at);

        // At the capped end the auction is closed to bids.
        $this->travelToTimestamp($cap);
        $late = $this->auctions->placeBid($users[0], $auction->id, $this->auctions->minimumNextBid($auction->fresh()));
        $this->assertSame('closed', $late->errorKey);
        $this->assertAuctionHealthy($auction->id, $ids, 2000000);
    }

    public function testBidAtOrAfterEndsAtIsClosed(): void
    {
        $auction = $this->makeAuction();
        $bidder = $this->makeBidder(5000);

        $this->travelToTimestamp($auction->ends_at);
        $atEnd = $this->auctions->placeBid($bidder, $auction->id, 600);
        $this->assertFalse($atEnd->accepted);
        $this->assertSame('closed', $atEnd->errorKey);

        $this->travelToTimestamp($auction->ends_at + 100);
        $this->assertSame('closed', $this->auctions->placeBid($bidder, $auction->id, 600)->errorKey);

        $this->assertSame(5000, $this->balance($bidder));
        $this->assertSame(0, AuctionBid::where('auction_id', $auction->id)->count());
    }

    public function testOneSecondBeforeEndsAtIsStillOpen(): void
    {
        $auction = $this->makeAuction();
        $bidder = $this->makeBidder(5000);

        $this->travelToTimestamp($auction->ends_at - 1);

        $this->assertTrue($this->auctions->placeBid($bidder, $auction->id, 600)->accepted);
    }

    public function testBidOnSettledOrMissingAuctionIsClosed(): void
    {
        $auction = $this->makeAuction();
        $bidder = $this->makeBidder(5000);
        $this->travelToTimestamp($auction->ends_at);
        $this->assertTrue($this->auctions->settleIfDue($auction->id));

        $this->assertSame('closed', $this->auctions->placeBid($bidder, $auction->id, 600)->errorKey);
        $this->assertSame('closed', $this->auctions->placeBid($bidder, 999999999, 600)->errorKey);
        $this->assertSame(5000, $this->balance($bidder));
    }

    public function testBidWhileFeatureDisabledIsRejected(): void
    {
        $auction = $this->makeAuction();
        $bidder = $this->makeBidder(5000);
        resolve(SettingsService::class)->set('auction_enabled', 0);

        $result = $this->auctions->placeBid($bidder, $auction->id, 600);

        $this->assertFalse($this->auctions->isEnabled());
        $this->assertFalse($result->accepted);
        $this->assertSame('disabled', $result->errorKey);
        $this->assertSame(5000, $this->balance($bidder));
        $this->assertSame(0, AuctionBid::where('auction_id', $auction->id)->count());
    }

    public function testBannedPlayerCannotBid(): void
    {
        $auction = $this->makeAuction();
        $bidder = $this->makeBidder(5000);
        Ban::create(['user_id' => $bidder->id, 'reason' => 'test', 'banned_until' => null, 'canceled' => false]);

        $result = $this->auctions->placeBid($bidder, $auction->id, 600);

        $this->assertFalse($result->accepted);
        $this->assertSame('not_allowed', $result->errorKey);
        $this->assertSame(5000, $this->balance($bidder));
        $this->assertSame(0, AuctionBid::where('auction_id', $auction->id)->count());
    }

    public function testExpiredBanDoesNotBlockBidding(): void
    {
        $auction = $this->makeAuction();
        $bidder = $this->makeBidder(5000);
        Ban::create([
            'user_id' => $bidder->id,
            'reason' => 'test',
            'banned_until' => Date::now()->subDay(),
            'canceled' => false,
        ]);

        $this->assertTrue($this->auctions->placeBid($bidder, $auction->id, 600)->accepted);
    }

    public function testPlayerInVacationModeCannotBid(): void
    {
        $auction = $this->makeAuction();
        $bidder = $this->makeBidder(5000);
        User::whereKey($bidder->id)->update(['vacation_mode' => true]);

        $result = $this->auctions->placeBid($bidder->fresh(), $auction->id, 600);

        $this->assertFalse($result->accepted);
        $this->assertSame('not_allowed', $result->errorKey);
        $this->assertSame(5000, $this->balance($bidder));
    }

    public function testBotsMayOnlyBidWhenEnabled(): void
    {
        $auction = $this->makeAuction();
        $bot = $this->makeBidder(5000);
        User::whereKey($bot->id)->update(['is_bot' => true]);

        $this->assertSame('not_allowed', $this->auctions->placeBid($bot->fresh(), $auction->id, 600)->errorKey);

        resolve(SettingsService::class)->set('auction_bots_enabled', 1);
        $this->assertTrue($this->auctions->placeBid($bot->fresh(), $auction->id, 600)->accepted);
        $this->assertTrue(AuctionBid::where('auction_id', $auction->id)->sole()->is_bot);
    }

    public function testDisabledSettingAtZeroIsHonoured(): void
    {
        // SettingsService::get() treats a stored "0" as unset: the service must still read 0 as off.
        resolve(SettingsService::class)->set('auction_enabled', 0);
        $this->assertFalse($this->auctions->isEnabled());

        resolve(SettingsService::class)->set('auction_enabled', 1);
        $this->assertTrue($this->auctions->isEnabled());
    }

    public function testBidRejectionsAfterAcceptedBidLeaveStateUntouched(): void
    {
        $auction = $this->makeAuction();
        $leader = $this->makeBidder(5000);
        $other = $this->makeBidder(5000);
        $this->assertTrue($this->auctions->placeBid($leader, $auction->id, 600)->accepted);
        $before = $auction->fresh()->getAttributes();

        // Below the new minimum (650).
        $this->assertSame('too_low', $this->auctions->placeBid($other, $auction->id, 640)->errorKey);
        // Equal to the current bid.
        $this->assertSame('too_low', $this->auctions->placeBid($other, $auction->id, 600)->errorKey);

        $this->assertSame($before, $auction->fresh()->getAttributes());
        $this->assertSame(5000, $this->balance($other));
        $this->assertAuctionHealthy($auction->id, [$leader->id, $other->id], 10000);
    }
}
