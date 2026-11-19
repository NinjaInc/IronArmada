# Auction T17: final independent review of the Armada Salvage Auction

Scope: `git diff main...night/auction-all` (head 92debdec, 63 files), read against `docs/AUCTION-SPEC.md` and
`docs/AUCTION-T5-REVIEW.md`. Branch for this review: `night/auction-final-review`.

**Nothing in this review has been executed.** PHP here is 8.3, the project needs ^8.5, `vendor/` is absent and Docker
is unavailable, so Pest, PHPStan, Rector, the race command and the migrations have never run. Every statement below
comes from reading code. Framework behaviour is assumed from Laravel 13 / MariaDB 11.3 InnoDB REPEATABLE READ, the same
basis T5 used.

## Verdict

**APPROVE WITH FIXES. Do not enable `auction_enabled` in production yet.**

I found no defect that can create, destroy or double-move dark matter, or deliver a lot twice. The escrow core
(`AuctionService`) is sound, and all six T5 fixes are applied correctly. None of them introduces a new deadlock,
double refund or double spend (section 1).

The blocking items are functional and process items, not money items:

- **F1 (High).** Settlement triggered by a page poll skips every `won`/`unsold` notification. With the 3 s poll in the
  final minutes, this will be the usual path.
- **F2 (Medium).** The bid response returns a stale dark matter balance, and `AuctionHttpTest` asserts the fresh value,
  so that test should fail.
- The whole test suite and the race command have never run. Section 4 lists what a human must run first.

Fix F1 to F4, then run the checklist, before switching the feature on.

---

## 1. T5 fixes: verified

| T5 item | Where | Verdict |
|---|---|---|
| F1 cancel: locking read of held bids plus `refunded_at IS NULL` guard | `AuctionService.php:333`, `:351-354` | **Correct.** `lockForUpdate()` reads the latest committed version whatever the snapshot, so the nested-transaction double refund in T5 F1 is closed. The guarded `UPDATE` and the `!== 1` check abort and roll back if anything already refunded the row. Lock order is auction X → bid range X → users X. `placeBid` uses auction X → users X → bid range X. Both orders start with the auction row lock, so they can never interleave, and no cycle is possible between them. |
| F2 lock-order docs and the "outside any transaction" rule | class docblock `:26-30`, spec 4.2, `BotService.php:258-271` | **Correct.** `BotService::considerAuction()` runs last in `takeTurn()`, after `PirateService`/`BotFleetService` return. No `DB::transaction` wraps `takeTurn` or `runDueBots`, so `placeBid` opens its own top-level transaction. |
| F3 `MAX_BID = 2147483647` | `:35`, `:156` | **Correct.** It matches the signed INT `users.dark_matter` / ledger columns. The comment at `:155` still says "unsigned 32 bit" (cosmetic). |
| F4 clamping plus `minimumNextBid` step ≥ 1 | `:76`, `:94-96`, `:116-117` | **Correct.** The minimum is always strictly above `current_bid`, so `unique(auction_id, amount)` can never throw for an accepted amount. Clamped values fit the unsigned/tinyint columns. |
| F4 `intSetting` reading stored `"0"` | `:591-602` | **Behaviour correct, premise wrong.** `SettingsService::get()` runs `empty()` on the cached `Setting` *model*, not on its value (`SettingsService.php:63`), so a stored `"0"` already comes back as `"0"`. The fallback query only runs when the key is missing or blank, so it is harmless dead weight. The same false premise appears in the comments in `TestRaceConditionAuctionBid.php:481-482` and `AuctionBidTest.php:439`. |
| F5 refund the held row, assert I1 fully | `:188-214` | **Correct.** The held row is read `FOR UPDATE` and checked against both leader and `current_bid`, and the credit uses the row amount. Minor: unlike `cancel()`, the guarded update at `:213` does not check its affected-row count. Under the lock it is always 1. |
| F6 `attempts: 3` | `:253`, `:284`, `:363` | **Safe.** Every decision inside the closures is a locking read, and `$winnerUserId` is re-assigned on each attempt. Laravel does not retry at nesting level > 1 (tests), which is correct. `claimForUser` (`:491`) still uses 1 attempt (see F11). |
| F7 `checkInvariants` shared locks | `:392-409` | **Correct, no new deadlock.** The S lock on the auction row cannot coexist with a writer's X lock, and every auction writer takes that X lock first, so a writer is either finished or waiting at its first statement. Locking reads create no read view, so the plain ledger `SUM` at `:465` takes its snapshot after the S locks are held and is consistent. The I1/I2 branches for a deleted leader or winner are right. |

