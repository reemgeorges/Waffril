# Admin panel audit, fixes and production readiness — 2026-11-16

Branch `feature/admin-panel-audit`, based on `main` at `e5d5ae0`. All commits
are **local only**: nothing has been pushed, merged or deployed.

This document is the written record of the 15-phase specification plus the
Hama clarification. Every claim below comes with how it was verified. Where
something was **not** verified, the document says so.

## 1. Inventory: what was examined

- **Panel:** Filament 5.11 on Livewire 4.4.8.
- **Resources and pages:** all resources under `app/Filament/Resources`, the
  dashboard widgets, the driver map, notifications and marketing.
- **Locale middleware:** `SetAdminPanelLocale`, plus Livewire's persistent
  middleware path (`/livewire/update`).
- **Services:** order lifecycle (`OrderAdminService`, `OrderStateMachine`,
  `RefundService`), PLUS, customer and driver accounts, marketing, push
  (FCM HTTP v1), realtime (Reverb, `Realtime`, `ChannelAccess`), customer OTP.
- **Demo seeders:** `WaffrliDemoSeeder` and the regional seeders.
- **Tests:** the existing suite (729 tests at the start of the work, all
  passing after the weekday fix), the mobile API contract tooling
  (`waffrli:api-contract`, `waffrli:openapi`, `waffrli:api-inventory`) and
  the MySQL schema tests.

## 2. Problems found (with root cause)

| # | Problem | Root cause | Status |
|---|---|---|---|
| P1 | Arabic panel switched to English labels after any Livewire interaction (tabs, filters, refresh). | Filament builds tabs in component boot; Livewire restores the locale only during hydrate, and `SetAdminPanelLocale` was not persistent middleware, so `/livewire/update` ran in the default locale. | Fixed (8cf87b8) |
| P2 | Audit details showed raw keys (`reason_code`, `policy`) and a driver language select in English. | Missing label groups. | Fixed (8cf87b8) |
| P3 | Full suite: 134 errors on Mondays. | Demo order DEMO-0012 was placed before a Homs pharmacy opened on Fridays. | Fixed (8cf87b8) |
| P4 | Panel screens other than orders never refreshed live. | Only `admin.orders` existed; the PLUS, customer, driver, finance and marketing screens had no event; there was no own-action refresh. | Fixed (3f4f50c) |
| P5 | Dashboard trends loaded every order of 14 days into PHP: 236 ms at 56k orders. Finance ran 8 queries per currency. | Row-by-row aggregation, and no index on `orders.created_at`, `(status, delivered_at)` or `order_status_histories(to_status, created_at)`. | Fixed (b7993ab) |
| P6 | Bell: unread and read notifications looked the same; the count was pale green on a 10% tint. | Filament default styles (2 px edge only). | Fixed (4f7b116) |
| P7 | An approval could overwrite another admin's rejection of a cancellation request, and cancel the order. | The request status was checked outside any lock. | Fixed (bbba54b) |
| P8 | Cancellation requests stayed "pending" forever when an admin cancelled directly or the order was delivered. This blocked the customer's anonymization and inflated the dashboard tile. | No code closed them. | Fixed (bbba54b) |
| P9 | Table filters were hidden in a dropdown whose button was clipped on wide tables. | Filament dropdown layout. | Fixed (95b8fa9) |
| P10 | Coordinates and polygons were typed blind. | No map in forms. | Fixed for points and preview (e3b6aec); polygon **drawing** is still open. |
| P11 | `DriverMapTest` failed after the Hama/Homs data was added (my own 1497f3f). That commit's "all passing" note was wrong for this file. | Exact-list assertions. | Fixed (b7993ab) |
| P12 | `docs:check-drift` fails on 149 undocumented legacy starter routes. | Pre-existing on `main`: identical count. Not part of the WAFFRLI contract, which passes. | Not changed |
| P13 | `HttpResponseTrait::handleException` returns the raw message on 500. | Legacy copy; the active handler (`GeneralTrait`) hides it in production. | Recommendation |

## 3. Changes, by phase

