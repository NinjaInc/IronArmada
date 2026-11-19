This directory contains custom php artisan commands that are used to run tests that utilize
parallel requests to check for race condition bugs. These tests are NOT meant to be run
in a production environment, and are only meant to be run in a development environment.

The tests are run using the following command (this runs an example test):

```bash
php artisan ogamex:test:race-condition-game-mission
```

## Auction bid race test

`ogamex:test:race-condition-auction-bid` checks the Armada Salvage Auction (docs/AUCTION-SPEC.md section 4.8) over real
HTTP. It needs the auction routes (`merchant.auction.bid`, `merchant.auction.claim`) and exits with a message if they
are missing. It will not run in production or while a real auction is active.

```bash
php artisan ogamex:test:race-condition-auction-bid
php artisan ogamex:test:race-condition-auction-bid --bidders=6
```

Each iteration creates test users (the main user, a leader and `--bidders` minus 1 extra bidders, 10,000 dark matter
each) and runs three scenarios, each on a freshly created auction:

- **A, double submit:** 20 parallel bids of 600 from one user. Expects exactly one accepted, the leader (holding 500)
  refunded once, balances 10,000 and 9,400.
- **B, bid vs settle:** the auction ends 4 seconds out and anti-snipe is switched off. 20 parallel bids from the bidders
  race a second PHP process looping `settleIfDue`. Expects dark matter conserved
  (`sum(balances before) == sum(balances after) + held bids`).
- **C, double claim:** the auction is settled, then 20 parallel claim POSTs. Expects `claimed_at` set and the planet metal
  raised by one lot only.

After each scenario `AuctionService::checkInvariants()` must return `[]`. The command prints `PASS` or `FAIL` and exits
0 or 1. It temporarily sets `auction_enabled` (and zeroes `auction_snipe_window_seconds` for scenario B), restores both,
and deletes its test auctions, ledger rows and users when done. `TestCommand::runParallelPostRequests()` is the POST
counterpart of `runParallelRequests()`.
