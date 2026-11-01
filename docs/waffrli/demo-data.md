# WAFFRLI — Seeders, demo data and the driver map (2026-11-01)

## Seeding modes

| Command | Where | What |
|---|---|---|
| `php artisan db:seed --class=WaffrliEssentialSeeder` | any environment | permissions + `super-admin` role, starter employee roles, setting defaults, **configured** currencies, payment methods |
| `php artisan db:seed --class=WaffrliDemoSeeder` | `local`, `development`, `demo`, `testing` only | essential data + full demo dataset |

Neither seeder is called from `DatabaseSeeder`, and neither creates a login. Create your own Super Admin afterwards:

```bash
php artisan waffrli:create-super-admin --email=you@example.com
```

### Essential (safe everywhere)

- `AdminAccessSeeder`: every permission and the `super-admin` role.
- `Essential\AdminRoleTemplatesSeeder`: `operations-manager`, `dispatcher`, `catalog-manager`, `finance-officer`, `plus-manager`, `support-agent`. A role gets its permissions only when it is created; an edited role is never changed.
- `Essential\PlatformSettingsSeeder`: stores documented defaults for keys that are missing; never overwrites.
- `Essential\ConfiguredCurrencySeeder`: currencies only from explicit configuration:
  ```
  WAFFRLI_DEFAULT_CURRENCY=USD
  WAFFRLI_EXTRA_CURRENCIES="SYP:13000:0,EUR:0.92:2"   # code:rate:decimals, rate per 1 default unit
  ```
  - Nothing configured means nothing created.
  - An existing default is never switched.
  - Existing currencies are never edited.
- `PaymentMethodSeeder`: wallet and cash on delivery.

### Demo (local or demo databases only)

`WaffrliDemoSeeder` refuses to run in `production`, `staging` or any other environment not listed above. It also refuses a database that already holds non-demo orders or non-demo wallet movements. Use an empty local database:

```bash
php artisan migrate
```

```bash
php artisan db:seed --class=WaffrliDemoSeeder
```

```bash
php artisan storage:link
```

The `storage:link` step makes the generated product images visible.

The seeders run in dependency order:

1. `Demo\DemoPlatformSeeder`
2. `Demo\DemoCatalogSeeder`
3. `Demo\DemoPeopleSeeder`
4. `Demo\DemoPlusSeeder`
5. `Demo\DemoOrderSeeder`, which uses `Demo\DemoOrderBuilder`
6. `Demo\DemoEngagementSeeder`

Shared rules live in `Demo\DemoContext`.

- **Identifiable**:
  - Emails end with `@demo.waffrli.test`.
  - Phones are fictional `+1 202 555 01xx/02xx` numbers.
  - Store and category slugs start with `demo-`, order numbers with `DEMO-`, and vehicle plates with `DEMO-`.
  - Every audit entry carries `generated_demo: true`.
- **No credentials**:
  - Customers and drivers get random, unknown passwords.
  - Business actions run as a temporary "Demo data generator (system)" admin. It holds `super-admin` only while seeding, then loses the role and is deactivated.
- **Real business rules**:
  - Every state change goes through the real services: `OrderAdminService` (accept, assign, pick up, deliver, cancel, cancellation requests), `RefundService`, `WalletLedger`, `DriverCashSettlementService`, `PlusService`, `ProductRatingService` and `CustomerAccountService`.
  - History is back-dated with time travel.
  - Orders are priced like checkout: branch prices, product offers, PLUS cycle snapshot discount and free delivery, platform fee quote, and conversion to the order currency.
- **Deterministic and idempotent**:
  - Every record is keyed by a demo identifier and skipped when it exists. A second run adds or removes no rows; this is verified on MySQL 8 and SQLite.
  - Each order scenario is one transaction.
  - Nothing is deleted or overwritten. The one exception: each run re-stamps the static demo driver positions, so the map shows fresh markers again.
- **Relative dates**: dates are relative to the first run. Seed a fresh database to move them to "now".
- **Demonstration exchange rates**: SYP 13000 and EUR 0.92 per USD. They are added only when USD is, or becomes, the default currency.

### Approximate record counts (one run on an empty database)

