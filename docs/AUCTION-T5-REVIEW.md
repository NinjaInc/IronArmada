# Auction T5: concurrency review of AuctionService (T4)

Scope: `app/Services/AuctionService.php` and `app/Models/Auction/BidResult.php` at `night/auction-t4` (2431995b),
checked against `docs/AUCTION-SPEC.md` section 4. The code has never been run: Docker is unavailable, PHP is 8.3,
`vendor/` is not installed and Pest cannot run. Everything below comes from reading the code. Framework behaviour is
Laravel v13.40 (from `composer.lock`), `ManagesTransactions`. The database is MariaDB 11.3.2 (`docker-compose.yml`),
InnoDB, REPEATABLE READ, and `innodb_snapshot_isolation` is OFF (it only defaults to ON from 11.6.2). That means classic RR
semantics: a plain `SELECT` reads the snapshot taken at the transaction's first consistent read. `FOR UPDATE` and
`UPDATE` read the latest committed version, and a stale snapshot raises no error.

Context read: `DarkMatterService`, `User`, `Planet`/`PlanetService::update()`, `PlayerService::update()` /
`updateFleetMissions()` / `delete()`, `HalvingService`, `DirectiveService::claim()`, `SettingsService::get()`,
`ObjectService::getShipObjectByMachineName()`, the auction models and migrations, and the users, planets and
dark_matter_transactions migrations.

**Verdict: APPROVE WITH FIXES.** There is no Critical or High defect. Every path the service is designed for serialises
on the auctions row lock and conserves dark matter. Two Medium findings must be fixed before T17 sign-off: F1 (cancel
reads held bids from the snapshot) and F2 (the lock order is wrong in the spec, and nothing prevents nesting inside an
outer transaction). F3 is a one-line constant fix. The rest is hardening.

---

## Answers to the five questions

### (1) Double-spend, double-refund, double-deliver, two active auctions

Every write path (`placeBid`, `settleIfDue`, `claimForUser`, `cancel`) starts with
`Auction::whereKey($id)->lockForUpdate()` as its **first statement**. That is a PK equality lookup, so it takes a record
lock only, with no gap lock (except for a non-existent id, see F8). All later decisions are made on locking reads
(`lockForUpdate`, `UPDATE ... WHERE`). The one exception is `cancel()` (F1).

| Interleaving | Result |
|---|---|
| Bid A ‖ Bid B (same or different users) | B blocks on the auction row until A commits, then re-reads `leader_user_id`/`current_bid` as latest-committed. Same user: `already_leading`. Lower amount: `too_low`. `unique(auction_id, amount)` is a second guard. Spec scenario A (20 × 600 from one user) gives 1 accepted and 19 `already_leading`. **Safe.** |
| Bid ‖ settle | Serialised on the auction row. If the bid goes first and was inside the snipe window, `ends_at` moves, and the settle re-reads it under the lock and no-ops (`$now < ends_at`). If the settle goes first, the bid sees `status != active` and gets `closed`. `$now` is sampled **after** the lock is acquired on both sides (L139, L254), so a long lock wait cannot let a bid land after `ends_at`. **Safe.** |
| Settle ‖ cancel | Both require `status = active` under the lock, so the second one returns false. **Safe.** |
| Claim ‖ claim (incl. bot auto-claim ‖ human) | `claimed_at` is checked under the auction lock. Delivery uses `incrementEach` in the same transaction. **Safe**: exactly one delivery. |
| Claim ‖ planet abandonment or account deletion | The planet is re-read under `lockForUpdate` with `destroyed = 0` and `user_id`, so it is current. **Safe**: `bad_planet`. |
| Bid ‖ other DM spend (Halving, merchant, directive) on the same user | All of them take the users row X lock: `lockForUpdate`, or the implicit X lock of the `UPDATE ... increment` in `DirectiveService`. The balance check at L168 and the one inside `debit()` both run under the lock. **Safe.** |
| Refunded leader spends the refund before commit | Their row stays X-locked until commit. **Safe.** |
| openIfDue ‖ openIfDue | The second `INSERT` waits on the duplicate-key S lock and gets 1062 when the first commits. Laravel maps that to `UniqueConstraintViolationException`, which is caught. `active_slot` is unique, so a second active row is impossible (I6 is DB-enforced). The classic three-inserter dup-key deadlock needs an inserter that rolls back, but `create()` autocommits. **Safe.** |
| Settle (active_slot 1 → NULL) ‖ openIfDue insert of active_slot 1 | The insert waits on the delete-marked unique entry, then succeeds after the settle commits. **Safe**, and correct. |

