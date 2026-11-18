# Iron Armada: handover

Written 2026-11-18 so work can continue in Claude Code. Everything here comes from the chat sessions and the git history. Nothing in the sandbox could boot the app: it only had PHP 8.3 (syntax checks only), so **none of the PHP changes were run or tested by Claude**. Treat all of it as unverified until you have run the test suite on the server.

## Project

- Private OGame-style browser game, forked from OGameX (lanedirt, MIT). Repo: `github.com/NinjaInc/IronArmada`, pushing straight to `main`.
- Live: https://ironarmada.archonhosting.au (Docker, behind a Cloudflare Tunnel). Tunnel targets `<host-ip>:8280` (game) and `8290` (websocket), published by `docker-compose.override.yml`.
- Stack: Laravel (PHP ^8.5), Blade, legacy jQuery, Vite, MariaDB, Reverb, Rust battle engine via FFI.
- Services: `ogamex-db`, `ogamex-app`, `ogamex-webserver`, `ogamex-scheduler`, `ogamex-queue-worker`, `ogamex-reverb`, `ogamex-phpmyadmin`. The entrypoint runs `php artisan migrate --force` on start.
- Owner: Josh Neufield (Archon Tech). Commit trailer preference: `Co-Authored-By: Josh Neufield <ozeyeball@gmail.com>`.

## Working rules and gotchas

- `public/build` is tracked. After any CSS/JS change run `npm run build` and commit the output.
- Theme lives in `resources/css/ingame/iron-armada.css` (imported last, `--ia-*` variables) and `resources/css/outgame/iron-armada.css`. It overrides the legacy sprite CSS.
- Legacy dropdown JS (`$('select').ogameDropDown()`) only builds a list for `select:not(.dropdownInitialized)`. Render plain `<select>` elements, not pre-built spans.
- Dev checks, run in Docker: `composer run rector`, `composer run cs`, `composer run stan`, `composer run tests`. New schema changes go in new migrations only.
- Art assets are in `public/img/iron-armada/` (banners, ui, logos, icons) and `public/img/planets/`. They were generated outside the repo with conversion scripts (not committed) from Josh's Google Drive originals.

## What has been done (newest first)

- **2026-11-17** Android PWA support, mobile responsiveness and desktop UI polish (`5b8eec7`, made in Claude Code: manifest, `sw.js`, icons, nginx config).
- **2026-11-17** `GET /register`, `/forgot-password` and `/reset-password/*` returned a 500 (Fortify views unbound). They now redirect to login (`fa4c308`, `FortifyServiceProvider`).
- **2026-11-16** README rewritten for Iron Armada (`396581c`, plus `docs/images/banners.jpg` and `menu-icons.jpg`).
- **2026-11-16** Options page: dropdowns now work (plain selects, `e7089a9`) and preferences now save (`515642f`: the `selectedTab` hidden input was inside an HTML comment, and `OptionsController::save()` now always calls `UserPreferencesService::saveFromRequest`).
- **2026-11-16** Build, research and unit requests with a missing `_token` returned a 500 (`hash_equals` got null). Now cast to string, returns "Invalid token" JSON (`821970f`, `AbstractBuildingsController` lines ~256/299, `AbstractUnitsController`, `ResearchController`).
- **2026-11-15** Public highscore feed merged from PR #1 (`8dc67b2`) and alliance class icons wired.
- **2026-11-13/14** Art pass: page banners, menu icons, class emblems, planet thumbnails, galaxy banner, moon/debris/missile icons, HUD frames with corner brackets, tile state rings.
- **2026-11-12** Art pass: object tiles, resource and mission icons, planet headers, logos, favicon. Interface preferences stored per player in `users.preferences` (migration `2026_11_12_000001`).
- **2026-11-11** Gameplay and platform:
  - **Bots** (NPC empires): economy, research and fleet logic, colonise free slots, raid weaker bots, admin activity report.
  - **Pirate raids:** per-target cooldown, daily cap, protection rules.
  - **Directives:** daily and weekly objectives paying dark matter.
  - **Queue everything affordable** for buildings and research.
  - **Discord:** webhook URL set in Admin > Server Settings (stored in DB), game event notifications.
  - **Mobile:** layout and card-style Galaxy view.
  - **Reports:** battle and espionage reports restyled.
  - **Cleanup:** dead premium, shop and payment pages removed.
- **2026-11-10** Branding: outgame login redesign, Iron Armada copy and logo, dead links fixed, GameForge links removed, deployment override compose file and tunnel ports.

