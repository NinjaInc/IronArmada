# Armada Salvage Auction: specification

Status: design spec, no application code yet. Branch `night/auction-spec`.

The Merchant page already has an "Auctioneer" tile (`resources/views/ingame/merchant/index.blade.php`, hidden copy in
`resource-market.blade.php`, lang keys `t_merchant.auctioneer` / `t_merchant.auctioneer_desc`) that links to `#`. There is no
auction backend. This spec replaces that dead link with an Iron Armada feature: the **Armada Salvage Auction**.

This is an Iron Armada mechanic, **not** a recreation of the OGame auctioneer, so the OGame-accuracy rule in `AGENTS.md`
does not apply to its mechanics or its English copy. Everything else in `AGENTS.md` does apply: the `t_` translation
convention, new migrations only, the full tool chain (rector, cs, stan, tests), and `npm run build` after CSS/JS changes.

---

## 1. Game design

### 1.1 Fiction

Every day the Armada Salvage Corps tows in one prize: a wrecked convoy's cargo, a mothballed squadron of hulls, a pirate
hoard. Commanders bid **dark matter** for it. The highest bid when the hammer falls wins the lot.

### 1.2 Rules

| Rule | Value (default, admin-configurable) |
|------|------|
| Auctions at a time | Exactly one. Enforced in the database (section 2.1, `active_slot`). |
| Cadence | One per UTC day. Opens at `auction_open_hour_utc` (18:00 UTC). |
| Duration | `auction_duration_minutes` = 1320 (22 h). Closes 16:00 UTC next day, leaving a 2 h gap that absorbs extensions. |
| Currency | Dark matter only. |
| Starting bid | Set per lot in the catalogue (section 1.3). |
| Minimum next bid | `max(current_bid + max(auction_min_increment_abs, ceil(current_bid * auction_min_increment_pct / 100)), starting_bid)`, rounded up to the next 10 DM. Defaults: 50 DM absolute, 5 %. |
| Anti-sniping | A bid placed within `auction_snipe_window_seconds` (300 s) of `ends_at` pushes `ends_at` to `now + auction_snipe_extension_seconds` (300 s). |
| Extension cap | `ends_at` can never exceed `original_ends_at + auction_max_extension_seconds` (3600 s). Bids are still accepted until the capped end. |
| Escrow | The full bid is debited when it is placed. |
| Outbid refund | The previous leader is refunded the full amount **in the same database transaction** as the new bid. |
| Self-raise | Not allowed. The current leader cannot bid again until outbid. With no proxy bidding a self-raise only burns DM, and banning it keeps escrow at exactly one held bid. |
| Settlement | At `ends_at` the leader wins. DM is already held, so settlement moves no DM. It only marks the winner and makes the lot claimable. |
| No bids | Auction closes as `unsold`. The lot is discarded. |
| Delivery | The winner presses **Claim** on the auction page and chooses a planet they own (defaults to the current planet). The resources and ships go onto that planet. Claims never expire. |
| Who may bid | Any non-banned player who is not in vacation mode and has the DM. Admins may bid. Bots only if `auction_bots_enabled`. |
| Notifications | In-game message on outbid and on win, gated by the existing `auctioneerNotifications` preference (`UserPreferencesService`, already on the Options page). |

**Why not a dark matter lot?** Bidding DM for DM is a currency exchange, not a prize, and it would let the richest player
farm a guaranteed margin. Lots are resources and ships. A DM lot is possible later only if the bid currency changes.

**Why dark matter as currency?** It is user-scoped (no "which planet pays" question), it already has row-locked
`debit()`/`credit()` in `DarkMatterService`, and every movement lands in the `dark_matter_transactions` audit ledger.
Resource bids would need cross-planet escrow and refunds to a planet that may have been abandoned.

### 1.3 Lot catalogue

The catalogue lives in a new constants class `app/GameConstants/AuctionLots.php`. It is a static array keyed by `lot_key`
with a selection weight. Amounts are multiplied by `SettingsService::economySpeed()` when the lot is drawn, and the
resolved contents are **frozen** into `auctions.lot_payload`. Changing the catalogue never alters a running auction.

| lot_key | Contents (×economy speed) | Starting bid DM | Weight |
|---------|---------------------------|-----------------|--------|
| `crate_metal` | 1,500,000 metal | 600 | 14 |
| `crate_crystal` | 900,000 crystal | 600 | 14 |
| `crate_deuterium` | 500,000 deuterium | 600 | 14 |
| `crate_convoy` | 800k metal, 500k crystal, 250k deuterium | 900 | 14 |
| `hulls_fighters` | 250 light_fighter | 700 | 10 |
| `hulls_cruisers` | 40 cruiser | 800 | 10 |
| `hulls_cargo` | 60 large_cargo | 600 | 8 |
| `hulls_recyclers` | 30 recycler | 600 | 6 |
| `hulls_battleships` | 15 battle_ship | 1,000 | 5 |
| `hulls_pathfinders` | 20 pathfinder | 800 | 3 |
| `hoard_pirate` | 400k metal, 400k crystal, 400k deuterium, 10 bomber | 1,200 | 2 |

**Sunday Grand Lot.** On Sundays (UTC) the draw uses only `grand_*` keys: `grand_deathstar` (1 deathstar, start 3,000) or
`grand_flotilla` (5 battlecruiser, 5 destroyer, start 2,500).

The draw is **seeded** with `crc32(auction_date)` so it is reproducible in tests. It avoids repeating the previous
day's `lot_key`.

Payload shape (JSON, frozen):

```json
{ "resources": { "metal": 1500000, "crystal": 0, "deuterium": 0 }, "units": { "cruiser": 40 } }
```

Unit keys must be valid ship `machine_name`s (`ObjectService::getShipObjectByMachineName`). The catalogue unit test checks this.

### 1.4 Bots (optional, default off)

With `auction_bots_enabled = 1`, `BotService::takeTurn()` calls `AuctionBotStrategy::consider(User $bot)`. A bot bids
`minimumNextBid` when all of these hold:

- the bot is not the current leader;
- `now < ends_at - auction_snipe_window_seconds`, so bots never trigger anti-snipe extensions or bidding wars in the final minutes;
- the bid is at most a valuation ceiling: `lotMetalEquivalent / dmMetalRate * personalityFactor`, with miner 1.2, balanced 1.0,
  raider 0.9, turtle 0.7, pirate 0.8, and `dmMetalRate` a constant of 2,500 metal per DM;
