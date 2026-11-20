<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use OGame\Jobs\NotifyAuctionEvent;
use OGame\Models\Auction;
use OGame\Models\AuctionBid;
use OGame\Models\Planet;
use OGame\Models\User;
use OGame\Services\SettingsService;
use Tests\IsolatedAccountTestCase;

/**
 * Armada Salvage Auction HTTP layer: state, bid and claim endpoints.
 */
class AuctionHttpTest extends IsolatedAccountTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Other tests or a dev database may hold an active auction (unique active_slot): start clean.
        // Rolled back with the test transaction.
        Auction::query()->delete();
    }

    private function enableAuction(bool $enabled = true): void
    {
        resolve(SettingsService::class)->set('auction_enabled', $enabled ? 1 : 0);
    }

    private function makeAuction(array $overrides = []): Auction
    {
        $now = (int)now()->timestamp;

        return Auction::create(array_merge([
            'auction_date' => '2024-01-01',
            'status' => Auction::STATUS_ACTIVE,
            'active_slot' => 1,
            'lot_key' => 'crate_metal',
            'lot_payload' => ['resources' => ['metal' => 1000, 'crystal' => 0, 'deuterium' => 0], 'units' => []],
            'starting_bid' => 500,
            'min_increment_abs' => 100,
            'min_increment_pct' => 5,
            'current_bid' => 0,
            'bid_count' => 0,
            'starts_at' => $now,
            'ends_at' => $now + 3600,
            'original_ends_at' => $now + 3600,
            'extension_count' => 0,
            'final_call_sent' => false,
        ], $overrides));
    }

    private function giveDarkMatter(int $userId, int $amount): void
    {
        User::whereKey($userId)->update(['dark_matter' => $amount]);
    }

    private function darkMatter(int $userId): int
    {
        return (int)User::whereKey($userId)->value('dark_matter');
    }

    private function postBid(int $auctionId, int|string $amount, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/ajax/merchant/auction/bid', array_merge([
            'auction_id' => $auctionId,
            'amount' => $amount,
            '_token' => csrf_token(),
        ], $extra));
    }

    public function testRouteNamesAreRegistered(): void
    {
        foreach (['index', 'state', 'bid', 'claim'] as $name) {
            $this->assertTrue(Route::has('merchant.auction.' . $name), "merchant.auction.{$name} missing");
        }
    }

    public function testStateWhenDisabled(): void
    {
        $this->enableAuction(false);
        $this->makeAuction();

        $response = $this->getJson('/ajax/merchant/auction/state');

        $response->assertOk();
        $response->assertJson(['enabled' => false, 'auction' => null]);
        $this->assertIsInt($response->json('server_now'));
    }

    public function testStateWithoutActiveAuctionReturnsNextOpening(): void
    {
        $this->enableAuction();

        $response = $this->getJson('/ajax/merchant/auction/state');

        $response->assertOk();
        $response->assertJson(['enabled' => true, 'auction' => null, 'recent_bids' => []]);
        $this->assertSame((int)now()->timestamp, $response->json('server_now'));
        $this->assertGreaterThan((int)now()->timestamp, $response->json('next_opens_at'));
    }

    public function testStateContract(): void
    {
        $this->enableAuction();
        $auction = $this->makeAuction();

        $response = $this->getJson('/ajax/merchant/auction/state');

        $response->assertOk();
        $response->assertJsonPath('auction.id', $auction->id);
        $response->assertJsonPath('auction.lot_key', 'crate_metal');
        $response->assertJsonPath('auction.lot.resources.metal', 1000);
        $response->assertJsonPath('auction.minimum_next_bid', 500);
        $response->assertJsonPath('auction.leader_name', null);
        $response->assertJsonPath('auction.you_lead', false);
        $response->assertJsonPath('auction.ends_at', $auction->ends_at);
        $this->assertSame([], $response->json('unclaimed'));
    }

    public function testBidWithMissingTokenReturnsInvalidTokenWithoutCrashing(): void
    {
        $this->enableAuction();
        $auction = $this->makeAuction();
        $this->giveDarkMatter($this->currentUserId, 5000);

        $response = $this->postJson('/ajax/merchant/auction/bid', ['auction_id' => $auction->id, 'amount' => 600]);

        $response->assertOk();
        $response->assertJson(['success' => false, 'message' => 'Invalid token.']);
        $this->assertSame(5000, $this->darkMatter($this->currentUserId));
        $this->assertSame(0, AuctionBid::count());
    }

    public function testBidValidationErrorsReturn422(): void
    {
        $this->enableAuction();
        $auction = $this->makeAuction();

        $this->postBid($auction->id, 'abc')->assertStatus(422);
        $this->postBid($auction->id, 0)->assertStatus(422);
        $this->postBid($auction->id, 2000000001)->assertStatus(422);
        $this->postJson('/ajax/merchant/auction/bid', ['amount' => 600, '_token' => csrf_token()])->assertStatus(422);
    }

    public function testBidWhenDisabled(): void
    {
        $this->enableAuction(false);
        $auction = $this->makeAuction();
        $this->giveDarkMatter($this->currentUserId, 5000);

        $response = $this->postBid($auction->id, 600);

        $response->assertOk();
        $response->assertJson(['success' => false, 'message' => 'The Salvage Auction is offline.']);
        $this->assertSame(5000, $this->darkMatter($this->currentUserId));
    }

    public function testBidOnNonCurrentAuctionIdIsRejectedBeforeTheService(): void
    {
        $this->enableAuction();
        $auction = $this->makeAuction();
        $this->giveDarkMatter($this->currentUserId, 5000);

        $response = $this->postBid($auction->id + 1000, 600);

        $response->assertOk();
        $response->assertJson(['success' => false, 'message' => 'The auction has closed.']);
        $this->assertSame(5000, $this->darkMatter($this->currentUserId));
        $this->assertSame(0, AuctionBid::count());
        $this->assertSame(0, $auction->fresh()->bid_count);
    }

    public function testBidWhenNoAuctionIsActive(): void
    {
        $this->enableAuction();
        $this->giveDarkMatter($this->currentUserId, 5000);

        $this->postBid(1, 600)->assertOk()->assertJson(['success' => false, 'message' => 'The auction has closed.']);
    }

    public function testSuccessfulBidDebitsAndReturnsState(): void
    {
        Bus::fake();
        $this->enableAuction();
        $auction = $this->makeAuction();
        $this->giveDarkMatter($this->currentUserId, 5000);

        $response = $this->postBid($auction->id, '1,000');

        $response->assertOk();
        $response->assertJson(['success' => true, 'message' => 'Bid placed: 1,000 DM']);
        $response->assertJsonPath('state.auction.current_bid', 1000);
        $response->assertJsonPath('state.auction.you_lead', true);
        $response->assertJsonPath('state.dark_matter', 4000);
        $this->assertSame(4000, $this->darkMatter($this->currentUserId));
        $this->assertSame($this->currentUserId, (int)$auction->fresh()->leader_user_id);

        // No previous leader and no extension: no notifications.
        Bus::assertNotDispatched(NotifyAuctionEvent::class);

        // Recent bids carry usernames only.
        $bids = $response->json('state.recent_bids');
        $this->assertCount(1, $bids);
        $this->assertSame(['name', 'amount', 'placed_at'], array_keys($bids[0]));
    }

    public function testTooLowBidShowsMinimum(): void
    {
        Bus::fake();
        $this->enableAuction();
        $auction = $this->makeAuction();
        $this->giveDarkMatter($this->currentUserId, 5000);

        $response = $this->postBid($auction->id, 100);

        $response->assertOk();
        $response->assertJson(['success' => false, 'message' => 'Bid must be at least 500 DM.']);
        $this->assertSame(5000, $this->darkMatter($this->currentUserId));
    }

    public function testInsufficientDarkMatter(): void
    {
        $this->enableAuction();
        $auction = $this->makeAuction();
        $this->giveDarkMatter($this->currentUserId, 100);

        $response = $this->postBid($auction->id, 600);

        $response->assertOk();
        $response->assertJson(['success' => false, 'message' => 'Not enough dark matter. You need 600 DM.']);
    }

    public function testVacationModeCannotBid(): void
    {
        $this->enableAuction();
        $auction = $this->makeAuction();
        $this->giveDarkMatter($this->currentUserId, 5000);
        User::whereKey($this->currentUserId)->update(['vacation_mode' => true]);

        $response = $this->postBid($auction->id, 600);

        $response->assertOk();
        $response->assertJson(['success' => false, 'message' => 'You are not allowed to bid on this auction.']);
        $this->assertSame(5000, $this->darkMatter($this->currentUserId));
    }

    public function testOutbidDispatchesOutbidNotificationForPreviousLeader(): void
    {
        $this->enableAuction();
        $auction = $this->makeAuction();
        $rival = $this->createUser();
        $this->giveDarkMatter($rival->id, 5000);
        $this->giveDarkMatter($this->currentUserId, 5000);

        // The rival leads first, via the service (they are not the logged-in user).
        $first = resolve(\OGame\Services\AuctionService::class)->placeBid($rival, $auction->id, 600);
        $this->assertTrue($first->accepted);

        Bus::fake();
        $response = $this->postBid($auction->id, 800);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertSame(5000, $this->darkMatter($rival->id), 'previous leader is refunded');
        Bus::assertDispatched(NotifyAuctionEvent::class, fn (NotifyAuctionEvent $job) => $job->event === 'outbid'
            && $job->auctionId === $auction->id
            && $job->context === ['outbid_user_id' => $rival->id, 'amount' => 800]);
        Bus::assertNotDispatched(NotifyAuctionEvent::class, fn (NotifyAuctionEvent $job) => $job->event === 'extended');
    }

    public function testBidInSnipeWindowDispatchesExtendedNotification(): void
    {
        Bus::fake();
        $this->enableAuction();
        $now = (int)now()->timestamp;
        $auction = $this->makeAuction(['ends_at' => $now + 60, 'original_ends_at' => $now + 60]);
        $this->giveDarkMatter($this->currentUserId, 5000);

        $response = $this->postBid($auction->id, 600);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertGreaterThan($now + 60, $auction->fresh()->ends_at);
        $response->assertJsonPath('state.auction.extension_count', 1);
        Bus::assertDispatched(NotifyAuctionEvent::class, fn (NotifyAuctionEvent $job) => $job->event === 'extended' && $job->auctionId === $auction->id);
        Bus::assertNotDispatched(NotifyAuctionEvent::class, fn (NotifyAuctionEvent $job) => $job->event === 'outbid');
    }

    public function testUnexpectedServiceFailureMapsToGenericTryAgain(): void
    {
        Bus::fake();
        $this->enableAuction();
        // Corrupt escrow: a leader with no held bid row makes the service throw a LogicException (invariant I1).
        $rival = $this->createUser();
        $auction = $this->makeAuction(['current_bid' => 1000, 'bid_count' => 1, 'leader_user_id' => $rival->id]);
        $this->giveDarkMatter($this->currentUserId, 5000);

        $response = $this->postBid($auction->id, 5000 - 1000);

        $response->assertOk();
        $response->assertJson(['success' => false, 'message' => 'Something went wrong. Please try again.']);
        $this->assertStringNotContainsString('invariant', strtolower((string)$response->getContent()));
        $this->assertSame(5000, $this->darkMatter($this->currentUserId));
        Bus::assertNotDispatched(NotifyAuctionEvent::class);
    }

    public function testClaimDeliversLotToChosenPlanet(): void
    {
        $this->enableAuction();
        $auction = $this->makeAuction([
            'status' => Auction::STATUS_SETTLED,
            'active_slot' => null,
            'winner_user_id' => $this->currentUserId,
            'final_price' => 600,
            'settled_at' => (int)now()->timestamp,
        ]);
        $before = (int)Planet::whereKey($this->currentPlanetId)->value('metal');

        $response = $this->post('/merchant/auction/claim', [
            'auction_id' => $auction->id,
            'planet_id' => $this->currentPlanetId,
        ]);

        $response->assertRedirect(route('merchant.auction.index'));
        $response->assertSessionHas('auction_success');
        $this->assertSame($before + 1000, (int)Planet::whereKey($this->currentPlanetId)->value('metal'));
        $this->assertNotNull($auction->fresh()->claimed_at);

        // Second claim is idempotent: refused, nothing delivered twice.
        $again = $this->post('/merchant/auction/claim', [
            'auction_id' => $auction->id,
            'planet_id' => $this->currentPlanetId,
        ]);
        $again->assertRedirect(route('merchant.auction.index'));
        $again->assertSessionHas('auction_error', 'This lot has already been claimed.');
        $this->assertSame($before + 1000, (int)Planet::whereKey($this->currentPlanetId)->value('metal'));
    }

    public function testClaimOfSomeoneElsesLotIsRefused(): void
    {
        $this->enableAuction();
        $rival = $this->createUser();
        $auction = $this->makeAuction([
            'status' => Auction::STATUS_SETTLED,
            'active_slot' => null,
            'winner_user_id' => $rival->id,
            'final_price' => 600,
            'settled_at' => (int)now()->timestamp,
        ]);

        $response = $this->post('/merchant/auction/claim', [
            'auction_id' => $auction->id,
            'planet_id' => $this->currentPlanetId,
        ]);

        $response->assertRedirect(route('merchant.auction.index'));
        $response->assertSessionHas('auction_error', 'That lot is not yours.');
        $this->assertNull($auction->fresh()->claimed_at);
    }

    public function testClaimValidationAndDisabled(): void
    {
        $this->enableAuction();
        $this->post('/merchant/auction/claim', ['auction_id' => 'x'])
            ->assertRedirect(route('merchant.auction.index'))
            ->assertSessionHas('auction_error');

        $this->enableAuction(false);
        $this->post('/merchant/auction/claim', ['auction_id' => 1, 'planet_id' => $this->currentPlanetId])
            ->assertSessionHas('auction_error', 'The Salvage Auction is offline.');
    }

    public function testStateListsUnclaimedWinnings(): void
    {
        $this->enableAuction();
        $auction = $this->makeAuction([
            'status' => Auction::STATUS_SETTLED,
            'active_slot' => null,
            'winner_user_id' => $this->currentUserId,
            'final_price' => 600,
            'settled_at' => (int)now()->timestamp,
        ]);

        $response = $this->getJson('/ajax/merchant/auction/state');

        $response->assertOk();
        $response->assertJsonPath('unclaimed.0.auction_id', $auction->id);
        $response->assertJsonPath('unclaimed.0.lot_key', 'crate_metal');
    }

    public function testStateSettlesDueAuction(): void
    {
        $this->enableAuction();
        $now = (int)now()->timestamp;
        $auction = $this->makeAuction(['ends_at' => $now - 10, 'original_ends_at' => $now - 10]);

        $response = $this->getJson('/ajax/merchant/auction/state');

        $response->assertOk();
        $response->assertJsonPath('auction', null);
        $this->assertSame(Auction::STATUS_UNSOLD, $auction->fresh()->status);
    }
}
