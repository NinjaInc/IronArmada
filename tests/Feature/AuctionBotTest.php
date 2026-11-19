<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Mockery\MockInterface;
use OGame\Models\Auction;
use OGame\Models\User;
use OGame\Services\AuctionBotStrategy;
use OGame\Services\AuctionService;
use OGame\Services\BotService;
use OGame\Services\SettingsService;
use Tests\IsolatedAccountTestCase;

/**
 * Bot bidding strategy (docs/AUCTION-SPEC.md section 1.4).
 *
 * Fixed rolls are injected into consider() so the 25 % turn chance never makes these tests random.
 */
class AuctionBotTest extends IsolatedAccountTestCase
{
    /** Roll that always passes the turn chance. */
    private const float ACT = 0.0;

    private User $bot;

    protected function setUp(): void
    {
        parent::setUp();

        // Only one auction may be active at a time; clear anything leaked into the test database.
        Auction::query()->delete();

        $settings = resolve(SettingsService::class);
        $settings->set('auction_enabled', 1);
        $settings->set('auction_bots_enabled', 1);
        $settings->set('auction_snipe_window_seconds', 300);

        $this->bot = $this->makeBot('balanced', 10000);
    }

    /**
     * A bot with a given personality and dark matter balance.
     */
    private function makeBot(string $personality, int $darkMatter): User
    {
        $bot = app(BotService::class)->createBot($personality, 2);
        $bot->dark_matter = $darkMatter;
        $bot->save();

        return $bot;
    }

    /**
     * Opens an auction directly. Defaults: halfway through, 12 h gone of 22 h, lot worth 5M metal (ceiling 2000 DM
     * for a balanced bot), starting bid 600.
     *
     * @param array<string, mixed> $overrides
     */
    private function openAuction(array $overrides = []): Auction
    {
        $now = (int)Date::now()->timestamp;
        $endsAt = $overrides['ends_at'] ?? $now + 10 * 3600;

        return Auction::create(array_merge([
            'auction_date' => '2024-01-01',
            'status' => Auction::STATUS_ACTIVE,
            'active_slot' => 1,
            'lot_key' => 'crate_metal',
            'lot_payload' => ['resources' => ['metal' => 5000000, 'crystal' => 0, 'deuterium' => 0], 'units' => []],
            'starting_bid' => 600,
            'min_increment_abs' => 50,
            'min_increment_pct' => 5,
            'current_bid' => 0,
            'leader_user_id' => null,
            'bid_count' => 0,
            'starts_at' => $now - 12 * 3600,
            'ends_at' => $endsAt,
            'original_ends_at' => $endsAt,
            'extension_count' => 0,
            'final_call_sent' => false,
        ], $overrides));
    }

    private function strategy(): AuctionBotStrategy
    {
        return app(AuctionBotStrategy::class);
    }

    public function testBotBidsMinimumNextBidThroughPlaceBid(): void
    {
        $auction = $this->openAuction();
        $expected = app(AuctionService::class)->minimumNextBid($auction);

        $this->strategy()->consider($this->bot, self::ACT);

        $auction->refresh();
        $this->assertSame($this->bot->id, (int)$auction->leader_user_id);
        $this->assertSame($expected, $auction->current_bid);
        $this->assertSame(1, $auction->bid_count);
        // The DM really left the bot's account (escrow), via the ledger-backed path.
        $this->assertSame(10000 - $expected, (int)User::whereKey($this->bot->id)->value('dark_matter'));
        $this->assertSame([], app(AuctionService::class)->checkInvariants($auction->id));
    }

    public function testBotOutbidsPlayerAtMinimumIncrementAndRefundsThem(): void
    {
        $auction = $this->openAuction();
        $service = app(AuctionService::class);

        $human = User::findOrFail($this->currentUserId);
        $human->dark_matter = 5000;
        $human->save();
        $this->assertTrue($service->placeBid($human, $auction->id, 700)->accepted);

        $this->strategy()->consider($this->bot, self::ACT);

        $auction->refresh();
        $this->assertSame($this->bot->id, (int)$auction->leader_user_id);
        // max(700 + max(50, ceil(5 %)), 600) = 750: exactly the minimum increment, nothing more.
        $this->assertSame(750, $auction->current_bid);
        $this->assertSame(5000, (int)User::whereKey($human->id)->value('dark_matter'), 'Outbid player is refunded in full.');
        $this->assertSame([], $service->checkInvariants($auction->id));
    }

    public function testBotNeverOutbidsItself(): void
    {
        $auction = $this->openAuction();

        $this->strategy()->consider($this->bot, self::ACT);
        $this->strategy()->consider($this->bot, self::ACT);
        $this->strategy()->consider($this->bot, self::ACT);

        $auction->refresh();
        $this->assertSame(1, $auction->bid_count);
        $this->assertSame($this->bot->id, (int)$auction->leader_user_id);
    }

    public function testNothingHappensWhenBotsSettingIsOff(): void
    {
        resolve(SettingsService::class)->set('auction_bots_enabled', 0);
        $auction = $this->openAuction();

        $this->strategy()->consider($this->bot, self::ACT);

        $this->assertSame(0, $auction->refresh()->bid_count);
    }

