<?php

namespace OGame\Console\Commands\Test;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use OGame\Actions\Fortify\CreateNewUser;
use OGame\Enums\DarkMatterTransactionType;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Auction;
use OGame\Models\AuctionBid;
use OGame\Models\DarkMatterTransaction;
use OGame\Models\Planet;
use OGame\Models\User;
use OGame\Services\AuctionService;
use OGame\Services\SettingsService;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Class TestRaceConditionAuctionBid.
 *
 * Fires parallel HTTP requests at the Armada Salvage Auction bid and claim endpoints and asserts that no sequence of
 * concurrent bids, settlements and claims can create or destroy dark matter or deliver a lot twice
 * (docs/AUCTION-SPEC.md section 4.8).
 *
 * Scenarios, each run on a freshly created auction:
 *  A. double submit: 20 parallel bids of the same amount from one user.
 *  B. bid vs settle: parallel bids from several users while a second PHP process runs settleIfDue in a loop.
 *  C. double claim: 20 parallel claim POSTs for one settled auction.
 *
 * The command refuses to run (exit code 1, nothing touched) if the auction routes are not registered, a real
 * auction is currently active, the scheduler or a queue worker looks to be running, or a Discord webhook is set in
 * the environment. It temporarily switches `auction_enabled` on and clears the Discord webhook setting, and restores
 * both (and every other setting it touched) in a finally block. Because `auction_enabled` is a real shared setting,
 * a running scheduler would open real auctions and post test auctions to Discord, so the operator must stop the
 * scheduler and queue worker first and confirm with --scheduler-stopped. Not for production use.
 */
#[Description('Issue parallel requests to test race conditions for auction bids, settlement and claims.')]
#[Signature('ogamex:test:race-condition-auction-bid {--bidders=4 : Number of test users for scenario B (the main test user is excluded from B, see scenarioBidVersusSettle)} {--scheduler-stopped : Required. Confirms the scheduler (schedule:work / cron) and queue workers are stopped} {--settle-worker= : Internal: auction id for the settle loop worker process} {--settle-seconds=8 : Internal: runtime of the settle loop worker}')]
class TestRaceConditionAuctionBid extends TestCommand
{
    /**
     * @var string The email of the test user (the main bidder and the winner in scenario C).
     */
    protected string $email = 'raceconditionauctionbid@test.com';

    /** Marker stored in auctions.lot_key so test auctions can always be found and removed. */
    private const string TEST_LOT_KEY = 'race_test';

    private const int START_BALANCE = 10000;

    /** @var array<int, string> Emails of the extra users created by this run. */
    private array $extraEmails = [];

    /** Setting key holding the Discord webhook (cleared during the run so nothing is ever posted). */
    private const string WEBHOOK_SETTING = 'discord_webhook_url';

    /** @var array<string, string|null> Setting key => original raw value (null: row did not exist), restored on exit. */
    private array $originalRaw = [];

    private Process|null $settleWorker = null;

    /** @var int Counter used to give every test auction its own unique auction_date. */
    private int $dateCounter = 0;

