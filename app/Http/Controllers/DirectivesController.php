<?php

namespace OGame\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use OGame\Services\DirectiveService;
use OGame\Services\PlayerService;

class DirectivesController extends OGameController
{
    /**
     * Shows the daily and weekly directives with the player's progress.
     */
    public function index(PlayerService $player, DirectiveService $directiveService): View
    {
        return view('ingame.directives.index', [
            'directives' => $directiveService->forPlayer($player),
            'darkMatter' => $player->getDarkMatter(),
        ]);
    }

    /**
     * Collect the dark matter reward of a completed directive.
     */
    public function claim(Request $request, PlayerService $player, DirectiveService $directiveService): RedirectResponse
    {
        $key = (string)$request->input('directive');
        $awarded = $directiveService->claim($player, $key);

        if ($awarded > 0) {
            return redirect()->route('directives.index')->with('status', __('t_ingame.directives.claimed', ['amount' => number_format($awarded)]));
        }

        return redirect()->route('directives.index')->with('error', __('t_ingame.directives.not_claimable'));
    }
}
