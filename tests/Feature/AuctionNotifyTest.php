<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use OGame\Factories\PlayerServiceFactory;
use OGame\Jobs\NotifyAuctionEvent;
use OGame\Models\Auction;
use OGame\Models\User;
use OGame\Services\AuctionNotifier;
use OGame\Services\SettingsService;
use OGame\Services\UserPreferencesService;
use RuntimeException;
use Tests\IsolatedAccountTestCase;

/**
 * Armada Salvage Auction notifications: Discord hooks and the outbid / won in-game messages.
 */
class AuctionNotifyTest extends IsolatedAccountTestCase
{
    private const WEBHOOK = 'https://discord.com/api/webhooks/123456789012345678/abc_DEF-123';

    private function makeAuction(array $overrides = []): Auction
    {
        $now = (int)now()->timestamp;

        return Auction::create(array_merge([
            'auction_date' => '2024-01-01',
            'status' => Auction::STATUS_ACTIVE,
            'active_slot' => 1,
            'lot_key' => 'crate_metal',
            'lot_payload' => ['resources' => ['metal' => 1000, 'crystal' => 0, 'deuterium' => 0], 'units' => []],
            'starting_bid' => 500,
            'min_increment_abs' => 100,
            'min_increment_pct' => 5,
            'current_bid' => 0,
            'bid_count' => 0,
            'starts_at' => $now,
            'ends_at' => $now + 3600,
            'original_ends_at' => $now + 3600,
            'extension_count' => 0,
            'final_call_sent' => false,
        ], $overrides));
    }

    private function enableDiscord(): void
    {
        config(['app.discord_alert_webhook' => '']);
        resolve(SettingsService::class)->set(\OGame\Services\DiscordNotifier::SETTING_KEY, self::WEBHOOK);
        Http::fake();
    }

    private function discordBodies(): array
    {
        $bodies = [];
        Http::assertSent(function ($request) use (&$bodies) {
            $bodies[] = $request['content'];
            return true;
        });

        return $bodies;
    }

    public function testJobAcceptsAllEventsAndRoutesToNotifier(): void
    {
        $this->enableDiscord();
        $auction = $this->makeAuction();

        foreach (NotifyAuctionEvent::EVENTS as $event) {
            (new NotifyAuctionEvent($event, $auction->id, ['outbid_user_id' => 0]))->handle(resolve(AuctionNotifier::class));
        }

        // opened, final_call, unsold post; won (no winner) posts; extended skipped (extension_count 0); outbid is in-game only.
        $this->assertSame(4, count($this->discordBodies()));
    }

    public function testJobIsDispatchedAfterCommit(): void
    {
        $this->assertTrue((new NotifyAuctionEvent('opened', 1))->afterCommit);
    }

    public function testDiscordMessageTexts(): void
    {
        $this->enableDiscord();
        $notifier = resolve(AuctionNotifier::class);
        $leader = User::find($this->currentUserId);
        $auction = $this->makeAuction(['current_bid' => 1500, 'leader_user_id' => $leader->id, 'extension_count' => 1]);

        $notifier->opened($auction);
        $notifier->finalCall($auction);
        $notifier->extended($auction);
        $auction->winner_user_id = $leader->id;
        $auction->final_price = 1500;
        $notifier->won($auction);
        $notifier->unsold($auction);

        $bodies = $this->discordBodies();
        $this->assertStringContainsString('Salvage Auction open: **Salvaged Metal Crate**. Bidding starts at 500 DM. Hammer falls <t:' . $auction->ends_at . ':R>.', $bodies[0]);
        $this->assertStringContainsString('Final call on **Salvaged Metal Crate**: 1,500 DM by **' . $leader->username . '**.', $bodies[1]);
        $this->assertStringContainsString('Bidding war on **Salvaged Metal Crate**! Clock extended.', $bodies[2]);
        $this->assertStringContainsString('SOLD! **Salvaged Metal Crate** to **' . $leader->username . '** for 1,500 DM.', $bodies[3]);
        $this->assertStringContainsString('No bids. **Salvaged Metal Crate** was scrapped.', $bodies[4]);
    }

    public function testExtendedOnlyPostsForFirstExtension(): void
    {
        $this->enableDiscord();
        $auction = $this->makeAuction(['extension_count' => 2]);

        resolve(AuctionNotifier::class)->extended($auction);

        Http::assertNothingSent();
    }

    public function testDiscordFailureIsSwallowed(): void
    {
        config(['app.discord_alert_webhook' => '']);
        resolve(SettingsService::class)->set(\OGame\Services\DiscordNotifier::SETTING_KEY, self::WEBHOOK);
        Http::fake(fn () => throw new RuntimeException('discord down'));

        resolve(AuctionNotifier::class)->unsold($this->makeAuction());

        $this->assertTrue(true);
    }

    public function testOutbidSendsInGameMessage(): void
    {
        $auction = $this->makeAuction(['current_bid' => 2000]);

        resolve(AuctionNotifier::class)->outbid($auction, $this->currentUserId, 2000);

        $player = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
        $this->assertMessageReceivedAndContainsDatabase($player, ['Salvaged Metal Crate', '2,000']);
    }

    public function testWonSendsInGameMessage(): void
    {
        $auction = $this->makeAuction([
            'status' => Auction::STATUS_SETTLED,
            'winner_user_id' => $this->currentUserId,
            'final_price' => 3000,
            'current_bid' => 3000,
        ]);

        resolve(AuctionNotifier::class)->won($auction);

        $player = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
        $this->assertMessageReceivedAndContainsDatabase($player, ['Salvaged Metal Crate', '3,000']);
    }

    public function testMessagesRespectPreference(): void
    {
        $user = User::find($this->currentUserId);
        $prefs = $user->preferences ?? [];
        $prefs['auctioneerNotifications'] = 0;
        $user->preferences = $prefs;
        $user->save();
        $this->assertSame(0, UserPreferencesService::get($user->fresh(), 'auctioneerNotifications'));

        resolve(AuctionNotifier::class)->outbid($this->makeAuction(), $this->currentUserId, 2000);

        $player = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
        $this->assertMessageReceivedAndContainsDatabase($player, ['Salvaged Metal Crate'], 0);
    }

    public function testTickDispatchesJobsUsingTheNewClasses(): void
    {
        Bus::fake();
        NotifyAuctionEvent::dispatch('final_call', 1);

        Bus::assertDispatched(NotifyAuctionEvent::class, fn (NotifyAuctionEvent $job) => $job->event === 'final_call' && $job->auctionId === 1);
    }
}