    /**
     * Main entry point for the command.
     *
     * @throws Exception|GuzzleException
     */
    public function handle(): int
    {
        // Worker mode: loop settleIfDue from a separate process (real parallel DB connection) and exit.
        $worker = $this->option('settle-worker');
        if (is_string($worker) && $worker !== '') {
            return $this->runSettleWorker((int)$worker, (int)$this->option('settle-seconds'));
        }

        if (App::environment('production')) {
            $this->error('This test must not run in a production environment. Exiting...');
            return 1;
        }

        foreach (['merchant.auction.bid', 'merchant.auction.claim'] as $routeName) {
            if (!Route::has($routeName)) {
                $this->error("Route '{$routeName}' is not registered. The auction controller and routes (AUCTION-SPEC 3.3/3.4) must exist before this test can run. Nothing was changed. Exiting...");
                return 1;
            }
        }

        if (Auction::where('status', Auction::STATUS_ACTIVE)->where('lot_key', '!=', self::TEST_LOT_KEY)->exists()) {
            $this->error('A real auction is currently active. Close or cancel it before running this test. Nothing was changed. Exiting...');
            return 1;
        }

        // The command flips the real `auction_enabled` setting. A running scheduler would then open a real daily
        // auction (which makes createAuction() hit the active_slot unique index) and its notification jobs would post
        // the race_test auctions to Discord. Refuse unless the operator confirms the scheduler and workers are down.
        if (!$this->option('scheduler-stopped')) {
            $this->error('Refusing to run: this command temporarily enables auction_enabled in the real settings. Stop the scheduler (schedule:work / cron) and all queue workers, then re-run with --scheduler-stopped. Nothing was changed. Exiting...');
            return 1;
        }
        $running = $this->detectRunningScheduler();
        if ($running !== null) {
            $this->error("Refusing to run: a scheduler or queue worker process appears to be running here ({$running}). Stop it first. Nothing was changed. Exiting...");
            return 1;
        }
        // A webhook from the environment cannot be cleared from inside this process (other processes read their own
        // env), so it has to be unset by the operator.
        if ((string)config('app.discord_alert_webhook', '') !== '') {
            $this->error('Refusing to run: DISCORD_ALERT_WEBHOOK is set in the environment, so test auctions could be posted to Discord. Unset it for this run. Nothing was changed. Exiting...');
            return 1;
        }

        $bidders = max(1, (int)$this->option('bidders'));
        $failed = false;

        try {
            $this->removeStaleTestData();
            // Clear the stored Discord webhook for the whole run (restored in finally) so no job can post.
            $this->overrideSetting(self::WEBHOOK_SETTING, '');
            $this->overrideSetting('auction_enabled', '1');

            for ($i = 0; $i < $this->numberOfIterations && !$failed; $i++) {
                $this->info("Running test iteration... $i");

                // Creates the main test user, a second planet and logs in over HTTP.
                parent::setup();

                $leader = $this->createExtraUser('raceconditionauctionbid-leader@test.com');
                $clients = [$this->httpClient];
                for ($b = 1; $b < $bidders; $b++) {
                    $email = "raceconditionauctionbid-bidder{$b}@test.com";
                    $this->createExtraUser($email);
                    $clients[] = $this->createLoggedInClient($email);
                }

                $failed = !$this->scenarioDoubleSubmit($leader)
                    || !$this->scenarioBidVersusSettle($leader, $clients)
                    || !$this->scenarioDoubleClaim($leader);

                $this->cleanup();
            }
        } catch (Exception $e) {
            $this->error('Test aborted with an unexpected error: ' . $e->getMessage());
            $failed = true;
        } finally {
            $this->stopSettleWorker();
            try {
                $this->cleanup();
            } catch (Throwable $e) {
                $this->error('Cleanup failed: ' . $e->getMessage());
            }
            // Always restore the real settings, even if cleanup or the run blew up.
            $this->restoreSettings();
        }

        if ($failed) {
            $this->error('FAIL: auction race condition test failed. Check for race conditions!');
            return 1;
        }

        $this->info('PASS: auction race condition test passed.');
        return 0;
    }

    /**
     * Scenario A: 20 parallel bids of the same amount from the same user.
     *
     * @throws GuzzleException|Exception
     */
    private function scenarioDoubleSubmit(User $leader): bool
    {
        $this->info('--- Scenario A: double submit ---');
        $main = $this->mainUser();
        $this->resetBalances([$main, $leader]);

        $auction = $this->createAuction(Date::now()->timestamp + 3600);
        $leaderBid = resolve(AuctionService::class)->placeBid($leader, $auction->id, 500);
        if (!$leaderBid->accepted) {
            return $this->fail("Setup: leader bid of 500 was rejected ({$leaderBid->errorKey}).");
        }

        $payloads = array_fill(0, 20, ['auction_id' => $auction->id, 'amount' => 600]);
        $results = $this->runParallelPostRequests('ajax/merchant/auction/bid', $payloads);
        $accepted = $this->countAccepted($results);

        $ok = $this->checkRequestStatuses($results);

        $leaderBalance = $this->balance($leader);
        $mainBalance = $this->balance($main);
        $refunds = DarkMatterTransaction::where('type', DarkMatterTransactionType::AUCTION_REFUND->value)
            ->where('description', 'like', "Auction #{$auction->id} %")
            ->count();

        $ok = $this->expect($accepted === 1, "exactly 1 bid accepted (got {$accepted})") && $ok;
        $ok = $this->expect($refunds === 1, "leader refunded exactly once (got {$refunds} refund transactions)") && $ok;
        $ok = $this->expect($leaderBalance === self::START_BALANCE, 'leader balance back at ' . self::START_BALANCE . " (got {$leaderBalance})") && $ok;
        $ok = $this->expect($mainBalance === self::START_BALANCE - 600, 'bidder balance at ' . (self::START_BALANCE - 600) . " (got {$mainBalance})") && $ok;

        return $this->checkInvariants($auction->id) && $ok;
    }

