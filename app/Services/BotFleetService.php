<?php

namespace OGame\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Models\User;
use Throwable;

/**
 * Fleet behaviour of regular (non-pirate) bots: colonising free planet slots and raiding.
 *
 * Both actions go through FleetMissionService like a human player's fleet, so travel time,
 * fuel, battles, loot and reports all use the normal game code.
 *
 * Raid rules (kept conservative on purpose):
 *  - only "raider" personality bots raid;
 *  - targets are other bots (never pirates) in the same galaxy, and only when the bot's attack fleet
 *    is at least twice as strong as the target's ships + defence;
 *  - raiding real players is OFF by default (setting "bots_raid_players"); when enabled the same
 *    protection rules as pirate raids apply (minimum points, account age, cooldown, no admins);
 *  - one raid at a time per bot and a per-target cooldown.
 */
class BotFleetService
{
    /** Ships that take part in a raid. */
    private const ATTACK_SHIPS = ['light_fighter', 'heavy_fighter', 'cruiser', 'battle_ship', 'battlecruiser', 'bomber', 'destroyer'];

    /** Own attack fleet must be worth at least this many times the target's military + defence value. */
    private const MIN_STRENGTH_RATIO = 2.0;

    private const TARGET_COOLDOWN_SECONDS = 6 * 3600;