| Phase | Commit(s) | What |
|---|---|---|
| 1 Localization | 8cf87b8 | `SetAdminPanelLocale` registered as Livewire **persistent** middleware; audit property and value labels; driver language select. Real HTTP Livewire tests fail without the fix and pass with it. |
| 2 Filters | 95b8fa9 | Filters above every list: open on screens ≥ 1024 px or when a filter is active, closed behind "Filters · n" on phones. Includes Reset, chips, and immediate apply. |
| 3 Forms | e3b6aec | Map picker (self-hosted Leaflet) for branch location and city centre plus radius; delivery-area polygon preview; city form sections and help; fee-rule help matching the resolver. |
| 4 Realtime | 3f4f50c | `admin.activity` channel (any active admin; identifiers only) next to `admin.orders`; publishers in refunds, PLUS, the audit log and driver availability; own-action refresh after `BusinessAction::run`; 30 s fallback polling while the socket is down, plus one refresh on reconnect. |
| 5 Dashboard | b7993ab | SQL day buckets (timezone/DST exact), grouped finance, 3 indexes, all widgets live. |
| 6 + 8 Hama / SYP | 1497f3f | See `docs/waffrli/demo-hama.md`. |
| 7 Fixed dev OTP | 21af29f | `123456` for allowlisted phones only, in local/testing only, never in production (checked in code, not by configuration alone). |
| 9 Errors | a2d2740 | Request ids, grouped and scrubbed error store, Monitoring → Errors, retention. See `docs/waffrli/error-monitoring.md`. |
| 10 Cancellation | bbba54b | P7 and P8 above. |
| 11 FCM | 54e8995 | `waffrli:fcm-check`. See `docs/waffrli/fcm-readiness.md`. |
| 12 Marketing | 635821e, ef469e0 | Customers, drivers or both; optional city; live progress counters. |
| 13 Notifications | 4f7b116 | Bell contrast; localized live toasts with a stable id (dedupe). |
| 14 Performance | b7993ab | Measurements in section 10. |

**Browser notifications** (desktop OS notifications) were *not* implemented.
Design, for a later decision:

- an explicit opt-in button in the user menu, so permission is requested
  only after a click;
- the toast stream on `admin.{id}` reused, shown with the Notification API
  only while the tab is hidden;
- the same stable id used as the notification `tag`, for dedupe;
- no personal data in the text.

## 4. Database changes (all additive, all reversible)

| Migration | Change | MySQL 8 algorithm (measured) |
|---|---|---|
| `2026_11_16_100000_add_boundary_quality_to_delivery_areas` | 2 nullable columns + CHECK `chk_delivery_areas_boundary_quality` | Columns: INSTANT. CHECK: **COPY** (writes to `delivery_areas` wait during the copy; a small table). |
| `2026_11_16_110000_add_dashboard_indexes` | `orders(created_at)`, `orders(status, delivered_at)`, `order_status_histories(to_status, created_at)` | **INPLACE, LOCK=NONE**: reads and writes continue. |
| `2026_11_16_120000_add_recipients_and_progress_to_marketing_broadcasts` | `recipient_type` (NOT NULL, default `customers`), `total_count` (NULL), `processed_count` (default 0) + CHECK `chk_mb_recipient_type` | Columns: INSTANT. CHECK: **COPY** (a small table). |
| `2026_11_16_130000_create_error_events_table` | New table + CHECK `chk_error_events_status` | New table: no impact on existing data. |

**Rehearsal on MySQL 8.0.46, disposable database `waffrli_migcheck`:**

1. All migrations were applied and the full demo dataset seeded (55 orders,
   53 delivery areas).
2. `migrate:rollback --step=4` rolled back exactly the four new migrations;
   every `down()` worked.
3. A marketing message was inserted in the *old* schema shape.
4. `migrate` re-applied the four migrations over the populated data.
   - The old message got `recipient_type = customers`, `total_count = NULL`
     and `processed_count = 0`: the previous behaviour.
   - Every existing delivery area kept `boundary_quality = NULL`, meaning
     "entered by an admin", unchanged.
   - The 3 CHECK constraints and 3 indexes exist.
5. Online behaviour was proven separately with explicit `ALGORITHM` clauses:
   - an index add with `ALGORITHM=INPLACE, LOCK=NONE`: accepted;
   - a column add with `ALGORITHM=INSTANT`: accepted;
   - a CHECK add with `ALGORITHM=INPLACE`: refused (ERROR 1845), so MySQL
     uses COPY.