**Gap/next-key locks.** The only range-locking statement is `UPDATE auction_bids WHERE auction_id=? AND refunded_at IS
NULL` (L195). It next-key-locks the `(auction_id, refunded_at)` range for this auction. The `AuctionBid::create` that
follows inserts into a gap this same transaction holds, so there is no conflict. Every other writer of that range must
already hold the same auction row lock, so these gap locks can never be the second edge of a cycle. The users lock
(`whereIn` on the PK, existing rows) takes record locks only.

**Snapshot vs locking read.** `placeBid`, `settleIfDue` and `claimForUser` never decide anything from a plain read. The
first plain read in `placeBid` (`isBanned()` and settings inside `mayBid`) happens after both row locks are held, so its
snapshot already includes every committed writer of those rows. `cancel()` is the exception: see F1.

### (2) Lock order vs the rest of the repo

Auction code is deadlock-free against existing code **as written**, but spec 4.2 states the global order wrongly (F2).
What the existing code actually does:

- `PlayerService::updateFleetMissions()` (L715-L735) locks **all planets of a user**, then fleet missions. Inside that,
  `ExpeditionMission` (L620) calls `DarkMatterService::credit()`, which locks the **users** row. Existing order: **planets → users**.
- `PlanetService::update()` (L1325) locks one planet. `PlayerService::update()` (L642) locks one user.
- `HalvingService` locks users → queue rows. `DirectiveService` locks user_directives → users (implicit, via `increment`).
- `PlayerService::delete()` (no transaction): `DELETE users` X-locks the users row, then the FK actions lock
  `auctions` (nullOnDelete) and `auction_bids` (cascade). Order: **users → auctions**.
- Auction code: auctions → users (`placeBid`, `cancel`, and `settleIfDue` implicitly through the FK check on
  `winner_user_id`, an S lock), and auctions → planets (`claim`). No auction transaction ever holds a users row and a
  planets row together.

A cycle needs a transaction that holds X and waits on Y, while another holds Y and waits on X. Only auction code and
account deletion lock auctions rows. So the only real cycle is **account deletion vs placeBid/settle/cancel** (F6). It
is rare, InnoDB detects it, and it fails closed. The "users before planets" rule in spec 4.2 contradicts
`updateFleetMissions` and must not be relied on by later tasks (T15 bots, any future DM lot).

### (3) Nested `DB::transaction` savepoints in `debit()`/`credit()`

Laravel at level ≥ 2 issues `SAVEPOINT trans2`. A nested commit only decrements the counter (no `RELEASE`). On a normal
exception, `handleTransactionException()` runs `ROLLBACK TO SAVEPOINT trans2` and rethrows. On a concurrency error
(deadlock 1213 / lock-wait 1205) at level > 1, it **decrements without any rollback** and throws `DeadlockException`.

- T4 never catches anything from `debit()`/`credit()`. Any throw unwinds to the outer `DB::transaction`, which issues a
  real `ROLLBACK`. Debit, credit, bid row and auction update are all-or-nothing. **Correct.**
- Re-locking a row the transaction already X-holds is a no-op, and the nested `save()` writes only the dirty
  `dark_matter` column. **Correct.**