Other money paths re-checked: bid ‖ bid, bid ‖ settle, settle ‖ cancel, claim ‖ claim, bot auto-claim ‖ human claim, and
bid ‖ account deletion (retried). Every other DM writer in `app/` (`HalvingService`, `DirectiveService`,
`DarkMatterService`) locks or atomically increments the users row. Nothing does an unlocked absolute
`dark_matter` write, so a refund cannot be lost. `BotService::runDueBots()`'s `$bot->save()` writes only dirty columns
and never `dark_matter`.

---

## 2. Findings, ranked by severity

### F1. High (functional, no money impact): settlement through a page poll skips the `won`/`unsold` notifications

`app/Http/Controllers/AuctionController.php:184-192` calls `settleIfDue()` and ignores the result.
`app/Console/Commands/Scheduler/AuctionTick.php:39-52` only notifies for ids that were still active when *it* looked.

Failing scenario: an auction ends at 16:00:00. Anyone with the page open polls every 3 s (`auction.blade.php:332`,
`:588`) and also force-polls at 0 s (`:390-394`). The poll at 16:00:01 settles the auction. At 16:01 the tick's
`$dueIds` is empty, so `settledCount` is 0 and no `NotifyAuctionEvent('won'|'unsold')` is ever dispatched. The winner
gets no in-game "Lot won" message, and Discord never gets the SOLD post. The same loss happens if a poll settles the
auction between the tick's `pluck` (`:40`) and `settleAllDue()` (`:41`). In a contested auction someone is almost
certainly watching, so this is the normal path, not an edge case.

Minimal fix: notify from whoever actually settled it. `settleIfDue()` already returns true only for the one caller
that settled.

```php
// AuctionController::buildState()
if ($auctions->settleIfDue($auction->id)) {
    $closed = Auction::find($auction->id);
    if ($closed !== null) {
        NotifyAuctionEvent::dispatch($closed->status === Auction::STATUS_SETTLED ? 'won' : 'unsold', $closed->id);
    }
}
```

A cleaner version moves the dispatch into `AuctionService::settleIfDue()` after the commit, next to `autoClaimForBot()`,
and drops the tick's re-read loop. Add a test: settle via `GET /ajax/merchant/auction/state` under `Bus::fake()` and
assert one `won` job.

### F2. Medium: the bid response reports the pre-bid dark matter balance, and `AuctionHttpTest` asserts the opposite

`AuctionController.php:177` reads `$player->getUser()->dark_matter`. That `User` was loaded by the `GlobalGame`
middleware before the bid. `placeBid` debits a different, freshly locked instance (`AuctionService.php:169`, `:199`),
so the request-scoped model is stale.

Failing scenario: `tests/Feature/AuctionHttpTest.php:190` posts a 1,000 DM bid with 5,000 DM and asserts
`state.dark_matter === 4000`. The response carries 5000, so **the test should fail**. In the UI the balance line
(`auction.blade.php:549`) shows the old balance until the next poll. A refunded leader who bids again sees the same
stale value.

Minimal fix: in `buildState()`, use `'dark_matter' => (int)User::whereKey($user->id)->value('dark_matter')`, or call
`$player->getUser()->refresh()` at the end of a successful `bid()`.

### F3. Medium: between auctions the page stops polling and can sleep for 24 hours across the daily open

`AuctionController.php:240-248` (`nextOpensAt`) and `auction.blade.php:475-487`, `:395-403`.

Failing scenario: a player has the page open at 17:59. At 18:00:00 the countdown reaches 0 and the script polls once
(`:398-402`). The tick runs `openIfDue()` a few seconds into the minute, so that poll still sees no auction. The server
answers `next_opens_at = tomorrow 18:00`, because `$opens > $now` is false (`:247`), and `showClosed()` sets
`stopped = true` again. The page shows "Opens in 23h 59m" next to an auction that opened seconds later.

Minimal fix: keep a slow poll (`POLL_SLOW`) while closed, instead of `stopped = true` in `showClosed()`. Or have
`nextOpensAt()` return today's open time while no auction exists for today and today's window has not passed.

