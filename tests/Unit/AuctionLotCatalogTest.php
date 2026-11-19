<?php

namespace Tests\Unit;

use InvalidArgumentException;
use OGame\GameConstants\AuctionLots;
use OGame\Models\Auction\AuctionLot;
use OGame\Services\AuctionLotCatalog;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\UnitTestCase;

class AuctionLotCatalogTest extends UnitTestCase
{
    private function catalog(int $economySpeed = 1): AuctionLotCatalog
    {
        $settings = new class ($economySpeed) extends SettingsService {
            public function __construct(private int $speed)
            {
                parent::__construct();
            }

            public function economySpeed(): int
            {
                return $this->speed;
            }
        };

        return new AuctionLotCatalog($settings);
    }

    /**
     * Returns the Y-m-d date $days after 2026-11-02 (a Monday).
     */
    private function date(int $days): string
    {
        return date('Y-m-d', (int)strtotime('2026-11-02 +' . $days . ' days UTC'));
    }

    private function isSunday(string $date): bool
    {
        return date('N', (int)strtotime($date . ' UTC')) === '7';
    }

    /**
     * Lot keys double as art filenames, so check them against the art list in the spec.
     */
    public function testLotKeysMatchArtFilenames(): void
    {
        $expected = [
            'crate_metal', 'crate_crystal', 'crate_deuterium', 'crate_convoy',
            'hulls_fighters', 'hulls_cruisers', 'hulls_cargo', 'hulls_recyclers',
            'hulls_battleships', 'hulls_pathfinders', 'hoard_pirate',
            'grand_deathstar', 'grand_flotilla',
        ];
        $actual = array_keys(AuctionLots::all());
        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual);
    }

    public function testEveryLotIsWellFormed(): void
    {
        $catalog = $this->catalog();
        foreach (AuctionLots::all() as $key => $lot) {
            $this->assertGreaterThan(0, $lot['starting_bid'], $key . ' needs a positive starting bid');
            $this->assertGreaterThan(0, $lot['weight'], $key . ' needs a positive weight');
            $contents = $lot['resources']['metal'] + $lot['resources']['crystal'] + $lot['resources']['deuterium'] + array_sum($lot['units']);
            $this->assertGreaterThan(0, $contents, $key . ' must contain something');
            foreach ($lot['units'] as $machineName => $amount) {
                $this->assertGreaterThan(0, $amount);
                // Throws if the machine name is not a real ship.
                $this->assertSame($machineName, ObjectService::getShipObjectByMachineName($machineName)->machine_name);
            }
            $this->assertSame($lot['starting_bid'], $catalog->startingBid($key));
        }
    }

    public function testStartingBidRejectsUnknownKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->catalog()->startingBid('nope');
    }

    public function testDrawIsDeterministic(): void
    {
        $catalog = $this->catalog();
        $first = $catalog->draw($this->date(16), null);
        $second = $catalog->draw($this->date(16), null);

        $this->assertSame($first->key, $second->key);
        $this->assertSame($first->toPayload(), $second->toPayload());
    }

    public function testDrawNeverRepeatsPreviousKey(): void
    {
        $catalog = $this->catalog();
        $previous = null;
        for ($day = 0; $day < 84; $day++) {
            $date = $this->date($day);
            if ($this->isSunday($date)) {
                $previous = null;
                continue;
            }
            $lot = $catalog->draw($date, $previous);
            $this->assertArrayHasKey($lot->key, AuctionLots::LOTS);
            $this->assertNotSame($previous, $lot->key, 'Repeated lot on ' . $date);
            $previous = $lot->key;
        }
    }

    public function testDrawAvoidsAnyGivenPreviousKey(): void
    {
        $catalog = $this->catalog();
        foreach (array_keys(AuctionLots::LOTS) as $previous) {
            for ($day = 0; $day < 30; $day++) {
                $date = $this->date($day);
                if ($this->isSunday($date)) {
                    continue;
                }
                $this->assertNotSame($previous, $catalog->draw($date, $previous)->key);
            }
        }
    }

    public function testSundayUsesOnlyGrandLots(): void
    {
        $catalog = $this->catalog();
        for ($week = 0; $week < 20; $week++) {
            $date = $this->date(6 + $week * 7);
            $this->assertTrue($this->isSunday($date));
            $this->assertArrayHasKey($catalog->draw($date, null)->key, AuctionLots::GRAND_LOTS);
        }
    }

    public function testWeekdaysNeverDrawGrandLots(): void
    {
        $catalog = $this->catalog();
        for ($day = 0; $day < 60; $day++) {
            $date = $this->date($day);
            if ($this->isSunday($date)) {
                continue;
            }
            $this->assertArrayNotHasKey($catalog->draw($date, null)->key, AuctionLots::GRAND_LOTS);
        }
    }

    public function testDrawRejectsMalformedDate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->catalog()->draw('not-a-date', null);
    }

    public function testDrawScalesByEconomySpeed(): void
    {
        $base = $this->catalog(1)->draw($this->date(16), null)->toPayload();
        $scaled = $this->catalog(5)->draw($this->date(16), null)->toPayload();

        foreach ($base['resources'] as $resource => $amount) {
            $this->assertSame($amount * 5, $scaled['resources'][$resource]);
        }
        foreach ($base['units'] as $machineName => $amount) {
            $this->assertSame($amount * 5, $scaled['units'][$machineName]);
        }
    }

    public function testPayloadRoundTrip(): void
    {
        $lot = $this->catalog()->draw($this->date(16), null);
        $rebuilt = AuctionLot::fromPayload($lot->key, $lot->toPayload());

        $this->assertSame($lot->toPayload(), $rebuilt->toPayload());
        $this->assertSame($lot->metalEquivalent(), $rebuilt->metalEquivalent());
    }

    public function testMetalEquivalent(): void
    {
        $resources = AuctionLot::fromPayload('x', [
            'resources' => ['metal' => 1000, 'crystal' => 200, 'deuterium' => 100],
            'units' => [],
        ]);
        // 1000 + 1.5 * 200 + 3 * 100
        $this->assertSame(1600, $resources->metalEquivalent());

        $ships = AuctionLot::fromPayload('y', ['resources' => [], 'units' => ['cruiser' => 2]]);
        $cost = ObjectService::getShipObjectByMachineName('cruiser')->price->resources;
        $perShip = $cost->metal->getRounded() + 1.5 * $cost->crystal->getRounded() + 3 * $cost->deuterium->getRounded();
        $this->assertSame((int)round(2 * $perShip), $ships->metalEquivalent());
    }
}