    /**
     * Scenario B: bids from several users race against settlement run by a separate process.
     *
     * @param array<int, Client> $clients Logged-in clients of the bidders (first one is the main test user).
     * @throws GuzzleException|Exception
     */
    private function scenarioBidVersusSettle(User $leader, array $clients): bool
    {
        $this->info('--- Scenario B: bid vs settle ---');
        $users = [$this->mainUser(), $leader];
        foreach ($this->extraEmails as $email) {
            $user = User::where('email', $email)->first();
            if ($user !== null && $user->id !== $leader->id) {
                $users[] = $user;
            }
        }
        $this->resetBalances($users);
        $balancesBefore = $this->totalBalance($users);

        // Anti-snipe extension would push ends_at away from every bid, so no bid could ever race the settlement.
        $this->overrideSetting('auction_snipe_window_seconds', '0');

        // The leader bids through the service so the escrow state is consistent from the start. ends_at starts far
        // away and is pulled in right before the bid burst, so it is relative to when the burst begins.
        $auction = $this->createAuction(Date::now()->timestamp + 3600);
        $leaderBid = resolve(AuctionService::class)->placeBid($leader, $auction->id, 500);
        if (!$leaderBid->accepted) {
            return $this->fail("Setup: leader bid of 500 was rejected ({$leaderBid->errorKey}).");
        }

        // Scenario A already used the main user's 20 requests of throttle:20,1 (per user per minute), so the main
        // user is left out here: its bids would all be 429. Use only the other bidders, each at most 20 requests.
        $bidClients = array_slice($clients, 1);
        if ($bidClients === []) {
            $this->warn('Only one bidder configured: scenario B reuses the main user and will be partly throttled. Use --bidders=2 or more.');
            $bidClients = $clients;
        }
        $payloadCount = min(20, 20 * count($bidClients));

        // Start the settle loop in its own process first so it is already polling when the bids land.
        $this->settleWorker = new Process([
            PHP_BINARY,
            base_path('artisan'),
            'ogamex:test:race-condition-auction-bid',
            '--settle-worker=' . $auction->id,
            '--settle-seconds=15',
        ]);
        $this->settleWorker->start();
        usleep(500000);

        // Two waves of bids with distinct, increasing amounts. The auction ends 2 s after the burst starts: wave 1
        // goes out immediately, wave 2 is fired just before ends_at so its requests overlap the settlement.
        $burstStart = (int)Date::now()->timestamp;
        $endsAt = $burstStart + 2;
        Auction::whereKey($auction->id)->update(['ends_at' => $endsAt, 'original_ends_at' => $endsAt]);

        $firstWave = [];
        $secondWave = [];
        for ($i = 0; $i < $payloadCount; $i++) {
            $payload = ['auction_id' => $auction->id, 'amount' => 700 + $i * 60];
            if ($i < intdiv($payloadCount, 2)) {
                $firstWave[] = $payload;
            } else {
                $secondWave[] = $payload;
            }
        }
        $results = $this->runParallelPostRequests('ajax/merchant/auction/bid', $firstWave, $bidClients);
        $wait = $endsAt - microtime(true) - 0.15;
        if ($wait > 0) {
            usleep((int)($wait * 1000000));
        }
        $results = array_merge($results, $this->runParallelPostRequests('ajax/merchant/auction/bid', $secondWave, $bidClients));

        $closed = 0;
        foreach ($results as $result) {
            if (($result['json']['success'] ?? null) === false && ($result['json']['message'] ?? '') === __('t_merchant.auction.error.closed')) {
                $closed++;
            }
        }
        $this->info('Bids accepted: ' . $this->countAccepted($results) . ' of ' . count($results) . ", rejected as closed: {$closed}");
        if ($closed === 0) {
            $this->warn('No bid was rejected as closed: the burst finished before the auction ended, so this iteration did not overlap settlement. Treat as inconclusive and re-run.');
        }
        $ok = $this->checkRequestStatuses($results);

        // Make sure the auction ends and is settled even if the worker was late (idempotent).
        $service = resolve(AuctionService::class);
        $deadline = time() + 15;
        while (time() < $deadline) {
            $fresh = Auction::find($auction->id);
            if ($fresh !== null && $fresh->status !== Auction::STATUS_ACTIVE) {
                break;
            }
            $service->settleIfDue($auction->id);
            usleep(200000);
        }
        $this->settleWorker->wait();
        $this->settleWorker = null;

        $final = Auction::find($auction->id);
        $ok = $this->expect($final !== null && $final->status !== Auction::STATUS_ACTIVE, 'auction left the active state') && $ok;

        $balancesAfter = $this->totalBalance($users);
        $held = (int)AuctionBid::where('auction_id', $auction->id)->whereNull('refunded_at')->sum('amount');
        $ok = $this->expect(
            $balancesBefore === $balancesAfter + $held,
            "dark matter conserved: before {$balancesBefore} == after {$balancesAfter} + held {$held}"
        ) && $ok;

        $this->restoreSetting('auction_snipe_window_seconds');

        return $this->checkInvariants($auction->id) && $ok;
    }

