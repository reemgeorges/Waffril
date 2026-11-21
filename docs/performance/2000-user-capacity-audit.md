# WAFFRLI — 2,000-user capacity audit (2026-11-21)

**Verdict: 2,000 simultaneous users is NOT VERIFIED.** No test has been run on
production-equivalent hardware. One hard blocker for 2,000 WebSocket
connections was **measured** and is not fixed yet (Reverb, §3.1).

Plan, profiles and proposed thresholds:
[benchmark-plan.md](benchmark-plan.md). Scripts: [`tests/load/`](../../tests/load/README.md).

## 1. Test environment (local baseline — NOT production)

- **Machine:** one container, 4 vCPU and 16 GB RAM. **k6 ran on the same CPUs** as the system under test, so these figures are a lower bound, not a capacity.
- **Web:** Nginx and PHP-FPM 8.3, with a static pool of 16 workers.
- **PHP:** OPcache with timestamp checks off; config, routes, events and views cached; `APP_DEBUG=false`.
- **Database:** MySQL 8.0 with defaults: 128 MB buffer pool, 151 max connections.
- **Other services:** Redis for cache, session and queue; one `queue:work` worker on `default`; one Reverb process without `ext-uv`.
- **Data:** demo dataset plus `LoadTestDatasetSeeder`:
  - 2,021 customers and 54 drivers;
  - 6,055 orders and 12,200 status-history rows;
  - 40 drivers with an in-progress delivery.
- **Load:** `customer-mixed.js` (think time 3–8 s, 5% of journeys check out) together with `driver-gps.js` (40 drivers, one ping every 5 s). The hold was 3 minutes per profile.

## 2. Measured results (before the optimisations in §4)

| Active VUs | Req/s | Errors | Read p50 / p95 / p99 | Checkout p95 | GPS p95 / p99 | CPU max | Queue max | Row-lock waits |
|---|---|---|---|---|---|---|---|---|
| 50 | 12.9 | 0.00% | 31 / 46 / 80 ms | 76 ms | 101 / 522 ms | 53% | 0 | 0 |
| 100 | 25.0 | 0.00% | 29 / 45 / 63 ms | 67 ms | 97 / 277 ms | 41% | 0 | 0 |
| 250 | 61.9 | 0.00% | 31 / 74 / 141 ms | 116 ms | 141 / 331 ms | 73% | 0 | 0 |
| 500 | 102.7 | 0.02% | 356 / 693 / 887 ms | 836 ms | 576 / 889 ms | **100%** | 0 | 0 |
| 1,000 | 112.7 | 0.12% | 4,107 / 4,946 / 5,289 ms | 5,027 ms | 4,675 / 5,045 ms | **100%** | 0 | 0 |

**Reading:**
- **First bottleneck: PHP CPU.** Throughput plateaus at about 110 req/s on 4 shared vCPU. Latency grows from 500 VUs onwards.
- **Not the limit:**
  - MySQL: no row-lock waits, no deadlocks, at most 16 connections;
  - the queue stayed empty;
  - checkout stayed consistent.
- **This box:** passes the proposed thresholds at ≤ 250 VUs and fails at 500 and 1,000.
- **Per-request cost, in process:**
  - 3–25 SQL queries per endpoint, 3–20 ms;
  - no statement above 2 ms;
  - with 6,000 orders, query counts do not grow with history (`tools/profile-endpoints.php`).

### WebSocket (measured)

| Test | Result |
|---|---|
| 20–30 connections with private-channel auth | 100% established and subscribed; connect p95 2–3 ms; subscribe p95 ≈ 30 ms; 0 unexpected closes |
| 1,200 connections, single Reverb, no `ext-uv` | **FAIL.** 1,017 established. At 1,024 file descriptors Reverb logged `stream_select(): You MUST recompile PHP with a larger value of FD_SETSIZE` and **exited (code 0)**, dropping every client. About 15 KB RSS per connection before the crash. |

## 3. Findings (file:line)

| # | Severity | Status | Finding |
|---|---|---|---|
| 3.1 | **Critical** | Confirmed (measured) | **Reverb** without `ext-uv`/`ev`/`event` uses ReactPHP `StreamSelectLoop` (`vendor/react/event-loop/src/Factory.php:72`), which is capped at 1,024 descriptors. Above that the process exits, dropping all clients; because it exits 0, a supervisor restart may loop. The dev image (`docker/php/Dockerfile`) has no `ext-uv`; production is unknown. |
| 3.2 | High | Confirmed (code) | **One queue for everything.** Every realtime event goes on `default` (`app/Events/Realtime/*.php`, no broadcast queue): `OrderStatusChanged`, `DriverLocationUpdated`, `DeliveryOfferChanged`, `AdminOperationalEvent`. So do push jobs (`config/waffrli.php:109`) and the marketing job (`app/Jobs/Push/SendMarketingBroadcast.php:21`). One campaign to 2,000 customers sends per-recipient notifications in a single job (`MarketingBroadcastService.php:124-127`): about 2,000 DB inserts plus about 2,000 broadcast jobs and the push jobs, all ahead of order and GPS updates. |
| 3.3 | Medium | Confirmed (code) | **Marketing job timeout.** The job has `tries = 1` and no `$timeout`. Workers default to 60 s (`compose.yaml:72` passes no `--timeout`). The run time for 2,000 recipients is **NOT VERIFIED**; if it exceeds the worker timeout, the job is killed and the campaign stays "sending". |
| 3.4 | Medium | **Fixed** | **Settings N+1.** `PlatformSettings::get()` ran a query on every call (`app/Support/Settings/PlatformSettings.php`), e.g. 12 identical queries for 12 branches. |
| 3.5 | Medium | **Fixed** | **Token writes.** Sanctum 4.3.3 writes `last_used_at` on every authenticated request (`vendor/laravel/sanctum/src/Guard.php:167`). |
| 3.6 | Low | Theoretical | **Synchronous work in requests.** `Notify::send` sends each channel synchronously after commit (`app/Services/Realtime/Notify.php:60`). An order fans out to every eligible admin inside the checkout request (`RealtimeDomainSubscriber.php:27`, `AdminAlerts.php:107`). Small with a few admins. |
| 3.7 | Low | Theoretical | **Search.** Leading-wildcard `LIKE` over JSON names (`app/Support/Database/JsonSearch.php:25`) cannot use an index. Fine at the current catalog size (search p95 ≤ 150 ms at 250 VUs). |
| 3.8 | Info | Deployment | **Scheduler.** `dispatch-sweep` runs every 15 s with `withoutOverlapping()` (`routes/console.php:19`). Run exactly one scheduler, or add `onOneServer()`, if there are several app servers. |