**Not affected:** no data is modified, no existing column changes type,
nothing is dropped, and no financial table gets a data change (the only
change touching `orders` / `order_status_histories` is secondary indexes).

## 5. Hama report

Sources and method: `docs/waffrli/demo-hama.md`. Verified 2026-11-16 on a
fresh MySQL 8 install of the demo data.

- **Areas:** 33 active Hama areas.
  - **Catch-all:** the verified OpenStreetMap city relation 18354844
    ("Hama — whole service area", sort 1000).
  - **Neighbourhoods:** 32 real named neighbourhoods, each with Arabic and
    English names (e.g. Az Zanbaqi / الزنبقي, Bab Qibli / باب القبلي,
    Al-Madinah / المدينة). Each is labelled *approximate* with its OSM
    source: a nearest-centre cell around an OSM neighbourhood point or way.
  - **No invented boundaries:** no neighbourhood claims an official
    boundary.
- **Homs (smaller):** 5 verified OSM boundaries plus 11 approximate areas.
- **Stores and branches:** 8 fictional stores (`demo-hama-*`) with 12
  branches, all in Hama and all active. With the same `DeliveryAreaLocator`
  checkout uses, each of the 12 branches lies inside the neighbourhood its
  name claims (e.g. "Bab Qibli branch" → area Bab Qibli).
- **Tests (11 passed):**
  - every point of a fine grid inside the city is covered, detected and
    priced;
  - areas are named, valid and honestly labelled;
  - **cash checkouts succeed in multiple neighbourhoods**;
  - minimum order, city mismatch and opening hours are enforced;
  - prices are exact Syrian-pound amounts;
  - re-running adds nothing and never overwrites;
  - production is refused;
  - the partition has no gaps or overlaps.
- **Known behaviour:** the seeder never overwrites an existing area, so it
  also does not add quality labels to areas that already exist. In the
  rollback rehearsal, the labels dropped with the columns came back NULL. A
  fresh install labels them correctly.

## 6. Currency

- **Active currencies:**
  - USD (default, rate 1, 2 decimals);
  - SYP (rate 13000, 0 decimals), stored in **old** pounds and displayed in
    **new** pounds through `Denomination`: 100 old = 1 new, a fixed legal
    ratio, never an exchange rate;
  - EUR (historical, 0.92).
- **Demo prices** are entered in new SYP and converted once on the way in.
  The test "demo prices are exact Syrian pound amounts" checks every
  displayed price.
- **Financial consistency** on the MySQL demo data:
  - for all 55 orders the payments sum to the order total, in the order's
    own currency;
  - each discounted order recomputes exactly as subtotal − offer discount −
    PLUS discount + delivery fee;
  - historical USD and EUR orders stay in their own currency; nothing was
    converted.
- **The finance widget** never sums across currencies. Its grouped rewrite
  is proven identical to the previous per-currency calculation (test, on
  SQLite and MySQL).
- **No change** to stored financial records, ledgers, payments or rates.

## 7. Firebase: tested vs. awaiting credentials and devices

| Area | Status | Evidence |
|---|---|---|
| Message building, JWT signing, response mapping (sent / invalid token / retry) in `FcmHttpV1Transport` | **Tested** (faked Google and FCM HTTP) | `PushNotificationsTest` (transport test), `DriverOfferApiTest` (TTL / APNs expiry). |
| Queueing, dedupe, skip-when-online, retries, invalid-token deactivation, per-channel isolation, token ownership and device limits | **Tested** (fake transport) | Push suite (16 tests). |
| `waffrli:fcm-check` local checks, `--online` / `--validate-token` paths, no secrets in output or report | **Tested** (faked Google and FCM) | `FcmCheckCommandTest` (6). |
| `waffrli:fcm-check` against this environment | **Ran** | Local checks only: push disabled, fake driver, no credentials (WARN), as expected here. |
| Real OAuth token from Google with your service account | **Awaiting credentials** | Run `php artisan waffrli:fcm-check --online` on the server. |
| Real FCM acceptance of a real device token | **Awaiting credentials and a device** | Run `php artisan waffrli:fcm-check --validate-token=ID` (dry run: nothing is delivered). |
| Real delivery, display, sound, tap handling and data payload on Android / iOS (customer and driver apps) | **Awaiting mobile testing** | Not testable from the server. |
| Driver app rendering `marketing.message` | **Awaiting mobile testing** | Confirm before the first driver campaign. |