- The spec's own pattern (`try { debit } catch (Exception) { return reject }`, spec 4.3) would have been **unsafe**.
  `DeadlockException` extends `PDOException`, so it would be caught. After a 1213, InnoDB has already rolled back the
  whole transaction, so the later `credit()`/`create()`/`save()` would run in autocommit, mint the refund without the
  debit, and then fail the outer `COMMIT`. After a 1205 with `innodb_rollback_on_timeout=OFF`, Laravel skips the
  savepoint rollback, which can leave a half-applied `debit()` (balance changed, no ledger row). T4's deviation D1 avoids
  both. Keep it.

### (4) Integer, column and arithmetic edge cases

- `users.dark_matter` is **signed INT** (`2025_11_29_194352`, max 2,147,483,647), and so are
  `dark_matter_transactions.amount`/`balance_after`. `MAX_BID = 4294967295` is the wrong ceiling (F3). It is harmless for
  debits, because the balance check rejects first. A refund that would push the leader past INT_MAX makes the `UPDATE`
  fail in strict mode (`config/database.php` `strict => true`). That rolls the whole bid back (fails closed), so that
  leader can't be outbid. This is reachable only with roughly 2.1 billion DM.
- `minimumNextBid`: `current * pct` ≤ 4.29e9 × 255 ≈ 1.1e12, which fits in PHP int64. `intdiv(x + 99, 100)` is a correct
  ceil for x ≥ 0. Near the top of the range the minimum can exceed `MAX_BID`, so every bid is rejected, which fails safe.
  The minimum is strictly greater than `current` only while `max(abs, pctStep) ≥ 1`. `SettingsService::get()` uses
  `empty()`, so a stored `"0"` silently falls back to the default (50 / 5). Negative values pass through and break the
  unsigned columns at open (F4).
- Anti-snipe: `newEnd = min(now + ext, original_ends_at + maxExt)`, applied only if `newEnd > ends_at`. So `ends_at` never
  shrinks and never exceeds the cap. A negative or zero window means no extension. `extension_count` (smallint) cannot
  realistically overflow. A stored `"0"` for the window, extension or cap is read as the default (300/300/3600): an admin
  can't disable sniping with 0 and has to use a negative value. That's a T14 UI note.
- Negative or zero `amount`: rejected as `too_low`, because the minimum is ≥ the catalogue `starting_bid` (≥ 600). `debit()`
  also rejects negative amounts.

### (5) The deviations from the spec

The task said "the six listed deviations", but no list exists in the T4 commit message, the repo or the handoffs. Below
are the six substantive deviations I found by diffing T4 against spec 4.3-4.7. Minor ones follow them.

| # | Deviation | Where | Decision |
|---|---|---|---|
| D1 | Balance pre-checked on the locked row and `debit()` not wrapped in try/catch, instead of the spec's catch-and-reject | L167-L170, L181 | **Accept.** Strictly safer, see (3). The check runs under the same X lock `debit()` re-checks under. |
| D2 | `MAX_BID` guard returns `insufficient_dm` | L28-L29, L148-L150 | **Accept with fix F3.** Correct idea, wrong constant. |
| D3 | `LogicException` if the previous leader's row is missing, instead of `User::find()` + credit | L172-L179 | **Accept.** FK `nullOnDelete` makes this unreachable unless state is corrupt, and aborting is correct. `credit()` would have thrown "User not found" anyway. |
| D4 | `refundedRows !== 1` throws (spec only had a comment) | L195-L201 | **Accept.** Enforces I1 at write time. Hardening in F5. |
| D5 | `mayBid()` (vacation, ban, bot gate) under the users lock; not in the spec 4.3 pseudo-code but required by rule 1.2 | L163-L166, L539-L546 | **Accept.** Evaluated on the locked row. `isBanned()` is a plain read taken after the locks, so it is current. |
| D6 | `cancel()` refunds the held bid row's `user_id`/`amount` instead of `leader_user_id`/`current_bid` | L321-L341 | **Accept the semantics** (robust to a deleted leader), but **the read must become a locking read**: F1. |

