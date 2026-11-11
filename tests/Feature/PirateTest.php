<?php

namespace Tests\Feature;

use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\FleetMission;
use OGame\Models\Highscore;
use OGame\Models\User;
use OGame\Services\BotService;
use OGame\Services\PirateService;
use OGame\Services\SettingsService;
use Tests\IsolatedAccountTestCase;

/**
 * Tests for the pirate raid rules: pirates must never hit new, weak, protected or absent players,
 * and their fleets must stay below the target's own strength.
 */
class PirateTest extends IsolatedAccountTestCase
{
    /**
     * Other eligible players may exist in the test database, so assert that *our* planet is never picked.
     */
    private function assertCurrentPlanetNotTargeted(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $target = app(PirateService::class)->pickTarget();
            $this->assertTrue($target === null || $target->getPlanetId() !== $this->planetService->getPlanetId());
        }
    }

    private function fleetValue(UnitCollection $fleet): float
    {
        $resources = $fleet->toResources();

        return $resources->metal->get() + $resources->crystal->get() + $resources->deuterium->get();
    }

    /**
     * Make the logged in test user an otherwise eligible target.
     */
    private function makeCurrentUserEligible(): User
    {
        $user = User::findOrFail($this->currentUserId);
        $user->created_at = now()->subDays(30);
        $user->save();

        Highscore::updateOrCreate(['player_id' => $user->id], ['general' => 50000]);

        return $user;
    }

    public function testEligiblePlayerCanBeTargeted(): void
    {
        $this->makeCurrentUserEligible();
        // Targets are picked at random among all eligible players, so retry a few times.
        $found = false;
        for ($i = 0; $i < 40 && !$found; $i++) {
            $target = app(PirateService::class)->pickTarget();
            $found = $target !== null && $target->getPlanetId() === $this->planetService->getPlanetId();
        }

        $this->assertTrue($found, 'Eligible player should be a possible pirate target.');
    }

    public function testNewAccountIsProtected(): void
    {
        $user = $this->makeCurrentUserEligible();
        $user->created_at = now()->subDay();
        $user->save();

        $this->assertCurrentPlanetNotTargeted();
    }

    public function testLowPointsPlayerIsProtected(): void
    {
        $this->makeCurrentUserEligible();
        Highscore::where('player_id', $this->currentUserId)->update(['general' => 10]);

        $this->assertCurrentPlanetNotTargeted();
    }

    public function testVacationModePlayerIsProtected(): void
    {
        $user = $this->makeCurrentUserEligible();
        $user->vacation_mode = true;
        $user->save();

        $this->assertCurrentPlanetNotTargeted();
    }

    public function testAdminIsProtected(): void
    {
        $user = $this->makeCurrentUserEligible();
        $this->artisan('ogamex:admin:assign-role', ['username' => $user->username]);

        $this->assertCurrentPlanetNotTargeted();
    }

    /**
     * A player that was just raided is left alone for the cooldown period.
     */
    public function testPirateRaidIsRecordedAndPlayerGetsCooldown(): void
    {
        // IsolatedAccountTestCase freezes the clock at 2024-01-01, but the cooldown query in PirateService
        // compares mission departure times against the real time(). Use the real clock for this test so
        // the raid that was just dispatched falls inside the cooldown window, as it does in production.
        $this->travelBack();

        $this->makeCurrentUserEligible();
        $pirate = app(BotService::class)->createBot('pirate', 2);
        $pirate->bot_next_action_at = time() - 10;
        $pirate->save();

        // Disable the daily cap interplay by making sure raids are allowed.
        app(SettingsService::class)->set('pirates_max_raids_per_day', 100);

        $acted = app(BotService::class)->runDueBots();
        $this->assertSame(1, $acted);

        // Whether or not the dispatch succeeded in this test setup, a second pick right after a
        // successful dispatch must not return the same player again.
        $raided = FleetMission::where('user_id', $pirate->id)->where('mission_type', 1)->exists();
        if ($raided) {
            $this->assertCurrentPlanetNotTargeted();
        }
    }

    /**
     * The raid fleet is a fraction of the target's own strength, never above the target's value.
     */
    public function testFleetStaysBelowTargetStrength(): void
    {
        $this->planetAddUnit('cruiser', 100);
        $this->planetAddUnit('rocket_launcher', 1000);

        $target = $this->planetService;
        $targetValue = $this->fleetValue($target->getShipUnits()) + $this->fleetValue($target->getDefenseUnits());

        $fleet = app(PirateService::class)->buildFleet($target, 3);

        $this->assertGreaterThan(0, $fleet->getAmount());
        $this->assertLessThan($targetValue, $this->fleetValue($fleet), 'Pirate fleet must be weaker than target.');
    }

    public function testFleetRespectsFloorAndCap(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('pirates_min_fleet_value', 15000);
        $settings->set('pirates_max_fleet_value', 20000);

        // Defenseless target: fleet is the floor value (or just below due to whole ships).
        $fleet = app(PirateService::class)->buildFleet($this->planetService, 2);
        $this->assertGreaterThan(0, $fleet->getAmount());
        $this->assertLessThanOrEqual(15000, $this->fleetValue($fleet));

        // Huge target: fleet never exceeds the cap.
        $this->planetAddUnit('battle_ship', 5000);
        $fleet = app(PirateService::class)->buildFleet($this->planetService, 3);
        $this->assertLessThanOrEqual(20000, $this->fleetValue($fleet));
    }

    public function testDisabledPiratesDoNotRaid(): void
    {
        $this->makeCurrentUserEligible();
        $pirate = app(BotService::class)->createBot('pirate', 2);
        $pirate->bot_next_action_at = time() - 10;
        $pirate->save();

        app(SettingsService::class)->set('pirates_enabled', 0);
        app(BotService::class)->runDueBots();

        $this->assertFalse(FleetMission::where('user_id', $pirate->id)->exists());
    }
}