**Already scalable:**
- **Checkout:** cart, PLUS cycle and wallet are locked in one transaction (`CheckoutService.php:72,122,320`). The idempotency key is backed by a unique index (`uq_orders_user_client_request`). In tests: 0 duplicate orders and 0 replay mismatches, row-lock waits 0.
- **Indexes** cover every hot path.
- **Rate limits:** 180 requests/min per customer, 12 GPS pings/min per driver.
- **Dispatch:** bounded batches, one ranking query per order.
- **Admin polling:** 30–120 s.

## 4. Changes in this branch

1. **Settings memo** (3.4): a scoped container instance — new for every request and reset by Laravel before every queue job (`QueueServiceProvider.php:262`). `Setting` saved/deleted events clear it. The branch list no longer runs one settings query per branch.
2. **`last_used_at` at most once a minute per token** (3.5): `App\Models\PersonalAccessToken`, registered in `AppServiceProvider`. Nothing reads that column. Authentication, expiry and revocation are unchanged.
3. **Load-test kit:**
   - **Safety** (`lib/common.js`): `TARGET_ENV` plus an explicit `ALLOWED_HOSTS` entry for every host (API and WebSocket), `FORBIDDEN_HOSTS`, and `ALLOW_WRITES=yes` for runs that write.
   - **Checkout replay** must return the original order (2xx, `replayed: true`, same id). 429s are counted separately from 409/422 refusals.
   - **WebSocket accounting:** every attempt counts in the denominator (failed handshakes, subscribe timeouts, unexpected closes). Sockets stay open until the end of the hold phase.
4. **`LoadTestDatasetSeeder` refuses to run unless all of these hold:**
   - the environment is not production;
   - the opt-in variable is set;
   - the database name is given explicitly and is disposable;
   - no real customers or orders exist.
   Synthetic orders are `LT-…` with a `SYNTHETIC load-test record` history note. History orders are closed directly (documented: no driver, cash payment left pending). In-progress deliveries have a full pending → assigned → picked_up history.

**Before/after:** the per-endpoint query profile and regression tests (`CapacityRegressionTest`) cover the fixes. **The load profiles were NOT re-run** after them; the expected gain is small compared with the CPU limit.

## 5. Required before claiming capacity (infrastructure, not done here)

1. **Reverb:** install `ext-uv` (`pecl install uv`), raise `ulimit -n` (e.g. 65,536) and put it behind a WebSocket-capable proxy. For several Reverb nodes, set `REVERB_SCALING_ENABLED=true` with Redis.
2. **Queues:** separate them (realtime / notifications / marketing) with dedicated workers. Give marketing an explicit timeout or chunked jobs. *Needs a code change plus a worker-config change, deployed together.*
3. **PHP tier sizing:** about 110 req/s on 4 shared vCPU suggests 2,000 active users (≈ 330–500 req/s at 4–6 s per request) need roughly 12–20 dedicated vCPU of PHP-FPM. That is an **estimate**, to be confirmed on staging.
4. **Production checklist (read-only):**
   - CPU and RAM;
   - PHP-FPM `pm.*` and `max_children`, OPcache (`validate_timestamps=0`, memory);
   - MySQL `innodb_buffer_pool_size`, `max_connections`, slow log;
   - Redis `maxmemory` and policy;
   - the number of queue workers and their `--timeout`;
   - Reverb `php -m | grep uv` and `ulimit -n`;
   - Nginx `worker_connections`, the WebSocket upgrade and its timeouts;
   - OS `fs.file-max` and `somaxconn`;
   - monitoring.

## 6. Capacity assessment

| Scenario | Status | Evidence |
|---|---|---|
| 2,000 **registered** customers | **PASS** | Seeded with 6,000 orders; query counts flat, no slow statements |
| 2,000 **daily** active users | **NOT VERIFIED** (likely fine) | Spread over a day, the measured ≤ 250 VU profile covers the average; the peak hour is not measured |
| 100–250 **simultaneous** users | **PASS (local, lower bound)** | p95 ≤ 74 ms reads, 0% errors at 250 VUs on 4 shared vCPU |
| 500 simultaneous users | **FAIL on this box / NOT VERIFIED on production** | CPU 100%, read p95 693 ms |
| 2,000 simultaneous users (HTTP) | **NOT VERIFIED** | Needs production-equivalent staging; estimated 12–20 vCPU for PHP |
| 2,000 persistent WebSocket connections | **FAIL as configured** (Reverb without `ext-uv`) | Crash at 1,024 descriptors, measured |
| Campaign to 2,000 recipients during operations | **NOT VERIFIED** | Shared queue (3.2), timeout (3.3) |
