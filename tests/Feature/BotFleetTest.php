<?php

namespace Tests\Feature;

use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Services\BotFleetService;
use OGame\Services\BotService;
use OGame\Services\SettingsService;
use Tests\IsolatedAccountTestCase;

/**
 * Tests for colonising and raiding bots.
 */
class BotFleetTest extends IsolatedAccountTestCase
{
    public function testFleetSettingsDefaults(): void
    {
        $fleetService = app(BotFleetService::class);

        $this->assertTrue($fleetService->coloniseEnabled());
        $this->assertTrue($fleetService->raidEnabled());
        $this->assertFalse($fleetService->raidPlayersEnabled(), 'Raiding real players must be opt-in.');
    }

    public function testSettingsCanBeSwitchedOff(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('bots_raid_enabled', 0);
        $settings->set('bots_colonise_enabled', 0);

        $fleetService = app(BotFleetService::class);
        $this->assertFalse($fleetService->raidEnabled());
        $this->assertFalse($fleetService->coloniseEnabled());
    }

    /**
     * A bot with a colony ship but no astrophysics headroom does not launch a colonisation mission.
     */
    public function testBotWithoutFreeColonySlotDoesNotColonise(): void
    {
        $bot = app(BotService::class)->createBot('balanced', 2);
        app(BotService::class)->takeTurn($bot);

        $this->assertSame(0, FleetMission::where('user_id', $bot->id)->where('mission_type', 7)->count());
        $this->assertTrue(Planet::where('user_id', $bot->id)->exists());
    }

    /**
     * A raider never attacks a stronger target and never attacks when no other bot exists.
     */
    public function testRaiderWithoutTargetsDoesNotAttack(): void
    {
        $bot = app(BotService::class)->createBot('raider', 3);
        app(BotService::class)->takeTurn($bot);

        $this->assertSame(0, FleetMission::where('user_id', $bot->id)->where('mission_type', 1)->count());
    }
}