## 8. Realtime

| Channel | Who may listen | Carries |
|---|---|---|
| `admin.orders` | `orders.view` | Order lifecycle, PLUS submissions. |
| `admin.activity` | Any active admin | Refunds, PLUS decisions, customer/driver status, settlements, marketing progress. |
| `admin.{id}` | That admin | Bell entries and live toasts. |

Payloads are identifiers only; screens re-read the database with the viewer's
permissions.

**Verified end to end** (two real Chromium sessions, local Reverb, no reloads):

- admin B suspends a customer in the UI → admin A's list shows
  "Suspended" **1.3 s** later;
- a separate process accepts an order → A's pending badge goes 6 → 5 in
  **1.4 s**;
- with Reverb stopped → A's badge updated by fallback polling after **40 s**.

**Language during live updates** (item 8): admin A worked in Arabic and admin
B in English. A changed a filter, then:
- B suspended a customer → A's re-rendered list showed "موقوف" after 1.4 s;
- A switched tabs, then another process accepted an order → A's badge went
  4 → 3 after 1.4 s.

A stayed `dir=rtl` with **no English label anywhere in the content**, and no
page reload. This complements the HTTP-level `AdminLivewireLocaleTest`
(tabs, filters, refresh in Arabic and English).

**Own action, same page** (item 9): on an order page with the status-history
panel open, "Accept → Confirm" switched the header actions to "Assign driver"
and added the "Accepted" history row. A JS marker set before the action was
still present after it, so it was the same document with no reload (the URL
only gained `?relation=2`). An order whose scheduled acceptance window had not
opened was refused, as designed, and nothing changed.

## 9. Error monitoring

See `docs/waffrli/error-monitoring.md`: correlation id header (JSON bodies
unchanged), grouping, scrubbing, regression reopen, permissions, retention.

## 10. Performance

Measured on a disposable SQLite copy inflated to **56,320 orders**, average of
repeated runs. "Before" is `main` `e5d5ae0`; "after" is this branch with its
migrations.

| Measure | Before | After |
|---|---|---|
| `TodayOverview::figures` | 233 ms / 9 queries | **12 ms** / 10 queries |
| `TodayOverview::series` | 184 ms | **11 ms** |
| `OrdersTrendChart::series` | 18 ms | **0.9 ms** |
| `FinanceOverview::rows` | 25 queries, 37 ms | **8 queries**, 32 ms |
| `OperationsOverview::items` | 18 ms | **7 ms** |
| Dashboard page (`/admin`, non-lazy widgets) | 39 queries, 100 ms | **22 queries**, 92 ms |
| `/admin/orders` | 17 queries, 137 ms | 17 queries, ~150 ms |
| `/admin/customers` | 6 queries, 122 ms | 6 queries, 107 ms |

The orders list is slightly slower (≈10 ms) because its filter form now
renders above the table. This trade-off is accepted for visible filters.
SQLite timings are indicative; MySQL 8 will differ in absolute numbers. The
new indexes and the grouped queries help on both.

## 11. Tests

- **New test files:** `AdminLivewireLocaleTest`,
  `AdminAuditDetailsLocalizationTest`, `FixedTestOtpTest`,
  `NearestCentrePartitionTest`, `HamaDemoDatasetTest`,
  `RegionalDemoSeederSafetyTest`, `AdminActivityRealtimeTest`,
  `DashboardAggregatesTest`, `AdminCancellationAuditTest`,
  `MarketingAudienceTest`, `FcmCheckCommandTest`, `ErrorMonitorTest`,
  `TableFiltersLayoutTest`, `AdminFormsMapPickerTest`; and the support trait
  `InvalidatesCurrencyRate`.
- **Full suite, SQLite (before 6a4ce9e):** 758 tests, 31,481 assertions,
  0 failures, 22 skipped (MySQL-only tests).