## Public highscore feed (contract, do not change without telling the xLAx site)

`GET /api/public/highscores`, no auth, `throttle:60,1`, cached 5 minutes. Returns `game`, `players`, `updated_at`, `categories{general,economy,research,military}[{rank,name,alliance,points,bot}]`, up to 10 per category. Admins and Legor excluded, bots included with `"bot": true`, no emails, IDs or coordinates. Code: `PublicHighscoreController`, route `public.highscores`, `tests/Feature/PublicHighscoreTest.php`.

## Not verified / needs doing on the server

1. **Deploy is manual.** `git pull`, rebuild the containers, `php artisan route:clear`. Until that happens the highscore feed (returned 404 externally when checked), Suoh's options fixes, the CSRF fix and the register redirect are not live.
2. Run the test suite (`composer run tests`) and fix anything failing. Nothing was run by Claude.
3. Curl the feed endpoint and confirm JSON. If it still 404s, check Cloudflare path rules.
4. Hard-refresh browsers after the UI update. For Suoh's options: if saving still fails, check `php artisan migrate:status | grep preferences`.
5. Visually unchecked on real pages: new frames and banners, galaxy banner, moon/debris icons, missile icon (16px slot, may look small), alliance icons.

## Open problems found (not fixed)

1. **Real client IP is wrong behind the tunnel.** No trusted-proxy configuration exists in `bootstrap/app.php`, so `request()->ip()` returns the cloudflared/Docker IP. Effects: `users.last_ip` is the same for everyone (multi-account detection in `ServerAdministrationController` is meaningless), the shared `throttle:60,1` on the public feed is one bucket for the whole internet, bans and IP logs are useless. Fix: `$middleware->trustProxies(...)` and read `CF-Connecting-IP` or `X-Forwarded-For`. Safe only if ports 80/443/8280 are not reachable except via the tunnel (restrict `at:` otherwise).
2. **Discord exception alert is thin.** `app/Exceptions/Handler.php` sends message, file and two stack lines only: no URL, user, real IP or commit SHA, and no dedupe (a loop floods the webhook). Add context and a cache-based dedupe (one per error per 10 min with a count).
3. **No outage alerting.** If the origin or tunnel is down the app cannot report it. Use Uptime Kuma or Cloudflare Tunnel health notifications, and add a scheduler heartbeat.
4. **GitHub Actions are upstream's.** `deploy-main.yml`, `deploy-release.yml`, `pr-preview*.yml` target `runs-on: [self-hosted, ctogx]` and `main.ogamex.dev`; no such runner exists for this repo, so they queue or fail. CI jobs (Pint, PHPStan, Rector, Pest, prod compose build) run on `ubuntu-latest`; their pass/fail status was never checked. Replace the deploy and preview workflows with one deploy workflow on a self-hosted runner on the Docker host that does `git pull`, `docker compose ... up -d --build`, `route:clear` and a Discord post.
5. **Admin could be better.** Six pages under `/admin/*` inside the game shell, same hostname as the game. Plan: Cloudflare Access (Zero Trust) on `/admin*`; a dedicated admin layout with a dashboard (online players, failed jobs, queue and scheduler health, bot and pirate stats, recent exceptions); an audit log for admin actions; disable Developer Shortcuts and impersonate in production behind an env flag.
6. **Multiple universes.** OGameX has no `universe_id`. Recommended: one compose stack per universe (own `COMPOSE_PROJECT_NAME`, `.env`, DB, Reverb port, subdomain; one cloudflared with a hostname each) plus a small hub page that lists universes and their highscore feeds.
7. **Branch protection** on `main` is not enabled. Josh wanted to add GitHub user `destinychoice` as a collaborator (the invite has to be done in the GitHub web UI; the API was blocked in the Claude sandbox).

## Smaller leftovers

- Ingame header logo needs a transparent cut-out (a webp attempt showed a lighter box and was reverted; the old PNG is in place).
- `mission_return` and `mission_pirate` buttons are not wired to new art.
- New Alliance Depot art not compared against the old.
- Discord webhook still pending rotation (it was exposed earlier in a chat).
- Update the git remote everywhere to `NinjaInc/IronArmada`.
- `AGENTS.md` still describes the project as OGameX; update it.
- README install section still uses the upstream `ogamex-*` container and `ogamex:admin:assign-role` names (they are the real names).

## Suggested order

Deploy and run tests, then the trusted-proxy/real-IP fix, Cloudflare Access on `/admin*`, alert context and dedupe, the Actions cleanup and deploy runner, the admin dashboard and audit log, and last the universe hub.