    /**
     * Scenario C: 20 parallel claim requests for one settled auction.
     *
     * @throws GuzzleException|Exception
     */
    private function scenarioDoubleClaim(User $leader): bool
    {
        $this->info('--- Scenario C: double claim ---');
        $main = $this->mainUser();
        $this->resetBalances([$main, $leader]);
        $lotMetal = 10000000;

        $auction = $this->createAuction(Date::now()->timestamp + 3600, $lotMetal);
        $service = resolve(AuctionService::class);
        $bid = $service->placeBid($main, $auction->id, 800);
        if (!$bid->accepted) {
            return $this->fail("Setup: winner bid of 800 was rejected ({$bid->errorKey}).");
        }

        // End the auction now and settle it.
        Auction::whereKey($auction->id)->update(['ends_at' => Date::now()->timestamp - 1]);
        $service->settleIfDue($auction->id);
        $settled = Auction::find($auction->id);
        if ($settled === null || $settled->status !== Auction::STATUS_SETTLED || (int)$settled->winner_user_id !== $main->id) {
            return $this->fail('Setup: auction did not settle for the test user.');
        }

        $planetId = $this->currentPlanetService->getPlanetId();
        $metalBefore = (int)Planet::whereKey($planetId)->value('metal');

        $payloads = array_fill(0, 20, ['auction_id' => $auction->id, 'planet_id' => $planetId]);
        $results = $this->runParallelPostRequests('merchant/auction/claim', $payloads);
        $ok = $this->checkRequestStatuses($results, true);

        $claimed = Auction::find($auction->id);
        $metalAfter = (int)Planet::whereKey($planetId)->value('metal');
        $delta = $metalAfter - $metalBefore;

        $ok = $this->expect($claimed !== null && $claimed->claimed_at !== null, 'claimed_at is set') && $ok;
        $ok = $this->expect($claimed !== null && (int)$claimed->claimed_planet_id === $planetId, 'claimed_planet_id is the target planet') && $ok;
        // Planet production adds a little on top; a double delivery would add at least a second full lot.
        $ok = $this->expect($delta >= $lotMetal && $delta < 2 * $lotMetal, "planet metal rose by one lot of {$lotMetal} (delta {$delta})") && $ok;

        return $this->checkInvariants($auction->id) && $ok;
    }

    /**
     * Worker process: settle in a tight loop until the auction closes or the time is up.
     */
    private function runSettleWorker(int $auctionId, int $seconds): int
    {
        $service = resolve(AuctionService::class);
        $deadline = microtime(true) + max(1, $seconds);
        while (microtime(true) < $deadline) {
            $service->settleIfDue($auctionId);
            $auction = Auction::find($auctionId);
            if ($auction === null || $auction->status !== Auction::STATUS_ACTIVE) {
                break;
            }
            usleep(10000);
        }

        return 0;
    }