### F4. Medium: disabling the feature freezes escrow and blocks winners from claiming lots they paid for

`AuctionController.php:135-137` (claim gated by `isEnabled()`), `:180-182` (state returns before `unclaimed` and before
settling), and `AuctionTick.php:32-35` (tick does nothing when disabled).

Failing scenario: an admin switches `auction_enabled` off to fix something while an auction is active, or after a
settled but unclaimed win. The leader's DM stays held with no settlement. A winner who has already paid the DM sees
"The Salvage Auction is offline" and has no claim form, for as long as the feature stays off. No DM is lost, and it all
resolves when the feature is re-enabled. But it is the opposite of what an admin expects from a kill switch, and the
admin docs do not mention it.

Minimal fix: let `claim()` and the `unclaimed` list work regardless of `isEnabled()`, since `claimForUser` is fully
lock-guarded. Document that disable means "pause: no new bids, no settlement" and that `ogamex:admin:auction-cancel`
(which ignores the flag) is the way to unwind an active auction.

### F5. Medium (test fidelity): the race command's scenario B rarely races bid against settle, and its throttle comment is wrong

`app/Console/Commands/Test/TestRaceConditionAuctionBid.php:200`, `:216`.

- `ends_at = now + 4`. Twenty local POSTs normally complete in well under 4 s, so every bid lands before the end and the
  settle worker only settles afterwards. The scenario then passes without ever exercising the interleaving it is named
  for. Use `now + 1` (as spec 4.8 says), or fire half the payloads after `sleep` until `ends_at - 0.2 s`, and report
  how many bids were rejected as `closed`. Some bids should be closed if the race really happened.
- The comment "each user stays below throttle:20,1" is false. Scenario A already spent the main user's 20 requests
  for that minute, so the main user's scenario B bids all return 429. Those are only warned about (`:361-363`), so the
  check silently loses a quarter of its bidders. Exclude client 0 from scenario B, or wait 60 s between scenarios.

### F6. Medium (ops): the race command interacts with the live scheduler and Discord

`TestRaceConditionAuctionBid.php:101`, `:200`, `:449-472`.

The command writes `auction_enabled = 1` to the real settings table. While it runs, the real `ogamex:scheduler:auction-tick`:

- settles the `race_test` auctions and dispatches `won`/`unsold`/`final_call` jobs. Scenario B's auction ends within
  15 min, so it also triggers `final_call`. These post "SOLD! **race_test** ..." to the configured Discord channel.
- may call `openIfDue()` and open a **real** daily auction inside the 18:00-16:00 window. That makes the next
  `createAuction()` hit the `active_slot` unique index and abort the run. Afterwards `restoreSettings()` turns
  `auction_enabled` back to 0 while a real auction stays active (see F4).

It refuses `APP_ENV=production`, which is right. Mitigation for the checklist: run it on a staging copy with the
scheduler container stopped and `discord_webhook_url` cleared, or have the command refuse to run while
`discord_webhook_url` is set.

### F7. Medium (test gap): no test renders the auction page, the merchant tile or the tick

`grep` over `tests/Feature/Auction*.php` finds no `GET /merchant/auction`, no `/merchant` tile check, no
`AuctionTick` run, no 419 and no 429 check, and no `/api/public/highscores` regression. All of those are in spec 8. So:

- The Blade view (`auction.blade.php`, 737 lines, including `resolve(PlayerService::class)->planets->current()` at
  `:217`) and the merchant tile (`merchant/index.blade.php`) have never been rendered by any test.
- Final call "exactly once across ticks" and the tick's notification dispatch are untested.
  `AuctionNotifyTest::testTickDispatchesJobsUsingTheNewClasses` (`:272-278`) only dispatches a job by hand and asserts
  that it was dispatched. It never runs the tick, so its name is misleading.
- `testBidWithMissingTokenReturnsInvalidTokenWithoutCrashing` exercises the controller's own `hash_equals` check.
  Laravel skips `ValidateCsrfToken` while running unit tests, so the real 419 path is untested. Production is still
  protected by the web-group CSRF middleware.

