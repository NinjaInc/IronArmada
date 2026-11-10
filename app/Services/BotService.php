<?php

namespace OGame\Services;

use Exception;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OGame\Actions\Fortify\CreateNewUser;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\User;
use Throwable;

/**
 * BotService - computer controlled empires ("bots").
 *
 * A bot is a normal user account (flagged with users.is_bot) with real planets, so galaxy view,
 * highscores, espionage and combat all work on it without special cases. Bots cannot log in
 * (random password, placeholder email). Every few minutes the scheduler calls runDueBots(),
 * which makes each due bot play one "turn" through the same queue services human players use.
 *
 * Phase 1 (this file): economy, research, ship and defense building.
 * Phase 2 (later): fleet missions (raiding, expeditions, colonising).
 */
class BotService
{
    /** Appended to every bot username. */
    public const NPC_SUFFIX = ' (NPC)';

    public const PERSONALITIES = ['balanced', 'miner', 'turtle', 'raider', 'pirate'];

    /** Difficulty => [label, minutes between turns, share of spare resources spent on units]. */
    public const DIFFICULTIES = [
        1 => ['label' => 'Easy', 'interval_minutes' => 30, 'unit_spend' => 0.25],
        2 => ['label' => 'Normal', 'interval_minutes' => 15, 'unit_spend' => 0.45],
        3 => ['label' => 'Hard', 'interval_minutes' => 6, 'unit_spend' => 0.70],
    ];

    /**
     * Building weights per personality. The bot always builds the available building with the
     * lowest (level + 1) / weight, i.e. levels grow in proportion to their weights.
     *
     * @var array<string, array<string, float>>
     */
    private const BUILDING_WEIGHTS = [
        'balanced' => [
            'metal_mine' => 1.0, 'crystal_mine' => 0.85, 'deuterium_synthesizer' => 0.6, 'solar_plant' => 0.9,
            'robot_factory' => 0.35, 'research_lab' => 0.35, 'shipyard' => 0.3,
            'metal_store' => 0.25, 'crystal_store' => 0.22, 'deuterium_store' => 0.15,
        ],
        'miner' => [
            'metal_mine' => 1.3, 'crystal_mine' => 1.1, 'deuterium_synthesizer' => 0.8, 'solar_plant' => 1.1,
            'robot_factory' => 0.3, 'research_lab' => 0.25, 'shipyard' => 0.2,
            'metal_store' => 0.35, 'crystal_store' => 0.3, 'deuterium_store' => 0.2,
        ],
        'turtle' => [
            'metal_mine' => 0.9, 'crystal_mine' => 0.8, 'deuterium_synthesizer' => 0.6, 'solar_plant' => 0.85,
            'robot_factory' => 0.4, 'research_lab' => 0.4, 'shipyard' => 0.4,
            'metal_store' => 0.25, 'crystal_store' => 0.22, 'deuterium_store' => 0.15,
        ],
        'raider' => [
            'metal_mine' => 0.9, 'crystal_mine' => 0.8, 'deuterium_synthesizer' => 0.75, 'solar_plant' => 0.85,
            'robot_factory' => 0.45, 'research_lab' => 0.4, 'shipyard' => 0.5,
            'metal_store' => 0.2, 'crystal_store' => 0.18, 'deuterium_store' => 0.15,
        ],
    ];

    /**
     * Research weights, same idea as the building weights.
     *
     * @var array<string, float>
     */
    private const RESEARCH_BASE_WEIGHTS = [
        'energy_technology' => 0.5, 'computer_technology' => 0.5, 'combustion_drive' => 0.5,
        'impulse_drive' => 0.35, 'hyperspace_technology' => 0.2, 'hyperspace_drive' => 0.2,
        'laser_technology' => 0.4, 'ion_technology' => 0.3, 'plasma_technology' => 0.25,
        'weapon_technology' => 0.5, 'shielding_technology' => 0.45, 'armor_technology' => 0.5,
        'espionage_technology' => 0.35, 'astrophysics' => 0.3,
    ];

    /**
     * Multipliers applied on top of the base research weights per personality.
     *
     * @var array<string, array<string, float>>
     */
    private const RESEARCH_PERSONALITY_BIAS = [
        'balanced' => [],
        'miner' => ['energy_technology' => 1.3, 'plasma_technology' => 1.5, 'computer_technology' => 1.2, 'weapon_technology' => 0.6, 'armor_technology' => 0.6],
        'turtle' => ['shielding_technology' => 1.4, 'armor_technology' => 1.3, 'weapon_technology' => 1.2, 'combustion_drive' => 0.6, 'impulse_drive' => 0.5],
        'raider' => ['combustion_drive' => 1.5, 'impulse_drive' => 1.5, 'weapon_technology' => 1.4, 'armor_technology' => 1.3, 'espionage_technology' => 1.3],
    ];