| Module | Records |
|---|---|
| Platform | 3 cities, 4 delivery areas, 8 fee bands (global, city, area), 3 currencies, 10 settings |
| Catalog | 5 store categories, 14 product categories (tree), 6 stores, 11 branches, 84 opening-hour rows, 4 special hours/closures, 24 products (1 SVG image each, 42 category links), 8 option groups, 23 options, 47 branch prices, 54 branch option prices, 5 offers (active, expired, upcoming) |
| People | 10 customers (1 suspended) with 11 addresses; 8 drivers (5 on an active delivery, 2 idle, 1 offline) |
| Orders | 35 orders: 3 pending, 3 scheduled pending (1 urgent), 2 accepted, 3 assigned, 2 picked up, 17 delivered, 5 cancelled. Also 54 items, 18 option snapshots, 127 status-history rows and 3 cancellation requests (pending, rejected, approved) |
| Finance | 39 payments (cash, wallet, wallet + cash; USD, SYP, EUR), 4 refunds, 1 wallet reversal, 9 ledger rows, 4 wallets (balance = ledger), 3 driver settlements (settled, disputed, open) |
| PLUS | 3 plans, 8 store benefits, 3 memberships (active, active, ended), 6 cycles (active, scheduled, expired), 11 snapshot discounts, 13 requests (pending, approved, rejected, custom) |
| Engagement | 10 verified-purchase ratings (2 hidden), 7 favorites, 8 stored notifications |
| Audit | about 83 entries, all marked `generated_demo` |

## Driver map (`/admin/driver-map`, permission `drivers.track`)

- **Library and tiles:**
  - Leaflet 1.9.4 is vendored unmodified in `public/vendor/leaflet` (BSD-2-Clause, LICENSE included). Its SHA-256 matches the published integrity hash.
  - Tiles are OpenStreetMap standard tiles with the required "© OpenStreetMap contributors" attribution.
  - The tile URL and attribution are configurable (`WAFFRLI_MAP_TILE_URL`, `WAFFRLI_MAP_TILE_ATTRIBUTION`). OSM's tile usage policy does not allow heavy production use, so configure your own or a commercial tile provider for production.
- **Which drivers appear:**
  - Only drivers with an `assigned` or `picked_up` order.
  - Each driver is shown at the last stored position (`drivers.last_lat`, `last_lng`, `last_location_at`). No movement is simulated.
  - A driver disappears as soon as no active delivery remains.
- **Markers and popups:**
  - Amber means assigned, blue means picked up.
  - A grey dashed marker plus a red "Stale position" badge means the position is older than `WAFFRLI_DRIVER_LOCATION_STALE_MINUTES` (default 10).
  - The popup shows driver name, city, order number(s), status, branch and last update. It is built from DOM text nodes, so stored text cannot inject markup.
- **Filters:** city and delivery status. Changing a filter re-fits the viewport to the visible markers.
- **Refresh:** a database poll every `WAFFRLI_DRIVER_MAP_REFRESH_SECONDS` (default 30) updates the markers without reloading the page.
- **Permissions:** `drivers.track` is checked on the page and again on every refresh, so revoking it stops the data immediately.
- **Future Reverb:** `markers()` and `refreshMap()` are the hook for the single shared Reverb WebSocket (private channel `admin.drivers-map`). Reverb is not installed.

Screenshots taken with Chromium against the seeded MySQL 8 demo database are in `docs/waffrli/screenshots/`.

**Caveat:** the build environment's network policy blocked `tile.openstreetmap.org` and `ui-avatars.com`. The screenshots therefore show the real markers, popups, filters and attribution on Leaflet's grey background, without map tiles or the avatar image. With normal internet access the OSM tiles load behind the same markers.

## Dashboard

All values come from the database, and each widget respects permissions:

- **PlatformOverview:** customers (with suspended), stores/branches, products, active PLUS memberships and cycles.
- **OperationsOverview:** pending, urgent, active deliveries, delivered today, cancellation requests, PLUS requests, open settlements.
- **PendingOrdersTable**.
- **OrdersByStatusChart**.
- **FinanceOverview** (per currency, never converted): wallet balances with a ledger-consistency check, collected payments, cash awaiting collection, refunds, and unsettled driver cash.