Minimal fix: add `GET /merchant/auction` (200, title present, with and without an unclaimed win), `GET /merchant`
(link not `#`, badge), `artisan('ogamex:scheduler:auction-tick')` twice under `Bus::fake()` with `ends_at = now + 600`
(exactly one `final_call`), and a highscores smoke test.

### F8. Low: hard-coded user-facing strings break the `t_` translation rule

- The countdown units `h`, `m`, `s`: `auction.blade.php:14-27` (PHP) and `:304-316` (JS). This is a real violation.
  Fix: `t_merchant.auction.time_hm` / `time_ms` / `time_s` templates passed through `#ia-auction-i18n`.
- `'Invalid token.'` (`AuctionController.php:62`) matches the existing convention in `AbstractBuildingsController`,
  `AbstractUnitsController` and `ResearchController`, and the JS maps it to `try_again` (`auction.blade.php:682`).
  Acceptable.
- The `'Something went wrong. Please try again.'` fallback (`AuctionController.php:311`) is now dead code, because
  `t_merchant.auction.error.try_again` exists (`t_merchant.php`). Remove the fallback and the outdated docblock at
  `:303-306`.
- The Discord copy in `AuctionNotifier` is plain English, which spec 6 allows. Usernames are restricted to
  `[A-Za-z0-9 _]` (`PlayerService::validateUsername`), and `allowed_mentions: parse []` is set, so there is no Markdown
  or mention injection.

### F9. Low: `extended` Discord post can be lost when two extensions happen before the job runs

`AuctionNotifier.php:177-179` checks `extension_count !== 1` at *job run time*. If a second extension commits before the
queue worker picks up the first job, both jobs see 2 and nothing is posted. Fix: pass the count in the job context from
`BidResult` or the controller (`'extension_count' => 1`) and check that instead.

### F10. Low: `withoutOverlapping()` without an expiry can stop the tick for 24 h after a crash

`routes/console.php` (new line). If the tick process is killed (OOM, container restart mid-run), the cache mutex stays
for the default 1440 minutes. No auction settles or opens for a day. Web polls still settle, but nothing opens.
`RunBots` has the same pre-existing pattern. Fix: `->withoutOverlapping(5)`.

### F11. Low: claim is not retried on deadlock

`AuctionService.php:491` (`DB::transaction` with no attempts). A claim can deadlock with account deletion (auction X →
planet X against deletion's planet → user → FK auction). The controller maps it to "try again" and nothing is lost.
Adding `, 3` is safe, because all decisions in the closure are locking reads.

### F12. Low: test isolation assumptions

`AuctionHttpTest::makeAuction` (`:25-46`) and `AuctionNotifyTest::makeAuction` (`:129-150`) insert
`auction_date = '2024-01-01'`, `active_slot = 1` without first clearing existing auctions, unlike
`ManagesAuctions::setUpAuctions()` and `AuctionBotTest`. Any leftover active auction in the dev or test database (for
example after an aborted race-command run) makes every test in those classes fail with a unique violation. Fix: call
`Auction::query()->delete()` in `setUp()`.

### F13. Info

- Snipe window, extension and cap are read live (`AuctionService.php:217-220`), not frozen at open like the increments.
  An admin change mid-auction applies immediately. That is acceptable, but document it.
- The admin validator allows `auction_min_increment_abs` up to 4294967295 (`ServerSettingsController.php`), while the
  service clamps to 2147483647. Align the rule to `between:1,2147483647`.
- `AuctionTick::notify()` guards with `class_exists()` for classes that now always exist (`AuctionTick.php:86-100`).
  Remove the guard, because PHPStan level 8 may flag it as always true.
- Polling `state` every 3 s runs the full `GlobalGame` player update on each request. That matches the other ajax
  endpoints, but watch server load on the first live days.
- Bots cannot drain or manipulate players. They bid only `minimumNextBid` through `placeBid`, with their own locked DM,
  capped by valuation and by 40 % of balance, and never inside the snipe window. Players can always outbid bots in the
  final 5 minutes, which is intended by the design. Bots bid only through `placeBid` and never call `DarkMatterService`
  directly.
- Security checks passed:
  - Output: every Blade output uses `{{ }}`, and the JS writes only through `.text()` and `.attr()`. I found no
    `innerHTML` and no `{!! !!}`, so there is no XSS path.
  - Server clock: the client clock is only used for display, and the server decides `closed` under the row lock.
  - Double submit: guarded three ways, by `inFlight`, by `already_leading`, and by `unique(auction_id, amount)`.
  - Mass assignment: none. No request array reaches `create` or `fill`.
  - Planet IDOR: `claim` checks `planet.user_id = winner`, `destroyed = 0` and the winner id under locks.
  - Stale auction id: the controller rejects any id other than `current()->id` before the service runs (`:99-102`).
  - Notification dispatch: jobs are dispatched after commit, with `afterCommit()` and outside any transaction.
- Migrations: all three are new files, and no merged migration is edited. FK types are `unsignedInteger` against
  `users.increments('id')`, and `foreignId` against `auctions.id()`. The unique indexes match spec 2. `down()` drops
  bids before auctions (reverse order) and deletes exactly the nine seeded keys. `insertOrIgnore` for settings is safe
  on re-run, provided `settings.key` is unique. Confirm that on the prod copy. Default `auction_enabled = 0`.
- The race command's routes, helper signatures and base classes all exist (`TestCommand::setup`, `$password`,
  `$numberOfIterations = 5`, `createLoggedInClient`). The test traits and helpers exist too (`IsolatedAccountTestCase`,
  `createUser`, `createForeignPlanet`, `assertMessageReceivedAndContainsDatabase`, `BotService::createBot`,
  `UserPreferencesService::get`, `DiscordNotifier::SETTING_KEY`, `PlayerServiceFactory::make($id, true)`). Route names
  match `routes/web.php`. Apart from F2 and F12, I expect the suite to run.
