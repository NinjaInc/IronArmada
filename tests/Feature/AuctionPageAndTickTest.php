<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use OGame\Enums\HighscoreTypeEnum;
use OGame\Http\Controllers\PublicHighscoreController;
use OGame\Jobs\NotifyAuctionEvent;
use OGame\Models\Auction;
use OGame\Models\AuctionBid;
use Tests\IsolatedAccountTestCase;
use Tests\Traits\ManagesAuctions;

/**
 * Armada Salvage Auction: rendering of the auction page and merchant tile, the scheduler tick, CSRF and throttle
 * on the bid endpoint, and the public highscore feed contract (docs/AUCTION-SPEC.md section 8,
 * docs/AUCTION-FINAL-REVIEW.md F7).
 *
 * Nothing here has been run (no vendor/, PHP 8.3, no Docker when written).
 */
class AuctionPageAndTickTest extends IsolatedAccountTestCase
{
    use ManagesAuctions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuctions();
    }

    private function enable(bool $enabled): void
    {
        resolve(\OGame\Services\SettingsService::class)->set('auction_enabled', $enabled ? 1 : 0);
    }

    private function countEvents(string $event): int
    {
        return Bus::dispatched(NotifyAuctionEvent::class, fn (NotifyAuctionEvent $job) => $job->event === $event)->count();
    }

    // ---- Page and tile rendering -------------------------------------------------------------------------------

    public function testAuctionPageRendersWhenEnabledWithActiveAuction(): void
    {
        $this->enable(true);
        $this->makeAuction(['current_bid' => 0]);

        $response = $this->get('/merchant/auction');

        $response->assertOk();
        $response->assertSee('Salvage Auction');
        $response->assertSee('Salvaged Metal Crate');
        $response->assertSee('Place bid');
        $response->assertSee('Starting bid');
        $response->assertSee('Hammer falls in');
    }

    public function testAuctionPageRendersUnclaimedWin(): void
    {
        $this->enable(true);
        $this->makeAuction([
            'status' => Auction::STATUS_SETTLED,
            'active_slot' => null,
            'winner_user_id' => $this->currentUserId,
            'final_price' => 700,
            'current_bid' => 700,
            'ends_at' => $this->nowTs() - 10,
        ]);

        $response = $this->get('/merchant/auction');

        $response->assertOk();
        $response->assertSee('Unclaimed lots');
        $response->assertSee('Claim to planet');
    }

    public function testAuctionPageRendersClosedState(): void
    {
        $this->enable(true);

        $response = $this->get('/merchant/auction');

        $response->assertOk();
        $response->assertSee('Auction closed.');
        $response->assertSee('Opens in');
        $response->assertDontSee('Place bid');
    }

    public function testAuctionPageRendersDisabledState(): void
    {
        $this->enable(false);

        $response = $this->get('/merchant/auction');

        $response->assertOk();
        $response->assertSee('The Salvage Auction is offline.');
        $response->assertDontSee('Place bid');
    }

    public function testMerchantTileShowsBadgeStates(): void
    {
        $this->enable(true);
        $auction = $this->makeAuction(['current_bid' => 0]);

        $response = $this->get('/merchant');
        $response->assertOk();
        $response->assertSee('js_traderAuctioneer', false);
        $response->assertSee(route('merchant.auction.index'), false);
        $response->assertSee('ia-auction-badge', false);
        $response->assertSee('Active');

        $auction->update(['current_bid' => 1500]);
        $this->get('/merchant')->assertSee('1,500');

        $auction->update(['status' => Auction::STATUS_UNSOLD, 'active_slot' => null]);
        $this->get('/merchant')->assertSee('ia-auction-badge', false)->assertSee('Closed');
    }

    public function testMerchantTileHidesBadgeWhenDisabled(): void
    {
        $this->enable(false);
        $this->makeAuction();

        $response = $this->get('/merchant');

        $response->assertOk();
        $response->assertSee('js_traderAuctioneer', false);
        $response->assertDontSee('ia-auction-badge', false);
    }

    // ---- Tick --------------------------------------------------------------------------------------------------

    public function testTickSendsFinalCallExactlyOnceAcrossTwoTicks(): void
    {
        Bus::fake();
        $this->travelToUtc('2024-01-01 12:00:00');
        $auction = $this->makeAuction(['ends_at' => $this->nowTs() + 600, 'original_ends_at' => $this->nowTs() + 600]);

        $this->assertSame(0, Artisan::call('ogamex:scheduler:auction-tick'));
        $this->assertSame(0, Artisan::call('ogamex:scheduler:auction-tick'));

        $this->assertSame(1, $this->countEvents('final_call'));
        $this->assertTrue((bool)$auction->fresh()->final_call_sent);
    }

    public function testTickDoesNothingWhenDisabled(): void
    {
        Bus::fake();
        $this->enable(false);
        $this->travelToUtc('2024-01-01 12:00:00');
        $this->makeAuction(['ends_at' => $this->nowTs() + 600, 'original_ends_at' => $this->nowTs() + 600]);

        Artisan::call('ogamex:scheduler:auction-tick');

        Bus::assertNotDispatched(NotifyAuctionEvent::class);
    }

    public function testTickOpensTodaysAuctionAtOpenHourOnce(): void
    {
        Bus::fake();
        $this->travelToUtc('2024-01-01 18:00:30');

        Artisan::call('ogamex:scheduler:auction-tick');
        Artisan::call('ogamex:scheduler:auction-tick');

        $this->assertSame(1, Auction::where('status', Auction::STATUS_ACTIVE)->count());
        $this->assertSame(1, $this->countEvents('opened'));
    }

    public function testTickSettlesDueAuctionWithBidsAndNotifiesWon(): void
    {
        Bus::fake();
        $this->travelToUtc('2024-01-01 12:00:00');
        $bidder = $this->makeBidder(5000);
        $auction = $this->makeAuction(['ends_at' => $this->nowTs() + 100, 'original_ends_at' => $this->nowTs() + 100]);
        $this->assertTrue($this->auctions->placeBid($bidder, $auction->id, 700)->accepted);

        $this->travelToUtc('2024-01-01 12:05:00');
        Artisan::call('ogamex:scheduler:auction-tick');

        $this->assertSame(Auction::STATUS_SETTLED, $auction->fresh()->status);
        $this->assertSame(1, $this->countEvents('won'));
        $this->assertSame(0, $this->countEvents('unsold'));
        $this->assertAuctionHealthy($auction->id, [$bidder->id], 5000);
    }

    public function testTickSettlesDueAuctionWithoutBidsAsUnsold(): void
    {
        Bus::fake();
        $this->travelToUtc('2024-01-01 12:00:00');
        $auction = $this->makeAuction(['ends_at' => $this->nowTs() + 100, 'original_ends_at' => $this->nowTs() + 100]);

        $this->travelToUtc('2024-01-01 12:05:00');
        Artisan::call('ogamex:scheduler:auction-tick');

        $this->assertSame(Auction::STATUS_UNSOLD, $auction->fresh()->status);
        $this->assertSame(1, $this->countEvents('unsold'));
        $this->assertSame(0, AuctionBid::count());
    }

    /**
     * Written against the intended behaviour of review F1 (the page poll that settles an auction dispatches the
     * won notification). Fails until the controller or service dispatches it.
     */
    public function testPollThatSettlesAnAuctionDispatchesWonOnce(): void
    {
        Bus::fake();
        $this->travelToUtc('2024-01-01 12:00:00');
        $bidder = $this->makeBidder(5000);
        $auction = $this->makeAuction(['ends_at' => $this->nowTs() + 100, 'original_ends_at' => $this->nowTs() + 100]);
        $this->assertTrue($this->auctions->placeBid($bidder, $auction->id, 700)->accepted);

        $this->travelToUtc('2024-01-01 12:05:00');
        $this->getJson('/ajax/merchant/auction/state')->assertOk();
        $this->getJson('/ajax/merchant/auction/state')->assertOk();
        Artisan::call('ogamex:scheduler:auction-tick');

        $this->assertSame(1, $this->countEvents('won'));
    }

    // ---- CSRF and throttle -------------------------------------------------------------------------------------

    public function testBidWithMissingOrInvalidTokenIsRejectedWith419ByTheCsrfMiddleware(): void
    {
        $this->enable(true);
        $auction = $this->makeAuction();
        $this->setDarkMatter($this->currentUserId, 5000);

        // Laravel skips CSRF verification while the app runs as "testing"; make it enforce for this request.
        $original = $this->app['env'];
        $this->app['env'] = 'local';
        try {
            $this->postJson('/ajax/merchant/auction/bid', ['auction_id' => $auction->id, 'amount' => 600])
                ->assertStatus(419);
            $this->postJson('/ajax/merchant/auction/bid', ['auction_id' => $auction->id, 'amount' => 600, '_token' => 'wrong'])
                ->assertStatus(419);
        } finally {
            $this->app['env'] = $original;
        }

        $this->assertSame(5000, $this->balance($this->currentUserId));
        $this->assertSame(0, AuctionBid::count());
    }

    public function testBidEndpointIsThrottledAfter20RequestsPerMinute(): void
    {
        $this->enable(true);
        $auction = $this->makeAuction();
        RateLimiter::clear('throttle');

        $last = null;
        for ($i = 0; $i < 21; $i++) {
            // 422 validation failures still count against the limiter and never touch dark matter.
            $last = $this->postJson('/ajax/merchant/auction/bid', [
                'auction_id' => $auction->id,
                'amount' => 'abc',
                '_token' => csrf_token(),
            ]);
            if ($i < 20) {
                $last->assertStatus(422);
            }
        }

        $last->assertStatus(429);
    }

    // ---- Public highscore API contract -------------------------------------------------------------------------

    public function testPublicHighscoreApiContractIsUnchanged(): void
    {
        Cache::forget(PublicHighscoreController::CACHE_KEY);

        $response = $this->getJson('/api/public/highscores');

        $response->assertOk();
        $response->assertHeader('Access-Control-Allow-Origin', '*');
        $this->assertStringContainsString('max-age=300', (string)$response->headers->get('Cache-Control'));

        $json = $response->json();
        $this->assertSame(['game', 'players', 'updated_at', 'categories'], array_keys($json));
        $this->assertSame('Iron Armada', $json['game']);
        $this->assertIsInt($json['players']);
        $this->assertTrue($json['updated_at'] === null || is_string($json['updated_at']));

        $this->assertSame(
            array_map(fn (HighscoreTypeEnum $type) => $type->name, HighscoreTypeEnum::cases()),
            array_keys($json['categories'])
        );
        foreach ($json['categories'] as $list) {
            $this->assertLessThanOrEqual(10, count($list));
            foreach ($list as $row) {
                $this->assertSame(['rank', 'name', 'alliance', 'points', 'bot'], array_keys($row));
                $this->assertIsInt($row['rank']);
                $this->assertIsString($row['name']);
                $this->assertTrue($row['alliance'] === null || is_string($row['alliance']));
                $this->assertIsInt($row['points']);
                $this->assertIsBool($row['bot']);
            }
        }
    }
}
