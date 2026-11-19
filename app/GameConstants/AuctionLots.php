<?php

namespace OGame\GameConstants;

/**
 * Lot catalogue for the daily Armada Salvage Auction (docs/AUCTION-SPEC.md section 1.3).
 *
 * Each lot is keyed by its lot_key, which must equal the art filename
 * public/img/iron-armada/auction/{lot_key}.webp. Resource and unit amounts are the base
 * amounts at economy speed 1; AuctionLotCatalog scales them when a lot is drawn and the
 * result is frozen into auctions.lot_payload.
 *
 * Unit keys must be valid ship machine names (ObjectService::getShipObjectByMachineName).
 */
final class AuctionLots
{
    /**
     * Regular lots, drawn Monday to Saturday (UTC).
     *
     * @var array<string, array{resources: array{metal: int, crystal: int, deuterium: int}, units: array<string, int>, starting_bid: int, weight: int}>
     */
    public const array LOTS = [
        'crate_metal' => [
            'resources' => ['metal' => 1500000, 'crystal' => 0, 'deuterium' => 0],
            'units' => [],
            'starting_bid' => 600,
            'weight' => 14,
        ],
        'crate_crystal' => [
            'resources' => ['metal' => 0, 'crystal' => 900000, 'deuterium' => 0],
            'units' => [],
            'starting_bid' => 600,
            'weight' => 14,
        ],
        'crate_deuterium' => [
            'resources' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 500000],
            'units' => [],
            'starting_bid' => 600,
            'weight' => 14,
        ],
        'crate_convoy' => [
            'resources' => ['metal' => 800000, 'crystal' => 500000, 'deuterium' => 250000],
            'units' => [],
            'starting_bid' => 900,
            'weight' => 14,
        ],
        'hulls_fighters' => [
            'resources' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0],
            'units' => ['light_fighter' => 250],
            'starting_bid' => 700,
            'weight' => 10,
        ],
        'hulls_cruisers' => [
            'resources' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0],
            'units' => ['cruiser' => 40],
            'starting_bid' => 800,
            'weight' => 10,
        ],
        'hulls_cargo' => [
            'resources' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0],
            'units' => ['large_cargo' => 60],
            'starting_bid' => 600,
            'weight' => 8,
        ],
        'hulls_recyclers' => [
            'resources' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0],
            'units' => ['recycler' => 30],
            'starting_bid' => 600,
            'weight' => 6,
        ],
        'hulls_battleships' => [
            'resources' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0],
            'units' => ['battle_ship' => 15],
            'starting_bid' => 1000,
            'weight' => 5,
        ],
        'hulls_pathfinders' => [
            'resources' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0],
            'units' => ['pathfinder' => 20],
            'starting_bid' => 800,
            'weight' => 3,
        ],
        'hoard_pirate' => [
            'resources' => ['metal' => 400000, 'crystal' => 400000, 'deuterium' => 400000],
            'units' => ['bomber' => 10],
            'starting_bid' => 1200,
            'weight' => 2,
        ],
    ];

    /**
     * Sunday Grand Lots. On Sundays (UTC) only these are drawn.
     *
     * @var array<string, array{resources: array{metal: int, crystal: int, deuterium: int}, units: array<string, int>, starting_bid: int, weight: int}>
     */
    public const array GRAND_LOTS = [
        'grand_deathstar' => [
            'resources' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0],
            'units' => ['deathstar' => 1],
            'starting_bid' => 3000,
            'weight' => 1,
        ],
        'grand_flotilla' => [
            'resources' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0],
            'units' => ['battlecruiser' => 5, 'destroyer' => 5],
            'starting_bid' => 2500,
            'weight' => 1,
        ],
    ];

    /**
     * All lots (regular and grand) keyed by lot_key.
     *
     * @return array<string, array{resources: array{metal: int, crystal: int, deuterium: int}, units: array<string, int>, starting_bid: int, weight: int}>
     */
    public static function all(): array
    {
        return [...self::LOTS, ...self::GRAND_LOTS];
    }
}
