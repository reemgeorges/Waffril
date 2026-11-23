---
name: waffrli-backend
description: Work safely on the WAFFRLI Laravel backend, APIs, Filament, notifications, realtime services, testing, and local staging.
---

# WAFFRLI backend

WAFFRLI is a delivery platform. This repository is its single Laravel backend:

- a **customer API** (`/api/v1/customer/*`) used by the customer Flutter app;
- a **driver API** (`/api/v1/driver/*`) used by the driver Flutter app;
- an **admin API** (`/api/v1/admin/*`) and a **Filament admin panel** (`/admin`, Arabic RTL + English);
- one **Laravel Reverb** WebSocket server, a queue (Redis in Docker, `database`/`sync` on Laragon), **FCM** push, and **MySQL 8**.

The released mobile apps depend on this API. Treat every public response as a contract.

Before you change anything, read the source. Where the code and the docs disagree, the code wins. Then fix the doc in the same change.

## 1. Where things live

| Area | Location |
|---|---|
| Route files (each auto-loaded under `api/v1` with the `api` group) | `routes/api/v1/{customer,driver,admin,general,platform,user,dev}.php`; the loader is in `bootstrap/app.php` |
| Middleware aliases (Laravel 12, no `Kernel.php` for HTTP) | `bootstrap/app.php`: `role`, `permission`, `guard.identity`, `driver.active`, `customer.active`; `ForceJsonResponse` appended globally; `SetRequestLocale` on `api` |
| Rate limiters (`customer-api`, `driver-api`, `customer-otp`, `driver-login`, `driver-location`, …) | `app/Providers/AppServiceProvider.php` |
| Response envelope and exception mapping | `app/Http/Traits/GeneralTrait.php` (`apiResponse`), `app/Exceptions/Handler.php`, `app/Providers/ApiExceptionServiceProvider.php` |
| Controllers, requests, resources | `app/Http/{Controllers,Requests,Resources}` |
| Business logic (keep it out of controllers and Filament) | `app/Services/*`: `Order/OrderStateMachine`, `Order/CheckoutService`, `Delivery/*`, `Driver/*`, `Customer/*`, `Wallet/WalletLedger`, `Plus/PlusService`, `Push/*`, `Realtime/{Realtime,Notify}`, `Notifications/AdminAlerts`, `Auth/OtpService` |
| Enums | `app/Enums/{OrderStatus,DeliveryOfferStatus}.php` |
| Filament | `app/Filament/{Resources,Pages,Widgets,Support,Concerns}`, `app/Providers/Filament/AdminPanelProvider.php`, policies in `app/Policies` |
| Channels | `routes/channels.php`, `app/Support/Realtime/ChannelAccess` |
| Auth modules (guards `user`, `driver`, `admin`) | `config/auth.php`, `config/auth_modules.php`, `app/Providers/AuthModulesServiceProvider.php` |
| WAFFRLI config | `config/{waffrli,dispatch,delivery,otp,reverb,broadcasting}.php` |
| Project commands | `app/Console/Commands/Waffrli/*` (`waffrli:api-inventory`, `waffrli:openapi`, `waffrli:api-contract`, `waffrli:db-docs`, `waffrli:dispatch-sweep`, `waffrli:create-super-admin`, `waffrli:demo-driver-password`, …) |
| Translations | `lang/{ar,en}/`. `api_labels.php` is the **app** vocabulary; `admin.php` is the **panel** vocabulary |
| Docs | `README.md` (local setup), `docs/waffrli/*` (`customer-api.md`, `driver-api.md`, `realtime-and-push.md`, `delivery-offers.md`, `api-inventory.md`, `openapi/`, `database/`, `audit/`) |
| API collections | `api-tests/bruno/WAFFRLI-{Customer,Driver,Admin-API}`, Hoppscotch export in `api-tests/hoppscotch/` (generated) |
| E2E on a running stack | `e2e/run.mjs`, `e2e/filament-qa.mjs`, `e2e/portal-collab.mjs` |

`CLAUDE.md` at the root describes the original starter-kit goals (generator, auth modules, OTP, soft delete). Its rules still apply. The `laravel-architect` agent covers starter-kit-level refactors. This skill covers WAFFRLI product work.

