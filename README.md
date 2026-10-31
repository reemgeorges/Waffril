# WAFFRLI — local development

WAFFRLI is a delivery platform built on Laravel 12 (PHP 8.3). It has three parts:

- a **customer API** for the customer app;
- a **driver API** for the driver app;
- a **Filament admin panel**.

It also has one shared **Reverb** WebSocket server, a Redis queue, and MySQL 8. This guide gets a complete local copy running with Docker on Windows 10/11 (Docker Desktop + WSL2), Linux or macOS.

> Local development only. Nothing here is a production deployment. Never run these scripts, seeders, the E2E runner or the Bruno collections against a shared, staging or production server.

## Contents

1. [Prerequisites (Windows)](#1-prerequisites-windows)
2. [Get the code](#2-get-the-code)
3. [One-command setup](#3-one-command-setup)
4. [URLs and services](#4-urls-and-services)
5. [Everyday Docker commands](#5-everyday-docker-commands)
6. [Environment (.env)](#6-environment-env)
7. [Database: migrations, seeders, demo data](#7-database-migrations-seeders-demo-data)
8. [Accounts: Super Admin, demo driver, customer OTP](#8-accounts-super-admin-demo-driver-customer-otp)
9. [API collections (Bruno) and API docs (Scalar)](#9-api-collections-bruno-and-api-docs-scalar)
10. [Realtime (Reverb) and push](#10-realtime-reverb-and-push)
11. [Database documentation (ERD, schema)](#11-database-documentation-erd-schema)
12. [Backup and restore](#12-backup-and-restore)
13. [Tests](#13-tests)
14. [Troubleshooting](#14-troubleshooting)
15. [Documentation index](#15-documentation-index)

## 1. Prerequisites (Windows)

1. **Windows 10 22H2 or Windows 11**, with virtualization enabled in the BIOS.
2. **WSL2.** In an administrator PowerShell, run the command below, then reboot:

   ```powershell
   wsl --install
   ```

3. **Docker Desktop** with *Use the WSL 2 based engine* turned on. Give it at least 4 GB of memory.
4. **Git for Windows**.
5. **Node 22+**. This is optional on the host: assets are built in the `node` container. Install it to run Bruno or the E2E scripts from Windows.

> **Performance tip:** clone the project **inside the WSL filesystem**, for example in an Ubuntu terminal: `~/code/waffrli`. A clone under `C:\` works, but bind mounts from Windows drives are much slower. Open it from Windows as `\\wsl$\Ubuntu\home\<you>\code\waffrli`.

Linux and macOS need only Docker Engine (or Desktop) with Compose v2, and Git.

## 2. Get the code

```bash
git clone <repository-url> waffrli
```

```bash
cd waffrli
```

```bash
git checkout feature/docker-e2e-qa
```

## 3. One-command setup

Both scripts are safe to repeat:

- they never delete data and never reset the database;
- they never overwrite an existing `.env` value, and ask before filling empty secrets in an existing `.env`;
- they load demo data only after you confirm.

Windows PowerShell:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\setup.ps1
```

WSL, Linux or macOS:

```bash
./scripts/setup.sh
```

Each script runs these steps:

1. Checks Docker and Compose.
2. Creates `.env` from `.env.docker.example` and generates random DB, Reverb and dev-OTP secrets.
3. Builds the images, starts MySQL and Redis, and waits until they are healthy.
4. Runs `composer install`, and `npm ci` if `node_modules` is missing.
5. Generates `APP_KEY` if it is empty.
6. Runs `php artisan migrate` on the **local** database.
7. Runs the essential seeders, then the demo seeders after your confirmation.
8. Creates the storage link and builds the assets: Vite, the admin theme and the API docs viewer.
9. Starts every service.
10. Optionally creates the first Super Admin. You type the password, and it is never stored or shown.

Options:

| PowerShell | Bash | Effect |
|---|---|---|
| `-Demo` / `-NoDemo` | `--demo` / `--no-demo` | Load demo data without asking, or never load it |
| `-Yes` | `--yes` | Fill **empty** secrets in an existing `.env` without asking |
| — | `--no-admin` | Skip the Super Admin prompt |

> **Corporate proxy or TLS inspection?** Put the proxy's CA certificate (`*.crt`) in `docker/certs/`, which is git-ignored, before building. If your network blocks Docker build DNS, pre-build with the command below; `docker compose build` then reuses that image.
>
> ```bash
> docker build --network=host -t waffrli-app:dev -f docker/php/Dockerfile .
> ```

## 4. URLs and services

| What | URL | Notes |
|---|---|---|
| API | `http://localhost:8080/api/v1` | `customer/*`, `driver/*`, `admin/*` |
| Admin panel (Filament) | `http://localhost:8080/admin` | Arabic (RTL) and English, dark and light |
| API reference (Scalar) | `http://localhost:8080/docs` | Only when `WAFFRLI_API_DOCS_ENABLED=true`; never in production |
| OpenAPI document | `http://localhost:8080/docs/openapi.yaml` | Same condition |
| Reverb WebSocket | `ws://localhost:8085` | The **one** realtime server for customers, drivers and the panel |
| Mailpit | `http://localhost:8025` | Catches every e-mail (e.g. e-mail verification codes) |
| MySQL | `127.0.0.1:33060` | Credentials in `.env`. Databases `waffrli` (dev) and `waffrli_testing` (tests) |
| Health | `http://localhost:8080/up` | |

Every published port binds to **127.0.0.1 only**. Redis has no published port, and nothing exposes a database or Redis management UI.

| Compose service | Role |
|---|---|
| `web` | Nginx in front of PHP-FPM |
| `app` | PHP 8.3 FPM (Laravel) |
| `queue` | `queue:work redis` (3 tries, 10 s backoff): notifications, push, broadcasts |
| `scheduler` | `schedule:work` |
| `reverb` | `reverb:start` |
| `mysql` | MySQL 8.4 (utf8mb4) |
| `redis` | Redis 7.4 (AOF persistence) |
| `mailpit` | Development mail catcher |
| `node` | One-off asset builds (`--profile tools` / `docker compose run`) |

Named volumes `mysql-data` and `redis-data` keep data across restarts.

## 5. Everyday Docker commands

The same commands work in PowerShell and Bash. Run them from the project folder.

| Task | Command |
|---|---|
| Start everything | `docker compose up -d` |
| Stop (keeps data) | `docker compose stop` |
| Stop and remove containers (keeps volumes and data) | `docker compose down` |
| Restart one service | `docker compose restart queue` |
| Status and health | `docker compose ps` |
| Logs (follow) | `docker compose logs -f app queue reverb` |
| Rebuild the PHP image | `docker compose build app`, then `docker compose up -d` |
| Artisan | `docker compose exec app php artisan <command>` |
| Composer | `docker compose exec app composer <command>` |
| Shell in the app | `docker compose exec app bash` |
| Rebuild assets | `docker compose run --rm node sh -c "npm run build && npm run build:admin-theme && npm run build:api-docs"` |
| MySQL client | `docker compose exec mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" waffrli'` |

After changing PHP code that the queue or Reverb run, restart them: `docker compose restart queue reverb`.

> **Never** `docker compose down -v` unless you really want to delete the local database and Redis volumes. It is irreversible.

## 6. Environment (.env)

`.env` is git-ignored and is created from **`.env.docker.example`**, which holds no secrets. Important values:

| Variable | Local value | Why |
|---|---|---|
| `DB_*` | host `mysql`, database `waffrli` | Generated password |
| `DB_TEST_DATABASE` | `waffrli_testing` | Created automatically on the first MySQL start |
| `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER` | `redis` | |
| `BROADCAST_CONNECTION` | `reverb` | `REVERB_HOST=reverb` for the containers, `REVERB_PUBLIC_*` for browsers and devices |
| `REVERB_ALLOWED_ORIGINS` | `localhost,127.0.0.1` | **Host names**, not URLs. See [§10](#10-realtime-reverb-and-push) |
| `WAFFRLI_SMS_DRIVER` | `fake` | No SMS or WhatsApp is ever sent locally |
| `WAFFRLI_DEV_OTP_PEEK_TOKEN` | random | Enables the dev-only OTP peek route ([§8](#8-accounts-super-admin-demo-driver-customer-otp)) |
| `WAFFRLI_PUSH_ENABLED` / `WAFFRLI_PUSH_DRIVER` | `true` / `fake` | Pushes run through the queue but never reach Firebase |
| `WAFFRLI_API_DOCS_ENABLED` | `true` | Serves `/docs` (it is off by default, and never on in production) |
| `MAIL_*` | Mailpit | |

Real SMS, WhatsApp and Firebase credentials are configured only through environment variables on servers that need them (see `docs/waffrli/realtime-and-push.md`). Never commit them.

## 7. Database: migrations, seeders, demo data

```bash
docker compose exec app php artisan migrate
```

```bash
docker compose exec app php artisan db:seed --class=WaffrliEssentialSeeder
```

```bash
docker compose exec app php artisan db:seed --class=WaffrliDemoSeeder
```

- **Essential** seeders create roles, permissions and platform settings. They are idempotent, never overwrite admin edits and never invent currencies.
- **Demo** seeders create fictional stores, products, customers, drivers, orders, wallets, PLUS cycles and ratings:
  - they run only in `local` or `testing`;
  - they refuse a database that has real (non-`DEMO-`) orders;
  - re-running them adds nothing.

  Every person, phone and e-mail is fictional (`*@demo.waffrli.test`, `+1 202 555 01xx`), and nobody can log in with demo passwords. See `docs/waffrli/demo-data.md`.
- Migrations and rollback tests run only against `waffrli_testing`, through the test suite. Never run `migrate:fresh` or `migrate:reset` on a database you care about.

## 8. Accounts: Super Admin, demo driver, customer OTP

**Super Admin.** The command prompts for the password, which is never shown or stored:

```bash
docker compose exec app php artisan waffrli:create-super-admin
```

**Demo driver for the driver API.** Choose a password for one demo driver; it is shown nowhere else:

```bash
docker compose exec app php artisan waffrli:demo-driver-password
```

Then log in with `POST /api/v1/driver/login` and `{ "phone": "+12025550201", "password": "…" }`.

**Customer phone OTP (local).** Customers log in with an SMS code. Locally the SMS driver is `fake`, so nothing is sent, and the code can be read through a **development-only** route. That route exists only when all of these hold: `APP_ENV=local`, `WAFFRLI_SMS_DRIVER=fake`, and `WAFFRLI_DEV_OTP_PEEK_TOKEN` set.

1. `POST /api/v1/customer/auth/otp/request` with `{ "phone": "+12025550101" }`
2. `GET /api/v1/dev/otp?phone=%2B12025550101` with the header `X-Dev-Token: <WAFFRLI_DEV_OTP_PEEK_TOKEN from .env>`
3. `POST /api/v1/customer/auth/otp/verify` with `{ "phone": "...", "code": "...", "device_id": "my-laptop" }` → `data.token`

Limits are enforced locally too: 5 codes per phone per hour, a 60-second cooldown, and 5 requests per IP per minute.

## 9. API collections (Bruno) and API docs (Scalar)

- **Bruno**, in `api-tests/bruno/`, has three collections: Customer (108 requests), Driver (46) and Admin (69). Every `/api/v1` route is covered, including negative and authorization tests. Start with `api-tests/bruno/README.md`.

  ```bash
  cd api-tests/bruno/WAFFRLI-Customer
  ```

  ```bash
  bru run --env Local
  ```

  Each collection has its own git-ignored `.env`; copy it from `.env.example`.
- **Route → request map:** `docs/waffrli/api-inventory.md` (`php artisan waffrli:api-inventory`). `tests/Feature/ApiInventoryTest` fails when a route has no request.
- **OpenAPI:** `docs/waffrli/openapi/waffrli-api.yaml` is generated by `php artisan waffrli:openapi` from three sources: the routes, their validation rules, and real request/response examples captured from Bruno runs (secrets redacted). `tests/Feature/OpenApiDocumentTest` fails on drift.
- **Scalar** at `http://localhost:8080/docs` is self-hosted (no CDN, telemetry off).

## 10. Realtime (Reverb) and push

- One Reverb server serves customers, drivers and the admin panel. All app channels are **private**, and each is authorized by the app:
  - `POST /api/v1/customer/broadcasting/auth`
  - `POST /api/v1/driver/broadcasting/auth`
  - `POST /broadcasting/auth` (admin panel session)

  Channels: `private-user.{id}`, `private-driver.{id}`, `private-order.{id}`, `private-admin.orders` and `private-admin.drivers-map`.
- `REVERB_ALLOWED_ORIGINS` lists **host names**. A client that sends no `Origin` header is refused unless the value is `*`; check what the mobile apps send before restricting origins in production.
- **Test it:** `node e2e/run.mjs` connects real WebSocket clients and checks events, channel denial, isolation and reconnection. The panel's live map is at `/admin/driver-map`.
- **Push:** the `fake` driver logs `push.fake_delivered` with a token hash and never contacts Firebase. Tokens starting with `fake-unregistered-` simulate FCM `UNREGISTERED`, so the token is deactivated.

Details: `docs/waffrli/realtime-and-push.md`.

## 11. Database documentation (ERD, schema)

`docs/waffrli/database/` is generated from the live MySQL schema; nothing in it is hand-written:

- `README.md`: 68 tables with every column, type, default, PK, FK (with ON DELETE), unique and plain indexes, CHECK constraints, Spatie translatable JSON columns, polymorphic pairs and cardinalities;
- `erd.md`: Mermaid diagrams, which GitHub renders. Views: full, financial, PLUS, notifications, catalog/orders;
- `erd-*.svg`: rendered diagrams;
- `schema.sql`: schema-only export.

Regenerate after migrations:

```bash
docker compose exec app php artisan waffrli:db-docs
```

```bash
./scripts/db/schema-dump.sh
```

The approved design baseline is in `docs/waffrli/erd.md`.

## 12. Backup and restore

| Task | Bash (WSL) | PowerShell |
|---|---|---|
| Back up the dev DB to `backups/` | `./scripts/db/backup.sh` | `.\scripts\db\backup.ps1` |
| Back up another local DB | `./scripts/db/backup.sh waffrli_testing` | `.\scripts\db\backup.ps1 -Database waffrli_testing` |
| Restore (asks you to type the DB name) | `./scripts/db/restore.sh backups/<file>.sql.gz` | `.\scripts\db\restore.ps1 backups\<file>.sql.gz` |
| Schema-only export | `./scripts/db/schema-dump.sh` | `.\scripts\db\schema-dump.ps1` |
| Synthetic demo dump | `./scripts/db/demo-dump.sh` | — |

- **Restore** drops and recreates the target database before importing it, and stops the queue worker and scheduler while it runs.
- The **demo dump** is built in a throwaway database, so your dev data is untouched.
- `backups/` is git-ignored. Run these scripts only against the local Docker MySQL. The root password never leaves the container.

## 13. Tests

| Suite | Command |
|---|---|
| Full suite on SQLite (fast; the default in `phpunit.xml`) | `docker compose exec app php artisan test` |
| Full suite on **MySQL 8** | `docker compose exec -e DB_CONNECTION=mysql -e DB_DATABASE=waffrli_testing app php vendor/bin/phpunit` |
| Code style | `docker compose exec app vendor/bin/pint --test` |
| Route list | `docker compose exec app php artisan route:list --path=api/v1` |
| API inventory / OpenAPI drift | `docker compose exec app php artisan waffrli:api-inventory --check` / `waffrli:openapi --check` |
| Bruno | see [§9](#9-api-collections-bruno-and-api-docs-scalar) |
| End-to-end on the running stack | `node e2e/run.mjs` and `node e2e/filament-qa.mjs` (see `e2e/README.md`) |

The test bootstrap **refuses** to run against a MySQL database whose name does not end in `_test` or `_testing`, so the dev data cannot be wiped by mistake. Tests never depend on your `.env`: push, SMS, docs and locale are pinned in `phpunit.xml`.

## 14. Troubleshooting

| Symptom | Fix |
|---|---|
| `docker compose` complains that `DB_PASSWORD` or `DB_ROOT_PASSWORD` is not set | Run the setup script, or fill in `.env`. |
| Port 8080, 8085, 8025 or 33060 is already in use | Set `APP_PORT`, `REVERB_PUBLIC_PORT`, `MAILPIT_UI_PORT` or `FORWARD_DB_PORT` in `.env`, then `docker compose up -d`. |
| Permission denied in `storage/` or `bootstrap/cache` (Linux/WSL) | Set `DOCKER_UID`/`DOCKER_GID` in `.env` to the values from `id -u` and `id -g`, then `docker compose build app && docker compose up -d`. |
| Product images return 403 or 404 | The storage link is missing: `docker compose exec app php artisan storage:link`. |
| The panel's live updates don't arrive; the browser console shows error 4009 | `REVERB_ALLOWED_ORIGINS` must contain the **host** you browse with (e.g. `localhost`), not a URL. Then `docker compose restart reverb`. |
| Events never arrive | Check `docker compose ps`: `queue` and `reverb` must be running. Check `docker compose logs queue reverb`. |
| The customer OTP request answers 429 | Rate limits: wait 60 s (cooldown), or up to an hour (5 per phone per hour), or use another demo customer. |
| The demo seeder says the database contains non-demo orders | A safety guard. Restore a backup, or rebuild the local DB. Only do the latter if you are sure: `docker compose down -v` deletes the volume. |
| A change to `docker/nginx/default.conf` is not applied | It is a single-file bind mount: `docker compose up -d --force-recreate web`. |
| The Docker build cannot reach package mirrors | See the proxy/CA note in [§3](#3-one-command-setup). |
| `/docs` returns 404 | Set `WAFFRLI_API_DOCS_ENABLED=true`, run `docker compose exec app php artisan config:clear`, and build the viewer with `npm run build:api-docs`. |
| Slow pages on Windows | Clone inside WSL ([§1](#1-prerequisites-windows)). |

## 15. Documentation index

| Topic | File |
|---|---|
| Customer API | `docs/waffrli/customer-api.md` |
| Driver API | `docs/waffrli/driver-api.md` |
| Admin panel | `docs/waffrli/admin-panel.md` |
| Realtime and push | `docs/waffrli/realtime-and-push.md` |
| Currencies | `docs/waffrli/currency-switch.md` |
| Demo data | `docs/waffrli/demo-data.md` |
| Database (as built) / design baseline | `docs/waffrli/database/` / `docs/waffrli/erd.md` |
| API inventory, OpenAPI | `docs/waffrli/api-inventory.md`, `docs/waffrli/openapi/` |
| Docker and E2E QA report | `docs/waffrli/docker-e2e-qa.md` |
| Starter roadmap | `docs/starter-roadmap.md` |