    public function testNothingHappensWhenAuctionsAreDisabled(): void
    {
        resolve(SettingsService::class)->set('auction_enabled', 0);
        $auction = $this->openAuction();

        $this->strategy()->consider($this->bot, self::ACT);

        $this->assertSame(0, $auction->refresh()->bid_count);
    }

    public function testBotsOnlyActOnTheTurnChance(): void
    {
        $auction = $this->openAuction();

        $this->strategy()->consider($this->bot, 0.25);
        $this->strategy()->consider($this->bot, 0.99);
        $this->assertSame(0, $auction->refresh()->bid_count);

        $this->strategy()->consider($this->bot, 0.2499);
        $this->assertSame(1, $auction->refresh()->bid_count);
    }

    public function testBotsStayOutOfTheFirstHalf(): void
    {
        $now = (int)Date::now()->timestamp;
        // 1 h gone of a 22 h auction.
        $auction = $this->openAuction(['starts_at' => $now - 3600, 'ends_at' => $now + 21 * 3600]);

        $this->strategy()->consider($this->bot, self::ACT);

        $this->assertSame(0, $auction->refresh()->bid_count);
    }

    public function testBotsNeverBidInsideTheSnipeWindow(): void
    {
        $now = (int)Date::now()->timestamp;
        $auction = $this->openAuction(['starts_at' => $now - 22 * 3600, 'ends_at' => $now + 299]);

        $this->strategy()->consider($this->bot, self::ACT);

        $auction->refresh();
        $this->assertSame(0, $auction->bid_count);
        $this->assertSame(0, $auction->extension_count);
    }

    public function testBotDoesNotBidAboveItsValuation(): void
    {
        // 1M metal = 400 DM for a balanced bot, below the 600 DM starting bid.
        $auction = $this->openAuction([
            'lot_payload' => ['resources' => ['metal' => 1000000, 'crystal' => 0, 'deuterium' => 0], 'units' => []],
        ]);

        $this->strategy()->consider($this->bot, self::ACT);

        $this->assertSame(0, $auction->refresh()->bid_count);
    }

    public function testPersonalityScalesTheValuationCeiling(): void
    {
        // 1.6M metal = 640 DM: a miner (x1.2 = 768) bids the 600 start, a turtle (x0.7 = 448) does not.
        $auction = $this->openAuction([
            'lot_payload' => ['resources' => ['metal' => 1600000, 'crystal' => 0, 'deuterium' => 0], 'units' => []],
        ]);

        $this->strategy()->consider($this->makeBot('turtle', 10000), self::ACT);
        $this->assertSame(0, $auction->refresh()->bid_count);

        $miner = $this->makeBot('miner', 10000);
        $this->strategy()->consider($miner, self::ACT);
        $this->assertSame(1, $auction->refresh()->bid_count);
        $this->assertSame($miner->id, (int)$auction->leader_user_id);
    }

    public function testBotNeverCommitsMoreThanFortyPercentOfItsBalance(): void
    {
        $auction = $this->openAuction();

        // Bid would be 600 DM; 40 % of 1400 is 560.
        $poor = $this->makeBot('balanced', 1400);
        $this->strategy()->consider($poor, self::ACT);
        $this->assertSame(0, $auction->refresh()->bid_count);

        // 40 % of 1500 is exactly 600.
        $enough = $this->makeBot('balanced', 1500);
        $this->strategy()->consider($enough, self::ACT);
        $this->assertSame(1, $auction->refresh()->bid_count);
        $this->assertSame(900, (int)User::whereKey($enough->id)->value('dark_matter'));
    }

    public function testBotWithoutDarkMatterDoesNothing(): void
    {
        $auction = $this->openAuction();

        $this->strategy()->consider($this->makeBot('balanced', 0), self::ACT);

        $this->assertSame(0, $auction->refresh()->bid_count);
    }

    public function testConsiderDoesNothingWithoutAnActiveAuctionAndNeverThrows(): void
    {
        $this->strategy()->consider($this->bot, self::ACT);

        $this->assertSame(10000, (int)User::whereKey($this->bot->id)->value('dark_matter'));
    }

    public function testConsiderIgnoresNonBotUsers(): void
    {
        $auction = $this->openAuction();
        $human = User::findOrFail($this->currentUserId);
        $human->dark_matter = 10000;
        $human->save();

        $this->strategy()->consider($human, self::ACT);

        $this->assertSame(0, $auction->refresh()->bid_count);
    }

    public function testTakeTurnCallsTheStrategyOnlyWhenBotsSettingIsOn(): void
    {
        $this->mock(AuctionBotStrategy::class, function (MockInterface $mock): void {
            $mock->shouldReceive('consider')->once();
        });

        app(BotService::class)->takeTurn($this->bot);

        resolve(SettingsService::class)->set('auction_bots_enabled', 0);
        // Mockery's once() above fails the test if this second turn calls consider() again.
        app(BotService::class)->takeTurn($this->bot);
    }
}