Minor deviations, all accepted: `incrementEach` instead of a raw `DB::raw` update (equivalent and atomic, L502-L504). The
ship whitelist throws and rolls back (L498). `openIfDue` adds an `auction_date` pre-check and a `now >= endsAt` upper
bound (L91-L96). These are only fast paths, since the unique indexes remain the guarantee. The bot auto-claim targets the
lowest-id `planet_type = 1` planet (`PlanetType::Planet = 1`) after the commit, and swallows errors (L518-L537).

---

## Findings, ranked by severity

### F1. Medium: `cancel()` reads held bids from the snapshot and updates them without a guard, which can double-refund

`app/Services/AuctionService.php:321` (`AuctionBid::...->whereNull('refunded_at')->get()`, a plain read) and
`:339-340` (`$heldBid->save()`, `UPDATE ... WHERE id = ?` with no `refunded_at IS NULL` guard).

As a standalone call it is correct. The plain read is the first consistent read, so its snapshot is created after the
auction lock is held, and every bid has committed by then. The service does nothing to enforce that precondition, though.
If `cancel()` runs inside an outer transaction that has already done any plain read (for example an admin controller
doing `DB::transaction(fn () => $svc->cancel($svc->current()->id, ...))`, or a test using `DatabaseTransactions`), the
snapshot predates the auction lock:

```
T_admin: BEGIN; SELECT ... FROM auctions WHERE status='active'   -- current(): snapshot S0. Leader A holds 500.
T_bid:   placeBid(B, 600): debit B 600, credit A 500, A's row refunded_at=now, B's row held. COMMIT.
T_admin: SELECT ... FOR UPDATE auctions        -- latest: leader B, 600
T_admin: SELECT auction_bids ... refunded_at IS NULL   -- snapshot S0: returns A's 500 row (already refunded!), B's row invisible
T_admin: credit(A, 500)                        -- A refunded a SECOND time: +500 DM minted
T_admin: UPDATE auction_bids SET refunded_at=? WHERE id=A_row   -- no guard, succeeds
T_admin: status=cancelled. COMMIT.             -- B's 600 stays held on a cancelled auction (I3, I4 broken), B loses 600
```

On MariaDB 11.3 (`innodb_snapshot_isolation=OFF`) nothing raises an error. Fix it by making the read a locking read (it
then sees the latest version, whatever the snapshot) and guarding the update:

```php
$held = AuctionBid::where('auction_id', $auction->id)
    ->whereNull('refunded_at')
    ->lockForUpdate()
    ->get();
// ...
$updated = AuctionBid::whereKey($heldBid->id)->whereNull('refunded_at')->update(['refunded_at' => $now]);
if ($updated !== 1) {
    throw new LogicException("Auction #{$auction->id}: held bid {$heldBid->id} already refunded.");
}
```

### F2. Medium: spec 4.2 lock order contradicts existing code, and nothing prevents calls inside an outer transaction

`docs/AUCTION-SPEC.md` 4.2, and `AuctionService.php:22-25` (class docblock), `:136`, `:314`, `:462`.

The spec and docblock say "auctions → users ascending → planets" is a global order. Existing code locks **planets →
users** (`PlayerService::updateFleetMissions()` L715-L735, then `ExpeditionMission` L620 `credit()`). T4 is safe only
because no auction transaction holds a users row and a planets row together, and only auction code locks `auctions`
first. Both properties break as soon as a caller wraps a service method in its own transaction that already holds a
user or planet lock. The likeliest case is T15, if `AuctionBotStrategy::consider()` runs inside a transaction in the bot
turn, or someone adds a DM-lot claim:

```
T_bot:   (outer tx) lock users[bot]  ... -> placeBid(bot) -> wait auctions[A]
T_human: placeBid(human) holds auctions[A] -> previous leader is the bot -> wait users[bot]   => deadlock
```