    /**
     * Units a bot will build, best first. The first one whose requirements are met is built.
     *
     * @var array<string, array{ships: list<string>, defense: list<string>, defense_share: float}>
     */
    private const UNIT_PLANS = [
        'balanced' => ['ships' => ['cruiser', 'heavy_fighter', 'light_fighter'], 'defense' => ['gauss_cannon', 'heavy_laser', 'light_laser', 'rocket_launcher'], 'defense_share' => 0.4],
        'miner' => ['ships' => ['light_fighter'], 'defense' => ['heavy_laser', 'light_laser', 'rocket_launcher'], 'defense_share' => 0.7],
        'turtle' => ['ships' => ['light_fighter'], 'defense' => ['plasma_turret', 'gauss_cannon', 'ion_cannon', 'heavy_laser', 'light_laser', 'rocket_launcher'], 'defense_share' => 0.9],
        'raider' => ['ships' => ['battle_ship', 'cruiser', 'heavy_fighter', 'light_fighter'], 'defense' => ['rocket_launcher'], 'defense_share' => 0.1],
    ];

    public function __construct(
        private readonly PlayerServiceFactory $playerServiceFactory,
        private readonly InitialUserDataService $initialUserDataService,
        private readonly SettingsService $settings,
        private readonly BuildingQueueService $buildingQueueService,
        private readonly ResearchQueueService $researchQueueService,
        private readonly UnitQueueService $unitQueueService,
    ) {
    }

    /**
     * Global on/off switch for all bots (server setting "bots_enabled", default on).
     */
    public function isEnabled(): bool
    {
        return $this->settings->get('bots_enabled', 1) === '1';
    }

    public function setEnabled(bool $enabled): void
    {
        $this->settings->set('bots_enabled', $enabled ? 1 : 0);
    }

    /**
     * Create a new bot account with its starting planet(s).
     *
     * @throws InvalidArgumentException when personality or difficulty is unknown.
     */
    public function createBot(string $personality, int $difficulty, string|null $name = null): User
    {
        if (!in_array($personality, self::PERSONALITIES, true)) {
            throw new InvalidArgumentException('Unknown bot personality: ' . $personality);
        }
        if (!isset(self::DIFFICULTIES[$difficulty])) {
            throw new InvalidArgumentException('Unknown bot difficulty: ' . $difficulty);
        }

        $username = $name !== null && trim($name) !== ''
            ? trim($name)
            : ($personality === 'pirate' ? 'Pirate ' : '') . app(CreateNewUser::class)->generateUniqueName();

        // Bots are always marked so players can tell them apart from real people.
        if (!str_ends_with($username, self::NPC_SUFFIX)) {
            $username .= self::NPC_SUFFIX;
        }

        if (User::where('username', $username)->exists()) {
            throw new InvalidArgumentException('That username is already taken.');
        }

        $user = User::create([
            'lang' => 'en',
            'username' => $username,
            // Reserved .invalid TLD: can never receive mail, and the random password cannot be guessed.
            'email' => 'bot-' . Str::lower(Str::random(12)) . '@bots.invalid',
            'password' => Hash::make(Str::random(48)),
        ]);

        $user->is_bot = true;
        $user->bot_personality = $personality;
        $user->bot_difficulty = $difficulty;
        $user->bot_enabled = true;
        // Spread the first turns out so bots created together do not all act in the same minute.
        $user->bot_next_action_at = time() + random_int(30, 300);
        $user->time = (string)time();
        $user->save();

        $this->initialUserDataService->createFor($user);

        return $user;
    }

    /**
     * Run a turn for every enabled bot that is due. Returns the number of bots that acted.
     */
    public function runDueBots(int $limit = 25): int
    {
        if (!$this->isEnabled()) {
            return 0;
        }

        $bots = User::where('is_bot', true)
            ->where('bot_enabled', true)
            ->where('bot_next_action_at', '<=', time())
            ->orderBy('bot_next_action_at')
            ->limit($limit)
            ->get();

        $acted = 0;
        foreach ($bots as $bot) {
            try {
                $this->takeTurn($bot);
                $acted++;
            } catch (Throwable $e) {
                // One misbehaving bot must never stop the others.
                Log::warning('Bot turn failed for user ' . $bot->id . ': ' . $e->getMessage());
            }

            $config = self::DIFFICULTIES[$bot->bot_difficulty] ?? self::DIFFICULTIES[2];
            $bot->bot_next_action_at = time() + ($config['interval_minutes'] * 60) + random_int(0, 120);
            $bot->time = (string)time();
            $bot->save();
        }

        return $acted;
    }