- **Full suite, MySQL 8 (before 6a4ce9e):** 758 tests, 2 errors, 7 skipped.
  - `Tests\Feature\Currency\OperationalMoneyTest::test_missing_or_invalid_rate_never_produces_an_amount`
  - `Tests\Feature\Customer\SypOperationalCurrencyTest::test_catalog_converts_real_amount_and_missing_rate_fails_closed`

  Both threw `Illuminate\Database\QueryException: SQLSTATE[HY000]: General
  error: 3819 Check constraint 'chk_currencies_rate_positive' is violated`
  on `update currencies set exchange_rate = 0`.

  **Root cause:** the tests simulated a "missing rate" by storing a zero
  rate. MySQL's CHECK (`exchange_rate > 0`) makes that row impossible;
  SQLite does not enforce CHECK constraints. **Pre-existing on `main`:**
  the same 2 tests fail there with the same error. Not caused by this
  branch.

  **Fix (6a4ce9e, test-only):** on MySQL the tests now assert that the
  database refuses the zero rate (a stronger guarantee), then exercise the
  application's fail-closed guard with the invalid rate in memory. On
  SQLite they behave as before. Both files: 15 passed on SQLite and on MySQL
  8.
- **Full suite, MySQL 8, final tree (6a4ce9e):** 758 tests, 31,907
  assertions, **0 failures, 0 errors**, 7 skipped.
  - 6 skips are `DeliveryOfferConcurrencyTest`, which runs only against a
    database named `waffrli_test`. Run separately on such a disposable
    MySQL 8 database: **6 passed** (152 assertions).
  - 1 skip is `DatabaseTranslationsTest::test_marketing_translation_migration_preserves_both_languages_both_ways`:
    SQLite-only by design (DDL would commit the MySQL test transaction). It
    passes in the SQLite run.
- **Full suite, SQLite, final tree (6a4ce9e):** 758 tests, 31,481 assertions,
  **0 failures**, 22 skipped (the MySQL-only tests).
- **Targeted re-runs after the fix (SQLite):** 112 passed across
  cancellation, order workflow, PLUS, marketing, error monitoring, realtime,
  push and localization. The Hama, Homs and partition tests: 11 passed.

## 12. Mobile API compatibility

- **Contract checks:** `waffrli:api-contract --check`, `waffrli:openapi --check`
  and `waffrli:api-inventory --check` (141 routes) all pass. The customer,
  driver, delivery and push suites pass.
- **No change** to customer or driver routes, JSON structures, field names,
  types, envelope, pagination or auth.
- **Observable differences, all additive:**
  - an `X-Request-Id` response **header**;
  - a cancellation request that used to stay `pending` forever now becomes
    `approved` (direct admin cancel) or `rejected` (delivered). These are
    existing status values, already in the enum;
  - drivers can receive `marketing.message` notifications, but only when an
    admin explicitly targets drivers. The default stays customers only.
- A full response-snapshot comparison against `main` was **not** run: the
  demo dataset differs between the two. The contract tooling and the API
  suites are the evidence.

## 13. Risks

- **Indexes:** they build online (INPLACE, LOCK=NONE), but the build itself
  takes time and I/O on a large `orders` table; run the migration in a quiet
  period.
- **CHECK constraints** on `delivery_areas` and `marketing_broadcasts` use a
  table COPY: writes to those two small tables wait for a moment.
- **Error monitor:** stores scrubbed data only. The scrubber is conservative
  but pattern-based; review the first entries after deploying.
- **Marketing to drivers:** the driver app shows whatever is in its
  notification list. Confirm the app renders `marketing.message` before the
  first driver campaign.
- **Fallback polling:** about 30–40 s of staleness only while Reverb is down.
- **Map tiles:** the default is OpenStreetMap; heavy use needs your own tile
  server (`WAFFRLI_MAP_TILE_URL`).

## 14. Deployment

### Migrations to run (exactly these four, in this order)

1. `2026_11_16_100000_add_boundary_quality_to_delivery_areas`
2. `2026_11_16_110000_add_dashboard_indexes`
3. `2026_11_16_120000_add_recipients_and_progress_to_marketing_broadcasts`
4. `2026_11_16_130000_create_error_events_table`

### Seeders

| Seeder | Production | Why |
|---|---|---|
| `Database\Seeders\AdminAccessSeeder` | **Run once** | Creates the missing `errors.view` / `errors.manage` permissions (`findOrCreate`). Never creates accounts, never assigns or removes roles. |
| `WaffrliEssentialSeeder` | Not needed for this release | Idempotent foundation data. Not required by these changes. |
| `WaffrliDemoSeeder`, `DemoHamaSeeder`, `DemoHomsSeeder` | **Never** | Refuse production by design. Local or demo databases only. |

