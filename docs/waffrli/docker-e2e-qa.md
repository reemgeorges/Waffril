# WAFFRLI — Docker environment, E2E and QA report (branch `feature/docker-e2e-qa`)

This branch is based on `origin/feature/final-audit-i18n`. Everything below was executed in a sandbox on the Docker Compose stack this branch adds:

- MySQL 8.4, Redis 7.4, Reverb, a queue worker, the scheduler, Nginx, PHP 8.3 FPM and Mailpit;
- 2026-10-31 to 2026-11-01.

Each result says how it was obtained. Anything that was **not** executed is listed under [Not executed / blockers](#not-executed--blockers).

## Final release gate (2026-11-01, branch `feature/docker-e2e-qa`)

Every row was executed in this run. Nothing below is extrapolated.

| Gate | Command | Result |
|---|---|---|
| PHPUnit, **MySQL 8.4**, full suite, one complete run (`waffrli_testing`) | `docker compose exec -e DB_CONNECTION=mysql -e DB_DATABASE=waffrli_testing app php vendor/bin/phpunit` | **529 tests, 7143 assertions: 0 failures, 0 errors, 1 skipped** (a DDL test that would commit MySQL's test transaction; it runs on SQLite). 5 PHPUnit deprecation notices, from doc-comment metadata in starter tests. |
| PHPUnit, SQLite, full suite | `docker compose exec app php vendor/bin/phpunit` | **529 tests: 0 failures, 0 errors, 13 skipped** (MySQL-only tests) |
| Tests never touch the dev database | row counts of all 68 tables, plus `CHECKSUM TABLE` of orders, wallets, wallet_transactions, order_payments and plus_cycles, before and after the MySQL run | **identical** |
| Tests never modify committed files | `git status` after both runs; `OpenApiAndPostmanGenerationTest` and `MakeAuthModuleOpenApiTest` now snapshot and restore `docs/openapi` and `docs/postman` | **clean** |
| Unknown API routes | `ApiErrorHandlingTest` (15 cases; 12 fail on the old handler) | **404** in the envelope, localized (ar/en), no path echo. Wrong method: 405. Real errors: still 500, with details hidden in production. 401 unchanged. |
| Bruno CLI 2.15.1 on Docker | `bru run --env Local` in each collection | **Customer 108/108 (335 tests), Driver 46/46 (148), Admin 69/69 (221)** |
| Route coverage | `php artisan waffrli:api-inventory --check` | **136 routes, all covered** (dev OTP and the disabled legacy routes are exempt) |
| OpenAPI | `waffrli:openapi --check` + Redocly lint | **valid, 0 errors**; drift test green |
| E2E on the stack (HTTP + real WebSocket + Chromium) | `node e2e/run.mjs` | **34 passed, 0 failed, 0 skipped** ([report](qa/e2e-latest.md)) |
| Reverb mobile clients | E2E steps 0.1, WS.2, WS.4 + `ReverbOriginConfigTest` | **No Origin** (native app) accepted; foreign and look-alike origins refused (4009); private-channel auth, isolation, no foreign GPS, reconnection |
| Filament browser validation | `node e2e/filament-qa.mjs` | **104/104 page views clean** (AR/RTL and EN/LTR, dark and light, desktop and mobile) |
| Shared API portal, two accounts | `node e2e/portal-collab.mjs` | **9/9** ([report](qa/portal-latest.md)) |
| Fresh setup from a clean checkout | `./scripts/setup.sh --demo --yes --no-admin --portal` in an isolated Compose project | **exit 0.** All services healthy; `/up`, `/admin/login`, `/docs`, product images and portal all 200; demo data seeded. Data survived `down`/`up`. One defect found and fixed (`APP_KEY`, see below). |
| Schema SQL vs migrations | fresh `migrate` into a throwaway database, then `mysqldump --no-data`, then `diff` | **identical** (68 tables) |
| Restore safety | `restore.sh` against a non-allow-listed database and with `APP_ENV=production` | **refused (exit 3)** in both cases; allowed test database restored |
| Dependency security | `composer audit`; `npm audit` | Composer: **no advisories**. npm: **0 high, 0 moderate** (`undici` overridden to 7.30.1); 6 low, all in Scalar's AI chat, which is disabled in our viewer. |
| Secrets | pattern scan of tracked files, logs, docs and portal exports | **none found** |
| Docker exposure | `docker compose ps` | only `127.0.0.1` ports; MySQL, Redis and the portal's PostgreSQL are not reachable from outside the host. Redis and portal PostgreSQL are not published at all. |
| Pint | changed files | clean. 99 issues in the untouched starter files predate this branch. |
| **Static analysis** | — | **NOT RUN.** Larastan could not be downloaded in this sandbox. `composer.json` and `composer.lock` are verified unmodified. |
| **Windows / PowerShell** | — | **NOT TESTED** (no PowerShell or Windows here). The scripts were reviewed line by line. Manual steps are under [Not executed / blockers](#not-executed--blockers). |
| CI | `.github/workflows/ci.yml` | added. The result on the pull request is reported in the PR. |

### Defects found and fixed in this final pass

| Defect | Fix |
|---|---|
| Unknown `/api/*` routes answered **500**; the handler did not handle `HttpExceptionInterface` | Status preserved in the envelope; 3 legacy tests corrected; regression test added |
| Native mobile WebSocket clients (no `Origin`) were refused unless origins were `*` | `REVERB_ALLOW_ORIGINLESS_CLIENTS` opt-in; browsers stay restricted to listed hosts |
| Starter generator tests **deleted the committed** `docs/openapi` and `docs/postman` on every run | Snapshot and restore (`tests/Concerns/PreservesGeneratedDocs`) |
| Generated starter docs depended on the developer's `APP_NAME` | `APP_NAME` pinned in `phpunit.xml` |
| Fresh setup failed at `key:generate`, because the container could not write a host-owned `.env` | `APP_KEY` generated by the setup script |
| `restore.sh` could target any database in the local MySQL | Hard guards: `APP_ENV`, the Compose `mysql` host, and an allow-list of database names |
| `undici` high and moderate advisories (Scalar's Node-side dependency) | npm `overrides` → 7.30.1 |

## 1. Docker environment

| File | Purpose |
|---|---|
| `compose.yaml` | Services: `web` (Nginx), `app` (FPM), `queue`, `scheduler`, `reverb` (the one WebSocket server), `mysql` 8.4, `redis` 7.4, `mailpit`, and `node` (tools profile) |
| `docker/php/Dockerfile` | Ubuntu 24.04 with PHP 8.3 packages: FPM and CLI, mysql, sqlite, redis, intl, gd, zip, bcmath, opcache |
| `docker/php/{php.ini,www.conf}`, `docker/nginx/default.conf` | Runtime configuration. Nginx sets security headers and `server_tokens off` |
| `docker/mysql/init/01-testing-database.sh` | Creates `waffrli_testing` and grants it to the app user |
| `docker/certs/` | Optional corporate CA certificates (`*.crt` are git-ignored) |
| `.env.docker.example` | Template with no secrets |
| `scripts/setup.ps1`, `scripts/setup.sh` | One-command safe setup |
| `scripts/db/*` | backup, restore, schema-dump, demo-dump, render-erd |

The services also provide:

- health checks on web, app, reverb, mysql, redis and mailpit;
- `depends_on` with `service_healthy`;
- named volumes `mysql-data` and `redis-data`, which survived a full Docker daemon restart in this run;
- every port bound to `127.0.0.1`, with Redis not published at all.

Commands and URLs: [README.md](../../README.md) §4–§5.

### Defects found and fixed while validating the stack

| Defect | Effect | Fix |
|---|---|---|
| `storage:link --relative` needs `symfony/filesystem`, which is not installed | The link was never created, so **every product image returned 403**. `setup.sh` hid the error with `\|\| true`, and `setup.ps1` would abort. | Absolute link (every container mounts `/var/www/html`), created only when missing |
| `REVERB_ALLOWED_ORIGINS=http://localhost:8080` | Reverb compares **host names**, so the admin panel's sockets were refused (4009) | `localhost,127.0.0.1`, documented |
| Bruno `params:query` blocks | The Bruno CLI never sent them, so `per_page` and `scope` were ignored | Query strings are now part of the URL |
| Admin login returned the **password hash** (`BasicResource`) | Credential exposure | Fixed and covered by `HiddenAttributesTest` (commit `5cdebb6`) |
| Tests depended on the developer's `.env` (push, OTP peek, `APP_NAME`) | Local failures; generated docs re-titled | Pinned in `phpunit.xml` |

## 2. API collections (Bruno)

There are three collections: `api-tests/bruno/WAFFRLI-Customer`, `WAFFRLI-Driver` and `WAFFRLI-Admin-API`.

Every `.bru` file carries:

- the method, URL, headers and auth;
- an example body;
- variables;
- docs;
- tests asserting the status code, the envelope and business rules.

Negative and authorization tests live in dedicated folders. Each collection has a `Local` environment (the Docker stack) and a `Shared-Dev` environment. Tokens are captured at runtime, and secrets come from each collection's git-ignored `.env`.

The `.bru` files are generated from `api-tests/bruno/generators/*.py`. The route → request map is `docs/waffrli/api-inventory.md`, and `ApiInventoryTest` fails when a route has no request or a request calls a route that doesn't exist.

## 3. OpenAPI and Scalar

`docs/waffrli/openapi/waffrli-api.yaml` documents 121 paths and 136 operations. It is generated from three sources:

- the **registered routes**;
- each action's **FormRequest rules**, which give the bodies and query parameters;
- **real Bruno exchanges**, captured with tokens, passwords and OTP codes redacted.

It documents:

- the envelope, including the validation `errors` map;
- per-guard security;
- AR/EN behaviour;
- both pagination shapes;
- throttles;
- the raw `broadcasting/auth` body.

The development-only OTP route and the disabled legacy starter routes are excluded.

Scalar is served at `/docs` only when `WAFFRLI_API_DOCS_ENABLED=true`, and never in production. It is vendored from npm (pinned at 1.72.2), with no CDN or web fonts, and with telemetry, the AI agent and MCP turned off. In Chromium it loaded with **0 external requests**.

## 4. Database documentation

`php artisan waffrli:db-docs` reads INFORMATION_SCHEMA of the live MySQL 8.4 database. The schema has 68 tables, 129 foreign keys and 93 CHECK constraints. The command writes:

- `docs/waffrli/database/README.md`: the table inventory, plus Spatie JSON columns, polymorphic pairs and cardinalities;
- `erd.md`: Mermaid diagrams (full, financial, PLUS, notifications, catalog/orders);
- SVG renders of those diagrams.

`scripts/db/schema-dump.sh` writes `schema.sql` (schema only, deterministic). No table is hand-written or invented.

## 5. Seeders, backup and restore

- **Idempotency:** a second demo-seeder run adds or removes **no row**. This is proven on MySQL by `WaffrliDemoSeederTest`, which also checks:
  - financial and ledger invariants;
  - PLUS free-delivery accounting;
  - every order status;
  - dashboard numbers;
  - and, new on this branch, that every translatable value has both `ar` and `en` text.
- On the live dev database, re-running the essential seeder changed 0 of 1,081 rows. The demo seeder correctly **refused** to run, because E2E and Bruno runs had created non-demo orders; this is its safety guard.
- **Restore** was drilled on `waffrli_testing`. A stray table survived the first drill, which exposed a real defect: the restore did not drop the old database first. It now drops and recreates the database, and the stray table no longer survives.
- **Synthetic demo dump:** built in a throwaway database (40 KB). It contains no real e-mail address. The only credentials in it are bcrypt hashes of random secrets that nobody knows.

## 6. End-to-end scenario (`e2e/run.mjs`)

Every step and its result is in the [last run report](qa/e2e-latest.md). How each kind of step was verified:

- **Real HTTP** against Nginx and PHP-FPM, on MySQL.
- **Real WebSocket:** a Node client speaking the Pusher protocol to Reverb, authorized by the app's own endpoints. Not mocked.
- **Real browser:** Chromium on Filament. Accept, Assign and Refund were done through the UI. The panel's own Echo client verified the admin channels and the live map marker.
- **Fake providers:**
  - SMS: the OTP is read from the development-only peek route;
  - FCM: the `fake` transport, observed through the queue worker's log (by token hash) and through token deactivation.

  **No real SMS, WhatsApp or FCM message was sent or claimed.**

### Realtime results (real WebSocket)

- Connection works, and a client **without** an Origin header is refused (4009).
- Private-channel authorization works:
  - own channels are allowed;
  - another customer's order and user channels are refused (403);
  - the driver is refused on the admin map (403);
  - a forged signature is refused by Reverb.
- `order.status_changed` reached the customer socket within 260–900 ms, at every status, and the admin browser too.
- Assignment reached the driver socket.
- `driver.location` reached the customer and the admin map, and **not** the other customer (isolation).
- Reconnection keeps delivering events.
- The Filament map marker moved to the new GPS position.

## 7. Filament browser validation (`e2e/filament-qa.mjs`)

The script covered all 41 panel pages without a record id: Dashboard, Stores, Branches, Products, Orders, Drivers, PLUS, Wallets, Payments, Map, Settings and the rest. It used Arabic (RTL) and English (LTR), dark and light themes, and desktop 1440 × 900 plus mobile 390 × 844.

All **104 page views** were clean:

- HTTP 200;
- no JavaScript error;
- no failed or 4xx request;
- `lang` and `dir` correct;
- the logo present;
- no raw translation key;
- no horizontal overflow.

Screenshots are in `qa/screenshots/`. Map tiles from OpenStreetMap could not load in this sandbox because it has no internet route; markers render regardless.

## 8. Security, performance and reliability checks

| Check | Result |
|---|---|
| Secrets in git | None. Pattern scan of tracked files found no keys, tokens, private keys or passwords; only `.env.example` templates are tracked. |
| Secrets in logs | `storage/logs/laravel.log` contains no Sanctum token, password, OTP code or raw push token. |
| Docker network | All published ports bind to 127.0.0.1. Redis is not published. No database, Redis or Reverb management UI exists. |
| CORS | `*` without credentials, for a bearer-token API. Browsers never send the token automatically. Restrict it per deployment if a browser client is added. |
| Channel auth | Strict, verified on the real WebSocket (see §6). |
| Separated auth | A customer token is refused by the driver and admin APIs, and vice versa. Single session per customer verified. |
| Upload validation | Covered by tests: avatar, delivery photo, product image. |
| Rate limits | Observed live: OTP 5/hour per phone plus a 60 s cooldown; 5/min per IP; driver API; admin realtime auth 60/min. |
| Financial transactions | Idempotent checkout: 4 parallel requests with one key produced 1 order. A consumed cart is refused. Payments equal the order total. The wallet ledger entry is recorded. Refunds credit the wallet. |
| Queue | 3 tries with 10 s backoff. `queue:failed` is empty. Failed jobs are visible through `php artisan queue:failed`. |
| Latency (Docker dev, OPcache with timestamp checks) | p50 30–60 ms, p95 under 80 ms on `/up`, driver and admin endpoints (25 requests each). |

## Open findings

1. **Admin paginated keys** are `deliveryareas` and `deliveryfeerules` (lower-cased model name, plural), while `/all` returns `delivery_areas`. This is a starter `BasicCrudService` contract, kept for compatibility and documented in Bruno and OpenAPI.
2. **Test hygiene:** the starter generator tests write into the source tree (`database/migrations`, `routes/`) and clean up afterwards, so they need a writable checkout. Their committed docs are now restored byte for byte.
3. **Portal onboarding window:** Hoppscotch's onboarding endpoint is open until the first administrator signs in to `/admin`. Do that immediately after the first start. Local installs bind to `127.0.0.1`.

## Not executed / blockers

1. **Windows PowerShell execution: NOT TESTED.** `scripts/setup.ps1` and `scripts/db/*.ps1` were reviewed for PowerShell 5.1/7 syntax and parity with the Bash scripts, which were executed. Manual verification on a Windows 10/11 machine with Docker Desktop and WSL2:
   1. `git clone …`, then `cd waffrli`.
   2. `powershell -ExecutionPolicy Bypass -File .\scripts\setup.ps1 -Demo -Yes -Portal`; expect exit 0 and the URL list.
   3. Open http://localhost:8080/admin, http://localhost:8080/docs, http://localhost:3000 and http://localhost:8025.
   4. Run `docker compose ps`: all services are healthy, and the only published ports are `127.0.0.1`.
   5. Run `docker compose down`, then `docker compose --profile portal up -d`; the data is still there.
   6. Run `.\scripts\db\backup.ps1`, then `.\scripts\db\restore.ps1 backups\<file>`; typing anything other than the database name cancels.
2. **Static analysis: NOT RUN.** It is not installable in this sandbox. Recommended: `composer require --dev larastan/larastan` on a developer machine, then run it at level 5 with a baseline.
3. **Portal access from two different computers: NOT TESTED.** The two accounts used two browser profiles on one machine. HTTPS deployment steps are in `api-portal.md` §5.
4. **Real SMS, WhatsApp and FCM delivery: NOT TESTED.** No provider credentials exist, and none were invented. Fake drivers were used, and the reports say so.
5. **Sandbox network limits (reported, not bypassed):** Docker Hub rate limits; `deb.debian.org`, `pecl.php.net` and the Alpine CDN blocked; no Packagist or npm access from containers; OpenStreetMap tiles unreachable; GitHub release downloads blocked for PowerShell and Larastan.
