# WAFFRLI Customer API (`/api/v1/customer`)

Branch: `feature/customer-api-realtime`. Every response uses the standard envelope `{data, status, error, statusCode}`:

- form-request validation errors answer **400**;
- business-rule refusals answer with their own status (404 not yours or not found, 409 state conflict, 422 rule violated, 429 rate limited, 503 provider unavailable).

## Identity (phone OTP, no password)

| Rule | Implementation |
|---|---|
| Phone format | E.164 normalization (`App\Support\Phone\PhoneNumber`); default country code `WAFFRLI_DEFAULT_COUNTRY_CODE` (963) |
| Channels | SMS first; WhatsApp fallback when SMS fails (`WAFFRLI_OTP_WHATSAPP_FALLBACK`); providers: `fake` (non-production only) or `twilio` (credentials from env) |
| Code | 6 random digits (CSPRNG), stored hashed, bound to purpose + phone (HMAC), 10 min expiry, 60 s resend cooldown, attempt ceiling, single atomic use |
| Rate limits | `customer-otp` 5/min per IP + 3/min per phone; `customer-otp-verify` 20/min per IP |
| Registration | Unknown phone → short-lived single-use `registration_token`; first + last name required; email optional (unique only once verified); avatar optional |
| Sessions | One active device: a login revokes every other token and deactivates other devices' push tokens |
| Status | `active` / `suspended` (403); deleted accounts can be **recovered by OTP within 30 days**; accounts with a non-zero wallet are never anonymized |
| Purposes | `customer_login`, `customer_recovery`, `customer_email_verify`: a code for one purpose never works for another |
| Dev helper | `GET /api/v1/dev/otp`: registered only for local/testing + fake driver + configured peek token (never in production) |

The legacy email/password user routes (`routes/api/v1/user.php`) are **off** unless `WAFFRLI_LEGACY_USER_AUTH=true` (the test suite enables them for the old tests).

## Business rules

- **City scope:** the default address decides the delivery city. Branches of other cities answer 404. A customer without an address gets 409 (choose an address).
- **Catalog:**
  - branch-specific prices, availability and option deltas; hours and exceptions;
  - offers, search (ar/en, case-insensitive on MySQL and SQLite);
  - aggregate ratings only, with hidden ratings excluded and the placeholder kept separate;
  - `requires_options` tells the app whether a product can be added directly.
- **Favorites:** products only. **Ratings:** only verified purchasers of a delivered order.
- **Carts:** one per branch, several in parallel. Identical configurations merge.
- **Checkout:**
  - requires an `Idempotency-Key` header (UUID), and a replay returns the same order;
  - immediate or scheduled; full validation (city, opening hours, lead time, coverage, minimum order, options);
  - platform delivery fee; the PLUS store discount has priority over public offers, and free delivery combines with it;
  - full or partial wallet payment plus cash for the remainder;
  - wallet debit and PLUS benefit consumption happen in one transaction with row locks;
  - client totals are never trusted;
  - every price, option and name is snapshotted on the order.
- **Orders:**
  - current and previous lists, details, status history, refunds;
  - the driver's first name and vehicle only while assigned or picked up, with live position on `private-order.{id}`;
  - cancel directly while pending; after acceptance, one cancellation request.
- **Wallet and payments:** read only (no top-up, withdrawal or conversion). Currencies and a display-currency preference.
- **PLUS:** plans, membership, cycles, remaining free deliveries, eligible discounts, and requests (new / renew / change / cancel / custom) with their history.
- **Notifications:** list, unread count, mark one or all read. **Device tokens:** register, rotate, remove, own rows only.

## Endpoint inventory

Generated from `php artisan route:list --path=api/v1/customer` (63 routes). "token" = `auth:user` + `guard.identity:user` + `customer.active` + `throttle:customer-api`.