## 2. Stack facts

- **Laravel 12**, PHP **8.3** in Docker and CI (`composer.json` allows `^8.2`). Configure middleware, routing and exceptions in `bootstrap/app.php`; there is no HTTP kernel.
- **Filament 5** (`filament/filament ^5.11`). Use the Filament 5 APIs that the existing resources use (schemas, `Filament\Actions\*`). Don't copy v2/v3 snippets from memory. Check `vendor/filament` or an existing resource first.
- **Sanctum 4** tokens per guard; **spatie/laravel-permission 6** for admin roles and permissions; **spatie/laravel-translatable** for JSON `ar`/`en` columns.
- **MySQL 8** (8.4 in Docker and CI) with utf8mb4. The default test run uses SQLite `:memory:`. Some tests are MySQL-only and are skipped on SQLite, so keep code portable across both (JSON columns, case-insensitive search, CHECK constraints, FK `ON DELETE`).
- **Money and currency:** use `app/Support/Money` and the currency services. Never use floats for amounts. Read `docs/waffrli/currency-switch.md` before you touch currency.

## 3. API compatibility rules (customer and driver apps)

These apps are released. Unless the user explicitly approves a breaking change and a versioning plan:

1. **Envelope never changes:** `{"data": …, "status": bool, "error": string|null, "statusCode": int}`. Validation errors answer **400**. Business refusals use their own code (404 not found or not yours, 409 state conflict, 422 rule violated, 429 throttled, 503 provider down).
2. **Don't remove or rename** a route, HTTP method, request field, response field or machine value (`status`, `type`, `payment.status`, enum values). Don't change a field's type or nullability, and don't change pagination shape.
3. **Additive only:** new optional request fields, new response fields, new endpoints. A new required field is a breaking change.
4. **Human labels for the apps come from `lang/{ar,en}/api_labels.php`** through `OrderStatus::publicLabel()` / `DeliveryOfferStatus::publicLabel()` and `App\Support\Localization\Label`. Panel wording edits go in `admin.php` and must not reach the API (`ApiLabelVocabularyTest`). To change app wording on purpose, edit `api_labels.php` and say so in the PR.
5. **Auth stack per route group must stay intact:**
   - customer: `auth:user` + `guard.identity:user` + `customer.active` + `throttle:customer-api`;
   - driver: `auth:driver` + `guard.identity:driver` + `driver.active` + `throttle:driver-api`.

   Tokens from one guard must get 401 on the others. Another user's resources answer 404.
6. **Secure by default.** A new route is authenticated unless public access is explicitly asked for. A public route must be read-only and throttled.
7. **Never expose** password hashes, remember tokens, OTP hashes, raw FCM tokens (use `token_hint`) or secrets in a resource.
8. Send `Accept-Language: ar|en`. With no header, the configured locale applies. Check both languages.
9. **Customer identity:** phone OTP only. Purposes `customer_login`, `customer_recovery` and `customer_email_verify` are not interchangeable. There is one active device per customer.
10. **Driver identity:** phone + password, created by admins only, no self-registration. A driver can have up to 3 devices for push.

**After any API change, keep the contracts in sync** (CI fails otherwise):

```bash
php artisan waffrli:api-inventory --check
```

```bash
php artisan waffrli:openapi --check
```

```bash
php artisan waffrli:api-contract --check
```

- **New route:** add a Bruno request (and negative or authorization cases) in the matching collection, then regenerate the inventory and OpenAPI.
- **Portal export:** regenerate it with `python3 api-tests/bruno/generators/hoppscotch.py` and commit the result.
- **Docs:** update `docs/waffrli/customer-api.md` or `driver-api.md`.

## 4. Filament admin panel

- Every resource has a policy in `app/Policies`, backed by Spatie permissions. Actions check permissions too (e.g. `orders.assign`). Don't bypass a policy, and don't add a resource without one.
- **Business actions call services** (`OrderAdminService`, `DriverAdminService`, `DeliveryOfferService`, …). Never write order, wallet or status columns directly from a form or action.
- **Audit** admin mutations through `App\Services\Audit\AdminAudit`, the way the existing actions do.
- **Navigation:** catalog children (Products, Branches, ratings, categories) open only in the store context (`Concerns/UnderStores`). Keep that structure (see `docs/waffrli/admin-ux-*.md`).
- **Both languages:** add `ar` and `en` strings in `lang/*/admin.php` and check the RTL layout.
- **Theme:** after theme or CSS changes, run `npm run build:admin-theme`.