- the bid is at most 40 % of the bot's DM balance;
- a per-turn chance of 25 % succeeds, so bots do not pile in all at once.

Bots go through the same `AuctionService::placeBid()` as players, so there is no second code path to keep safe. A bot
winner is auto-claimed onto its homeworld during settlement, because bots have no UI.

---

## 2. Database schema (new migrations only)

All timestamps are unsigned int unix seconds, matching `fleet_missions.time_arrival` and `user_directives.period_start`.
They are written via `Date::now()->timestamp`, so `travelTo()` / `Carbon::setTestNow()` control them in tests.
`users.id` is an unsigned int (see `dark_matter_transactions.user_id`), so FKs use `unsignedInteger`.

### 2.1 `2026_11_20_000001_create_auctions_table.php`

```php
Schema::create('auctions', function (Blueprint $table) {
    $table->id();
    $table->date('auction_date')->unique();                 // UTC opening date: makes daily creation idempotent
    $table->string('status', 16)->index();                  // active | settled | unsold | cancelled
    $table->unsignedTinyInteger('active_slot')->nullable()->unique(); // 1 while active, NULL otherwise => max one active auction
    $table->string('lot_key', 40);
    $table->json('lot_payload');                            // frozen contents, see 1.3
    $table->unsignedInteger('starting_bid');
    $table->unsignedInteger('min_increment_abs');           // frozen from settings at open
    $table->unsignedTinyInteger('min_increment_pct');       // frozen from settings at open
    $table->unsignedInteger('current_bid')->default(0);
    $table->unsignedInteger('leader_user_id')->nullable();
    $table->unsignedInteger('bid_count')->default(0);
    $table->unsignedInteger('starts_at');
    $table->unsignedInteger('ends_at');
    $table->unsignedInteger('original_ends_at');
    $table->unsignedSmallInteger('extension_count')->default(0);
    $table->boolean('final_call_sent')->default(false);     // Discord "final 15 minutes" sent once
    $table->unsignedInteger('settled_at')->nullable();
    $table->unsignedInteger('winner_user_id')->nullable();
    $table->unsignedInteger('final_price')->nullable();
    $table->unsignedInteger('claimed_at')->nullable();
    $table->unsignedInteger('claimed_planet_id')->nullable();
    $table->timestamps();

    $table->foreign('leader_user_id')->references('id')->on('users')->nullOnDelete();
    $table->foreign('winner_user_id')->references('id')->on('users')->nullOnDelete();
    $table->index(['status', 'ends_at']);                   // tick: "active and due"
    $table->index(['winner_user_id', 'claimed_at']);        // "my unclaimed winnings"
});
```

`active_slot` works because MariaDB unique indexes allow many NULLs. Opening a second auction while one is active
fails with a duplicate-key error. It is caught and treated as "already open", so two scheduler runs cannot race.

### 2.2 `2026_11_20_000002_create_auction_bids_table.php`

This table is an append-only ledger. A row is never deleted, and the only update ever made is setting `refunded_at`.

```php
Schema::create('auction_bids', function (Blueprint $table) {
    $table->id();
    $table->foreignId('auction_id')->constrained('auctions')->cascadeOnDelete();
    $table->unsignedInteger('user_id');
    $table->unsignedInteger('amount');
    $table->unsignedInteger('placed_at');
    $table->boolean('extended_end')->default(false);         // this bid triggered anti-snipe
    $table->boolean('is_bot')->default(false);
    $table->unsignedInteger('refunded_at')->nullable();      // set when outbid (or auction cancelled)
    $table->timestamps();

    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->unique(['auction_id', 'amount']);                // two bids at the same amount are impossible
    $table->index(['auction_id', 'refunded_at']);            // "the one held bid"
    $table->index(['user_id', 'placed_at']);
});
```

### 2.3 `2026_11_20_000003_add_auction_settings.php`

Insert rows into `settings`, following the pattern of `2025_11_29_194447_add_dark_matter_settings.php`. Use
`insertOrIgnore` so re-running is safe. `down()` deletes the keys.

| key | default |
|-----|---------|
| `auction_enabled` | `0` (admin switches it on once the art is in) |
| `auction_open_hour_utc` | `18` |
| `auction_duration_minutes` | `1320` |
| `auction_snipe_window_seconds` | `300` |
| `auction_snipe_extension_seconds` | `300` |
| `auction_max_extension_seconds` | `3600` |
| `auction_min_increment_abs` | `50` |
| `auction_min_increment_pct` | `5` |
| `auction_bots_enabled` | `0` |

### 2.4 Enum additions (no migration: `dark_matter_transactions.type` is a string)

`app/Enums/DarkMatterTransactionType.php` gains `AUCTION_BID = 'auction_bid'` and `AUCTION_REFUND = 'auction_refund'`.
Descriptions follow the existing English-literal convention, for example `"Auction #42 bid"` and
`"Auction #42 refund (outbid)"`, so a reconciliation query can match them by auction id.

### 2.5 Models

- `app/Models/Auction.php`: `#[Table(name: 'auctions')]`. Casts: `lot_payload` to `array`, `final_call_sent` to `boolean`.
  Relations: `bids()` (hasMany AuctionBid), `leader()`, `winner()` (belongsTo User). Status constants:
  `STATUS_ACTIVE`, `STATUS_SETTLED`, `STATUS_UNSOLD`, `STATUS_CANCELLED`.
- `app/Models/AuctionBid.php`: `#[Table(name: 'auction_bids')]`. Casts: `is_bot` and `extended_end` to `boolean`.
  Relations: `auction()`, `user()`.

Follow `UserDirective` for the attribute style (`#[Fillable]`, `#[Table]`, `@property` docblocks).

---

## 3. Services, controller, routes, jobs, commands

### 3.1 Value objects

`app/GameMissions/Models` is mission-specific, so these go in `app/Models/Auction/`:

```php
final class AuctionLot      // resolved lot: key, resources (Resources), units (array<string,int>)
{
    public static function fromPayload(string $key, array $payload): self;
    public function toPayload(): array;
    public function metalEquivalent(): int;   // metal + 1.5*crystal + 3*deuterium + unit costs; used by bots and Discord copy
}

final class BidResult       // returned by placeBid, consumed by controller + notifications
{
    public function __construct(
        public bool $accepted,
        public string $errorKey,          // '' when accepted; else a t_merchant.auction.error.* suffix
        public int $currentBid,
        public int $minimumNextBid,
        public int $endsAt,
        public bool $extended,
        public int|null $outbidUserId,    // previous leader who was refunded
        public int $refundedAmount,
    ) {}
}
```

### 3.2 `app/Services/AuctionService.php` (core; all DM movement lives here)

```php
class AuctionService
{
    public function __construct(
        private DarkMatterService $darkMatter,
        private SettingsService $settings,
        private AuctionLotCatalog $catalog,
    ) {}

    public function isEnabled(): bool;

    /** Active auction or null. Read-only, no lock. */
    public function current(): Auction|null;

    /** Last N closed auctions for the history strip. */
    public function recent(int $limit = 7): Collection;

    /** Pure function of the auction row. Used by UI, bots and placeBid. */
    public function minimumNextBid(Auction $auction): int;

    /**
     * Opens today's auction if due and none is active. Idempotent: the unique auction_date and active_slot
     * make a second concurrent call a no-op. Returns the new auction or null.
     */
    public function openIfDue(): Auction|null;

    /**
     * Places a bid. Debits the bidder and refunds the previous leader in ONE transaction (section 4).
     * Never throws for business-rule failures; returns BidResult with errorKey instead.
     */
    public function placeBid(User $bidder, int $auctionId, int $amount): BidResult;

    /**
     * Settles one auction if it is active and ends_at <= now. Idempotent. Returns true if this call settled it.
     */
    public function settleIfDue(int $auctionId): bool;

    /** Settles every due auction (normally 0 or 1). Returns the number settled. */
    public function settleAllDue(): int;

    /**
     * Delivers a won lot to a planet the winner owns. Idempotent via claimed_at under a row lock.
     * @return string '' on success, or a t_merchant.auction.error.* suffix
     */
    public function claim(PlayerService $winner, int $auctionId, int $planetId): string;

    /** Admin: cancels the active auction and refunds the held bid. */
    public function cancel(int $auctionId, string $reason): bool;

    /** Winnings the player has not claimed yet. */
    public function unclaimedFor(int $userId): Collection;

    /** Diagnostic used by tests and the race command: returns a list of invariant violations (empty = healthy). */
    public function checkInvariants(int $auctionId): array;
}
```

`app/Services/AuctionLotCatalog.php`:

```php
class AuctionLotCatalog
{
    /** Seeded, weighted draw; avoids $previousKey; uses grand_* lots on Sundays. */
    public function draw(string $auctionDate, string|null $previousKey): AuctionLot;
    public function startingBid(string $lotKey): int;
}
```

`app/Services/AuctionBotStrategy.php`:

```php
class AuctionBotStrategy
{
    public function __construct(private AuctionService $auctions, private SettingsService $settings) {}
    /** Called from BotService::takeTurn() after its normal turn. Never throws. */
    public function consider(User $bot): void;
}
```

`app/Services/AuctionNotifier.php` sends Discord posts and in-game messages. It is **never** called inside a transaction:

```php
class AuctionNotifier
{
    public function opened(Auction $auction): void;
    public function finalCall(Auction $auction): void;
    public function extended(Auction $auction): void;         // only for extension_count === 1
    public function outbid(int $userId, Auction $auction, int $refunded): void;  // in-game message
    public function won(Auction $auction): void;              // Discord + in-game message to winner
    public function unsold(Auction $auction): void;
}
```

### 3.3 Controller: `app/Http/Controllers/AuctionController.php`

This is a new controller. `MerchantController` is already over 600 lines.

```php
class AuctionController extends OGameController
{
    /** GET /merchant/auction: full page. */
    public function index(PlayerService $player, AuctionService $auctions): View;

    /** GET /ajax/merchant/auction/state: polling JSON (contract below). */
    public function state(PlayerService $player, AuctionService $auctions): JsonResponse;

    /** POST /ajax/merchant/auction/bid  {auction_id:int, amount:int}. */
    public function bid(Request $request, PlayerService $player, AuctionService $auctions, AuctionNotifier $notifier): JsonResponse;

    /** POST /merchant/auction/claim  {auction_id:int, planet_id:int}: redirect with flash. */
    public function claim(Request $request, PlayerService $player, AuctionService $auctions): RedirectResponse;
}
```

`index` and `state` call `$auctions->settleIfDue()` on the current auction before reading it. That is safe because the
call is idempotent and lock-guarded, and it keeps the page correct if the scheduler is late.

Validation for `bid`: `auction_id` is a required integer. `amount` is a required integer between 1 and 2,000,000,000;
strip commas and spaces first, as `executeTrade` does. Banned players, players in vacation mode and disabled auctions get
an error key without touching the database. Rate limit: `throttle:20,1` on the bid route.

**State JSON contract** (`GET /ajax/merchant/auction/state`):

```json
{
  "enabled": true,
  "server_now": 1795000000,
  "auction": {
    "id": 42, "status": "active", "lot_key": "hulls_cruisers",
    "lot": { "resources": {"metal":0,"crystal":0,"deuterium":0}, "units": {"cruiser": 40} },
    "starting_bid": 800, "current_bid": 1450, "minimum_next_bid": 1530,
    "bid_count": 9, "leader_name": "Vex", "you_lead": false,
    "ends_at": 1795003600, "extension_count": 1
  },
  "recent_bids": [ { "name": "Vex", "amount": 1450, "placed_at": 1794999000 } ],
  "dark_matter": 5200,
  "unclaimed": [ { "auction_id": 40, "lot_key": "crate_metal" } ]
}
```

`auction` is `null` between auctions. In that case the response adds `next_opens_at`. `recent_bids` holds the last 10 bids
and contains usernames only, never user ids.

**Bid response:** `{ "success": bool, "message": string, "state": <same as above> }`. HTTP 200 for a rejected bid, so the
UI shows the message. HTTP 422 only for validation errors.

### 3.4 Routes (`routes/web.php`, inside the existing authenticated ingame group next to the merchant routes)