    /**
     * Run AuctionService::checkInvariants and print the result.
     */
    private function checkInvariants(int $auctionId): bool
    {
        $violations = resolve(AuctionService::class)->checkInvariants($auctionId);
        if ($violations === []) {
            $this->info("[OK] checkInvariants(#{$auctionId}) returned no violations.");
            return true;
        }
        foreach ($violations as $violation) {
            $this->error('[ERROR] Invariant violated: ' . $violation);
        }
        return false;
    }

    /**
     * Any failed request or 5xx is a failure. 429 (throttle) is reported as a warning; the assertions that follow
     * then show whether the outcome is still consistent.
     *
     * @param array<int, array{status: int, json: array<string, mixed>|null, body: string}> $results
     * @param bool $redirectsOk Claim answers with a redirect, so 3xx is a normal outcome.
     */
    private function checkRequestStatuses(array $results, bool $redirectsOk = false): bool
    {
        $counts = [];
        foreach ($results as $result) {
            $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;
        }
        ksort($counts);
        $parts = [];
        foreach ($counts as $status => $n) {
            $parts[] = "{$status}: {$n}";
        }
        $this->info('HTTP statuses: ' . implode(', ', $parts));

        $bad = 0;
        foreach ($results as $result) {
            $status = $result['status'];
            if ($status === 0 || $status >= 500 || (!$redirectsOk && $status >= 300 && $status !== 422 && $status !== 429)) {
                $bad++;
            }
        }
        if (isset($counts[429])) {
            $this->warn('Some requests were throttled (429). Counts below may be off; lower --bidders or check the throttle.');
        }

        return $this->expect($bad === 0, 'no failed or 5xx responses (' . $bad . ' bad)');
    }

    /**
     * @param array<int, array{status: int, json: array<string, mixed>|null, body: string}> $results
     */
    private function countAccepted(array $results): int
    {
        $accepted = 0;
        foreach ($results as $result) {
            if ($result['status'] === 200 && ($result['json']['success'] ?? false) === true) {
                $accepted++;
            }
        }
        return $accepted;
    }

    private function expect(bool $condition, string $message): bool
    {
        if ($condition) {
            $this->info("[OK] {$message}");
        } else {
            $this->error("[ERROR] Expected: {$message}");
        }
        return $condition;
    }

    private function fail(string $message): bool
    {
        $this->error("[ERROR] {$message}");
        return false;
    }

    private function mainUser(): User
    {
        return User::findOrFail($this->playerService->getId());
    }

    /**
     * @throws ValidationException
     */
    private function createExtraUser(string $email): User
    {
        $this->deleteUserByEmail($email);
        $user = resolve(CreateNewUser::class)->create(['email' => $email, 'password' => $this->password]);
        $this->extraEmails[] = $email;

        return $user;
    }

    private function deleteUserByEmail(string $email): void
    {
        $user = User::where('email', $email)->first();
        if ($user !== null) {
            resolve(PlayerServiceFactory::class)->make($user->id)->delete();
        }
    }

    /**
     * @param array<int, User> $users
     */
    private function resetBalances(array $users): void
    {
        foreach ($users as $user) {
            User::whereKey($user->id)->update(['dark_matter' => self::START_BALANCE]);
        }
    }

    /**
     * @param array<int, User> $users
     */
    private function totalBalance(array $users): int
    {
        return (int)User::whereIn('id', array_map(fn (User $user) => $user->id, $users))->sum('dark_matter');
    }

    private function balance(User $user): int
    {
        return (int)User::whereKey($user->id)->value('dark_matter');
    }

