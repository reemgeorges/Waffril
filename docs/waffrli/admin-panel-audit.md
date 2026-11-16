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

| Migration | Change |
|---|---|
| `2026_11_16_100000_add_boundary_quality_to_delivery_areas` | 2 nullable columns + CHECK. |
| `2026_11_16_110000_add_dashboard_indexes` | 3 secondary indexes (online on InnoDB). |
| `2026_11_16_120000_add_recipients_and_progress_to_marketing_broadcasts` | `recipient_type` (default `customers`) + 2 counters + CHECK. |
| `2026_11_16_130000_create_error_events_table` | New table + CHECK. |

No data is modified, no existing column changes type, and nothing is dropped.
After deploying, run the essential access seeder once, so the
`errors.view` / `errors.manage` permissions exist for roles. It is idempotent
and creates no accounts.

## 5. Hama report

See `docs/waffrli/demo-hama.md`. In short:

- **City boundary:** the verified OpenStreetMap relation 18354844 is the
  catch-all area.
- **Neighbourhoods:** 32 approximate nearest-centre areas from OSM
  neighbourhood points, each labelled *approximate* with its source; no
  invented boundaries.
- **Shops:** fictional stores, prices in new Syrian pounds.
- **Homs:** smaller; 5 verified OSM boundaries plus 10 approximate areas.
- **Tests:** grid coverage tests and checkouts across the neighbourhoods.

## 6. Currency

- Base currency USD; SYP is stored in old pounds (rate 13000/USD) and
  displayed in new pounds through `Denomination`.
- Demo prices are entered in new SYP and converted on the way in;
  historical amounts are never converted.
- The finance widget never sums across currencies, and its grouped rewrite
  is proven identical to the previous calculation (test).

## 7. Firebase

`php artisan waffrli:fcm-check` (no network by default; `--online` and
`--validate-token` are opt-in, and nothing is delivered). This environment
has no Firebase credentials: only the local checks ran, and the network
paths are verified with faked Google/FCM endpoints. **Run `--online` on the
real server.**

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
  `TableFiltersLayoutTest`, `AdminFormsMapPickerTest`.
- **Full suite, SQLite, final tree:** 758 tests, 31,481 assertions, 0
  failures, 22 skipped (MySQL-only tests).
- **Full suite, MySQL 8, final tree:** 758 tests, 31,897 assertions,
  **2 errors**, 7 skipped. Both errors are pre-existing on `main` (same 2
  tests fail there): `OperationalMoneyTest::test_missing_or_invalid_rate_never_produces_an_amount`
  and `SypOperationalCurrencyTest::test_catalog_converts_real_amount_and_missing_rate_fails_closed`.
  Each sets `exchange_rate = 0`, which the MySQL CHECK `chk_currencies_rate_positive`
  correctly refuses. SQLite does not enforce it. Test-only; see checklist item 8.

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

- **Indexes:** online on InnoDB, but on a very large `orders` table run the
  migration in a quiet period.
- **Error monitor:** stores scrubbed data only. The scrubber is conservative
  but pattern-based; review the first entries after deploying.
- **Marketing to drivers:** the driver app shows whatever is in its
  notification list. Confirm the app renders `marketing.message` before the
  first driver campaign.
- **Fallback polling:** about 30–40 s of staleness only while Reverb is down.
- **Map tiles:** the default is OpenStreetMap; heavy use needs your own tile
  server (`WAFFRLI_MAP_TILE_URL`).

## 14. Local deployment

```bash
git switch feature/admin-panel-audit
```

```bash
composer install
```

```bash
php artisan migrate
```

```bash
php artisan db:seed --class="Database\Seeders\AdminAccessSeeder"
```

```bash
npm run build:admin-theme
```

```bash
php artisan optimize:clear
```

```bash
php artisan waffrli:fcm-check
```

Demo data, in a local or demo database only:

```bash
php artisan db:seed --class="Database\Seeders\WaffrliDemoSeeder"
```

Keep `php artisan queue:work`, `php artisan schedule:work` and
`php artisan reverb:start` running.

## 15. Prioritized checklist

1. Review and merge this branch (nothing is pushed yet).
2. On the server: migrate, run the access seeder, then
   `waffrli:fcm-check --online`.
3. Confirm the driver app renders marketing messages before targeting
   drivers.
4. Delivery-area polygon **drawing** (leaflet-draw) if admins will draw
   areas by hand.
5. Optional browser notifications (design in section 3).
6. Remove or align the legacy `HttpResponseTrait::handleException` and the
   starter `docs/openapi` drift (149 legacy routes).
7. Add `ext-bcmath` to `composer.json` `require` (reported earlier; not
   changed here).
8. Make the two "missing rate" currency tests simulate the missing rate without
   violating the MySQL CHECK (e.g. mock the rate lookup), so the MySQL suite
   is fully green.