```php
Route::get('/merchant/auction', [AuctionController::class, 'index'])->name('merchant.auction.index');
Route::get('/ajax/merchant/auction/state', [AuctionController::class, 'state'])->name('merchant.auction.state');
Route::post('/ajax/merchant/auction/bid', [AuctionController::class, 'bid'])->middleware('throttle:20,1')->name('merchant.auction.bid');
Route::post('/merchant/auction/claim', [AuctionController::class, 'claim'])->name('merchant.auction.claim');
```

Nothing goes under `/api/public/*`. The public highscore contract is untouched.

### 3.5 Scheduler command and jobs

`app/Console/Commands/Scheduler/AuctionTick.php`, signature `ogamex:scheduler:auction-tick`:

```php
public function handle(AuctionService $auctions, AuctionNotifier $notifier): int
// 1. if !enabled: return SUCCESS
// 2. $settledIds = settle all due (each settle in its own transaction); then dispatch NotifyAuctionEvent per result
// 3. final call: active auction with ends_at - now <= 900 and !final_call_sent -> conditional UPDATE ... WHERE final_call_sent = 0,
//    and only if 1 row affected dispatch NotifyAuctionEvent('final_call')
// 4. $opened = openIfDue(); if opened -> dispatch NotifyAuctionEvent('opened')
```

Registered in `routes/console.php`:

```php
Schedule::command(AuctionTick::class)->everyMinute()->withoutOverlapping();
```

Jobs (`app/Jobs/`, `QUEUE_CONNECTION=database`; the `ogamex-queue-worker` container already exists):

- `NotifyAuctionEvent implements ShouldQueue`. Constructor `(string $event, int $auctionId, array $context = [])`;
  `handle(AuctionNotifier $notifier)`. Retries 3 times, then fails silently. Discord is best-effort, and
  `DiscordNotifier::send()` already swallows errors. This keeps the 3 s Discord timeout out of both the bid request
  and the tick.

Settlement is done inline by the tick, not by a job. It is fast (one row lock and a couple of updates) and idempotent,
and a job would add a second place where it can run without adding safety.

Admin command `app/Console/Commands/Admin/AuctionCancel.php`, signature
`ogamex:admin:auction-cancel {auction_id} {--reason=}`, calls `AuctionService::cancel()`.

Admin settings: `Admin/ServerSettingsController` `index()`/`update()` gains an "Auction" fieldset for the nine settings
keys. Values are validated as integers within their ranges. The view is the existing server-settings blade.

### 3.6 In-game messages

These follow the `app/GameMessages/WelcomeMessage.php` pattern (`tab = 'universe'`, `subtab = 'universe'`):

- `AuctionOutbid`: params `lot`, `amount`, `link`.
- `AuctionWon`: params `lot`, `amount`, `link`.

Both are sent through `MessageService::sendSystemMessageToPlayer()`, only if the recipient's `auctioneerNotifications`
preference is 1 (default 1).

---

## 4. Concurrency design

This is the part that must not be wrong. The goal is that no sequence of concurrent bids, settlements, claims and
cancels can create or destroy dark matter, or deliver a lot twice.

### 4.1 Escrow invariants

For any auction `A`, let **held(A)** be the bid rows with `refunded_at IS NULL`.

- **I1 (active):** if `A.bid_count = 0`, then held(A) is empty and `leader_user_id` is NULL. Otherwise held(A) is exactly one row,
  its `user_id = A.leader_user_id`, and its `amount = A.current_bid`.
- **I2 (settled):** held(A) is exactly one row, with `user_id = winner_user_id` and `amount = final_price`.
- **I3 (unsold/cancelled):** held(A) is empty.
- **I4 (ledger):** the sum of `dark_matter_transactions.amount` with type in (`auction_bid`, `auction_refund`) and description
  matching `Auction #A ` equals `-sum(held(A).amount)`.
- **I5 (delivery):** `claimed_at` goes from NULL to set at most once, only when `status = settled`, and the planet stock
  increase happens in the same transaction.
- **I6 (single active):** at most one row has `active_slot = 1` (DB-enforced).

`AuctionService::checkInvariants()` evaluates I1 to I5. Tests and the race command assert it returns `[]`.

### 4.2 Lock order (deadlock avoidance)

Every auction write follows one global order:

1. the `auctions` row (`lockForUpdate`);
2. `users` rows involved, in **ascending id** (`lockForUpdate`);
3. the `planets` row (claim only).

Nothing else in the codebase locks an auction row. Existing DM code (`DarkMatterService::debit/credit`,
`processRegeneration`) locks only a single user row, so no lock cycle is possible. `DarkMatterService::debit/credit`
re-lock the user row inside a nested transaction (a savepoint). Re-locking a row this transaction already holds is a
no-op in InnoDB, so we call them as they are and do not duplicate their balance logic.

### 4.3 `placeBid` (pseudo-code)

```php
return DB::transaction(function () use ($bidder, $auctionId, $amount): BidResult {
    $a = Auction::whereKey($auctionId)->lockForUpdate()->first();          // (1) serialises all bids on this auction
    $now = (int)Date::now()->timestamp;
    if ($a === null || $a->status !== Auction::STATUS_ACTIVE || $now >= $a->ends_at)  return reject('closed');
    if ($a->leader_user_id === $bidder->id)                                 return reject('already_leading');
    if ($amount < $this->minimumNextBid($a))                                return reject('too_low');

    $prevLeader = $a->leader_user_id; $prevAmount = $a->current_bid;
    $ids = array_filter([$bidder->id, $prevLeader]); sort($ids);
    User::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();      // (2) ascending id

    try {
        $this->darkMatter->debit($bidder, $amount, AUCTION_BID, "Auction #{$a->id} bid");   // throws on insufficient
    } catch (Exception) { return reject('insufficient_dm'); }   // nested savepoint rolled back, outer tx still clean

    if ($prevLeader !== null) {
        $this->darkMatter->credit(User::find($prevLeader), $prevAmount, AUCTION_REFUND, "Auction #{$a->id} refund (outbid)");
        AuctionBid::where('auction_id', $a->id)->whereNull('refunded_at')->update(['refunded_at' => $now]);  // exactly 1 row
    }

    $extended = false;
    if ($a->ends_at - $now <= snipeWindow) {
        $newEnd = min($now + snipeExtension, $a->original_ends_at + maxExtension);
        if ($newEnd > $a->ends_at) { $a->ends_at = $newEnd; $a->extension_count++; $extended = true; }
    }
    AuctionBid::create([... 'amount' => $amount, 'placed_at' => $now, 'extended_end' => $extended, 'is_bot' => $bidder->is_bot]);
    $a->current_bid = $amount; $a->leader_user_id = $bidder->id; $a->bid_count++; $a->save();
    return accept(..., outbidUserId: $prevLeader, refundedAmount: $prevAmount, extended: $extended);
});
// after commit: controller dispatches NotifyAuctionEvent('outbid' / 'extended')
```