    /**
     * Play one turn for a single bot: finish queues, then spend resources.
     *
     * @throws Throwable
     */
    public function takeTurn(User $bot): void
    {
        $personality = in_array($bot->bot_personality, self::PERSONALITIES, true) ? $bot->bot_personality : 'balanced';
        $difficulty = self::DIFFICULTIES[$bot->bot_difficulty] ?? self::DIFFICULTIES[2];

        $player = $this->playerServiceFactory->make($bot->id);

        // Pirates do not build an economy, they only raid (with strict protection rules for players).
        if ($personality === 'pirate') {
            app(PirateService::class)->takeTurn($bot, $player);
            return;
        }

        $isFirstPlanet = true;

        foreach ($player->planets->allPlanets() as $planet) {
            if ($planet->isMoon()) {
                continue;
            }

            // Completes finished queue items and brings resources up to date.
            $planet->update();

            $this->manageBuildings($planet, $personality);

            if ($isFirstPlanet) {
                // Research is per player; run it from the home planet (it holds the research lab).
                $this->manageResearch($player, $planet, $personality);
                $isFirstPlanet = false;
            }

            $this->manageUnits($planet, $personality, (float)$difficulty['unit_spend']);
        }
    }

    /**
     * Queue the next building if the building queue is empty and the bot can afford it.
     */
    private function manageBuildings(PlanetService $planet, string $personality): void
    {
        if ($this->buildingQueueService->retrieveQueue($planet)->count() > 0) {
            return;
        }

        $weights = self::BUILDING_WEIGHTS[$personality];

        // Energy deficit: fix it first, nothing else produces at full speed.
        $candidates = $planet->energy()->get() < 0 ? ['solar_plant'] : array_keys($weights);

        $best = null;
        $bestScore = PHP_FLOAT_MAX;
        foreach ($candidates as $machineName) {
            $level = $planet->getObjectLevel($machineName);
            if (!ObjectService::objectRequirementsMetWithQueue($machineName, $level + 1, $planet)) {
                continue;
            }
            $score = ($level + 1) / ($weights[$machineName] ?? 1.0);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $machineName;
            }
        }

        if ($best === null) {
            return;
        }

        // Cannot afford it yet: wait and save up instead of picking something cheaper forever.
        if (!$planet->hasResources(ObjectService::getObjectPrice($best, $planet))) {
            return;
        }

        try {
            $this->buildingQueueService->add($planet, ObjectService::getObjectByMachineName($best)->id);
        } catch (Exception $e) {
            // e.g. no free fields left; try again next turn.
            Log::info('Bot building skipped (' . $best . '): ' . $e->getMessage());
        }
    }

    /**
     * Queue the next research if nothing is being researched and the bot can afford it.
     */
    private function manageResearch(PlayerService $player, PlanetService $planet, string $personality): void
    {
        if ($player->isResearching() || $planet->getObjectLevel('research_lab') < 1) {
            return;
        }

        $bias = self::RESEARCH_PERSONALITY_BIAS[$personality];

        $best = null;
        $bestScore = PHP_FLOAT_MAX;
        foreach (self::RESEARCH_BASE_WEIGHTS as $machineName => $baseWeight) {
            $level = $player->getResearchLevel($machineName);
            if (!ObjectService::objectRequirementsMetWithQueue($machineName, $level + 1, $planet)) {
                continue;
            }
            $score = ($level + 1) / ($baseWeight * ($bias[$machineName] ?? 1.0));
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $machineName;
            }
        }

        if ($best === null) {
            return;
        }

        if (!$planet->hasResources(ObjectService::getObjectPrice($best, $planet))) {
            return;
        }

        try {
            $this->researchQueueService->add($player, $planet, ObjectService::getObjectByMachineName($best)->id);
        } catch (Exception $e) {
            Log::info('Bot research skipped (' . $best . '): ' . $e->getMessage());
        }
    }

    /**
     * Spend a share of the *spare* resources on ships or defense.
     *
     * Only runs while a building is under construction (its cost is already paid), so unit
     * spending never starves the economy.
     */
    private function manageUnits(PlanetService $planet, string $personality, float $spendShare): void
    {
        if ($planet->getObjectLevel('shipyard') < 1) {
            return;
        }
        if ($this->buildingQueueService->retrieveQueue($planet)->count() === 0) {
            return;
        }
        if ($this->unitQueueService->retrieveQueue($planet)->count() > 0) {
            return;
        }

        $plan = self::UNIT_PLANS[$personality];
        $list = (mt_rand() / mt_getrandmax()) < $plan['defense_share'] ? $plan['defense'] : $plan['ships'];

        foreach ($list as $machineName) {
            if (!ObjectService::objectRequirementsMet($machineName, $planet)) {
                continue;
            }

            $maxAmount = ObjectService::getObjectMaxBuildAmount($machineName, $planet, true);
            $amount = (int)floor($maxAmount * $spendShare);
            if ($amount < 1) {
                continue;
            }

            try {
                $this->unitQueueService->add($planet, ObjectService::getObjectByMachineName($machineName)->id, $amount);
            } catch (Exception $e) {
                Log::info('Bot unit order skipped (' . $machineName . '): ' . $e->getMessage());
            }

            return;
        }
    }
}
