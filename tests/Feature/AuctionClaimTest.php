<?php

namespace Tests\Feature;

use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Auction;
use OGame\Models\DarkMatterTransaction;
use OGame\Models\Planet;
use OGame\Models\User;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use RuntimeException;
use Tests\IsolatedAccountTestCase;
use Tests\Traits\ManagesAuctions;

/**
 * Armada Salvage Auction: claiming a won lot (docs/AUCTION-SPEC.md sections 1.2, 4.1 I5, 4.5 and 8).
 */
class AuctionClaimTest extends IsolatedAccountTestCase
{
    use ManagesAuctions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuctions();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function settledAuctionWonBy(int $winnerUserId, array $payload, int $price = 600): Auction
    {
        $auction = $this->makeAuction([
            'lot_payload' => $payload,
            'starting_bid' => $price,
        ]);
        $this->setDarkMatter($winnerUserId, 10000);
        $this->assertTrue($this->auctions->placeBid(User::findOrFail($winnerUserId), $auction->id, $price)->accepted);
        $this->travelToTimestamp($auction->ends_at);
        $this->assertTrue($this->auctions->settleIfDue($auction->id));

        return $auction->fresh();
    }

    private function player(int $userId): PlayerService
    {
        return resolve(PlayerServiceFactory::class)->make($userId, true);
    }

    /**
     * @return array<string, int>
     */
    private function stock(int $planetId): array
    {
        $planet = Planet::findOrFail($planetId);

        return [
            'metal' => (int)$planet->metal,
            'crystal' => (int)$planet->crystal,
            'deuterium' => (int)$planet->deuterium,
            'small_cargo' => (int)$planet->small_cargo,
            'light_fighter' => (int)$planet->light_fighter,
        ];
    }

    public function testWinnerClaimsResourcesAndShipsOntoChosenPlanet(): void
    {
        $payload = [
            'resources' => ['metal' => 1500, 'crystal' => 700, 'deuterium' => 250],
            'units' => ['small_cargo' => 3, 'light_fighter' => 12],
        ];
        $auction = $this->settledAuctionWonBy($this->currentUserId, $payload);
        $secondPlanetId = $this->secondPlanetService?->getPlanetId();
        $this->assertNotNull($secondPlanetId);
        $chosenBefore = $this->stock($secondPlanetId);
        $otherBefore = $this->stock($this->currentPlanetId);
        $balance = $this->balance($this->currentUserId);
        $ledgerRows = DarkMatterTransaction::where('user_id', $this->currentUserId)->count();

        $this->assertSame([$auction->id], $this->auctions->unclaimedFor($this->currentUserId)->pluck('id')->all());

        $result = $this->auctions->claim($this->player($this->currentUserId), $auction->id, $secondPlanetId);

        $this->assertSame('', $result);
        $chosenAfter = $this->stock($secondPlanetId);
        $this->assertSame($chosenBefore['metal'] + 1500, $chosenAfter['metal']);
        $this->assertSame($chosenBefore['crystal'] + 700, $chosenAfter['crystal']);
        $this->assertSame($chosenBefore['deuterium'] + 250, $chosenAfter['deuterium']);
        $this->assertSame($chosenBefore['small_cargo'] + 3, $chosenAfter['small_cargo']);
        $this->assertSame($chosenBefore['light_fighter'] + 12, $chosenAfter['light_fighter']);
        // The other planet is untouched.
        $this->assertSame($otherBefore, $this->stock($this->currentPlanetId));

        $auction->refresh();
        $this->assertNotNull($auction->claimed_at);
        $this->assertSame($secondPlanetId, $auction->claimed_planet_id);
        $this->assertCount(0, $this->auctions->unclaimedFor($this->currentUserId));

        // A claim moves no dark matter.
        $this->assertSame($balance, $this->balance($this->currentUserId));
        $this->assertSame($ledgerRows, DarkMatterTransaction::where('user_id', $this->currentUserId)->count());
        $this->assertAuctionHealthy($auction->id);
    }

    public function testSecondClaimIsRejectedAndChangesNothing(): void
    {
        $payload = ['resources' => ['metal' => 1500, 'crystal' => 0, 'deuterium' => 0], 'units' => ['small_cargo' => 3]];
        $auction = $this->settledAuctionWonBy($this->currentUserId, $payload);
        $player = $this->player($this->currentUserId);
        $this->assertSame('', $this->auctions->claim($player, $auction->id, $this->currentPlanetId));
        $claimedAt = $auction->fresh()->claimed_at;
        $stockAfterFirst = $this->stock($this->currentPlanetId);

        $secondPlanetId = $this->secondPlanetService?->getPlanetId();
        $this->assertNotNull($secondPlanetId);
        $secondBefore = $this->stock($secondPlanetId);
        $this->travelToTimestamp($this->nowTs() + 600);

        $this->assertSame('already_claimed', $this->auctions->claim($player, $auction->id, $this->currentPlanetId));
        $this->assertSame('already_claimed', $this->auctions->claim($player, $auction->id, $secondPlanetId));

        $this->assertSame($stockAfterFirst, $this->stock($this->currentPlanetId));
        $this->assertSame($secondBefore, $this->stock($secondPlanetId));
        $auction->refresh();
        $this->assertSame($claimedAt, $auction->claimed_at);
        $this->assertSame($this->currentPlanetId, $auction->claimed_planet_id);
        $this->assertAuctionHealthy($auction->id);
    }