- `public/build/manifest.json` points at `ingame-LCrIWMgx.css`, which exists and contains the `.ia-auction-*` rules,
  including `.is-final` from the last commit.

---

## 3. Fix list before enabling

Must fix: **F1, F2, F3, F4.** Should fix: F5, F6 (or follow the checklist precautions), F7, F8. Nice to have: F9 to F13.

## 4. Checklist: what a human MUST run on a Docker host before setting `auction_enabled = 1`

Run on a staging copy, never on production directly.

1. `docker compose run --rm ogamex-app composer install` (vendor is missing on the review host; do not `composer update`).
2. `docker compose run --rm ogamex-app composer run rector`. Expect no changes.
3. `docker compose run --rm ogamex-app composer run cs`. Expect no changes.
4. `docker compose run --rm ogamex-app composer run stan`. Expect 0 errors at level 8.
5. `docker compose run --rm ogamex-app composer run tests -- --filter Auction`, then the full `composer run tests`.
   All must pass. `AuctionHttpTest::testSuccessfulBidDebitsAndReturnsState` should fail until F2 is fixed.
6. Restore a **copy of production data** into a staging database, then
   `docker compose run --rm ogamex-app php artisan migrate --pretend` (review the SQL), then `php artisan migrate`.
   Check that `SHOW CREATE TABLE auctions` / `auction_bids` show the FKs and unique indexes, and that
   `SELECT * FROM settings WHERE key LIKE 'auction_%'` returns 9 rows with `auction_enabled = '0'`. Then run
   `php artisan migrate:rollback --step=3` and `migrate` again to prove rollback works.
7. On staging, stop the scheduler and queue worker containers and clear `discord_webhook_url` (F6). Then run
   `docker compose run --rm ogamex-app php artisan ogamex:test:race-condition-auction-bid`. It must print `PASS` for all
   5 iterations, with no `[ERROR]` and no 5xx. Ideally run it after the F5 fix so that scenario B really races.
8. Also run the existing race commands, since `TestCommand` changed:
   `php artisan ogamex:test:race-condition-unitqueue` and `php artisan ogamex:test:race-condition-game-mission`.
9. Restart the scheduler and worker on staging, enable `auction_enabled`, set `auction_duration_minutes = 20` and an open
   hour of now, and watch one auction end to end. Check the open post, the bid from two accounts with the refund
   message, the final call (once), the extension, the SOLD post plus the won message (F1), and the claim. Then run
   `php artisan tinker` →
   `app(OGame\Services\AuctionService::class)->checkInvariants(<id>)` and expect `[]`.
10. `npm run build`, then confirm that `git status` is clean (the committed `public/build` matches the source).

Only after 1 to 10 pass, with F1 to F4 fixed, enable `auction_enabled` in production. Leave `auction_bots_enabled` off
for the first week.
