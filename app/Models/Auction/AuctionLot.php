<?php

namespace OGame\Models\Auction;

use InvalidArgumentException;
use OGame\Models\Resources;
use OGame\Services\ObjectService;

/**
 * A resolved auction lot: the lot key plus its frozen contents (resources and ships).
 */
final class AuctionLot
{
    /**
     * @param string $key Catalogue lot_key.
     * @param Resources $resources Resource contents (metal, crystal, deuterium).
     * @param array<string, int> $units Ship machine_name => count.
     */
    public function __construct(
        public readonly string $key,
        public readonly Resources $resources,
        public readonly array $units,
    ) {
    }

    /**
     * Rebuild a lot from a frozen auctions.lot_payload.
     *
     * @param string $key
     * @param array<string, mixed> $payload
     * @return self
     */
    public static function fromPayload(string $key, array $payload): self
    {
        $resources = $payload['resources'] ?? [];
        $units = $payload['units'] ?? [];
        if (!is_array($resources) || !is_array($units)) {
            throw new InvalidArgumentException('Invalid auction lot payload for ' . $key);
        }

        $unitCounts = [];
        foreach ($units as $machineName => $amount) {
            $amount = (int)$amount;
            if ($amount > 0) {
                $unitCounts[(string)$machineName] = $amount;
            }
        }

        return new self(
            $key,
            new Resources(
                (int)($resources['metal'] ?? 0),
                (int)($resources['crystal'] ?? 0),
                (int)($resources['deuterium'] ?? 0),
            ),
            $unitCounts,
        );
    }

    /**
     * Serialise to the frozen lot_payload shape.
     *
     * @return array{resources: array{metal: int, crystal: int, deuterium: int}, units: array<string, int>}
     */
    public function toPayload(): array
    {
        return [
            'resources' => [
                'metal' => $this->resources->metal->getRounded(),
                'crystal' => $this->resources->crystal->getRounded(),
                'deuterium' => $this->resources->deuterium->getRounded(),
            ],
            'units' => $this->units,
        ];
    }

    /**
     * Value of the lot in metal: metal + 1.5 * crystal + 3 * deuterium, plus the same
     * weighting applied to the build cost of every ship. Used by bots and Discord copy.
     *
     * @return int
     */
    public function metalEquivalent(): int
    {
        $total = self::weigh(
            $this->resources->metal->getRounded(),
            $this->resources->crystal->getRounded(),
            $this->resources->deuterium->getRounded(),
        );

        foreach ($this->units as $machineName => $amount) {
            $cost = ObjectService::getShipObjectByMachineName($machineName)->price->resources;
            $total += $amount * self::weigh(
                $cost->metal->getRounded(),
                $cost->crystal->getRounded(),
                $cost->deuterium->getRounded(),
            );
        }

        return (int)round($total);
    }

    private static function weigh(int $metal, int $crystal, int $deuterium): float
    {
        return $metal + 1.5 * $crystal + 3 * $deuterium;
    }
}