Notes for the reviewer:

- The "insufficient DM" check is the one inside `debit()`, under the user lock. Never pre-check with a stale `$user->dark_matter`.
- The refunded user cannot spend the refunded DM before the commit, because their row is locked until then.
- If the bidder is deleted mid-request, `debit()` throws "User not found" and the bid is rejected.
- If the previous leader was deleted, `leader_user_id` was nulled by the FK (`nullOnDelete`) and their bid rows cascaded
  away. The DM vanished with the account, which matches how account deletion treats DM today. The next bid then sees
  `prevLeader = null`, and I1 still holds.
- The `unique(auction_id, amount)` index is a second guard. The row lock already prevents equal amounts.

### 4.4 `settleIfDue`

```php
DB::transaction(function () use ($id): bool {
    $a = Auction::whereKey($id)->lockForUpdate()->first();
    $now = (int)Date::now()->timestamp;
    if ($a === null || $a->status !== STATUS_ACTIVE || $now < $a->ends_at) return false;   // idempotent no-op
    $a->active_slot = null; $a->settled_at = $now;
    if ($a->leader_user_id === null) { $a->status = STATUS_UNSOLD; }
    else { $a->status = STATUS_SETTLED; $a->winner_user_id = $a->leader_user_id; $a->final_price = $a->current_bid; }
    $a->save();
    return true;
});
// bot winner: after commit, call claim() onto homeworld
```

A bid and a settlement for the same auction serialise on the auction row lock. Either the bid commits first, and
`ends_at` may move, so the settle no-ops if it is no longer due. Or the settle commits first, and the bid sees
`status != active` and is rejected. There is no window in which a bid is debited after the winner is fixed.

### 4.5 `claim`

```php
DB::transaction(function () use ($winner, $auctionId, $planetId): string {
    $a = Auction::whereKey($auctionId)->lockForUpdate()->first();
    if ($a === null || $a->status !== STATUS_SETTLED || $a->winner_user_id !== $winner->getId()) return 'not_yours';
    if ($a->claimed_at !== null) return 'already_claimed';
    $planet = Planet::whereKey($planetId)->where('user_id', $winner->getId())->where('destroyed', 0)->lockForUpdate()->first();
    if ($planet === null) return 'bad_planet';
    // Atomic column increments; never PlanetService::save() of a possibly stale model.
    Planet::whereKey($planet->id)->update([
        'metal' => DB::raw('metal + ' . (int)$metal), 'crystal' => ..., 'deuterium' => ...,
        // plus one "<machine_name> = <machine_name> + n" per unit, machine names whitelisted via ObjectService
    ]);
    $a->claimed_at = (int)Date::now()->timestamp; $a->claimed_planet_id = $planet->id; $a->save();
    return '';
});
```

Lot resources deliberately **bypass storage capacity**. A salvage crate is a prize, like expedition loot. The UI says so.