| Method | URI | Auth | Extra throttle |
|---|---|---|---|
| GET | `/api/v1/customer/addresses` | token | customer-api |
| POST | `/api/v1/customer/addresses` | token | customer-api |
| DELETE | `/api/v1/customer/addresses/{address}` | token | customer-api |
| PATCH | `/api/v1/customer/addresses/{address}` | token | customer-api |
| POST | `/api/v1/customer/addresses/{address}/default` | token | customer-api |
| POST | `/api/v1/customer/auth/logout` | token | customer-api |
| POST | `/api/v1/customer/auth/otp/request` | public | customer-otp |
| POST | `/api/v1/customer/auth/otp/verify` | public | customer-otp-verify |
| POST | `/api/v1/customer/auth/recovery/request` | public | customer-otp |
| POST | `/api/v1/customer/auth/recovery/verify` | public | customer-otp-verify |
| POST | `/api/v1/customer/auth/register` | public | customer-otp-verify |
| GET | `/api/v1/customer/branches` | token | customer-api |
| GET | `/api/v1/customer/branches/{branch}` | token | customer-api |
| GET | `/api/v1/customer/branches/{branch}/products` | token | customer-api |
| GET | `/api/v1/customer/branches/{branch}/products/{product}` | token | customer-api |
| POST | `/api/v1/customer/broadcasting/auth` | token | customer-api |
| POST | `/api/v1/customer/cart/items` | token | customer-api |
| DELETE | `/api/v1/customer/cart/items/{item}` | token | customer-api |
| PATCH | `/api/v1/customer/cart/items/{item}` | token | customer-api |
| GET | `/api/v1/customer/carts` | token | customer-api |
| DELETE | `/api/v1/customer/carts/{cart}` | token | customer-api |
| GET | `/api/v1/customer/carts/{cart}` | token | customer-api |
| POST | `/api/v1/customer/carts/{cart}/checkout` | token | customer-api, customer-checkout |
| GET | `/api/v1/customer/cities` | token | customer-api |
| POST | `/api/v1/customer/cities/detect` | token | customer-api, 60,1 |
| GET | `/api/v1/customer/context` | token | customer-api |
| GET | `/api/v1/customer/currencies` | token | customer-api |
| GET | `/api/v1/customer/device-tokens` | token | customer-api |
| POST | `/api/v1/customer/device-tokens` | token | customer-api |
| DELETE | `/api/v1/customer/device-tokens/{deviceToken}` | token | customer-api |
| PATCH | `/api/v1/customer/device-tokens/{deviceToken}` | token | customer-api |
| GET | `/api/v1/customer/favorites` | token | customer-api |
| DELETE | `/api/v1/customer/me` | token | customer-api |
| GET | `/api/v1/customer/me` | token | customer-api |
| PATCH | `/api/v1/customer/me` | token | customer-api |
| DELETE | `/api/v1/customer/me/avatar` | token | customer-api |
| POST | `/api/v1/customer/me/avatar` | token | customer-api |
| POST | `/api/v1/customer/me/email/verification` | token | customer-api, 6,1 |
| POST | `/api/v1/customer/me/email/verify` | token | customer-api, 10,1 |
| GET | `/api/v1/customer/notifications` | token | customer-api |
| POST | `/api/v1/customer/notifications/read` | token | customer-api |
| GET | `/api/v1/customer/notifications/unread-count` | token | customer-api |
| POST | `/api/v1/customer/notifications/{notification}/read` | token | customer-api |
| GET | `/api/v1/customer/offers` | token | customer-api |
| GET | `/api/v1/customer/orders` | token | customer-api |
| GET | `/api/v1/customer/orders/{order}` | token | customer-api |
| POST | `/api/v1/customer/orders/{order}/cancel` | token | customer-api |
| POST | `/api/v1/customer/orders/{order}/cancellation-request` | token | customer-api |
| GET | `/api/v1/customer/payments` | token | customer-api |
| GET | `/api/v1/customer/plus/eligible-discounts` | token | customer-api |
| GET | `/api/v1/customer/plus/membership` | token | customer-api |
| GET | `/api/v1/customer/plus/plans` | token | customer-api |
| GET | `/api/v1/customer/plus/requests` | token | customer-api |
| POST | `/api/v1/customer/plus/requests` | token | customer-api, 10,1 |
| GET | `/api/v1/customer/product-categories` | token | customer-api |
| DELETE | `/api/v1/customer/products/{product}/favorite` | token | customer-api |
| POST | `/api/v1/customer/products/{product}/favorite` | token | customer-api |
| POST | `/api/v1/customer/products/{product}/rating` | token | customer-api |
| GET | `/api/v1/customer/refunds` | token | customer-api |
| GET | `/api/v1/customer/search` | token | customer-api, 60,1 |
| GET | `/api/v1/customer/store-categories` | token | customer-api |
| GET | `/api/v1/customer/wallet` | token | customer-api |
| GET | `/api/v1/customer/wallet/transactions` | token | customer-api |

## Tests

- `tests/Feature/Customer/*`:
  - `CustomerIdentityTest` (9)
  - `CustomerCatalogTest` (7, includes an N+1 query ceiling)
  - `CustomerCommerceTest` (9)
- `tests/Feature/Push`, `tests/Feature/Realtime`, `tests/Feature/Currency`: see `realtime-and-push.md` and `currency-switch.md`.
- Bruno: `api-tests/bruno/WAFFRLI-Customer` (README inside).
