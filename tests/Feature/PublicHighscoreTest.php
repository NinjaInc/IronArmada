<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use OGame\Http\Controllers\PublicHighscoreController;
use OGame\Models\Highscore;
use Tests\IsolatedAccountTestCase;

/**
 * The public top 10 feed used by the xLAx clan website.
 */
class PublicHighscoreTest extends IsolatedAccountTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(PublicHighscoreController::CACHE_KEY);
    }

    public function testFeedListsTopPlayersAndHidesAdminsAndPrivateData(): void
    {
        // Scores far above anything else in the shared test database, so these
        // players are guaranteed to be at the top of every list.
        $leader = $this->createUser();
        Highscore::updateOrCreate(['player_id' => $leader->id], ['general' => 9_000_000_000_000, 'economy' => 9_000_000_000_000, 'research' => 9_000_000_000_000, 'military' => 9_000_000_000_000]);

        $bot = $this->createUser();
        $bot->is_bot = true;
        $bot->save();
        Highscore::updateOrCreate(['player_id' => $bot->id], ['general' => 8_000_000_000_000, 'economy' => 8_000_000_000_000, 'research' => 8_000_000_000_000, 'military' => 8_000_000_000_000]);

        $admin = $this->createUser();
        $admin->assignRole('admin');
        Highscore::updateOrCreate(['player_id' => $admin->id], ['general' => 9_900_000_000_000, 'economy' => 9_900_000_000_000, 'research' => 9_900_000_000_000, 'military' => 9_900_000_000_000]);

        $response = $this->get('/api/public/highscores');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'game',
            'players',
            'updated_at',
            'categories' => [
                'general' => [['rank', 'name', 'alliance', 'points', 'bot']],
                'economy',
                'research',
                'military',
            ],
        ]);

        $data = $response->json();
        foreach (['general', 'economy', 'research', 'military'] as $category) {
            $list = $data['categories'][$category];
            $this->assertLessThanOrEqual(10, count($list));

            // The admin outscores everyone but must never appear.
            $this->assertNotContains($admin->username, array_column($list, 'name'));

            $this->assertSame(1, $list[0]['rank']);
            $this->assertSame($leader->username, $list[0]['name']);
            $this->assertSame(9_000_000_000_000, $list[0]['points']);
            $this->assertFalse($list[0]['bot']);

            $this->assertSame(2, $list[1]['rank']);
            $this->assertSame($bot->username, $list[1]['name']);
            $this->assertTrue($list[1]['bot']);

            // Only public fields go out.
            $this->assertSame(['rank', 'name', 'alliance', 'points', 'bot'], array_keys($list[0]));
        }

        $this->assertStringNotContainsString((string) $leader->email, $response->getContent() ?: '');
    }

    public function testFeedIsReachableWithoutLoggingIn(): void
    {
        auth()->logout();

        $this->get('/api/public/highscores')->assertStatus(200)->assertJsonPath('game', 'Iron Armada');
    }
}