InnoDB breaks it (1213), but the auction becomes un-biddable for that bot's rival while the bot turn keeps re-entering.
Combined with F1, nesting is the one way to turn a correct implementation into a wrong one.

Fix: correct the docs and state the precondition. Do **not** add a hard `DB::transactionLevel() === 0` assertion:
`tests/IsolatedAccountTestCase.php` uses `DatabaseTransactions`, so level 1 is normal in tests.

```php
/**
 * Lock order (auction code only): auctions row -> users rows ascending (placeBid, cancel; settle takes an implicit
 * FK S-lock on the winner) | auctions row -> planets row (claim). No auction transaction holds users AND planets.
 * Existing game code locks planets -> users (updateFleetMissions -> expedition DM credit), so never extend an
 * auction transaction to lock both, and never call placeBid/claim/cancel from inside a transaction that already
 * holds a users or planets lock (call them after BotService::takeTurn's own work, outside any transaction).
 */
```

Change spec 4.2 to match the comment, and add the "call outside any transaction" constraint to T15's description.

### F3. Low: `MAX_BID` is the unsigned 32-bit max, but `users.dark_matter` and the ledger are signed INT

`app/Services/AuctionService.php:28-29`, against `database/migrations/2025_11_29_194352_add_dark_matter_to_users_table.php`
(`integer('dark_matter')`) and the `dark_matter_transactions` `integer('amount')` / `integer('balance_after')` columns.

Input: a leader holding about 2.1 billion DM in total after the refund. `credit()` fails the `UPDATE` under strict mode,
the whole bid rolls back, and nobody can outbid that leader (each attempt returns a 500). It fails closed, so no DM is
created or lost. Fix the constant so the guard matches the narrowest column that stores the amount:

```php
/** users.dark_matter, dark_matter_transactions.amount/balance_after are signed INT. */
private const int MAX_BID = 2147483647;
```

### F4. Low: `openIfDue()` freezes unclamped settings into unsigned or tinyint columns

`app/Services/AuctionService.php:109-110` (and `:87-89`).

Input: an admin stores `auction_min_increment_pct = 300` or `auction_min_increment_abs = -10`. T14 validation may stop
it, but nothing in the service does. `Auction::create` throws a `QueryException` (out of range). That is not the caught
`UniqueConstraintViolationException`, so every tick from then on errors at step 4 and no auction ever opens. Separately,
`SettingsService::get()` treats `"0"` as unset (`empty()`), so 0 can never be stored here in effect. Clamp at the
boundary, and make `minimumNextBid` always strictly exceed `current_bid`, so `unique(auction_id, amount)` can never
throw for a value the rules accepted:

```php
'min_increment_abs' => max(1, min(self::MAX_BID, $this->intSetting('auction_min_increment_abs', 50))),
'min_increment_pct' => max(0, min(255, $this->intSetting('auction_min_increment_pct', 5))),
```

```php
// minimumNextBid()
$minimum = max($current + max($auction->min_increment_abs, $percentStep, 1), $auction->starting_bid);
```

### F5. Low: `placeBid()` refunds `current_bid` rather than the held row's amount and only counts rows

`app/Services/AuctionService.php:189-201`.

If the state is ever corrupt, the refund and the escrow can drift silently: for example, a held row of 500 for user A
while `current_bid` = 550 or `leader_user_id` = B after a manual admin edit. A then gets 550 back for a 500 hold, and the
count check still passes. `cancel()` already trusts the held row (D6), so make both paths consistent and assert I1 fully:

```php
$held = AuctionBid::where('auction_id', $auction->id)->whereNull('refunded_at')->lockForUpdate()->get();
if ($held->count() !== 1
    || (int)$held[0]->user_id !== $previousLeaderId
    || (int)$held[0]->amount !== $previousAmount) {
    throw new LogicException("Auction #{$auction->id}: held bid does not match leader/current_bid (I1).");
}
$this->darkMatter->credit($previousLeader, $previousAmount, /* ... */);
AuctionBid::whereKey($held[0]->id)->update(['refunded_at' => $now]);
```

