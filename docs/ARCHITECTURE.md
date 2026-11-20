# Iron Armada: code map

Generated alongside the knowledge graph in `graphify-out/` (3,693 nodes, 10,763 edges, built 2026-11-20 from `app/`, `routes/`, `config/`, `database/`, `rust/`, `resources/views`, `docs/` and the compose and nginx files). Open `graphify-out/graph.html` in a browser for the interactive map, read `graphify-out/GRAPH_REPORT.md` for the hubs and surprising links, and use `/graphify query "<question>"` in Claude Code to ask the graph. Re-run `/graphify <path> --update` after big changes.

## Where things live

| Area | Where |
|---|---|
| Fleet missions (the core gameplay) | `app/GameMissions/` (`AttackMission`, `TransportMission`, `ExpeditionMission`, ...), base class `Abstracts/GameMission.php`, dispatch and events in `app/Services/FleetMissionService.php`, UI in `app/Http/Controllers/FleetController.php` |
| Battle engine | `app/GameMissions/BattleEngine/` (`PhpBattleEngine`, `RustBattleEngine`); Rust crate in `rust/battle_engine_ffi`. Any combat change must go in both |
| Planets and resources | `app/Services/PlanetService.php` (largest service), `PlayerService`, `ObjectService`; stats and requirements in `app/GameObjects/`, formulas in `app/GameConstants/` |
| Build and research queues | `AbstractBuildingsController`, `AbstractUnitsController`, `ResearchController`, queue models and `*QueueService` |
| Alliances, buddies, chat, messages | `AllianceController`, `BuddiesController`, `ChatController` + `ChatMessageSent` (Reverb), `app/GameMessages/` + `GameMessageFactory` |
| Highscores | `HighscoreService`, scheduler commands `GenerateHighscores*`, public feed `PublicHighscoreController` (`/api/public/highscores`) |
| Admin | `app/Http/Controllers/Admin/` (server settings, rules, bots, fleet timing, server administration, developer shortcuts); routes in `routes/web.php` under the `admin` middleware |
| Auth | Laravel Fortify: `app/Providers/FortifyServiceProvider.php`, `app/Actions/Fortify/`, login view `resources/views/outgame/login.blade.php` |
| Scheduler | `app/Console/Commands/Scheduler/` (highscores, wreck-field and debris cleanup, `RunBots`, `UserDirective`, `AuctionTick`) |
| Queue jobs | `app/Jobs/` (fleet processing and notifications) |
| Schema | `database/migrations/` (never edit a merged one) |
| Views and skin | `resources/views/ingame`, `outgame`; CSS in `resources/css/ingame/iron-armada.css` and `outgame/iron-armada.css`; `public/build` is tracked, run `npm run build` |

## Iron Armada additions

| Feature | Code |
|---|---|
| Bots (NPC empires) | `BotService`, `BotFleetService`, `app/Console/Commands/Scheduler/RunBots.php`, `Admin/BotsController`, migration `2026_11_10_000001_add_bot_fields_to_users_table` |
| Pirate raids | `PirateService` (protection rules, cooldowns, daily cap) |
| Directives | `DirectiveService`, `UserDirective` command, migration `2026_11_11_000001_create_user_directives_table` |
| Discord | `DiscordNotifier` (webhook from Server Settings), `app/Exceptions/Handler.php` (exception alerts) |
| Player options | `OptionsController`, `UserPreferencesService`, `users.preferences` JSON (migration `2026_11_12_000001`) |
| PWA and mobile | `public/manifest.webmanifest`, `public/sw.js`, `resources/views/partials/pwa-head.blade.php`, mobile rules at the end of `iron-armada.css` |
| Auctions | `docs/AUCTION-SPEC.md`, `AuctionTick`, `NotifyAuctionEvent`, `AuctionBotStrategy`, migrations dated `2026_11_20` |

## Runtime

Docker compose services: `ogamex-app` (PHP-FPM), `ogamex-webserver` (nginx), `ogamex-db` (MariaDB), `ogamex-scheduler`, `ogamex-queue-worker`, `ogamex-reverb` (websockets), `ogamex-phpmyadmin`. `docker-compose.override.yml` publishes game 8280 and websocket 8290 for the Cloudflare Tunnel. See `HANDOVER.md` for deploy steps and open problems.
