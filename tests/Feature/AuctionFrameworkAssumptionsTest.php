<?php

namespace Tests\Feature;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use OGame\Models\Auction;
use OGame\Models\Planet;
use Tests\IsolatedAccountTestCase;
use Tests\Traits\ManagesAuctions;

/**
 * Pins the framework behaviour AuctionService relies on: Planet::whereKey()->incrementEach() for atomic delivery
 * and UniqueConstraintViolationException for the idempotent daily open.
 */
class AuctionFrameworkAssumptionsTest extends IsolatedAccountTestCase
{
    use ManagesAuctions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuctions();
    }

    public function testIncrementEachAddsToSeveralColumnsAtomically(): void
    {
        $otherPlanetId = $this->secondPlanetService?->getPlanetId();
        $this->assertNotNull($otherPlanetId);
        Planet::whereKey($this->currentPlanetId)->update(['metal' => 100, 'crystal' => 200, 'small_cargo' => 1]);
        $otherMetal = (int)Planet::findOrFail($otherPlanetId)->metal;

        $affected = Planet::whereKey($this->currentPlanetId)->incrementEach([
            'metal' => 1500,
            'crystal' => 25,
            'small_cargo' => 3,
        ]);

        $this->assertSame(1, $affected);
        $planet = Planet::findOrFail($this->currentPlanetId);
        $this->assertSame(1600, (int)$planet->metal);
        $this->assertSame(225, (int)$planet->crystal);
        $this->assertSame(4, (int)$planet->small_cargo);
        // Only the targeted row changes.
        $this->assertSame($otherMetal, (int)Planet::findOrFail($otherPlanetId)->metal);
    }

    public function testIncrementEachDoesNotOverwriteAConcurrentChangeMadeAfterTheModelWasLoaded(): void
    {
        Planet::whereKey($this->currentPlanetId)->update(['metal' => 100]);
        $stale = Planet::findOrFail($this->currentPlanetId);

        // Concurrent change after $stale was loaded.
        Planet::whereKey($this->currentPlanetId)->update(['metal' => 1000]);
        Planet::whereKey($this->currentPlanetId)->incrementEach(['metal' => 50]);

        $this->assertSame(100, (int)$stale->metal);
        $this->assertSame(1050, (int)Planet::findOrFail($this->currentPlanetId)->metal);
    }

    public function testIncrementEachSupportsAShipColumnNamedByMachineName(): void
    {
        $before = (int)Planet::findOrFail($this->currentPlanetId)->light_fighter;

        Planet::whereKey($this->currentPlanetId)->incrementEach(['light_fighter' => 7]);

        $this->assertSame($before + 7, (int)Planet::findOrFail($this->currentPlanetId)->light_fighter);
    }

    public function testDuplicateAuctionDateThrowsUniqueConstraintViolationException(): void
    {
        $this->makeAuction(['auction_date' => '2030-05-05', 'status' => Auction::STATUS_SETTLED, 'active_slot' => null]);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->makeAuction(['auction_date' => '2030-05-05', 'status' => Auction::STATUS_SETTLED, 'active_slot' => null]);
    }

    public function testSecondActiveSlotThrowsUniqueConstraintViolationException(): void
    {
        $this->makeAuction(['auction_date' => '2030-05-06']);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->makeAuction(['auction_date' => '2030-05-07']);
    }

    public function testUniqueViolationInsideASavepointLeavesTheOuterTransactionUsable(): void
    {
        $this->makeAuction(['auction_date' => '2030-05-06']);

        $caught = false;
        DB::transaction(function () use (&$caught): void {
            try {
                $this->makeAuction(['auction_date' => '2030-05-07']);
            } catch (UniqueConstraintViolationException) {
                $caught = true;
            }
            // The transaction is still usable after catching the violation (InnoDB statement level rollback).
            $this->assertSame(1, Auction::count());
        });

        $this->assertTrue($caught);
    }
}