    public function testOnlyTheWinnerMayClaim(): void
    {
        $payload = ['resources' => ['metal' => 1500, 'crystal' => 0, 'deuterium' => 0], 'units' => []];
        $auction = $this->settledAuctionWonBy($this->currentUserId, $payload);

        $foreignPlanet = $this->createForeignPlanet();
        $foreignPlanetId = $foreignPlanet->getPlanetId();
        $foreignUserId = (int)Planet::findOrFail($foreignPlanetId)->user_id;
        $foreignBefore = $this->stock($foreignPlanetId);

        $this->assertSame('not_yours', $this->auctions->claim($this->player($foreignUserId), $auction->id, $foreignPlanetId));

        $this->assertSame($foreignBefore, $this->stock($foreignPlanetId));
        $this->assertNull($auction->fresh()->claimed_at);
        $this->assertCount(1, $this->auctions->unclaimedFor($this->currentUserId));
        $this->assertCount(0, $this->auctions->unclaimedFor($foreignUserId));
    }

    public function testClaimToAnotherPlayersPlanetIsRejectedAndAuctionStaysClaimable(): void
    {
        $payload = ['resources' => ['metal' => 1500, 'crystal' => 0, 'deuterium' => 0], 'units' => []];
        $auction = $this->settledAuctionWonBy($this->currentUserId, $payload);
        $foreignPlanetId = $this->createForeignPlanet()->getPlanetId();
        $foreignBefore = $this->stock($foreignPlanetId);
        $ownBefore = $this->stock($this->currentPlanetId);

        $this->assertSame('bad_planet', $this->auctions->claim($this->player($this->currentUserId), $auction->id, $foreignPlanetId));

        $this->assertSame($foreignBefore, $this->stock($foreignPlanetId));
        $this->assertSame($ownBefore, $this->stock($this->currentPlanetId));
        $this->assertNull($auction->fresh()->claimed_at);
        $this->assertNull($auction->fresh()->claimed_planet_id);

        // A valid claim afterwards still works.
        $this->assertSame('', $this->auctions->claim($this->player($this->currentUserId), $auction->id, $this->currentPlanetId));
        $this->assertSame($ownBefore['metal'] + 1500, $this->stock($this->currentPlanetId)['metal']);
    }

    public function testClaimToMissingOrDestroyedPlanetIsRejected(): void
    {
        $payload = ['resources' => ['metal' => 1500, 'crystal' => 0, 'deuterium' => 0], 'units' => []];
        $auction = $this->settledAuctionWonBy($this->currentUserId, $payload);
        $player = $this->player($this->currentUserId);

        $this->assertSame('bad_planet', $this->auctions->claim($player, $auction->id, 999999999));

        $secondPlanetId = $this->secondPlanetService?->getPlanetId();
        $this->assertNotNull($secondPlanetId);
        Planet::whereKey($secondPlanetId)->update(['destroyed' => 1]);
        $this->assertSame('bad_planet', $this->auctions->claim($player, $auction->id, $secondPlanetId));
        $this->assertNull($auction->fresh()->claimed_at);
    }

    public function testClaimIsRejectedUnlessSettled(): void
    {
        $player = $this->player($this->currentUserId);

        $active = $this->makeAuction();
        $this->assertSame('not_yours', $this->auctions->claim($player, $active->id, $this->currentPlanetId));

        $unsold = $this->makeAuction(['status' => Auction::STATUS_UNSOLD, 'active_slot' => null]);
        $this->assertSame('not_yours', $this->auctions->claim($player, $unsold->id, $this->currentPlanetId));

        $this->assertSame('not_yours', $this->auctions->claim($player, 999999999, $this->currentPlanetId));
    }

    public function testCancelledAuctionCannotBeClaimed(): void
    {
        $auction = $this->makeAuction();
        $this->setDarkMatter($this->currentUserId, 5000);
        $this->auctions->placeBid(User::findOrFail($this->currentUserId), $auction->id, 600);
        $this->assertTrue($this->auctions->cancel($auction->id, 'test'));

        $this->assertSame('not_yours', $this->auctions->claim($this->player($this->currentUserId), $auction->id, $this->currentPlanetId));
    }

