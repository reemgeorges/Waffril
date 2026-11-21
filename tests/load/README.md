# WAFFRLI load tests

Runnable k6 scenarios for the 2,000-user capacity plan. Read
[docs/performance/benchmark-plan.md](../../docs/performance/benchmark-plan.md)
first; results go in
[docs/performance/2000-user-capacity-audit.md](../../docs/performance/2000-user-capacity-audit.md).

> **Never run these against production** without written authorization.
> Every script refuses to start unless `TARGET_ENV=staging` (or `local`).
> Put the production host names in `FORBIDDEN_HOSTS` as a second guard.

## 1. Prepare an isolated staging stack

The scripts need:

- an empty staging database;
- fake SMS and push (`WAFFRLI_SMS_DRIVER=fake`, `WAFFRLI_PUSH_DRIVER=fake`);
- `QUEUE_CONNECTION=redis`;
- Reverb reachable from the load generator.

Seed the database and warm up:

```bash
php artisan migrate --force
```

```bash
WAFFRLI_DELIVERY_OFFERS_ENABLED=false php artisan db:seed --class=WaffrliDemoSeeder --force
```

```bash
WAFFRLI_LOADTEST_SEED=yes WAFFRLI_DELIVERY_OFFERS_ENABLED=false php artisan db:seed --class="Database\Seeders\LoadTest\LoadTestDatasetSeeder" --force
```

```bash
php artisan optimize
```

**What the load-test seeder does:**

- **Who:** 2,000 customers (`@loadtest.invalid`, unassigned `+999` phones) and 40 drivers.
- **Order history:** 3 orders per customer through the real checkout, back-dated and marked delivered.
- **Active deliveries:** one in-progress delivery per driver, so GPS pings take the full path.
- **Tokens:** one token per account, written to `storage/app/loadtest/tokens.json`. That file is git-ignored, private (mode 600) and is never printed. Copy it to the load-generator machine over a secure channel, and delete it after the campaign.
- **Sending:** nothing is sent while it runs.

**Settings:** `LOADTEST_CUSTOMERS`, `LOADTEST_DRIVERS`, `LOADTEST_ORDERS_PER_CUSTOMER`, `LOADTEST_CITY` (default Hama), `LOADTEST_ACTIVE_DELIVERIES=no`.

## 2. Run

Install [k6](https://grafana.com/docs/k6/latest/set-up/install-k6/) v0.54+ on a machine **separate from staging**. Then raise its file-descriptor limit:

```bash
ulimit -n 65535
```

Customers, with profiles 50, 100, 250, 500, 1000 and 2000:

```bash
TARGET_ENV=staging BASE_URL=https://staging.example TOKENS_FILE=./tokens.json PROFILE=250 HOLD_MIN=10 CHECKOUT_RATE=0.05 k6 run --summary-export summary-250.json tests/load/customer-mixed.js
```

Drivers (GPS every 5 s):

```bash
TARGET_ENV=staging BASE_URL=https://staging.example TOKENS_FILE=./tokens.json DRIVERS=40 HOLD_MIN=10 k6 run tests/load/driver-gps.js
```

Persistent WebSocket connections:

```bash
TARGET_ENV=staging BASE_URL=https://staging.example WS_URL=wss://ws.staging.example REVERB_KEY=<public app key> TOKENS_FILE=./tokens.json CONNECTIONS=2000 RAMP_MIN=5 HOLD_MIN=15 k6 run tests/load/reverb-connections.js
```

Server-side sampling, run on the staging host during each test:

```bash
QUEUES="default" REVERB_PORT=8080 tests/load/tools/monitor.sh 5 > monitor-250.csv
```

Per-endpoint query profile, on the staging host (before and after a change):

```bash
php tests/load/tools/profile-endpoints.php 30
```

**Variables:**

| Variable | Default | Meaning |
|---|---|---|
| `PROFILE` | `50` | Active virtual users (people, with think time) |
| `HOLD_MIN` | `5` | Minutes at full load |
| `THINK_MIN` / `THINK_MAX` | `3` / `8` | Seconds between screens (lower them for a burst test) |
| `CHECKOUT_RATE` | `0.05` | Share of journeys that place a **real order on staging**; `0` keeps it read-only |
| `DRIVERS` / `GPS_INTERVAL` | `40` / `5` | Drivers and seconds between pings (the API allows 12 per minute) |
| `CONNECTIONS` / `RAMP_MIN` / `SUBSCRIBE` | `200` / `2` / `yes` | WebSocket count, ramp, private-channel auth + subscribe |

The thresholds in the scripts are **proposals** (benchmark plan §4). A red
threshold fails the k6 run (exit code 99).

## 3. Clean up

Revoke the tokens and delete the token file:

```bash
php artisan tinker --execute='DB::table("personal_access_tokens")->where("name","loadtest")->delete();'
```

```bash
rm -f storage/app/loadtest/tokens.json
```

Then restore or recreate the staging database. Load-test customers are identified by `@loadtest.invalid`.