    /** How far (in systems) a bot looks for a free colony slot around its home system. */
    private const COLONY_SEARCH_RADIUS = 12;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly PlayerServiceFactory $playerServiceFactory,
        private readonly PlanetServiceFactory $planetServiceFactory,
        private readonly UnitQueueService $unitQueueService,
        private readonly PirateService $pirateService,
    ) {
    }

    public function coloniseEnabled(): bool
    {
        return $this->settings->get('bots_colonise_enabled', 1) === '1';
    }

    public function raidEnabled(): bool
    {
        return $this->settings->get('bots_raid_enabled', 1) === '1';
    }

    public function raidPlayersEnabled(): bool
    {
        return $this->settings->get('bots_raid_players', 0) === '1';
    }

    /**
     * One fleet decision for a bot. Safe to call every turn: it does at most one thing.
     */
    public function takeTurn(User $bot, PlayerService $player, string $personality): void
    {
        try {
            $home = null;
            foreach ($player->planets->allPlanets() as $planet) {
                if (!$planet->isMoon()) {
                    $home = $planet;
                    break;
                }
            }
            if ($home === null) {
                return;
            }

            if ($player->getFleetSlotsInUse() >= $player->getFleetSlotsMax()) {
                return;
            }

            if ($this->coloniseEnabled() && $this->tryColonise($bot, $player, $home)) {
                return;
            }

            if ($personality === 'raider' && $this->raidEnabled()) {
                $this->tryRaid($bot, $player, $home);
            }
        } catch (Throwable $e) {
            Log::info('Bot fleet turn failed for bot ' . $bot->id . ': ' . $e->getMessage());
        }
    }

    /**
     * Send a colony ship to a free slot, or queue one if the bot wants a colony but has no ship.
     */
    private function tryColonise(User $bot, PlayerService $player, PlanetService $home): bool
    {
        if ($player->planets->planetCount() >= $player->getMaxPlanetAmount()) {
            return false;
        }

        // A colony ship already on its way.
        if (FleetMission::where('user_id', $bot->id)->where('mission_type', 7)->where('processed', 0)->exists()) {
            return false;
        }

        $ships = $home->getShipUnits();
        if ($ships->getAmountByMachineName('colony_ship') < 1) {
            $this->queueColonyShip($home);
            return false;
        }

        $target = $this->findFreeSlot($player, $home);
        if ($target === null) {
            return false;
        }

        $fleet = new UnitCollection();
        $fleet->addUnit(ObjectService::getUnitObjectByMachineName('colony_ship'), 1);

        return $this->dispatch($home, $player, $target, 7, $fleet);
    }

    private function queueColonyShip(PlanetService $home): void
    {
        if ($home->getObjectLevel('shipyard') < 4) {
            return;
        }
        if (!ObjectService::objectRequirementsMet('colony_ship', $home)) {
            return;
        }
        if ($this->unitQueueService->retrieveQueue($home)->count() > 0) {
            return;
        }
        if (ObjectService::getObjectMaxBuildAmount('colony_ship', $home, true) < 1) {
            return;
        }

        try {
            $this->unitQueueService->add($home, ObjectService::getObjectByMachineName('colony_ship')->id, 1);
        } catch (Exception $e) {
            Log::info('Bot colony ship not queued: ' . $e->getMessage());
        }
    }

    /**
     * A free planet slot near home that the bot's astrophysics level allows.
     */
    private function findFreeSlot(PlayerService $player, PlanetService $home): Coordinate|null
    {
        $homeCoordinate = $home->getPlanetCoordinates();

        for ($attempt = 0; $attempt < 25; $attempt++) {
            $system = $homeCoordinate->system + random_int(-self::COLONY_SEARCH_RADIUS, self::COLONY_SEARCH_RADIUS);
            $position = random_int(4, 12);
            if ($system < 1 || $system > 499) {
                continue;
            }
            if (!$player->canColonizePosition($position)) {
                continue;
            }

            $coordinate = new Coordinate($homeCoordinate->galaxy, $system, $position);
            if ($this->planetServiceFactory->planetExistsAtCoordinate($coordinate)) {
                continue;
            }

            return $coordinate;
        }

        return null;
    }

    /**
     * Raid a clearly weaker target with the ships already on the bot's home planet.
     */
    private function tryRaid(User $bot, PlayerService $player, PlanetService $home): void
    {
        if (FleetMission::where('user_id', $bot->id)->where('mission_type', 1)->where('processed', 0)->exists()) {
            return;
        }

        $fleet = new UnitCollection();
        foreach ($home->getShipUnits()->units as $entry) {
            if ($entry->amount > 0 && in_array($entry->unitObject->machine_name, self::ATTACK_SHIPS, true)) {
                $fleet->addUnit($entry->unitObject, $entry->amount);
            }
        }
        if ($fleet->getAmount() < 1) {
            return;
        }

        $target = $this->pickTarget($bot, $home);
        if ($target === null) {
            return;
        }

        $targetValue = $this->unitValue($target->getShipUnits()) + $this->unitValue($target->getDefenseUnits());
        if ($this->unitValue($fleet) < $targetValue * self::MIN_STRENGTH_RATIO) {
            return;
        }

        $this->dispatch($home, $player, $target->getPlanetCoordinates(), 1, $fleet);
    }

    /**
     * Another bot's planet in the same galaxy that has not been raided by this bot recently.
     * Real players are only considered when the admin switched that on.
     */
    private function pickTarget(User $bot, PlanetService $home): PlanetService|null
    {
        if ($this->raidPlayersEnabled() && $this->pirateService->isEnabled() && random_int(1, 4) === 1) {
            $planet = $this->pirateService->pickTarget();
            if ($planet !== null) {
                return $planet;
            }
        }

        $candidates = User::where('is_bot', true)
            ->where('id', '!=', $bot->id)
            ->where(function ($query) {
                $query->whereNull('bot_personality')->orWhere('bot_personality', '!=', 'pirate');
            })
            ->inRandomOrder()
            ->limit(10)
            ->get();

        foreach ($candidates as $candidate) {
            $otherPlayer = $this->playerServiceFactory->make($candidate->id);
            $planets = [];
            foreach ($otherPlayer->planets->allPlanets() as $planet) {
                if (!$planet->isMoon() && $planet->getPlanetCoordinates()->galaxy === $home->getPlanetCoordinates()->galaxy) {
                    $planets[] = $planet;
                }
            }
            if (empty($planets)) {
                continue;
            }

            $planet = $planets[array_rand($planets)];

            $recentlyRaided = FleetMission::where('user_id', $bot->id)
                ->where('mission_type', 1)
                ->where('planet_id_to', $planet->getPlanetId())
                ->where('time_departure', '>=', time() - self::TARGET_COOLDOWN_SECONDS)
                ->exists();
            if ($recentlyRaided) {
                continue;
            }

            return $planet;
        }

        return null;
    }

    private function unitValue(UnitCollection $units): float
    {
        $resources = $units->toResources();

        return $resources->metal->get() + $resources->crystal->get() + $resources->deuterium->get();
    }

    private function dispatch(PlanetService $home, PlayerService $player, Coordinate $target, int $missionType, UnitCollection $fleet): bool
    {
        /** @var FleetMissionService $fleetMissionService */
        $fleetMissionService = app()->makeWith(FleetMissionService::class, ['player' => $player]);

        try {
            $fleetMissionService->createNewFromPlanet(
                $home,
                $target,
                PlanetType::Planet,
                $missionType,
                $fleet,
                new Resources(0, 0, 0, 0),
                10
            );
        } catch (Exception $e) {
            // Typically not enough deuterium or no free slot: just try again on a later turn.
            Log::info('Bot fleet not dispatched: ' . $e->getMessage());
            return false;
        }

        return true;
    }
}
