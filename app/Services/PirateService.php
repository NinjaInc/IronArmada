<?php

namespace OGame\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Resources;
use OGame\Models\User;
use Throwable;

/**
 * PirateService - raiding behaviour for bots with the "pirate" personality.
 *
 * Pirates do not build an economy. When their turn comes they pick a suitable target, conjure a
 * fleet that is deliberately sized *below* the target's own military and defense value, and send
 * it on a normal attack mission, so battle reports, debris fields and loot all use the regular code.
 *
 * Protection rules (all configurable in Admin > Bots):
 *  - never target admins, banned players, players in vacation mode or other bots
 *  - never target accounts younger than pirates_min_account_age_days
 *  - never target players below pirates_min_points
 *  - at most one raid per target every pirates_target_cooldown_hours
 *  - at most pirates_max_raids_per_day raids across all pirates in 24 hours
 *  - the fleet is only pirates_strength_percent % of the target's military + defense value
 *    (scaled by the pirate's difficulty), with a floor and a hard cap on total fleet value
 */
class PirateService
{
    public const SETTING_DEFAULTS = [
        'pirates_enabled' => 1,
        'pirates_min_points' => 1000,
        'pirates_min_account_age_days' => 7,
        'pirates_strength_percent' => 60,
        'pirates_target_cooldown_hours' => 24,
        'pirates_max_raids_per_day' => 10,
        'pirates_min_fleet_value' => 15000,
        'pirates_max_fleet_value' => 2000000,
    ];

    /** Difficulty => multiplier applied to the configured strength percentage. */
    private const DIFFICULTY_STRENGTH = [1 => 0.7, 2 => 1.0, 3 => 1.3];

    /**
     * Share of the fleet value spent per ship type (cheap ships first so small raids still work).
     *
     * @var array<string, float>
     */
    private const FLEET_MIX = ['light_fighter' => 0.5, 'cruiser' => 0.3, 'battle_ship' => 0.2];

    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function getSetting(string $key): int
    {
        return (int)$this->settings->get($key, self::SETTING_DEFAULTS[$key]);
    }

    public function isEnabled(): bool
    {
        return $this->getSetting('pirates_enabled') === 1;
    }

    /**
     * Let one pirate bot attempt a raid. Returns true when a fleet was dispatched.
     *
     * @throws Throwable
     */
    public function takeTurn(User $pirate, PlayerService $player): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $planets = $player->planets->allPlanets();
        $home = null;
        foreach ($planets as $planet) {
            if (!$planet->isMoon()) {
                $home = $planet;
                break;
            }
        }
        if ($home === null) {
            return false;
        }

        // Bring the pirate planet up to date: this also lands returning fleets.
        $home->update();

        if ($player->getFleetSlotsInUse() >= $player->getFleetSlotsMax()) {
            return false;
        }
        if ($this->raidsInLastDay() >= $this->getSetting('pirates_max_raids_per_day')) {
            return false;
        }

        $target = $this->pickTarget();
        if ($target === null) {
            return false;
        }

        $fleet = $this->buildFleet($target, (int)$pirate->bot_difficulty);
        if ($fleet->getAmount() < 1) {
            return false;
        }