### Production / existing database (with a backup first)

Back up the database first and keep the dump outside the web root:

```bash
mysqldump --single-transaction --routines --triggers -u USER -p DATABASE > waffrli-before-admin-audit.sql
```

Preview the SQL; this changes nothing:

```bash
php artisan migrate --pretend
```

Apply the four migrations:

```bash
php artisan migrate --force
```

```bash
php artisan db:seed --class="Database\Seeders\AdminAccessSeeder" --force
```

```bash
php artisan optimize:clear
```

```bash
php artisan optimize
```

Restart the workers so they load the new code:

```bash
php artisan queue:restart
```

```bash
php artisan reverb:restart
```

Readiness check (local; no network, nothing sent):

```bash
php artisan waffrli:fcm-check
```

Optional, on the server with real credentials:

```bash
php artisan waffrli:fcm-check --online
```

Keep in place:
- `.env` is not changed by this release. The new variables are optional,
  with safe defaults (see `.env.example`):
  `WAFFRLI_ERROR_MONITOR_ENABLED`, `WAFFRLI_ERROR_RETENTION_DAYS`,
  `WAFFRLI_ERROR_STALE_RETENTION_DAYS`.
- `WAFFRLI_TEST_FIXED_OTP_ENABLED` must stay `false`. It is ignored in
  production anyway.
- The compiled admin theme (`public/css/filament/admin/theme.css`) is
  committed. `npm run build:admin-theme` is only needed after editing the
  theme source.
- The schedule (`schedule:run` cron or `schedule:work`) now also prunes old
  error groups daily at 00:35.

### Rollback (only if needed)

`php artisan migrate:rollback --step=4` reverses exactly these four
migrations. It drops the new columns, indexes and the `error_events` table,
so any error groups, boundary-quality labels and marketing progress counters
recorded since the deploy are lost. No financial data is involved.

### Local development

```bash
php artisan migrate
```

```bash
php artisan db:seed --class="Database\Seeders\WaffrliDemoSeeder"
```

Keep `php artisan queue:work`, `php artisan schedule:work` and
`php artisan reverb:start` running.

## 15. Prioritized checklist

1. Review and merge this branch (nothing is pushed yet).
2. On the server: back up, migrate, run the access seeder, then
   `waffrli:fcm-check --online` (section 14).
3. Confirm the driver app renders marketing messages before targeting
   drivers.
4. Delivery-area polygon **drawing** (leaflet-draw) if admins will draw
   areas by hand.
5. Optional browser notifications (design in section 3).
6. Remove or align the legacy `HttpResponseTrait::handleException` and the
   starter `docs/openapi` drift (149 legacy routes).
7. Add `ext-bcmath` to `composer.json` `require` (reported earlier; not
   changed here).
8. ~~Fix the two MySQL-only currency test errors.~~ Done in 6a4ce9e
   (test-only).

## 16. Requirement status

### Completed and verified

| Requirement | Evidence |
|---|---|
| Arabic/English persist through Livewire actions, filters, tabs and live updates | `AdminLivewireLocaleTest` (fails without the fix); browser: Arabic session through filter, tab and two live re-renders, no English labels. |
| Panel-wide live refresh: own actions, other sessions, other processes | `AdminActivityRealtimeTest`; browser with real Reverb: 1.3–1.4 s, no reload; own action on the order page updates the header and the status history in the same document. |
| Fallback polling and reconnect | Browser: Reverb stopped → update after 40 s. |
| Dashboard accurate, live, efficient | `DashboardAggregatesTest` (identical to the previous calculation in 3 timezones incl. DST, SQLite + MySQL); measured 236 → 12 ms. |
| Visible, consistent filters with reset and active indicators | `TableFiltersLayoutTest`; browser desktop and phone, RTL. |
| Fixed development OTP, never in production | `FixedTestOtpTest` (8). |
| Hama comprehensive named areas, coverage, linked branches, checkouts | Section 5 (MySQL fresh install + 11 tests). |
| SYP demo pricing, multi-currency kept, no re-denomination of history | Section 6 (invariants on MySQL + tests). |
| Error monitoring with UI, correlation ids, scrubbing, resolve workflow, retention | `ErrorMonitorTest` (7); MySQL schema test. |
| Admin cancellation audit (race, forever-pending requests) | `AdminCancellationAuditTest` (bug tests fail on the old code). |
| FCM readiness diagnostic | `FcmCheckCommandTest` (6); ran locally. |
| Marketing: customers / drivers / both, city filter, live progress | `MarketingAudienceTest` (6), SQLite + MySQL. |
| Bell contrast and localized live toasts with dedupe | Browser measurements light/dark, ar/en; toast test. |
| Four additive migrations safe on existing MySQL data | Section 4 rehearsal. |
| Customer and driver API contracts unchanged | Contract checks pass; API suites pass. |

