# WAFFRLI — Docker environment, E2E and QA report (branch `feature/docker-e2e-qa`)

This branch is based on `origin/feature/final-audit-i18n`. Everything below was executed in a sandbox on the Docker Compose stack this branch adds:

- MySQL 8.4, Redis 7.4, Reverb, a queue worker, the scheduler, Nginx, PHP 8.3 FPM and Mailpit;
- 2026-10-31 to 2026-11-01.

Each result says how it was obtained. Anything that was **not** executed is listed under [Not executed / blockers](#not-executed--blockers).

## Summary of results

| Check | Command | Result |
|---|---|---|
| PHPUnit, SQLite (default) | `docker compose exec app php vendor/bin/phpunit` | **511 tests: 0 failures, 0 errors, 13 skipped** |
| PHPUnit, **MySQL 8.4** (`waffrli_testing`) | `docker compose exec -e DB_CONNECTION=mysql -e DB_DATABASE=waffrli_testing app php vendor/bin/phpunit` | 511 tests: 1 skipped, and 50 errors in the first run. See note 1: after the fix, **the 62 tests of the 8 affected classes pass on MySQL** (0 errors). |
| Bruno CLI 2.15.1, Customer | `bru run --env Local` | **108/108 requests, 335/335 tests** |
| Bruno, Driver | same | **46/46, 148/148** |
| Bruno, Admin | same | **69/69, 221/221** |
| API route coverage | `php artisan waffrli:api-inventory --check` | **136 routes, all covered** |
| OpenAPI | `php artisan waffrli:openapi --check` + Redocly CLI lint | **valid, 0 errors** (one warning: the license has no URL) |
| E2E on the stack (HTTP + real WebSocket + browser) | `node e2e/run.mjs` | **33 passed, 0 failed, 0 skipped** ([report](qa/e2e-latest.md)) |
| Filament browser validation | `node e2e/filament-qa.mjs` | **104 page views clean** ([report](qa/filament-latest.md)) |
| Seeders on MySQL | `WaffrliDemoSeederTest`, `DriverMapTest` (MySQL) | **21 tests, 957 assertions, passing** |
| Pint | `vendor/bin/pint --test` on the files this branch changed | clean (note 2) |
| Static analysis | — | **not executed** (blocker 3) |

**Note 1, MySQL errors.** All 50 errors had the same cause: `file_put_contents(): Permission denied`. The scaffold-generator tests write into `database/migrations`, `routes/` and `docs/`, and in this sandbox those directories belonged to root while the containers run as UID 1000. With `DOCKER_UID` set to the developer's UID (the default setup), they are writable. After fixing the ownership, the 8 affected classes ran again on MySQL: 62 tests, 0 errors.

The same investigation found a **real test bug**, now fixed. `OpenApiAndPostmanGenerationTest` and `MakeAuthModuleOpenApiTest` deleted the **committed** `docs/openapi` and `docs/postman` folders in `tearDown()` on every run. They now snapshot those folders and restore them byte for byte (`tests/Concerns/PreservesGeneratedDocs`).

**Note 2, Pint.** The repository has 99 pre-existing style issues in starter files this branch does not touch. Two touched files (`BasicResource.php`, `TestCase.php`) already failed Pint on the base branch and were left as they were, to avoid unrelated diffs.

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

## Open findings (not changed, decision needed)

1. **Unknown `/api/*` routes answer 500 instead of 404.** This is a documented legacy starter contract, and about 30 starter tests assert it. Changing it to 404 is recommended, but it needs that test migration.
2. **Admin paginated keys** are `deliveryareas` and `deliveryfeerules` (lower-cased model name, plural), while `/all` returns `delivery_areas`. This is a starter `BasicCrudService` contract, kept for compatibility and documented.
3. **Reverb origins vs native apps.** A WebSocket client that sends no `Origin` header is refused unless `REVERB_ALLOWED_ORIGINS=*`. Verify what the mobile apps send before restricting origins in production. That trade-off is a product decision.
4. **Test hygiene:** the starter generator tests write into the source tree (`database/migrations`, `routes/`). They clean up after themselves, but they need a writable checkout.

## Not executed / blockers

1. **`scripts/setup.ps1` and the `scripts/db/*.ps1` scripts were not executed.** PowerShell is not installed in the sandbox, and downloading it was refused by the sandbox policy. `setup.sh` was executed end to end; the PowerShell scripts mirror its steps.
2. **Windows/WSL2 itself** was not available. The stack was validated on Linux, Docker Engine 29.9 with Compose 5.6.
3. **Static analysis** (Larastan/PHPStan) could not be installed: Composer could not download the package from GitHub through the sandbox network. There is no analyser configured in the project.
4. **Sandbox network limits (reported, not bypassed):**
   - Docker Hub rate-limited the image pulls (429), so the images were pulled from `mirror.gcr.io`;
   - `deb.debian.org`, `pecl.php.net` and the Alpine CDN were blocked (403), hence the Ubuntu base image;
   - OpenStreetMap tiles were unreachable.
5. **Real SMS, WhatsApp and FCM delivery** were not tested. No provider credentials exist, and none were invented.
