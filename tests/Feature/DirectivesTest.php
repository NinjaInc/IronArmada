<?php

namespace Tests\Feature;

use OGame\Factories\PlayerServiceFactory;
use OGame\Models\User;
use OGame\Services\DirectiveService;
use Tests\IsolatedAccountTestCase;

/**
 * Tests for the dark matter directives (daily and weekly objectives).
 */
class DirectivesTest extends IsolatedAccountTestCase
{
    public function testDirectivesPageLoads(): void
    {
        $response = $this->get('/directives');

        $response->assertStatus(200);
        $response->assertSee(__('t_ingame.directives.title'));
    }

    public function testNothingIsClaimableBeforeProgress(): void
    {
        $directiveService = app(DirectiveService::class);
        $player = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);

        $directives = $directiveService->forPlayer($player);
        $this->assertCount(count(DirectiveService::DEFINITIONS), $directives);
        foreach ($directives as $directive) {
            $this->assertFalse($directive['complete']);
            $this->assertFalse($directive['claimed']);
        }

        $this->assertSame(0, $directiveService->claim($player, 'daily_build'));
        $this->assertSame(0, $directiveService->claim($player, 'does_not_exist'));
    }

    public function testCompletedDirectivePaysOnceOnly(): void
    {
        $directiveService = app(DirectiveService::class);
        $player = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);

        // Open the period (takes the baseline), then raise a building level so "daily_build" needs only 1 more.
        $directiveService->forPlayer($player);
        $this->planetSetObjectLevel('metal_mine', $this->planetService->getObjectLevel('metal_mine') + 3);

        $player = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
        $before = (int)User::findOrFail($this->currentUserId)->dark_matter;

        $awarded = $directiveService->claim($player, 'daily_build');
        $this->assertSame(DirectiveService::DEFINITIONS['daily_build']['reward'], $awarded);
        $this->assertSame($before + $awarded, (int)User::findOrFail($this->currentUserId)->dark_matter);

        $this->assertSame(0, $directiveService->claim($player, 'daily_build'), 'A directive pays once per period.');
    }
}
