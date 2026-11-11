<?php

namespace OGame\Services;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Models\FleetMission;
use OGame\Models\User;
use OGame\Models\UserDirective;

/**
 * Directives: daily and weekly objectives that pay out dark matter.
 *
 * Progress is derived from data the game already stores, so no gameplay code needs hooks:
 *  - "levels" metrics (buildings, research) are measured as the increase over a baseline taken the first
 *    time the player sees the directive in a period;
 *  - "missions" metrics count fleet missions the player launched since the period began.
 */
class DirectiveService
{
    public const PERIOD_DAILY = 'daily';
    public const PERIOD_WEEKLY = 'weekly';

    public const METRIC_BUILDING_LEVELS = 'building_levels';
    public const METRIC_RESEARCH_LEVELS = 'research_levels';
    public const METRIC_MISSIONS = 'missions';

    /**
     * Mission type ids: 1 attack, 15 expedition.
     *
     * @var array<string, array{period: string, metric: string, mission_type?: int, target: int, reward: int}>
     */
    public const DEFINITIONS = [
        'daily_build' => ['period' => self::PERIOD_DAILY, 'metric' => self::METRIC_BUILDING_LEVELS, 'target' => 3, 'reward' => 150],
        'daily_research' => ['period' => self::PERIOD_DAILY, 'metric' => self::METRIC_RESEARCH_LEVELS, 'target' => 1, 'reward' => 150],
        'daily_fleet' => ['period' => self::PERIOD_DAILY, 'metric' => self::METRIC_MISSIONS, 'target' => 2, 'reward' => 100],
        'weekly_build' => ['period' => self::PERIOD_WEEKLY, 'metric' => self::METRIC_BUILDING_LEVELS, 'target' => 20, 'reward' => 1000],
        'weekly_research' => ['period' => self::PERIOD_WEEKLY, 'metric' => self::METRIC_RESEARCH_LEVELS, 'target' => 5, 'reward' => 1000],
        'weekly_expedition' => ['period' => self::PERIOD_WEEKLY, 'metric' => self::METRIC_MISSIONS, 'mission_type' => 15, 'target' => 3, 'reward' => 1000],
        'weekly_attack' => ['period' => self::PERIOD_WEEKLY, 'metric' => self::METRIC_MISSIONS, 'mission_type' => 1, 'target' => 3, 'reward' => 1000],
    ];

    /**
     * Current period key and its start timestamp (UTC).
     *
     * @return array{0: string, 1: int, 2: int} [period key, period start, next reset]
     */
    public function currentPeriod(string $period): array
    {
        $now = Date::now('UTC');

        if ($period === self::PERIOD_WEEKLY) {
            $start = $now->copy()->startOfWeek();

            return ['w:' . $start->format('o-\WW'), (int)$start->timestamp, (int)$start->copy()->addWeek()->timestamp];
        }

        $start = $now->copy()->startOfDay();

        return ['d:' . $start->format('Y-m-d'), (int)$start->timestamp, (int)$start->copy()->addDay()->timestamp];
    }

    /**
     * All directives for the player with their progress in the current period.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forPlayer(PlayerService $player): array
    {
        $entries = [];

        foreach (self::DEFINITIONS as $key => $definition) {
            [$periodKey, $periodStart, $resetsAt] = $this->currentPeriod($definition['period']);
            $row = $this->getOrCreateRow($player, $key, $periodKey, $periodStart);

            $progress = $this->progress($player, $definition, $row);

            $entries[] = [
                'key' => $key,
                'period' => $definition['period'],
                'metric' => $definition['metric'],
                'mission_type' => $definition['mission_type'] ?? null,
                'target' => $definition['target'],
                'reward' => $definition['reward'],
                'progress' => min($progress, $definition['target']),
                'complete' => $progress >= $definition['target'],
                'claimed' => $row->claimed,
                'resets_at' => $resetsAt,
            ];
        }

        return $entries;
    }

    /**
     * Pay out a completed directive once per period. Returns the dark matter awarded (0 if not claimable).
     */
    public function claim(PlayerService $player, string $key): int
    {
        if (!isset(self::DEFINITIONS[$key])) {
            return 0;
        }

        $definition = self::DEFINITIONS[$key];
        [$periodKey, $periodStart] = $this->currentPeriod($definition['period']);

        return DB::transaction(function () use ($player, $key, $definition, $periodKey, $periodStart): int {
            $row = UserDirective::where('user_id', $player->getId())
                ->where('directive_key', $key)
                ->where('period_key', $periodKey)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                $row = $this->getOrCreateRow($player, $key, $periodKey, $periodStart);
            }

            if ($row->claimed || $this->progress($player, $definition, $row) < $definition['target']) {
                return 0;
            }

            $row->claimed = true;
            $row->save();

            User::where('id', $player->getId())->increment('dark_matter', $definition['reward']);

            return $definition['reward'];
        });
    }

    /**
     * @param array{period: string, metric: string, mission_type?: int, target: int, reward: int} $definition
     */
    private function progress(PlayerService $player, array $definition, UserDirective $row): int
    {
        if ($definition['metric'] === self::METRIC_MISSIONS) {
            return $this->missionsSince($player, $row->period_start, $definition['mission_type'] ?? null);
        }

        $current = $definition['metric'] === self::METRIC_BUILDING_LEVELS
            ? $this->totalBuildingLevels($player)
            : $this->totalResearchLevels($player);

        return max(0, $current - $row->baseline);
    }

    private function getOrCreateRow(PlayerService $player, string $key, string $periodKey, int $periodStart): UserDirective
    {
        $row = UserDirective::where('user_id', $player->getId())
            ->where('directive_key', $key)
            ->where('period_key', $periodKey)
            ->first();
        if ($row !== null) {
            return $row;
        }

        $definition = self::DEFINITIONS[$key];
        $baseline = match ($definition['metric']) {
            self::METRIC_BUILDING_LEVELS => $this->totalBuildingLevels($player),
            self::METRIC_RESEARCH_LEVELS => $this->totalResearchLevels($player),
            default => 0,
        };

        return UserDirective::firstOrCreate(
            ['user_id' => $player->getId(), 'directive_key' => $key, 'period_key' => $periodKey],
            ['period_start' => $periodStart, 'baseline' => $baseline, 'claimed' => false]
        );
    }

    private function totalBuildingLevels(PlayerService $player): int
    {
        $total = 0;
        foreach ($player->planets->allPlanets() as $planet) {
            if ($planet->isMoon()) {
                continue;
            }
            foreach ([...ObjectService::getBuildingObjects(), ...ObjectService::getStationObjects()] as $object) {
                $total += $planet->getObjectLevel($object->machine_name);
            }
        }

        return $total;
    }

    private function totalResearchLevels(PlayerService $player): int
    {
        $total = 0;
        foreach (ObjectService::getResearchObjects() as $object) {
            $total += $player->getResearchLevel($object->machine_name);
        }

        return $total;
    }

    private function missionsSince(PlayerService $player, int $since, int|null $missionType): int
    {
        $query = FleetMission::where('user_id', $player->getId())
            ->whereNull('parent_id')
            ->where('time_departure', '>=', $since);
        if ($missionType !== null) {
            $query->where('mission_type', $missionType);
        }

        return $query->count();
    }
}