## 5. Realtime (Reverb) and notifications

- **One Reverb server** serves customers, drivers and the panel. All channels are **private**, and client events are disabled. Channels:
  - `private-user.{id}`, `private-driver.{id}`, `private-order.{id}`;
  - `private-admin.orders`, `private-admin.drivers-map`, `private-admin.{id}`.
- **Authorization endpoints:** `POST /api/v1/customer/broadcasting/auth`, `POST /api/v1/driver/broadcasting/auth`, and `POST /broadcasting/auth` (panel session).
- **Publish only through `Realtime::publish()`. Notify only through `Notify::send()`** (or `AdminAlerts::send()`).
  - All three run **after commit**, deliver each recipient and channel separately, and **log instead of throwing**.
  - Never call `$model->notify()` or `broadcast()` inside a business transaction. A Reverb outage must never roll back an order, an assignment or a payment (audit defects N-1 to N-3).
- **Origins:** `REVERB_ALLOWED_ORIGINS` holds **host names**, not URLs, and never `*` in production. Native apps need `REVERB_ALLOW_ORIGINLESS_CLIENTS=true`. Error 4009 means the origin host is not listed.
- **Queues:** broadcasts and pushes are queued, so a worker is needed. With delivery offers on, `schedule:work` is also needed for the 15-second `waffrli:dispatch-sweep`. After changing PHP code, restart the queue and Reverb processes.
- **Missed events are not replayed.** The apps must re-fetch on resume and on reconnect. Never design a feature that relies on a socket event alone; the database notification is the source of truth.

## 6. Push (FCM HTTP v1)

- **Transport:** `App\Services\Push\FcmHttpV1Transport` (service-account JWT, then an OAuth token cached for 50 min). `FakePushTransport` is used locally and in tests.
- **Environment switches:**
  - `WAFFRLI_PUSH_ENABLED` turns push on;
  - `WAFFRLI_PUSH_DRIVER` is `fake` or `fcm`;
  - credentials come from `FIREBASE_CREDENTIALS` (a JSON path **outside the repository**) or `FIREBASE_PROJECT_ID` / `FIREBASE_CLIENT_EMAIL` / `FIREBASE_PRIVATE_KEY`.
- **Fake driver behaviour:** it logs `push.fake_delivered` with a token hash. A token starting with `fake-unregistered-` simulates `UNREGISTERED`, and the token is deactivated.
- **Status:** real-device delivery has **not** been verified. Never claim it works.
  - Enable `fcm` on staging first, with a test phone, and check `push.sent` in the log.
  - Only send a real push when the user explicitly authorizes it for that environment.
- **Device-token lifecycle:** the same `device_id` replaces the old token. Logout deactivates that device's tokens. Keep this behaviour (`DeviceTokenLifecycleTest`).
- **`WAFFRLI_PUSH_SKIP_WHEN_ONLINE`:** the audit recommends `false` in production; see `docs/waffrli/audit/notification-realtime-audit.md` §6.

## 7. Database and migrations

- **Additive migrations only by default.** Add tables and columns, and add new columns as nullable or with a default.
  - Dropping, renaming or changing a column's type needs explicit user approval, a data-migration plan, and a working `down()`.
- **Destructive commands are forbidden** without explicit authorization: `migrate:fresh`, `migrate:reset`, `migrate:rollback`, `db:wipe`, `docker compose down -v`, force deletes, and `restore` scripts against a database the user cares about.
- **Seeders:**
  - `WaffrliEssentialSeeder` is idempotent: roles, permissions and settings;
  - `WaffrliDemoSeeder` runs only in `local`/`testing` and refuses databases with real orders.

  Never seed a shared, staging or production database as a side effect.