### Partially completed

| Requirement | What is missing |
|---|---|
| Professional forms + map picker (Phase 3) | Points (branch, city centre and radius) and a polygon **preview** are done. Drawing polygons on the map is not (needs leaflet-draw). Stores, products and currencies forms were reviewed but not restructured. |
| Notifications (Phase 13) | Bell and toasts done. **Browser (OS) notifications**: design only (section 3). |
| Performance (Phase 14) | Dashboard measured before/after on SQLite (56k orders). Not measured on MySQL at volume. The orders list is ≈10 ms slower because of the visible filters. |

### Unverified (needs your environment)

- Real Firebase OAuth, real FCM acceptance and delivery on phones, driver-app
  rendering of marketing messages (section 7).
- A response-by-response JSON snapshot comparison against `main` (the demo
  data differs between the two); covered instead by contract checks and API
  suites.
- Production-scale timing of the index migration on your real `orders`
  table.
- Map tiles: blocked in this sandbox; markers, circles and polygons rendered.

### Failed / open

- None. Both suites are green on the final tree (section 11).
- Pre-existing and unchanged: `docs:check-drift` (149 legacy starter routes,
  same on `main`), and the legacy `HttpResponseTrait::handleException`
  (section 2, P13).

## 17. Follow-up 2026-11-16: admin UI polish and honest notification status

| Item | Change | Evidence |
|---|---|---|
| Filters (all lists) | Compact panel. Date ranges sit side by side via the shared `DateRangeFilter` (6 lists). Date filters now show removable chips and count towards the active total. 2 columns on phones, 4–5 on desktop. While open, the toggle sits in the panel header next to Reset. Queries unchanged. | Orders filter panel: desktop 369 → 214 px (3 → 2 rows); phone (opened) 909 → 438 px (9 → 5 rows). Sweep of all 45 panel lists (light/en, dark/ar): all 200, no horizontal scroll, no JS errors. Arabic chip after a Livewire filter update. `TableFiltersLayoutTest`. |
| City form | Both sections full width. Latitude, longitude and radius in one row; the map full width below; km suffix. | Inputs 105 → 251 px, map 364 → 800 px. Map↔input binding checked in en and ar. |
| Errors screen | Not in the sidebar; still available to `errors.view` at `/admin/error-events` and refused to others. Monitoring unchanged. | `AdminNavigationVisibilityTest` |
| Currency redenominations | Sidebar entry only while the feature flag is on (it is off). When on, the screen explains that it changes the accounting base currency, not the SYP display. | `AdminNavigationVisibilityTest` |
| Marketing status | "Sent" is now shown as "Processed". Per-stage lines: in-app saved (counted from `notifications`); push queued / accepted by Firebase / waiting / failed / invalid / not needed; recipients without a phone; push off. A message queued for more than 2 minutes says it is waiting for a queue worker. Additive migration `2026_11_16_140000_add_delivery_stats_to_marketing_broadcasts`. | `MarketingDeliveryStatusTest`: sync queue; database queue with the worker stopped, then started; duplicate job (at most once); failure after retries. |
| Push / OTP readiness | OTP is sent synchronously (no worker needed) and never logged. SMS and push drivers are separate from their fakes. Customer and driver devices get push jobs. | `FirebaseOtpReadinessTest`; `fcm-readiness.md` (what still needs real credentials and phones). |

Migration to run on deploy (additive, nullable or with defaults; columns are
added instantly on MySQL 8): `2026_11_16_140000_add_delivery_stats_to_marketing_broadcasts`.
No seeder is needed for this follow-up.
