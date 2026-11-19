<?php

namespace OGame\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OGame\GameConstants\AuctionLots;
use OGame\Models\Auction\AuctionLot;
use OGame\Models\Resources;

/**
 * Draws the daily auction lot from the AuctionLots catalogue.
 */
class AuctionLotCatalog
{
    public function __construct(private SettingsService $settings)
    {
    }

    /**
     * Seeded, weighted draw for the given auction date (Y-m-d, UTC). The same date always
     * yields the same lot. Avoids $previousKey and draws only grand_* lots on Sundays.
     * Contents are scaled by the economy speed setting.
     *
     * @param string $auctionDate
     * @param string|null $previousKey
     * @return AuctionLot
     */
    public function draw(string $auctionDate, string|null $previousKey): AuctionLot
    {
        $pool = $this->isSunday($auctionDate) ? AuctionLots::GRAND_LOTS : AuctionLots::LOTS;

        $weights = [];
        foreach ($pool as $key => $definition) {
            if ($key !== $previousKey) {
                $weights[$key] = $definition['weight'];
            }
        }
        if ($weights === []) {
            // Only possible if the pool has a single lot that equals $previousKey.
            foreach ($pool as $key => $definition) {
                $weights[$key] = $definition['weight'];
            }
        }

        $roll = crc32($auctionDate) % array_sum($weights);
        $cumulative = 0;
        $selected = array_key_first($weights);
        foreach ($weights as $key => $weight) {
            $cumulative += $weight;
            if ($roll < $cumulative) {
                $selected = $key;
                break;
            }
        }

        return $this->resolve((string)$selected);
    }

    /**
     * Starting bid in dark matter for a catalogue lot.
     *
     * @param string $lotKey
     * @return int
     */
    public function startingBid(string $lotKey): int
    {
        return $this->definition($lotKey)['starting_bid'];
    }

    /**
     * Resolve a catalogue lot with contents scaled by the economy speed.
     *
     * @param string $lotKey
     * @return AuctionLot
     */
    private function resolve(string $lotKey): AuctionLot
    {
        $definition = $this->definition($lotKey);
        $speed = max(1, $this->settings->economySpeed());

        $units = [];
        foreach ($definition['units'] as $machineName => $amount) {
            $units[$machineName] = $amount * $speed;
        }

        return new AuctionLot(
            $lotKey,
            new Resources(
                $definition['resources']['metal'] * $speed,
                $definition['resources']['crystal'] * $speed,
                $definition['resources']['deuterium'] * $speed,
            ),
            $units,
        );
    }

    /**
     * @param string $lotKey
     * @return array{resources: array{metal: int, crystal: int, deuterium: int}, units: array<string, int>, starting_bid: int, weight: int}
     */
    private function definition(string $lotKey): array
    {
        $all = AuctionLots::all();
        if (!isset($all[$lotKey])) {
            throw new InvalidArgumentException('Unknown auction lot key: ' . $lotKey);
        }

        return $all[$lotKey];
    }

    private function isSunday(string $auctionDate): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $auctionDate, new DateTimeZone('UTC'));
        if ($date === false) {
            throw new InvalidArgumentException('Invalid auction date (expected Y-m-d): ' . $auctionDate);
        }

        return $date->format('N') === '7';
    }
}