- **Schema docs:** after a migration, regenerate them with `php artisan waffrli:db-docs` and `scripts/db/schema-dump.sh`, and commit `docs/waffrli/database/`.
- **Soft deletes:** activate/deactivate (`is_active`) and soft-delete/restore (`deleted_at`) are separate states. Permanent deletion needs its own authorization. Don't reintroduce `forceDelete()` behind a "delete" action.

## 8. Testing

Run the narrowest relevant tests first, then the wider suite. Report exactly what ran, the pass/fail/skip counts, and what could not run. Never invent results.

| Purpose | Command (prefix with `docker compose exec app` when using Docker) |
|---|---|
| One test class | `php artisan test --filter=ApiLabelVocabularyTest` |
| Full suite on SQLite (the `phpunit.xml` default) | `php artisan test` |
| Full suite on MySQL 8 | `DB_CONNECTION=mysql DB_DATABASE=waffrli_testing php vendor/bin/phpunit` |
| Code style | `vendor/bin/pint --test` (format only the files you touched: `vendor/bin/pint <paths>`) |
| Routes | `php artisan route:list --path=api/v1` |
| Contract drift | the three `--check` commands in §3 |
| Bruno | `bru run --env Local` inside a collection folder (local stack only) |

- **Database guard:** `tests/TestCase.php` refuses a MySQL database whose name doesn't end in `_test` or `_testing`. Never weaken that guard.
- **Pinned environment:** `phpunit.xml` pins push, SMS, docs and locale, so tests never touch real providers. Keep it that way: use `Http::fake`, `Notification::fake`, `Queue::fake` and the fake transports.
- **Regression tests:** for a bug fix, write a test that fails on the old code and passes on the fix.
- **Tests must not modify committed docs.** CI runs `git diff --exit-code -- docs api-tests` after PHPUnit.
- **CI:** `.github/workflows/ci.yml` runs PHPUnit on SQLite and on MySQL 8.4, plus the contract checks and the portal-export check.

## 9. Git safety

- **Never commit to `main`, push to `main`, or merge into it** unless the user explicitly asks.
- **Branching:** start each piece of work on a branch from the latest `origin/main`, named `feature/<topic>` or `fix/<topic>`, or the branch the session assigns. Open a PR and wait for the user's review.
- **Before editing:** run `git status`. Never discard, stash-drop or overwrite uncommitted changes you didn't make.
- **Forbidden without explicit permission:**
  - `git push --force`, except `--force-with-lease` on your own branch;
  - `git reset --hard` on shared branches;
  - rewriting history that others have pulled;
  - deleting branches.
- **Commits:** keep them small and focused, with conventional prefixes (`feat(api):`, `fix(notifications):`, `docs(audit):`, …). Explain the *why* and list the tests that ran.
- **Never commit** `.env`, `.env.*` other than the `*.example` files, Firebase JSON, Twilio or SMS keys, Reverb secrets, tokens, `backups/`, database dumps containing real data, or `docker/certs/*.crt`. If you see a secret, don't print it; redact it in output.
- **No deploys:** don't run deploy, release or production commands.

## 10. Local environments

There are two supported local setups. Before giving commands, ask which one the user is on, or detect it.

### A. Docker (README)

- **Setup:** `./scripts/setup.sh`, or `scripts\setup.ps1` on Windows; both are idempotent.
- **URLs:** API `http://localhost:8080/api/v1`, panel `/admin`, Reverb `ws://localhost:8085`, Mailpit `:8025`, MySQL `127.0.0.1:33060` (`waffrli` dev, `waffrli_testing` tests).
- **Hostnames:** inside containers `REVERB_HOST=reverb` and `DB_HOST=mysql`.
- **Ports:** every port binds to 127.0.0.1 only.

### B. Windows laptop with Laragon (local staging for the apps)

- **Project path:** `C:\laragon\www\Waffril`. Use PowerShell or Laragon's terminal. Paths use backslashes, and line endings are handled by `.gitattributes`.
- **`.env` is hand-maintained and may differ from Docker.** Don't assume its values; ask, or read only the non-secret keys you need, and never echo secrets.
- **Typical `.env` values** (from the audit, §7):
  - `REVERB_HOST=127.0.0.1`, `REVERB_PORT=8080`, `REVERB_SCHEME=http`, `REVERB_SERVER_HOST=0.0.0.0`;
  - `QUEUE_CONNECTION=database` (or `sync` without a worker);
  - `WAFFRLI_PUSH_DRIVER=fake` until Firebase is ready;
  - `WAFFRLI_SMS_DRIVER=fake`.
