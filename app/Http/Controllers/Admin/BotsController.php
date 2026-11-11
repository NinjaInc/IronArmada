<?php

namespace OGame\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use OGame\Http\Controllers\OGameController;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\User;
use OGame\Services\BotFleetService;
use OGame\Services\BotService;
use OGame\Services\PirateService;
use OGame\Services\SettingsService;
use Throwable;

class BotsController extends OGameController
{
    /**
     * Shows the bot administration page.
     */
    public function index(BotService $botService, PirateService $pirateService, BotFleetService $botFleetService): View
    {
        $bots = User::with('highscore')
            ->where('is_bot', true)
            ->orderBy('id')
            ->get();

        $botIds = $bots->pluck('id')->all();
        $now = time();

        $raids = FleetMission::with(['planetFrom', 'planetTo'])
            ->where('mission_type', 1)
            ->whereIn('user_id', $botIds)
            ->orderByDesc('time_departure')
            ->limit(25)
            ->get();
        $targetOwners = User::whereIn('id', $raids->map(fn (FleetMission $m) => $m->planetTo?->user_id)->filter()->unique()->all())
            ->pluck('username', 'id');
        $attackers = $bots->pluck('username', 'id');

        $raidLog = $raids->map(fn (FleetMission $m) => [
            'time' => $m->time_departure,
            'attacker' => $attackers[$m->user_id] ?? ('#' . $m->user_id),
            'target' => ($m->planetTo->name ?? '?') . ' [' . $m->galaxy_to . ':' . $m->system_to . ':' . $m->position_to . ']',
            'owner' => $targetOwners[$m->planetTo?->user_id] ?? '?',
            'target_is_bot' => in_array($m->planetTo?->user_id, $botIds, true),
            'ships' => (int)($m->light_fighter + $m->heavy_fighter + $m->cruiser + $m->battle_ship + $m->battlecruiser + $m->bomber + $m->destroyer + $m->deathstar),
            'done' => (bool)$m->processed,
        ])->all();

        $stats = [
            'bots' => $bots->count(),
            'colonies' => Planet::whereIn('user_id', $botIds)->where('planet_type', 1)->count(),
            'raids_24h' => FleetMission::where('mission_type', 1)->whereIn('user_id', $botIds)->where('time_departure', '>=', $now - 86400)->count(),
            'raids_players_24h' => FleetMission::where('mission_type', 1)->whereIn('user_id', $botIds)->where('time_departure', '>=', $now - 86400)
                ->whereHas('planetTo', fn ($q) => $q->whereNotIn('user_id', $botIds))->count(),
            'colonisations_24h' => FleetMission::where('mission_type', 7)->whereIn('user_id', $botIds)->where('time_departure', '>=', $now - 86400)->count(),
            'by_personality' => $bots->groupBy(fn (User $b) => $b->bot_personality ?? 'balanced')->map->count()->all(),
        ];

        return view('ingame.admin.bots')->with([
            'raid_log' => $raidLog,
            'stats' => $stats,
            'bots_colonise_enabled' => $botFleetService->coloniseEnabled(),
            'bots_raid_enabled' => $botFleetService->raidEnabled(),
            'bots_raid_players' => $botFleetService->raidPlayersEnabled(),
            'bots' => $bots,
            'bots_enabled' => $botService->isEnabled(),
            'personalities' => BotService::PERSONALITIES,
            'difficulties' => array_keys(BotService::DIFFICULTIES),
            'pirate_settings' => array_combine(
                array_keys(PirateService::SETTING_DEFAULTS),
                array_map(fn (string $key) => $pirateService->getSetting($key), array_keys(PirateService::SETTING_DEFAULTS))
            ),
        ]);
    }

    /**
     * Global on/off switch.
     */
    public function saveGlobal(Request $request, BotService $botService, SettingsService $settings): RedirectResponse
    {
        $botService->setEnabled($request->boolean('bots_enabled'));
        foreach (['bots_colonise_enabled', 'bots_raid_enabled', 'bots_raid_players'] as $key) {
            $settings->set($key, $request->boolean($key) ? 1 : 0);
        }

        return redirect()->route('admin.bots.index')->with('status', __('t_ingame.bots.global_saved'));
    }

    /**
     * Save the pirate raid rules.
     */
    public function savePirates(Request $request, SettingsService $settings): RedirectResponse
    {
        $validated = $request->validate([
            'pirates_min_points' => ['required', 'integer', 'min:0'],
            'pirates_min_account_age_days' => ['required', 'integer', 'min:0', 'max:365'],
            'pirates_strength_percent' => ['required', 'integer', 'min:1', 'max:200'],
            'pirates_target_cooldown_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'pirates_max_raids_per_day' => ['required', 'integer', 'min:0', 'max:1000'],
            'pirates_min_fleet_value' => ['required', 'integer', 'min:0'],
            'pirates_max_fleet_value' => ['required', 'integer', 'min:0'],
        ]);

        foreach ($validated as $key => $value) {
            $settings->set($key, (int)$value);
        }
        $settings->set('pirates_enabled', $request->boolean('pirates_enabled') ? 1 : 0);

        return redirect()->route('admin.bots.index')->with('status', __('t_ingame.bots.pirates_saved'));
    }

    /**
     * Create one or more bots.
     */
    public function create(Request $request, BotService $botService): RedirectResponse
    {
        $validated = $request->validate([
            'personality' => ['required', 'string'],
            'difficulty' => ['required', 'integer'],
            'amount' => ['required', 'integer', 'min:1', 'max:20'],
            'name' => ['nullable', 'string', 'max:30'],
        ]);

        $amount = (int)$validated['amount'];
        $created = 0;

        try {
            for ($i = 0; $i < $amount; $i++) {
                // A custom name only makes sense for a single bot; otherwise names are generated.
                $name = ($amount === 1 && !empty($validated['name'])) ? (string)$validated['name'] : null;
                $botService->createBot((string)$validated['personality'], (int)$validated['difficulty'], $name);
                $created++;
            }
        } catch (InvalidArgumentException $e) {
            return redirect()->route('admin.bots.index')->withErrors(['bots' => $e->getMessage()]);
        }

        return redirect()->route('admin.bots.index')->with('status', __('t_ingame.bots.created', ['count' => $created]));
    }

    /**
     * Update personality, difficulty and active state of a single bot.
     */
    public function update(Request $request, int $id): RedirectResponse
    {
        $bot = User::where('is_bot', true)->findOrFail($id);

        $validated = $request->validate([
            'personality' => ['required', 'string', 'in:' . implode(',', BotService::PERSONALITIES)],
            'difficulty' => ['required', 'integer', 'in:' . implode(',', array_keys(BotService::DIFFICULTIES))],
        ]);

        $bot->bot_personality = (string)$validated['personality'];
        $bot->bot_difficulty = (int)$validated['difficulty'];
        $bot->bot_enabled = $request->boolean('bot_enabled');
        $bot->save();

        return redirect()->route('admin.bots.index')->with('status', __('t_ingame.bots.updated'));
    }

    /**
     * Force a turn for one bot right now (handy for testing).
     */
    public function turn(int $id, BotService $botService): RedirectResponse
    {
        $bot = User::where('is_bot', true)->findOrFail($id);

        try {
            $botService->takeTurn($bot);
        } catch (Throwable $e) {
            return redirect()->route('admin.bots.index')->withErrors(['bots' => __('t_ingame.bots.turn_failed', ['error' => $e->getMessage()])]);
        }

        return redirect()->route('admin.bots.index')->with('status', __('t_ingame.bots.turn_done'));
    }
}