The claim uses atomic `col = col + n` updates rather than `PlanetService::addResources()` + `save()`. That way a concurrent
request holding a stale in-memory planet cannot overwrite the delivery with an older value (see the "state cached in
service singletons" warning in `AGENTS.md`). The implementer must confirm that the planet column names for units equal
their `machine_name`, as `PlanetService::addUnit` implies, and that the destroyed-planet flag column is named as above.

### 4.6 `cancel`

Lock the auction row, then the leader's user row. Credit `current_bid` with type `AUCTION_REFUND` and description
`Auction #N refund (cancelled)`. Set `refunded_at` on the held row, `status = cancelled` and `active_slot = NULL`. All of
this happens in one transaction. Only an active auction can be cancelled. A settled auction cannot be reversed by this
command; that needs a manual admin DM adjustment.

### 4.7 `openIfDue`

Compute today's `auction_date` and the open timestamp. If `now < open` or `current() !== null`, return null. Otherwise
draw the lot and insert with `active_slot = 1` inside try/catch on `UniqueConstraintViolationException`. On a duplicate,
return null. `withoutOverlapping()` is a convenience; correctness comes from the two unique indexes.

### 4.8 Race-condition test command

Add `app/Console/Commands/Test/TestRaceConditionAuctionBid.php`, signature `ogamex:test:race-condition-auction-bid`,
extending `TestCommand`. `TestCommand::runParallelRequests()` only issues GETs, so add a protected
`runParallelPostRequests(string $endpoint, array $payloads)` to `TestCommand`. It reuses `getCsrfToken()` and must not
change the GET helper's behaviour.

- **Setup:** create the auction directly with `current_bid = 0` and `starting_bid = 100`. Give the test user 10,000 DM.
  Create a second user as the existing leader holding a bid of 500.
- **Scenario A (double submit):** 20 parallel bids from the same user, all `amount = 600`. Expect exactly one accepted,
  the leader refunded once, and the user's balance at 9,400.
- **Scenario B (bid vs settle):** set `ends_at = now + 1`, fire 20 bids while running `settleIfDue` in a loop. Afterwards,
  `checkInvariants()` returns `[]` and DM conservation holds:
  `sum(balances before) == sum(balances after) + sum(held amounts)`.
- **Scenario C (double claim):** settle the auction, then send 20 parallel claim POSTs. `claimed_at` is set once and the
  planet metal increases by exactly the lot amount.

The run is documented in `app/Console/Commands/Test/README.md`.

---

## 5. UI wiring

### 5.1 Entry points

- `resources/views/ingame/merchant/index.blade.php`: change the `#js_traderAuctioneer` link from `href="#"` to
  `href="{{ route('merchant.auction.index') }}"`. Add a small live badge (`.ia-auction-badge`) with the current bid or
  "Closed". This is server-rendered; no polling on the Merchant page.
- `resource-market.blade.php`: leave the hidden tile (`style="display: none;"`) as it is.
- Optional: show a line in the overview event box when the player has unclaimed winnings. Not part of v1.

### 5.2 Page: `resources/views/ingame/merchant/auction.blade.php`

The page extends `ingame.layouts.main`. It wraps in `#traderOverviewcomponent`, so the merchant banner rules apply, and
then sets `#planet` to the auction banner via a new `.ia-auction` class.

Structure:

```
#traderOverviewcomponent.ia-auction
  #planet (banner auction.jpg) + #header_text h2 = t_merchant.auction.title + back link to merchant.index
  .ia-auction-lot          lot icon (img/iron-armada/auction/<lot_key>.webp), lot name, contents list, "bypasses storage" note
  .ia-auction-status       current bid, leader (or "No bids yet"), bid count, countdown (data-ends-at), "Extended" chip
  .ia-auction-bidform      DM balance, number input (min = minimum_next_bid), quick buttons [+min] [+10%] [+25%], Bid button
  .ia-auction-feed         last 10 bids
  .ia-auction-unclaimed    one row per unclaimed win: planet <select> (player's planets, current preselected) + Claim (POST form)
  .ia-auction-history      last 7 closed auctions: lot, winner, price / "Unsold"
```

When no auction is active, show `t_merchant.auction.closed` with a countdown to `next_opens_at`. When
`auction_enabled = 0`, show `t_merchant.auction.disabled` and hide the badge on the Merchant tile.

### 5.3 Live updates: polling (v1), Reverb (v2, optional)

**v1 polls.** Inline JS in the blade, following the existing jQuery style on merchant pages:

- `GET merchant.auction.state` every 10 s, every 3 s once `ends_at - server_now < 300`, and paused while
  `document.hidden`.
- The countdown is computed from `server_now`, with the offset against the client clock taken once per response, so a
  skewed client clock does not matter.
- The bid POST sends `_token` and returns `state`. The UI re-renders from that response, then resumes polling.
- When a response shows the player was outbid (the `you_lead` flag went from true to false), flash
  `t_merchant.auction.outbid_flash`.

Why polling: Reverb is optional at runtime (`resources/js/ingame/echo.js` bails if no key is configured), it runs behind
the Cloudflare tunnel on a separate port, and today's only channels are private chat channels. One indexed primary-key
read every 10 s per open auction tab is negligible on this server's scale.

**v2 (separate, optional task):** add an `AuctionUpdated implements ShouldBroadcast` event on a new private channel
`auction` (authorised for any logged-in user in `routes/channels.php`), fired after commit from `placeBid` and settle.
The page subscribes via `window.Echo` when it exists and drops the poll to 30 s. Polling stays as the fallback.

### 5.4 CSS

Add to `resources/css/ingame/iron-armada.css` in a new `/* ===== Armada Salvage Auction ===== */` block. Use the
existing tokens (`--ia-hull*`, `--ia-seam`, `--ia-steel-hi`, `--ia-amber`, `--ia-amber-hi`, `--ia-ink*`) and the "Barlow
Condensed" heading style from `.ia-directives-heading`:

- `#traderOverviewcomponent.ia-auction #planet { background-image: url(/img/iron-armada/banners/auction.jpg) !important; }`
- `.ia-auction-lot`, `.ia-auction-status`, `.ia-auction-bidform`, `.ia-auction-feed`, `.ia-auction-unclaimed`,
  `.ia-auction-history`, `.ia-auction-badge`, `.ia-auction-chip` (extended / leading / outbid states).
- Countdown under 5 min: amber pulse. Respect `prefers-reduced-motion`.
- Mobile at `@media (max-width: 900px)`, matching the directives breakpoint: single column, 44 px minimum tap targets
  on the bid buttons, numeric keypad via `inputmode="numeric"`.

Then run `npm run build` and commit `public/build`.

---

## 6. Discord event hooks

All hooks go through `NotifyAuctionEvent` → `AuctionNotifier` → `DiscordNotifier::send()`, after commit. The messages are
plain English and are not translated, matching the existing `PirateService` and `CreateNewUser` posts. Player names are
already safe because the notifier sends `allowed_mentions: parse []`.

| Event | When | Message |
|-------|------|---------|
| `opened` | tick opens an auction | `:package: Salvage Auction open: **{lot}**. Bidding starts at {start} DM. Hammer falls <t:{ends_at}:R>.` |
| `final_call` | first tick with ≤ 15 min left (once, `final_call_sent`) | `:hourglass: Final call on **{lot}**: {bid} DM by **{leader}**.` |
| `extended` | first anti-snipe extension only (`extension_count === 1`) | `:crossed_swords: Bidding war on **{lot}**! Clock extended.` |
| `won` | settle with a winner | `:hammer: SOLD! **{lot}** to **{winner}** for {price} DM.` |
| `unsold` | settle without bids | `:wastebasket: No bids. **{lot}** was scrapped.` |

Individual outbids are **not** posted, because they would spam the channel. Discord's `<t:...:R>` gives each reader a
relative time in their own timezone.

---

## 7. Translation keys

Add these to `resources/lang/en/t_merchant.php` as a nested `'auction' => [...]` array, used as
`__('t_merchant.auction.<key>')`. The existing `auctioneer` and `auctioneer_desc` keys stay. Update `auctioneer_desc`'s
English to match the feature. This is allowed because the auction is an Iron Armada feature, not an OGame string.

```
title, intro, closed, disabled, opens_in, ends_in, extended, current_bid, starting_bid, no_bids, leader, you_lead,
bid_count, your_balance, minimum_bid, place_bid, bid_placed, outbid_flash, quick_min, quick_10, quick_25,
storage_bypass_note, recent_bids, history, history_unsold, unclaimed_title, claim, claim_to_planet, claimed,
badge_current, badge_closed,
lots.<lot_key>                 (one name per catalogue key, e.g. lots.crate_metal => 'Salvaged Metal Crate')
error.closed, error.already_leading, error.too_low, error.insufficient_dm, error.not_allowed,
error.not_yours, error.already_claimed, error.bad_planet, error.disabled
```

Updated tile description, `auctioneer_desc`:
`'The Salvage Corps auctions one prize every day. Bid dark matter; the highest bid when the hammer falls wins.'`

Messages go in `resources/lang/en/t_messages.php`, following the `welcome_message` shape (`from`, `subject`, `body`):
`auction_outbid` and `auction_won`, with `from` set to `'Salvage Corps'`.

Admin labels go in the `t_ingame.php` `admin` block: `auction_*` labels for the nine settings.

For other locales, create no files. English is the fallback. If AI translations are added later, mark them with
`// AI-TRANSLATED: verify against original OGame text` per `AGENTS.md`, even though no original exists for these
strings, so the marker stays greppable.

---

## 8. Test plan

Write these as class-based feature tests run by Pest (`composer run tests`), matching `tests/Feature/DirectivesTest.php`:
extend `IsolatedAccountTestCase`. Time is controlled with `travelTo()` / `Carbon::setTestNow()`.
`IsolatedAccountTestCase` already pins time to 2024-01-01 00:00. Never use `sleep`.

`tests/Unit/AuctionLotCatalogTest.php`
- Every catalogue unit key is a real ship machine name, and every lot has a positive starting bid.
- `draw()` is deterministic for the same date, never repeats the previous key, and uses only `grand_*` lots on a Sunday.

`tests/Feature/AuctionOpenSettleTest.php`
- The tick before the open hour creates nothing. The tick after it creates one auction with `active_slot = 1`.
  Running it twice creates no second auction.
- With an active auction, `openIfDue` returns null (I6).
- Settle before `ends_at` is a no-op. After `travelTo(ends_at)`: no bids gives `unsold`, with bids gives `settled` with
  winner and price, and `active_slot` is NULL.
- After settlement the next day's open works.

`tests/Feature/AuctionBidTest.php`
- A bid below the minimum is rejected with `too_low`, and DM is unchanged.
- A first bid at `starting_bid` is accepted: DM is debited, one `auction_bid` transaction is recorded, and the user is leader.
- When outbid, the previous leader gets the full refund, one `auction_refund` transaction, and `refunded_at` set;
  `checkInvariants()` is `[]`.
- The leader bidding again is rejected with `already_leading`.
- A bidder with insufficient DM is rejected with `insufficient_dm`: no partial debit, no bid row, leader unchanged.
- Anti-snipe: a bid at `ends_at - 60` moves `ends_at` to `now + 300` and sets `extended_end`. Repeated late bids never
  push `ends_at` past `original_ends_at + 3600`.
- A bid at `ends_at` or later is rejected with `closed`.
- The minimum increment formula holds at the boundaries (0, small bids, large bids, rounding to 10).

`tests/Feature/AuctionClaimTest.php`
- The winner claims to their own planet: resources and units increase by exactly the payload, and `claimed_at` is set.
- A second claim returns `already_claimed` and changes nothing.
- A non-winner claim returns `not_yours`. A claim to another player's planet returns `bad_planet`.
- The claim exceeds storage capacity (documented bypass).

`tests/Feature/AuctionHttpTest.php`
- `GET /merchant/auction` returns 200 and shows `__('t_merchant.auction.title')`. The merchant index links to it, not `#`.
- The state JSON matches the contract keys and contains no user ids.
- The bid POST without CSRF returns 419. Validation errors return 422. The throttle returns 429 after 20 per minute.
- Disabled feature: the page shows the disabled message and the bid is rejected with `disabled`.
- `GET /api/public/highscores` is unchanged (regression guard).

`tests/Feature/AuctionNotifyTest.php`
- `Http::fake()`: opening sends one Discord post, and settlement sends one `won` or `unsold` post.
- The final call is sent once across repeated ticks. An outbid does not post to Discord.
- An outbid in-game message is sent when the preference is 1 and not sent when it is 0.
- `Queue::fake()`: bid and tick dispatch `NotifyAuctionEvent`; nothing is sent inside the transaction.

`tests/Feature/AuctionBotTest.php`
- Bots disabled: `consider()` does nothing.
- Bots enabled: the bot bids the minimum when under its valuation ceiling, never bids inside the snipe window, and never
  bids as leader.

`tests/Feature/AuctionCancelTest.php`
- Cancelling refunds the leader, sets status `cancelled`, frees `active_slot`, and leaves invariants clean.

Manual concurrency checks run `php artisan ogamex:test:race-condition-auction-bid` (section 4.8) next to the existing
`ogamex:test:race-condition-game-mission`.

Full chain before every PR: `composer run rector`, `composer run cs`, `composer run stan`, `composer run tests`.

---

## 9. Task breakdown

Each task is one PR, per `AGENTS.md` ("one issue, one PR"). Do not open the next PR while an earlier one has unresolved
review comments. "Model" is the cheapest model that can do the task well.

| # | Task | Files | Model | Depends on |
|---|------|-------|-------|-----------|
| T1 | Migrations (auctions, auction_bids, settings) + models `Auction`, `AuctionBid` + enum cases | `database/migrations/2026_11_20_00000{1,2,3}_*.php`, `app/Models/Auction.php`, `app/Models/AuctionBid.php`, `app/Enums/DarkMatterTransactionType.php` | sonnet | none |
| T2 | English lang strings (`t_merchant.auction.*`, `lots.*`, `error.*`, `t_messages` auction_outbid/won, `t_ingame.admin.auction_*`, updated `auctioneer_desc`) | `resources/lang/en/t_merchant.php`, `t_messages.php`, `t_ingame.php` | haiku | none |
| T3 | Lot catalogue + `AuctionLot` value object + unit test | `app/GameConstants/AuctionLots.php`, `app/Services/AuctionLotCatalog.php`, `app/Models/Auction/AuctionLot.php`, `tests/Unit/AuctionLotCatalogTest.php` | sonnet | none |
| T4 | `AuctionService`: open / bid / settle / claim / cancel / invariants + `BidResult` | `app/Services/AuctionService.php`, `app/Models/Auction/BidResult.php` | sonnet | T1, T3 |
| T5 | **Concurrency review** of T4 against section 4: lock order, savepoint behaviour of nested `debit()`/`credit()`, atomic planet increments, invariants I1 to I6 | review comments only | **opus** | T4 |
| T6 | Feature tests: bid, open/settle, claim, cancel | `tests/Feature/Auction{Bid,OpenSettle,Claim,Cancel}Test.php` | sonnet | T4 (can be written alongside) |
| T7 | Race command + `TestCommand::runParallelPostRequests` + README entry | `app/Console/Commands/Test/TestRaceConditionAuctionBid.php`, `TestCommand.php`, `Test/README.md` | sonnet | T4 |
| T8 | `AuctionTick` scheduler command + schedule entry + `AuctionCancel` admin command | `app/Console/Commands/Scheduler/AuctionTick.php`, `app/Console/Commands/Admin/AuctionCancel.php`, `routes/console.php` | sonnet | T4 |
| T9 | `AuctionNotifier`, `NotifyAuctionEvent` job, `AuctionOutbid`/`AuctionWon` game messages + notify test | `app/Services/AuctionNotifier.php`, `app/Jobs/NotifyAuctionEvent.php`, `app/GameMessages/Auction{Outbid,Won}.php`, `tests/Feature/AuctionNotifyTest.php` | sonnet | T2, T4 |
| T10 | `AuctionController` + routes + HTTP test | `app/Http/Controllers/AuctionController.php`, `routes/web.php`, `tests/Feature/AuctionHttpTest.php` | sonnet | T4, T9 |
| T11 | Auction page markup (static structure, server-rendered first paint) + merchant tile link and badge | `resources/views/ingame/merchant/auction.blade.php`, `merchant/index.blade.php` | haiku | T2, T10 |
| T12 | Polling JS (inline in the blade): countdown, bid POST, quick buttons, outbid flash | `auction.blade.php` script block | sonnet | T11 |
| T13 | CSS block + mobile rules + `npm run build`, commit `public/build` | `resources/css/ingame/iron-armada.css`, `public/build/*` | haiku | T11, A1 to A3 |
| T14 | Admin server-settings "Auction" fieldset | `Admin/ServerSettingsController.php`, server settings blade | haiku (view) + sonnet (validation) | T1, T2 |
| T15 | Bot strategy + `BotService::takeTurn` hook + bot test | `app/Services/AuctionBotStrategy.php`, `BotService.php`, `tests/Feature/AuctionBotTest.php` | sonnet | T4 |
| T16 | (v2, optional) Reverb `AuctionUpdated` event + channel + JS subscribe | `app/Events/AuctionUpdated.php`, `routes/channels.php`, `auction.blade.php` | sonnet | T12 |
| T17 | Final concurrency sign-off: run the race command, re-check invariants, approve enabling `auction_enabled` in prod | review only | **opus** | T7, T8, T10 |

T1, T2 and T3 can run in parallel. After T4, tasks T6 to T9 and T15 are independent of each other. Opus does only T5
and T17.

---

## 10. Art needs (Gemini image model)

House style: dark industrial sci-fi, gunmetal hulls with blue-steel seams, amber hazard accents, matching the palette
`#070a0e / #182532 / #3b6385 / #8fb3d1 / #d18533 / #e7b15a`. No text or logos in the images. Banner sizes and formats
match the existing `public/img/iron-armada/banners/*.jpg` (1308×600). Lot icons match `public/img/iron-armada/obj/*.webp`
(200×200, transparent).

| ID | File | Size / format | Prompt brief |
|----|------|---------------|--------------|
| A1 | `public/img/iron-armada/banners/auction.jpg` | 1308×600 JPEG, progressive, ≤ 250 KB | Interior of an orbital salvage hangar. A massive mechanical claw holds a battered cargo container under a single amber spotlight. Holographic bid readouts float as abstract glowing panels with no legible text. Keep the left third darker so the page title overlays cleanly. |
| A2 | `public/img/iron-armada/auction/crate_metal.webp` | 200×200 WebP, alpha | Heavy armoured cargo crate, open lid, grey metal ingots. |
| A3 | `.../auction/crate_crystal.webp` | 200×200 WebP, alpha | Same crate family, glowing blue crystal shards. |
| A4 | `.../auction/crate_deuterium.webp` | 200×200 WebP, alpha | Same crate family, racked green-teal deuterium canisters. |
| A5 | `.../auction/crate_convoy.webp` | 200×200 WebP, alpha | Three stacked crates of the family above, strapped together. |
| A6 | `.../auction/hulls_fighters.webp` | 200×200 WebP, alpha | Rack of small fighter hulls in a docking cradle, mothballed with tarps. |
| A7 | `.../auction/hulls_cruisers.webp` | 200×200 WebP, alpha | Two cruiser hulls side by side in a dry-dock frame. |
| A8 | `.../auction/hulls_cargo.webp` | 200×200 WebP, alpha | Bulky cargo freighter hull with salvage tags. |
| A9 | `.../auction/hulls_recyclers.webp` | 200×200 WebP, alpha | Recycler hull with arms folded. |
| A10 | `.../auction/hulls_battleships.webp` | 200×200 WebP, alpha | Battleship hull in scaffolding, amber work lights. |
| A11 | `.../auction/hulls_pathfinders.webp` | 200×200 WebP, alpha | Sleek scout hull with sensor fins. |
| A12 | `.../auction/hoard_pirate.webp` | 200×200 WebP, alpha | Pirate strongbox with skull-stencil paint, spilling mixed resources. |
| A13 | `.../auction/grand_deathstar.webp` | 200×200 WebP, alpha | Silhouette of a moon-sized battle station behind a gold "grand lot" frame (frame only, no text). |
| A14 | `.../auction/grand_flotilla.webp` | 200×200 WebP, alpha | Small formation of capital ships under a gold frame. |
| A15 (optional) | `public/img/iron-armada/ui/auction_gavel.webp` | 96×96 WebP, alpha (matches `ui/menu_*.webp`) | Mechanical gavel or hammer icon for the merchant tile badge and the Discord-style "sold" state. |

Delivery: all files go in a new `public/img/iron-armada/auction/` directory. Filenames must equal the catalogue
`lot_key`, because the view builds the path as `/img/iron-armada/auction/{lot_key}.webp`. The view falls back to
`/img/iron-armada/obj/{first unit machine-name icon}` or the crate_convoy icon when a file is missing, so art can land
after the code.

---

## 11. Out of scope (v1)

- Proxy / max bidding.
- Multiple simultaneous lots, and alliance bidding.
- Dark matter lots (section 1.2).
- Resource-currency bids.
- Refunding or reversing a settled auction (use a manual admin DM adjustment).
- Translations beyond English.
- Fixing `DirectiveService::claim()`, which increments `dark_matter` without writing a `dark_matter_transactions` row.
  This was noticed while writing the spec and belongs in a separate issue.