- **Processes**, one terminal each:

  ```powershell
  php artisan serve --host=0.0.0.0 --port=8000
  ```

  ```powershell
  php artisan reverb:start
  ```

  ```powershell
  php artisan queue:work --tries=3 --sleep=1
  ```

  ```powershell
  php artisan schedule:work
  ```

- **After pulling changes**, in order:
  1. Back up the database (Laragon → MySQL export, or `mysqldump`).
  2. Run `composer install`.
  3. Run `php artisan migrate`. Never run `migrate:fresh` here.
  4. Run `php artisan config:clear`.
  5. Restart the queue and Reverb.

### Cloudflare Tunnel (exposing the laptop to phones and testers)

Cloudflare Tunnel (`cloudflared`) is used to reach the Laragon backend from real devices over HTTPS. **The repository holds no tunnel config.** The tunnel token or credentials JSON stays on the laptop (e.g. `%USERPROFILE%\.cloudflared\`). Never commit it, print it or put it in `.env.example`.

- **Two public hostnames**, one per local service:
  - API and panel → `http://localhost:8000`;
  - Reverb → `http://localhost:8080` (the tunnel carries WebSocket upgrades).
- **Laravel settings behind the tunnel:**
  - `APP_URL=https://<api-hostname>`, so generated URLs, storage links and Filament assets use HTTPS.
  - Laravel 12 doesn't trust proxy headers by default; this repo configures no `trustProxies` in `bootstrap/app.php`. If URLs or redirects come out as `http://` behind the tunnel, propose adding `$middleware->trustProxies(at: '*', headers: …)` limited to local/staging. Don't add it silently.
- **Reverb behind the tunnel:**
  - Clients use `host=<reverb-hostname>`, `port=443`, `scheme=https` (wss). Server-side Laravel keeps publishing to `127.0.0.1:8080`, through `REVERB_HOST/PORT/SCHEME` or the `REVERB_PUBLIC_*` values for the panel.
  - Add the panel's tunnel host to `REVERB_ALLOWED_ORIGINS` (host only). Keep `REVERB_ALLOW_ORIGINLESS_CLIENTS=true` for the apps.
- **Restarts:** after `.env` changes, run `php artisan config:clear` and restart Reverb, the queue and `serve`.
- **Exposure:** the tunnel makes the laptop internet-reachable.
  - Keep `APP_DEBUG=false` while it is up.
  - `WAFFRLI_API_DOCS_ENABLED` and the dev OTP peek route are reachable through the tunnel. Disable them, or keep the peek token secret, when outsiders have the URL.
  - Use only fake SMS and push providers unless the user authorizes real ones.
  - Stop the tunnel when you're done.
- **Verify before handing a URL to testers:**
  1. `GET https://<api-hostname>/up` → 200.
  2. `GET https://<api-hostname>/api/v1/nope` → the 404 JSON envelope.
  3. A WebSocket connect plus `broadcasting/auth` from a test client.
- **Run nothing destructive** against the Laragon database: the tunnel-backed apps and testers use it as local staging data.

## 11. Working checklist

1. `git status`, then branch from the latest `origin/main`.
2. Read the relevant code, tests and doc in `docs/waffrli/`.
3. Make a small change, in a service rather than a controller or Filament, with a test.
4. Run targeted tests → Pint on the touched files → route list → contract checks → the full suite when the change is broad.
5. Update docs and the Bruno, OpenAPI and inventory files when contracts or behaviour change.
6. Commit, push the feature branch, and open a PR with the test results. Don't merge.
7. Report the touched files, what ran, what could not run, and any remaining risk.

Never do any of the following without explicit approval:

- deploy;
- run destructive migrations or database commands;
- send a real SMS, WhatsApp or push message;
- seed a non-local database;
- add hard-coded OTP bypasses or credentials;
- add public mutation routes.