### F6. Low: account deletion can deadlock with placeBid, settle or cancel, and the transaction never retries

`app/Services/AuctionService.php:136`, `:252`, `:314` (`DB::transaction(...)`, attempts = 1), against
`PlayerService::delete()` L942.

Interleaving: `placeBid` holds `auctions[A]` and waits for `users[L]` (L is the leader). `DELETE users WHERE id=L` holds
`users[L]` and the FK `nullOnDelete` needs `auctions[A]`. That is a 1213 deadlock. `settleIfDue` hits the same cycle
through its implicit FK S lock on `winner_user_id`. Whichever side loses rolls back cleanly, so no DM is affected, but the
bidder gets a 500. The deletion is not transactional either, so a lost deletion leaves a half-deleted account. That is a
pre-existing problem and out of scope here. Every decision in the closures is a locking read, so a retry is safe:

```php
return DB::transaction(function () use ($bidder, $auctionId, $amount): BidResult {
    // ...
}, 3);
```

T10 should also map any remaining `Throwable` to a generic "try again" error, not a 500.

### F7. Low: `checkInvariants()` false positives (account deletion, unlocked multi-statement read)

`app/Services/AuctionService.php:380-386`, `:395-400`, `:415-420`.

Input 1: the leader deletes their account. `leader_user_id` is set to NULL and their bid rows cascade away, but
`bid_count > 0` stays, so I1 reports "expected exactly 1 held bid, found 0". The same happens for I2 when a settled
winner is deleted. The data is fine. I4 also still holds, because `dark_matter_transactions` cascades too.

Input 2: the race command calls this while bids are in flight. The auction row and the bids are read in separate
autocommit statements, so a bid that commits between them produces a spurious mismatch. Fix:

```php
return DB::transaction(function () use ($auctionId): array {
    $auction = Auction::whereKey($auctionId)->sharedLock()->first();   // freeze writers for a consistent view
    // ... existing checks, with the I1 "no bids" branch widened to:
    // if ($auction->leader_user_id === null) { held must be empty }   // covers bid_count = 0 and a deleted leader
    // and for STATUS_SETTLED with winner_user_id === null: held must be empty (winner deleted)
});
```

### F8. Info: notes for T6, T7 and T10

- `placeBid($bidder, $nonexistentId, ...)` (`:138`) takes a gap lock on the PK supremum under RR. Until that short
  transaction ends, it blocks `openIfDue()`'s insert of the next auction. T10 should reject an `auction_id` that is not
  `current()->id` before calling the service.
- `vendor/` is absent, so I could not confirm two things by reading framework source. T6 should cover both with a test:
  that `Eloquent\Builder::incrementEach` resolves on Laravel 13 (`:503`), and that MariaDB error 1062 surfaces as
  `UniqueConstraintViolationException` (`:120`).
- `BidResult` (`app/Models/Auction/BidResult.php`) matches spec 3.1 field-for-field. No issues.
- The claim's atomic increments stop the claim from overwriting a stale planet model. They do not stop a later stale
  absolute `save()` from overwriting the claim. In this codebase the absolute writes happen in `PlanetService::update()`
  after `reloadPlanet()` under the planet lock, and deductions use `deductResourcesAtomic`. So delivery is as safe as
  transport or expedition arrivals. No change is needed, but the rationale in spec 4.5 is worded backwards.

---

## Verdict

**APPROVE WITH FIXES.** Must fix before T17 or before enabling `auction_enabled`: **F1**, **F2** (docs plus the T15
constraint) and **F3**. Should fix: F4, F5, F6. Nice to have: F7, F8. Deviations D1 to D5 are accepted as they are, and
D6 is accepted once F1 is applied.
