<?php

namespace Tests\Feature;

use InvalidArgumentException;
use OGame\Models\BuildingQueue;
use OGame\Models\Planet;
use OGame\Models\User;
use OGame\Services\BotService;
use Tests\IsolatedAccountTestCase;

/**
 * Tests for the computer controlled empires (bots) feature.
 */
class BotTest extends IsolatedAccountTestCase
{
    /**
     * A created bot is a flagged user with a homeworld and cannot be logged into with a guessable password.
     */
    public function testCreateBotCreatesUserWithPlanet(): void
    {
        $botService = app(BotService::class);
        $bot = $botService->createBot('miner', 3, 'Test Bot Alpha');

        $this->assertTrue($bot->is_bot);
        $this->assertSame('miner', $bot->bot_personality);
        $this->assertSame(3, $bot->bot_difficulty);
        $this->assertSame('Test Bot Alpha (NPC)', $bot->username);
        $this->assertStringEndsWith('@bots.invalid', $bot->email);
        $this->assertTrue(Planet::where('user_id', $bot->id)->exists());
    }

    public function testCreateBotRejectsUnknownPersonalityAndDifficulty(): void
    {
        $botService = app(BotService::class);

        $this->expectException(InvalidArgumentException::class);
        $botService->createBot('does-not-exist', 2);
    }

    public function testCreateBotRejectsUnknownDifficulty(): void
    {
        $botService = app(BotService::class);

        $this->expectException(InvalidArgumentException::class);
        $botService->createBot('balanced', 99);
    }

    /**
     * With plenty of resources a bot turn puts something in the building queue.
     */
    public function testBotTurnQueuesABuilding(): void
    {
        $botService = app(BotService::class);
        $bot = $botService->createBot('balanced', 2);

        $planet = Planet::where('user_id', $bot->id)->where('planet_type', 1)->firstOrFail();
        $planet->metal = 50000;
        $planet->crystal = 50000;
        $planet->deuterium = 50000;
        $planet->save();

        $botService->takeTurn($bot);

        $this->assertTrue(BuildingQueue::where('planet_id', $planet->id)->exists(), 'Bot should have queued a building.');
    }

    /**
     * A bot whose turn is not due yet must not act, and disabled bots never act.
     */
    public function testRunDueBotsRespectsScheduleAndSwitches(): void
    {
        $botService = app(BotService::class);
        $bot = $botService->createBot('balanced', 2);

        // Not due yet (createBot schedules the first turn in the future).
        $this->assertSame(0, $botService->runDueBots());

        // Due now.
        $bot->bot_next_action_at = time() - 10;
        $bot->save();
        $this->assertSame(1, $botService->runDueBots());

        // Rescheduled into the future after its turn.
        $bot->refresh();
        $this->assertGreaterThan(time(), $bot->bot_next_action_at);

        // Disabled bot never acts.
        $bot->bot_next_action_at = time() - 10;
        $bot->bot_enabled = false;
        $bot->save();
        $this->assertSame(0, $botService->runDueBots());

        // Global switch off: nothing acts.
        $bot->bot_enabled = true;
        $bot->save();
        $botService->setEnabled(false);
        $this->assertSame(0, $botService->runDueBots());
        $botService->setEnabled(true);
        $this->assertTrue($botService->isEnabled());
    }

    /**
     * Admins can see the bot page and create bots; normal users are redirected.
     */
    public function testAdminCanManageBots(): void
    {
        $authUser = auth()->user();
        if ($authUser === null) {
            $this->fail('Not authenticated.');
        }
        $this->artisan('ogamex:admin:assign-role', ['username' => $authUser->username]);

        $this->get('/admin/bots')->assertStatus(200);

        $this->post('/admin/bots/create', [
            'personality' => 'turtle',
            'difficulty' => 1,
            'amount' => 2,
        ])->assertRedirect('/admin/bots');

        $this->assertSame(2, User::where('is_bot', true)->count());

        $bot = User::where('is_bot', true)->firstOrFail();
        $this->post('/admin/bots/' . $bot->id, [
            'personality' => 'raider',
            'difficulty' => 3,
        ])->assertRedirect('/admin/bots');

        $bot->refresh();
        $this->assertSame('raider', $bot->bot_personality);
        $this->assertSame(3, $bot->bot_difficulty);
        // Checkbox not sent => disabled.
        $this->assertFalse($bot->bot_enabled);
    }

    public function testNormalUserCannotAccessBotAdmin(): void
    {
        $authUser = auth()->user();
        if ($authUser === null) {
            $this->fail('Not authenticated.');
        }
        $this->artisan('ogamex:admin:remove-role', ['username' => $authUser->username]);

        $this->get('/admin/bots')->assertRedirect('/overview');
    }
}