    public function testDeliveryBypassesStorageCapacity(): void
    {
        $storageCap = (int)Planet::findOrFail($this->currentPlanetId)->metal_max;
        $huge = 50000000;
        $this->assertGreaterThan($storageCap, $huge);
        $payload = ['resources' => ['metal' => $huge, 'crystal' => 0, 'deuterium' => 0], 'units' => []];
        $auction = $this->settledAuctionWonBy($this->currentUserId, $payload);
        $before = $this->stock($this->currentPlanetId)['metal'];

        $this->assertSame('', $this->auctions->claim($this->player($this->currentUserId), $auction->id, $this->currentPlanetId));

        $after = $this->stock($this->currentPlanetId)['metal'];
        $this->assertSame($before + $huge, $after);
        $this->assertGreaterThan($storageCap, $after);
    }

    public function testResourceOnlyAndUnitOnlyLotsDeliverOnlyTheirContents(): void
    {
        $before = $this->stock($this->currentPlanetId);

        $resourceLot = $this->settledAuctionWonBy($this->currentUserId, [
            'resources' => ['metal' => 0, 'crystal' => 900, 'deuterium' => 0],
            'units' => [],
        ]);
        $this->assertSame('', $this->auctions->claim($this->player($this->currentUserId), $resourceLot->id, $this->currentPlanetId));
        $afterResources = $this->stock($this->currentPlanetId);
        $this->assertSame($before['crystal'] + 900, $afterResources['crystal']);
        $this->assertSame($before['metal'], $afterResources['metal']);
        $this->assertSame($before['small_cargo'], $afterResources['small_cargo']);

        $unitLot = $this->settledAuctionWonBy($this->currentUserId, [
            'resources' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0],
            'units' => ['small_cargo' => 4],
        ]);
        $this->assertSame('', $this->auctions->claim($this->player($this->currentUserId), $unitLot->id, $this->currentPlanetId));
        $afterUnits = $this->stock($this->currentPlanetId);
        $this->assertSame($afterResources['small_cargo'] + 4, $afterUnits['small_cargo']);
        $this->assertSame($afterResources['crystal'], $afterUnits['crystal']);
    }

    public function testEmptyLotClaimsCleanly(): void
    {
        $auction = $this->settledAuctionWonBy($this->currentUserId, [
            'resources' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0],
            'units' => [],
        ]);
        $before = $this->stock($this->currentPlanetId);

        $this->assertSame('', $this->auctions->claim($this->player($this->currentUserId), $auction->id, $this->currentPlanetId));

        $this->assertSame($before, $this->stock($this->currentPlanetId));
        $this->assertNotNull($auction->fresh()->claimed_at);
    }

    public function testNonShipUnitInPayloadRollsTheWholeClaimBack(): void
    {
        $auction = $this->settledAuctionWonBy($this->currentUserId, [
            'resources' => ['metal' => 1000, 'crystal' => 0, 'deuterium' => 0],
            'units' => ['small_cargo' => 2, 'metal_mine' => 5],
        ]);
        $before = $this->stock($this->currentPlanetId);

        try {
            $this->auctions->claim($this->player($this->currentUserId), $auction->id, $this->currentPlanetId);
            $this->fail('A non-ship unit key must abort the claim.');
        } catch (RuntimeException) {
            // Expected: the whitelist rejects anything that is not a ship.
        }

        $this->assertSame($before, $this->stock($this->currentPlanetId));
        $this->assertNull($auction->fresh()->claimed_at);
        $this->assertNull($auction->fresh()->claimed_planet_id);
    }

    public function testBotWinnerIsAutoClaimedOntoItsHomeworldAtSettlement(): void
    {
        resolve(SettingsService::class)->set('auction_bots_enabled', 1);
        $botPlanetId = $this->createForeignPlanet()->getPlanetId();
        $botId = (int)Planet::findOrFail($botPlanetId)->user_id;
        User::whereKey($botId)->update(['is_bot' => true]);
        $homeworldId = (int)Planet::where('user_id', $botId)->where('planet_type', 1)->orderBy('id')->value('id');
        $this->assertGreaterThan(0, $homeworldId, 'The bot needs a homeworld (planet_type 1) for auto-claim.');
        $before = $this->stock($homeworldId);

        $auction = $this->settledAuctionWonBy($botId, [
            'resources' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 800],
            'units' => ['light_fighter' => 5],
        ]);

        $auction->refresh();
        $after = $this->stock($homeworldId);
        $this->assertNotNull($auction->claimed_at);
        $this->assertSame($homeworldId, $auction->claimed_planet_id);
        $this->assertSame($before['deuterium'] + 800, $after['deuterium']);
        $this->assertSame($before['light_fighter'] + 5, $after['light_fighter']);
        $this->assertSame([], $this->auctions->checkInvariants($auction->id));
    }

    public function testHumanWinnerIsNotAutoClaimed(): void
    {
        $auction = $this->settledAuctionWonBy($this->currentUserId, [
            'resources' => ['metal' => 500, 'crystal' => 0, 'deuterium' => 0],
            'units' => [],
        ]);

        $this->assertNull($auction->claimed_at);
    }
}
