# Report 1 — API compatibility audit (2026-11-13)

Branch `feature/api-notifications-integrity-audit`.

- **Baseline:** `6498708`, the last `main` commit before the Filament redesign (PR #2).
- **Compared against:** `main` at `1da0b5b` (after PR #2, PR #3 and the table overhaul).
- **Rule:** the source code was treated as authoritative where documentation differs.

## Verdict

- **Customer and driver APIs.** Routes, HTTP methods, authentication, validation, field names, types, nullability, pagination, error envelopes and machine values (`status`, `type`, …) are unchanged.
- **One unintended behaviour change, now fixed.** Human-readable `*_label` text changed in the customer and driver APIs and in the realtime status payload. The cause was the admin-panel wording review, which edited translation keys shared with the APIs. After the fix, real responses match the baseline in English and Arabic.
- **Additive changes only otherwise.** Two new public read-only endpoints (Terms and FAQ), and an optional `device_id` on driver logout.
- **No removed endpoints. No breaking change remains.**

## Scope

**144 registered API routes** were reviewed (`php artisan route:list --path=api`). `waffrli:api-inventory` counts 142; it leaves out the development-only `GET /api/v1/dev/otp` and the OpenAPI document route.

| Area | Routes | Notes |
|---|---|---|
| Customer (`/api/v1/customer/…`) | 65 | auth/OTP/registration/recovery, profile, addresses, cities, catalog, favorites, ratings, carts/checkout, orders, cancellation, wallet/payments/refunds, PLUS, notifications, device tokens, broadcasting auth, content |
| Driver (`/api/v1/driver/…`) | 29 | login/logout/profile/password, availability, location, offers, orders, problems, cash, notifications, device tokens, broadcasting auth |
| Admin API (`/api/v1/admin/…`) | 45 | login (+ challenge), password reset, cities, currencies, delivery areas, fee rules, settings, users |
| Legacy starter `/api/v1/user/…` | 3 | shipped disabled (`WAFFRLI_LEGACY_USER_AUTH=false`) |
| Development / docs | 2 | `dev/otp` (local + fake SMS + peek token only), OpenAPI document |

## Method

1. **Code diff** of everything an API can reach (`routes`, `app/Http`, `app/Services`, `app/Models`, `app/Enums`, `app/Events`, `app/Notifications`, `app/Support`, `config`, `lang`, migrations) between the baseline and `main`.
2. **Black-box response comparison.**
   - **Code:** the baseline and `main` ran side by side from two clean worktrees, with the same dependencies, the same `APP_KEY` and no `.env`. Each side had its own copy of one freshly seeded database (essential + demo data).
   - **Requests:** the three Bruno collections (Customer 110 requests, Driver 50, Admin API 69) ran in the same order against both sides. That covers every API route, including writes: OTP login, registration, recovery, carts, checkout, cancellation, offers, deliveries, ratings, PLUS and device tokens.
   - **Comparison:** all **229 request/response pairs** were compared by HTTP status, field names, types and values, in **English and Arabic**.
   - **Normalisation:** only run-generated values were normalised (tokens, timestamps, UUIDs, OTP codes, generated phone numbers, e-mails, order numbers, server port).
3. **Automated checks:** the contract checks, the full PHPUnit suite on SQLite, and the full suite on MySQL 8.
4. **Security probe** of the running API: cross-account access, guard isolation, revocation, suspension, leaks and throttles.

## Findings

### Customer API

| Item | Result |
|---|---|
| Routes, methods, auth middleware, validation rules | unchanged (code diff + `waffrli:openapi --check` + `waffrli:api-contract --check`) |
| Response structure, field names, types, nullability, pagination | identical in all 110 compared customer requests (en + ar) |
| Machine values (`status`, `type`, `payment.status`, …) | unchanged |
| Error envelope `{data, status, error, statusCode}` and HTTP codes | unchanged |
| Catalog, branch pricing, availability, options, categories, ratings, PLUS, wallet | responses identical; the Filament category/store/pricing/rating changes did not alter API output |
| **Human labels (`status_label`, `from_label`/`to_label`, payment and request labels)** | **changed, then fixed** (A-1 below) |
| New | `GET /api/v1/customer/content/terms`, `GET /api/v1/customer/content/faqs` (public, read-only, throttled 60/min/IP) |

### Driver API

| Item | Result |
|---|---|
| Routes, auth, validation, responses, offers, deliveries, cash | identical in all 50 compared driver requests (en + ar) |
| Offer and order `status_label`, "illegal transition" error text | changed via the same shared keys, then fixed (A-1) |
| Logout | additive: optional `device_id` (stops push to that device; see the notification report N-5) |

### Admin API and integrations

- **Admin API:** all 69 requests identical.
- **Bruno collections:** pass on `main`, with Customer 110/110 (341 assertions), Driver 50/50 (154) and Admin 69/69 (221).
- **Baseline:** fails only the new FAQ request, because that route didn't exist yet.

### A-1 — Admin wording leaked into the mobile APIs (fixed)

**Cause.** `App\Support\Localization\Label` and `OrderStatus::label()` read one vocabulary in `lang/{ar,en}/admin.php`, documented as "shared by the admin panel and the APIs". The panel wording review of 2026-11-11 (`beda3da`) rewrote 19 of those keys, which changed text the apps display:

| Key | English | Arabic |
|---|---|---|
| `order_status.pending` | Pending → Awaiting acceptance | معلق → بانتظار القبول |
| `order_status.picked_up` | Picked up → On the way | تم الاستلام من الفرع → في الطريق إلى العميل |
| `order_status.assigned` | — | تم إسناد سائق → أُسند إلى سائق |
| `payments.status_pending` / `_reversed` / `_failed` | Pending → Awaiting payment; Reversed → Returned | معلق → بانتظار الدفع; … |
| `requests.status_pending` / `_approved` / `_withdrawn` | Pending → Awaiting review | معلق → بانتظار المراجعة; … |
| `settlements.status_open` / `_disputed` / `_settled` | Open → Not settled yet; Disputed → Amount difference | … |
| `plus.membership_*`, `drivers.availability_offline`, `wallets.type_order_payment_reversal`, 2 account-deletion blockers | — (Arabic only) | … |

The same text also reached the realtime `order.status_changed` payload (`status_labels`).

**Fix (`33e3754`).**
- The APIs now read `lang/{ar,en}/api_labels.php`: 73 keys holding the released app text from the baseline. They fall back to `admin.php` for keys added later.
- `OrderStatus::publicLabel()` and `DeliveryOfferStatus::publicLabel()` are used by the API resources, the order service, the state-machine error and the realtime payload.
- The panel keeps its reviewed wording.
- Values, fields and codes were untouched.

**Proof.**
- **Re-comparison after the fix.** No label differences remain in English or Arabic; 216 of 229 pairs are identical.
- **The remaining 13 differences** are generated test data (Bruno's random setting keys and city names, order numbers, device-token hints) and the additive Terms/FAQ endpoints.

**Regression tests.**
- `ApiLabelVocabularyTest` (4 tests) pins the released strings and proves a panel-only edit cannot reach the API.
- `ApiLocalizationTest` now asserts the API vocabulary, not the panel keys. Comparing against the moving admin keys is why this drift went unnoticed.

To change app wording deliberately, edit `api_labels.php` and release it with the apps.

## Added, removed and modified contracts

| Type | Items |
|---|---|
| Added endpoints | `GET customer/content/terms`, `GET customer/content/faqs` |
| Removed endpoints | none |
| Modified request contracts | driver `POST logout` accepts an optional `device_id` (additive; old clients unaffected) |
| Modified response contracts | none (label text restored to the released wording) |
| Breaking changes | none remaining |

## Security and isolation

Black-box probe of the running `main` server (21 checks), plus the existing PHPUnit coverage and the E2E runs:

| Check | Result |
|---|---|
| Customer A → customer B's order, cancel, cancellation request, address, notification | 404 for each |
| Driver A → another driver's order / pickup; another driver's offer (read, accept) | 404 |
| Customer, driver and admin tokens on each other's APIs | 401 in every direction |
| No token / forged token | 401 |
| Token after logout | 401 |
| Suspended customer: API and broadcasting auth | 403 "This account is suspended" |
| OTP request throttle | 200 then 429 |
| Sensitive keys (`password`, `remember_token`, OTP hashes, secrets, raw FCM tokens) in profile, order, device-token and notification payloads | none (device tokens are shown as `token_hint` "…a8c969") |
| Admin panel session cannot authenticate as a customer | E2E step 20: `/admin/orders` without session → redirect; customer token → admin API 401 |
| Rate limits and authorization checks | intact (`customer-api` 180/min, OTP limits, driver location 12/min: covered by PHPUnit) |

## Automated test results

| Run | Result |
|---|---|
| `php artisan waffrli:api-inventory --check` | 142 API routes, all covered by Bruno requests |
| `php artisan waffrli:openapi --check` | the OpenAPI document matches the routes, rules and examples |
| `php artisan waffrli:api-contract --check` | the mobile API contract matches the routes |
| `php artisan test` (SQLite), before the fixes | 630 passed, 22 skipped (MySQL-only), 0 failed |
| `php artisan test` (SQLite), with all fixes | **641 passed, 22 skipped, 0 failed** (9,397 assertions) |
| `php artisan test` on MySQL 8 (disposable `waffrli_testing`), with all fixes | **656 passed, 7 skipped, 0 failed** (9,812 assertions) |
| Bruno, baseline vs main, en + ar | 229 pairs each; differences explained above |
| Security probe | 21/21 |

Contracts were **not regenerated**: the checks pass against the committed contract files.

## Remaining risks

- **Labels belong to the apps now.** Future wording changes for the apps must be made in `api_labels.php`. The panel's `admin.php` no longer affects them, except for brand-new keys that have no API entry yet.
- **Arabic account-deletion blockers.** These messages come from the shared customer account service, so the panel shows the API (released) wording for them.
- **Flutter coverage.** The comparison used the Bruno collections (every route). Flutter-specific call sequences outside those flows were not replayed.