    /**
     * Create an active auction directly (current_bid 0, starting_bid 100), with its own unique auction_date.
     */
    private function createAuction(int $endsAt, int $lotMetal = 1000): Auction
    {
        $now = (int)Date::now()->timestamp;
        $this->dateCounter++;

        return Auction::create([
            'auction_date' => Date::create(2001, 1, 1)->addDays($this->dateCounter)->format('Y-m-d'),
            'status' => Auction::STATUS_ACTIVE,
            'active_slot' => 1,
            'lot_key' => self::TEST_LOT_KEY,
            'lot_payload' => ['resources' => ['metal' => $lotMetal, 'crystal' => 0, 'deuterium' => 0], 'units' => []],
            'starting_bid' => 100,
            'min_increment_abs' => 50,
            'min_increment_pct' => 5,
            'current_bid' => 0,
            'leader_user_id' => null,
            'bid_count' => 0,
            'starts_at' => $now - 60,
            'ends_at' => $endsAt,
            'original_ends_at' => $endsAt,
            'extension_count' => 0,
            'final_call_sent' => false,
        ]);
    }

    private function overrideSetting(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->originalRaw)) {
            $raw = DB::table('settings')->where('key', $key)->value('value');
            $this->originalRaw[$key] = $raw === null ? null : (string)$raw;
        }
        // The database row is the source of truth for other processes (SettingsService::get() treats a stored "0"
        // or "" as unset and set() skips writes it considers unchanged), so write through the query builder too.
        resolve(SettingsService::class)->set($key, $value);
        if (DB::table('settings')->where('key', $key)->exists()) {
            DB::table('settings')->where('key', $key)->update(['value' => $value]);
        } else {
            DB::table('settings')->insert(['key' => $key, 'value' => $value]);
        }
    }

    private function restoreSetting(string $key): void
    {
        if (!array_key_exists($key, $this->originalRaw)) {
            return;
        }
        $original = $this->originalRaw[$key];
        unset($this->originalRaw[$key]);
        if ($original === null) {
            DB::table('settings')->where('key', $key)->delete();
        } else {
            DB::table('settings')->where('key', $key)->update(['value' => $original]);
        }
    }

    private function restoreSettings(): void
    {
        foreach (array_keys($this->originalRaw) as $key) {
            try {
                $this->restoreSetting($key);
            } catch (Throwable $e) {
                $this->error("FAILED to restore setting '{$key}': " . $e->getMessage() . ' Restore it manually.');
            }
        }
    }

    private function stopSettleWorker(): void
    {
        if ($this->settleWorker !== null && $this->settleWorker->isRunning()) {
            $this->settleWorker->stop(2);
        }
        $this->settleWorker = null;
    }

    /**
     * Best effort: names a scheduler or queue worker process visible from this container, or null.
     */
    private function detectRunningScheduler(): string|null
    {
        try {
            $process = new Process(['pgrep', '-af', 'schedule:work|schedule:run|queue:work|queue:listen|horizon']);
            $process->run();
            if ($process->getExitCode() === 0) {
                foreach (explode("\n", trim($process->getOutput())) as $line) {
                    // Ignore pgrep itself and this command.
                    if ($line !== '' && !str_contains($line, 'pgrep') && !str_contains($line, 'race-condition-auction-bid')) {
                        return $line;
                    }
                }
            }
        } catch (Throwable) {
            // pgrep unavailable: rely on the --scheduler-stopped confirmation.
        }

        return null;
    }

    /**
     * Remove test auctions (bids cascade), their ledger rows and the test users.
     */
    private function cleanup(): void
    {
        $this->removeTestAuctions();
        foreach ($this->extraEmails as $email) {
            $this->deleteUserByEmail($email);
        }
        $this->extraEmails = [];
        $this->deleteUserByEmail($this->email);
    }

    private function removeStaleTestData(): void
    {
        $this->removeTestAuctions();
        $this->deleteUserByEmail('raceconditionauctionbid-leader@test.com');
        for ($b = 1; $b <= 20; $b++) {
            $this->deleteUserByEmail("raceconditionauctionbid-bidder{$b}@test.com");
        }
    }

    private function removeTestAuctions(): void
    {
        $ids = Auction::where('lot_key', self::TEST_LOT_KEY)->pluck('id');
        foreach ($ids as $id) {
            DarkMatterTransaction::whereIn('type', [
                DarkMatterTransactionType::AUCTION_BID->value,
                DarkMatterTransactionType::AUCTION_REFUND->value,
            ])->where('description', 'like', "Auction #{$id} %")->delete();
        }
        Auction::where('lot_key', self::TEST_LOT_KEY)->delete();
    }
}
