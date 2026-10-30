# WAFFRLI P0 — Foundation (implemented 2026-10-30)

P0 adds the seven platform tables, their admin APIs, the customer city
APIs, currency/money and delivery-fee services, an ar/en locale
middleware and the P0 permissions. It does **not** touch users, OTP,
catalog, orders, PLUS, wallet, Filament or WebSocket code.

## Tables

| Table | Notes |
|---|---|
| `settings` | key/value JSON; `is_monetary` marks default-currency values; `key` and `is_monetary` fixed after creation |
| `currencies` | `exchange_rate` = units per 1 default unit; exactly one default (`default_marker` unique) |
| `currency_redenominations` | run log for the P7 default-switch job; one in-flight run max. Table/model only in P0 |
| `cities` | center + `radius_km` for GPS detection |
| `delivery_areas` | GeoJSON Polygon (one outer ring, `[lng, lat]`), server-derived bounding box |
| `delivery_fee_rules` | platform-wide distance bands per area / city / global (`scope_key` generated) |
| `notifications` | Laravel standard database notifications |

Records are deactivated, never deleted (no delete routes). Models extend
`App\Models\BasePlatformModel` (no SoftDeletes, because the approved schema
has no `deleted_at` on these tables).

## Permissions (admin guard)

Created by `Database\Seeders\PlatformPermissionSeeder` — idempotent, never
assigns anything, **not** called from `DatabaseSeeder`:

```
php artisan db:seed --class=Database\\Seeders\\PlatformPermissionSeeder
```

| Permission | Grants |
|---|---|
| `settings.manage` | `/api/v1/admin/settings/*` |
| `currencies.manage` | `/api/v1/admin/currencies/*` incl. first-default initialization |
| `currencies.change_default` | nothing yet — reserved for P7 |
| `cities.manage` | `/api/v1/admin/cities/*` |
| `delivery.manage` | `/api/v1/admin/delivery-areas/*`, `/api/v1/admin/delivery-fee-rules/*` |

Every admin route requires `auth:admin` + `guard.identity:admin` + the
model policy's `manage` ability. Customer routes require `auth:user` +
`guard.identity:user`.

## Endpoints

Responses use the existing envelope `{data, status, error, statusCode}`.
Validation errors return 400 (existing `BasicRequest` convention); business
rule violations return 422; a second default initialization returns 409.

| Method | Path | Notes |
|---|---|---|
| GET | `/api/v1/admin/settings/all`, `/all/paginated` | list |
| POST | `/api/v1/admin/settings/show` `{id}` | |
| POST | `/api/v1/admin/settings/create` `{key, value, is_monetary?}` | |
| POST | `/api/v1/admin/settings/update` `{id, value}` | value only |
| GET | `/api/v1/admin/currencies/all`, `/all/paginated` | |
| POST | `/api/v1/admin/currencies/show` `{id}` | |
| POST | `/api/v1/admin/currencies/create` `{code, name{ar,en}, symbol, decimals, exchange_rate, is_active?}` | `is_default` prohibited |
| POST | `/api/v1/admin/currencies/update` `{id, name?, symbol?, decimals?, exchange_rate?}` | `code`, `is_active`, `is_default` prohibited |
| POST | `/api/v1/admin/currencies/activate`, `/deactivate` `{id}` | default cannot be deactivated |
| POST | `/api/v1/admin/currencies/initialize-default` `{id}` | only while no default exists |
| GET | `/api/v1/admin/cities/all`, `/all/paginated` | |
| POST | `/api/v1/admin/cities/show`, `/create`, `/update`, `/activate`, `/deactivate` | |
| GET | `/api/v1/admin/delivery-areas/all`, `/all/paginated` | |
| POST | `/api/v1/admin/delivery-areas/show`, `/create`, `/update`, `/activate`, `/deactivate` | bbox derived server-side |
| GET | `/api/v1/admin/delivery-fee-rules/all`, `/all/paginated` | |
| POST | `/api/v1/admin/delivery-fee-rules/show`, `/create`, `/update`, `/activate`, `/deactivate` | no overlapping active bands per scope |
| POST | `/api/v1/admin/delivery-fee-rules/quote` `{city_id, origin_lat, origin_lng, destination_lat, destination_lng}` | read-only preview |
| GET | `/api/v1/user/cities` | active cities |
| POST | `/api/v1/user/cities/detect` `{lat, lng}` | nearest active city within radius; 404 if none; throttled 60/min |

Machine-readable spec: `docs/waffrli/openapi/p0-foundation.yaml`.

## Currency rules

1. The first currency may be created without a default; any further
   currency requires an initialized default (rates are relative to it).
2. `initialize-default` sets `is_default = 1`, `exchange_rate = 1`,
   `is_active = 1`, only while no default exists (409 afterwards).
3. Switching an existing default is **not possible in P0** (P7 job +
   `currencies.change_default`). `CurrencyPolicy::changeDefault()` always
   returns false.
4. The default's rate stays 1 and it cannot be deactivated; `code` is
   immutable.
5. Send rates as strings (e.g. `"0.000076923077"`): up to 12 decimals.
6. `CurrencyConverter` converts via exact cross rates
   (`rate_to / rate_from` at 12 dp) using brick/math `BigDecimal`, rounding
   half-up to the target currency's `decimals`. It never rewrites stored
   prices.

## Delivery coverage and fee

`DeliveryFeeResolver::quote(cityId, origin, destination)`:

1. If the city has active areas, the destination must be inside one
   (else `outside_service_areas`).
2. Distance from the bound `DistanceCalculator`.
3. Most specific scope **that has active rules** is authoritative
   (area → city → global); if its bands don't cover the distance, the
   quote is not covered (`no_matching_fee_rule`) — broader scopes never
   extend a narrower scope.
4. Band match `from ≤ d < to`; fee = `base_fee + per_km_fee × (d − from)`,
   default currency, 4 dp.

The branch-city ↔ address-city check belongs to checkout (P5).

**Distance strategy (provisional):** `config/delivery.php`
`distance_strategy` (env `DELIVERY_DISTANCE_STRATEGY`, default `haversine`).
Add an implementation of `App\Contracts\Delivery\DistanceCalculator` to
`distance_strategies` to replace it; every quote reports `distance_method`.

## Locale

`App\Http\Middleware\SetRequestLocale` (api group): first of `ar`/`en`
from `Accept-Language` sets the app locale and `Content-Language`. Without
the header the configured locale is unchanged. Resources return the
current-locale value plus `name_translations`.

## SQLite differences (test suite)

| Concern | MySQL 8 (production) | SQLite (fast tests) |
|---|---|---|
| CHECK constraints | added via `ALTER TABLE ... ADD CONSTRAINT` | **not created** (SQLite cannot add constraints); same rules enforced by requests/services; verified only by `@group mysql` |
| Generated columns | `... AS (...) STORED` | same (`storedAs`), created with the table |
| Concatenation in `scope_key` | `CONCAT('c:', city_id)` | `('c:' \|\| city_id)` |
| Error code mapping | 3819 → 400 envelope | n/a |
| Foreign keys | enforced | enforced by Laravel's SQLite FK pragma |

Run the MySQL suite only against an **isolated** MySQL 8 test database:

```
DB_CONNECTION=mysql DB_HOST=... DB_DATABASE=waffrli_test DB_USERNAME=... DB_PASSWORD=... vendor/bin/phpunit --group mysql
```

## Development data

`Database\Seeders\CurrencySeeder` is development-only (skips in
production): creates USD and makes it default only if no default exists;
seeds SYP only when `DEV_SYP_EXCHANGE_RATE` is provided. Not called from
`DatabaseSeeder`.
