<?php

namespace OGame\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use OGame\Http\Controllers\OGameController;
use OGame\Models\User;
use OGame\Services\BotService;
use Throwable;

class BotsController extends OGameController
{
    /**
     * Shows the bot administration page.
     */
    public function index(BotService $botService): View
    {
        $bots = User::with('highscore')
            ->where('is_bot', true)
            ->orderBy('id')
            ->get();

        return view('ingame.admin.bots')->with([
            'bots' => $bots,
            'bots_enabled' => $botService->isEnabled(),
            'personalities' => BotService::PERSONALITIES,
            'difficulties' => array_keys(BotService::DIFFICULTIES),
        ]);
    }

    /**
     * Global on/off switch.
     */
    public function saveGlobal(Request $request, BotService $botService): RedirectResponse
    {
        $botService->setEnabled($request->boolean('bots_enabled'));

        return redirect()->route('admin.bots.index')->with('status', __('t_ingame.bots.global_saved'));
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