        return $this->dispatch($home, $player, $target, $fleet);
    }

    /**
     * Choose a raid target that satisfies every protection rule, or null if there is none.
     */
    public function pickTarget(): PlanetService|null
    {
        $minAgeDays = $this->getSetting('pirates_min_account_age_days');
        $minPoints = $this->getSetting('pirates_min_points');
        $cooldownSeconds = $this->getSetting('pirates_target_cooldown_hours') * 3600;

        $candidates = User::where('is_bot', false)
            ->where('vacation_mode', false)
            ->where('created_at', '<=', now()->subDays($minAgeDays))
            ->whereHas('highscore', function ($query) use ($minPoints) {
                $query->where('general', '>=', $minPoints);
            })
            ->whereDoesntHave('roles', function ($query) {
                $query->where('name', 'admin');
            })
            ->inRandomOrder()
            ->limit(25)
            ->get();

        $pirateIds = User::where('is_bot', true)->where('bot_personality', 'pirate')->pluck('id')->all();
        $playerServiceFactory = app(PlayerServiceFactory::class);

        foreach ($candidates as $candidate) {
            if ($candidate->isBanned()) {
                continue;
            }

            $targetPlayer = $playerServiceFactory->make($candidate->id);
            $homePlanets = [];
            foreach ($targetPlayer->planets->allPlanets() as $planet) {
                if (!$planet->isMoon()) {
                    $homePlanets[] = $planet;
                }
            }
            if (empty($homePlanets)) {
                continue;
            }

            $planet = $homePlanets[array_rand($homePlanets)];

            // Per-target cooldown.
            $recentlyRaided = FleetMission::where('mission_type', 1)
                ->whereIn('user_id', $pirateIds)
                ->where('planet_id_to', $planet->getPlanetId())
                ->where('time_departure', '>=', time() - $cooldownSeconds)
                ->exists();
            if ($recentlyRaided) {
                continue;
            }

            return $planet;
        }

        return null;
    }

    /**
     * Build the fleet for a raid: a fraction of the target's military + defense value.
     */
    public function buildFleet(PlanetService $target, int $difficulty): UnitCollection
    {
        $defenseValue = $this->unitValue($target->getShipUnits()) + $this->unitValue($target->getDefenseUnits());

        $strength = $this->getSetting('pirates_strength_percent') / 100 * (self::DIFFICULTY_STRENGTH[$difficulty] ?? 1.0);
        $budget = $defenseValue * $strength;

        // Floor so raids on defenseless players are not pointless, hard cap so they are never overwhelming.
        $budget = max($budget, (float)$this->getSetting('pirates_min_fleet_value'));
        $budget = min($budget, (float)$this->getSetting('pirates_max_fleet_value'));

        $fleet = new UnitCollection();
        foreach (self::FLEET_MIX as $machineName => $share) {
            $object = ObjectService::getUnitObjectByMachineName($machineName);
            $price = ObjectService::getObjectRawPrice($machineName);
            $unitCost = $price->metal->get() + $price->crystal->get() + $price->deuterium->get();
            if ($unitCost <= 0) {
                continue;
            }
            $amount = (int)floor(($budget * $share) / $unitCost);
            if ($amount > 0) {
                $fleet->addUnit($object, $amount);
            }
        }

        // Budget too small for any big ship: fall back to light fighters only.
        if ($fleet->getAmount() < 1) {
            $price = ObjectService::getObjectRawPrice('light_fighter');
            $unitCost = $price->metal->get() + $price->crystal->get() + $price->deuterium->get();
            $amount = (int)floor($budget / max(1, $unitCost));
            if ($amount > 0) {
                $fleet->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), $amount);
            }
        }

        return $fleet;
    }

    /**
     * Total raw price (metal + crystal + deuterium) of a set of units.
     */
    private function unitValue(UnitCollection $units): float
    {
        $resources = $units->toResources();

        return $resources->metal->get() + $resources->crystal->get() + $resources->deuterium->get();
    }

    private function raidsInLastDay(): int
    {
        $pirateIds = User::where('is_bot', true)->where('bot_personality', 'pirate')->pluck('id')->all();

        return FleetMission::where('mission_type', 1)
            ->whereIn('user_id', $pirateIds)
            ->whereNull('parent_id')
            ->where('time_departure', '>=', time() - 86400)
            ->count();
    }

    /**
     * Make sure the pirate planet holds the raid fleet and fuel, then send the attack.
     */
    private function dispatch(PlanetService $home, PlayerService $player, PlanetService $target, UnitCollection $fleet): bool
    {
        $coordinate = $target->getPlanetCoordinates();

        // Top up the base fleet: survivors of earlier raids stay, only the shortfall is conjured.
        $existing = $home->getShipUnits();
        foreach ($fleet->units as $entry) {
            $have = $existing->getAmountByMachineName($entry->unitObject->machine_name);
            if ($have < $entry->amount) {
                $home->addUnit($entry->unitObject->machine_name, $entry->amount - $have);
            }
        }

        /** @var FleetMissionService $fleetMissionService */
        $fleetMissionService = app()->makeWith(FleetMissionService::class, ['player' => $player]);

        // Pirates "find" their fuel along the way: refill what the trip needs.
        $consumption = $fleetMissionService->calculateConsumption($home, $fleet, $coordinate, 0, 10);
        $home->addResources(new Resources(0, 0, $consumption, 0));

        try {
            $fleetMissionService->createNewFromPlanet(
                $home,
                $coordinate,
                PlanetType::Planet,
                1,
                $fleet,
                new Resources(0, 0, 0, 0),
                10
            );
        } catch (Exception $e) {
            Log::info('Pirate raid not dispatched: ' . $e->getMessage());
            return false;
        }

        $targetPlayer = $target->getPlayer();
        app(DiscordNotifier::class)->send(':skull_crossbones: Pirates are raiding **' . ($targetPlayer?->getUsername(false) ?? 'a commander') . '** at [' . $coordinate->asString() . ']');

        return true;
    }
}
