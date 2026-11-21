# WAFFRLI — benchmark plan (2,000-user capacity)

Status: **plan + runnable scripts**. Measurements taken so far are in
[2000-user-capacity-audit.md](2000-user-capacity-audit.md). Nothing in this
plan is run against production.

## 1. What is being measured (do not mix these up)

| Quantity | Meaning | How it loads the system |
|---|---|---|
| **Registered customers** (2,000) | Rows in `users` | Storage and index size only |
| **Daily active users** (2,000/day) | Distinct customers who open the app in a day | Spread over ~12 operating hours |
| **Simultaneously active users** (100–500, peak 2,000) | People using the app *at the same moment*, with think time between screens | ≈ users ÷ think time ≈ requests/second |
| **Concurrent HTTP requests** | Requests in flight at one instant | Bounded by PHP-FPM workers |
| **Requests per second** | Throughput | CPU-bound on the PHP tier |
| **Persistent WebSocket connections** | Open apps holding a Reverb connection | Memory + file descriptors on Reverb |

Rule of thumb used in this plan (an assumption, to be confirmed with real
analytics): an active user makes one API request every ~4–6 s on average.
Then 500 simultaneously active users ≈ 80–125 req/s, and 2,000 ≈ 330–500 req/s.

## 2. Environment rules (mandatory)

- **Isolated staging only.** A separate database, Redis and Reverb.
- **Fake transports.**
  - `WAFFRLI_SMS_DRIVER=fake`.
  - `WAFFRLI_PUSH_DRIVER=fake`, or push disabled.
  - No payment provider: all orders are paid in cash. Wallet payments aren't used.
- **Data.**
  - Start with `WaffrliDemoSeeder`.
  - Then run `LoadTestDatasetSeeder`, which needs `WAFFRLI_LOADTEST_SEED=yes` and refuses to run in production.
  - Test accounts use `@loadtest.invalid` emails and the unassigned `+999` phone prefix.
- **Same as production.** Staging hardware and configuration must match production: PHP-FPM pool, OPcache, MySQL buffer pool, Redis, Reverb behind the same proxy.
  - Results from a smaller box are a lower bound, not a capacity figure.
- **The load generator** runs on a separate machine from the system under test.
- **Production.** Never run the scripts against production without written authorization.
  - The scripts refuse to start unless `TARGET_ENV` is `staging` or `local`.
  - Set `FORBIDDEN_HOSTS` to the production host names.

## 3. Scenarios (`tests/load/`)

| Script | Models | Main metrics |
|---|---|---|
| `customer-mixed.js` | Browse home → store → product; search every third journey; orders and notifications; cart + checkout on `CHECKOUT_RATE` of journeys (with an idempotent replay) | p50/p95/p99 by endpoint group, error rate, checkout latency, orders placed |
| `driver-gps.js` | One driver per VU: a GPS ping every 5 s during an in-progress delivery, offer polling, active-order screen | GPS p95/p99, 409/429 counts |
| `reverb-connections.js` | One open customer app per VU: Pusher-protocol connect, private-channel auth through the API, subscribe, keep-alive | Connect/subscribe time, established and subscribed rate, unexpected closes |
| `tools/monitor.sh` | Server sampler (run on the staging host) | CPU, RAM, FPM processes, Reverb sockets, queue length and oldest-job age, MySQL threads, row-lock waits, deadlocks |
| `tools/profile-endpoints.php` | Queries and SQL time per request, endpoint by endpoint | N+1 detection, before/after comparisons |

### Load profiles

`customer-mixed.js` runs with `PROFILE` = **50, 100, 250, 500, 1000, 2000** active virtual users:

- Think time is 3–8 s between screens and 6–16 s between journeys.
- The ramp is 1 minute (up to 250 VUs), 3 minutes (up to 1,000) or 5 minutes (2,000).
- The hold is `HOLD_MIN` (10 minutes recommended), then a 1-minute ramp-down.

Run alongside it:

- `driver-gps.js` with `DRIVERS` = 40 (or the expected peak number of drivers on shift).
- `reverb-connections.js` with `CONNECTIONS` = the same number as the customer VUs, plus drivers and admins.

### Additional runs

| Run | How | What it shows |
|---|---|---|
| **Burst** | `PROFILE=500`, ramp `THINK_MIN=1 THINK_MAX=2` for 3 minutes | Lunch-time spike |
| **Soak** | `PROFILE=250 HOLD_MIN=60` | Memory growth, connection leaks, queue drift |
| **Overload and recovery** | Step up to 2× the first failing profile for 5 minutes, then back to 50 | Time until p95, queue age and error rate return to baseline (recovery time) |
| **Reverb** | `CONNECTIONS` = 500, 1,000, 2,000 (and 3,000 for headroom), `HOLD_MIN=15` | Plus a reconnection storm: restart Reverb during the hold and measure the time until 99% are re-established (the mobile SDK reconnects automatically) |
| **Marketing during operations** | Send one campaign to all 2,000 customers from the panel while `customer-mixed.js` (PROFILE=250) and `driver-gps.js` run | Queue length and oldest-job age per queue; latency of order-status and GPS broadcasts during the campaign |

## 4. Proposed acceptance thresholds (NOT approved business requirements)

These are starting points for discussion; the business must confirm them.

| Area | Proposed threshold |
|---|---|
| API reads (catalog, orders, notifications) | p95 < 500 ms, p99 < 1.5 s |
| Search | p95 < 800 ms |
| Checkout | p95 < 2 s; **zero** duplicate orders for a replayed idempotency key; zero negative wallets |
| GPS ping | p95 < 300 ms, p99 < 800 ms |
| Error rate (5xx + timeouts) | < 1% (excluding expected business refusals: 409/422/429) |
| Queue: realtime/time-sensitive | oldest job < 5 s at all times, including during a campaign |
| Queue: marketing | campaign to 2,000 recipients completes < 10 min without breaching the line above |
| Reverb | ≥ 99% of connections established and subscribed; < 0.5% unexpected closes per hour; 99% re-established within 60 s after a restart |
| Resources at the target profile | CPU < 70% sustained, no swap, MySQL row-lock waits ≈ 0, no deadlocks |
| Recovery | back to baseline p95 within 2 minutes after overload ends |

A scenario is **PASS** only when every threshold holds for the whole hold
phase. It is **FAIL** when one breaks. Any profile that wasn't run on
production-equivalent staging is **NOT YET VERIFIED**.

## 5. Procedure

1. Read-only production diagnostics (audit report, §2): record the real CPU, RAM, PHP-FPM, MySQL, Redis, Reverb and proxy settings.
2. Build staging to the same specification. Then:
   - `php artisan migrate`;
   - `php artisan db:seed --class=WaffrliDemoSeeder`;
   - `WAFFRLI_LOADTEST_SEED=yes php artisan db:seed --class="Database\Seeders\LoadTest\LoadTestDatasetSeeder"`.
3. Warm up: `php artisan optimize`, then run one `PROFILE=50` pass.
4. Baseline: `php tests/load/tools/profile-endpoints.php 30 > profile-before.txt`.
5. For each profile, start `tools/monitor.sh 5 > monitor-<profile>.csv` on the host, then the k6 scripts with `--summary-export summary-<profile>.json`.
6. Stop at the first profile that fails a threshold. Record the first resource to saturate (CPU, FPM queue, MySQL, Redis, Reverb, queue age).
7. Apply one change at a time and re-run the same profile (before/after).
8. Re-seed or restore staging between destructive runs. Never truncate a shared database.
