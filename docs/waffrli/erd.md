# WAFFRLI — Frozen ERD Baseline

Status: **approved design baseline** (frozen 2026-10-29). Implementation is
phased; only P0 is implemented (see `p0-foundation.md`). Database target:
**MySQL 8.0.16+** (CHECK constraints enforced). The fast test suite runs on
SQLite; MySQL-only constraints are covered by `@group mysql`.

## Conventions

- PK `id BIGINT UNSIGNED AUTO_INCREMENT`; FK columns `BIGINT UNSIGNED`, indexed.
- `money` = `DECIMAL(18,4)`; `rate` = `DECIMAL(24,12)`; coordinates `DECIMAL(10,7)`.
- Translatable fields are JSON `{"ar": ..., "en": ...}` (spatie/laravel-translatable).
- Status columns are `VARCHAR` + CHECK list + PHP backed enum (no MySQL ENUM).
- Conditional uniqueness uses stored generated "marker" columns + UNIQUE
  (MySQL has no partial indexes; many NULLs are allowed in a unique index).
- Expressions use `CASE ... END` (portable); concatenation is driver-specific.
- MySQL rules respected: no CHECK on a column used by a CASCADE/SET NULL FK;
  no CASCADE/SET NULL FK on a base column of a stored generated column;
  constraint names are schema-unique (`chk_<table>_<rule>`), ≤ 64 chars.
- Catalog prices and platform money values are stored in the **default
  currency**; historical financial records carry their own currency id.

## Table inventory (65)

| Group | Tables | Status |
|---|---|---|
| Framework (7) | cache, cache_locks, jobs, job_batches, failed_jobs, sessions, password_reset_tokens | existing |
| Spatie (5) | permissions, roles, model_has_permissions, model_has_roles, role_has_permissions | existing |
| Auth (6) | users (M), admins, personal_access_tokens, device_tokens, otps (M), password_reset_grants | existing |
| Demo (2) | tests (fixture), categories (M) | existing |
| P0 (7) | settings, currencies, currency_redenominations, cities, delivery_areas, delivery_fee_rules, notifications | **implemented** |
| P1 (1) | drivers | planned |
| P2 (14) | store_categories, store_store_category, stores, branches, branch_opening_hours, branch_hour_exceptions, product_category, products, product_option_groups, product_options, branch_products, branch_product_options, offers, favorites | planned |
| P3 (4) | user_addresses, carts, cart_items, cart_item_options | planned |
| P4 (6) | plus_plans, plus_memberships, plus_store_discounts, plus_requests, plus_cycles, plus_cycle_discounts | planned |
| P5 (12) | payment_methods, wallets, driver_cash_settlements, orders, order_items, order_item_options, order_status_histories, order_cancellation_requests, order_cancellations, order_payments, order_refunds, wallet_transactions | planned |
| P6 (1) | product_ratings | planned |

20 existing (3 modified) + 45 new = **65**. M = modified in its phase.

**Deliberately absent:** merchant payout/settlement, store or driver
ratings, store favorites, coupons, support tickets/chat, customer passwords,
product image galleries, a second WebSocket service, delivery-assignment /
location-history / free-delivery-usage / rate-history / invoice /
separate financial-transaction tables, wallet top-ups or manual credits.

## Phase dependencies

| Phase | Depends on | Gate |
|---|---|---|
| P0 Foundation | admins | done |
| P1 Identity (users M, otps M, drivers) | P0 | libphonenumber decision |
| P2 Catalog (+ categories M) | P0, P1 | — |
| P3 Addresses & carts | P1, P2 | — |
| P4 PLUS | P1, P2, P0 | — |
| P5 Orders & money | P0–P4 | — |
| P6 Driver ops & ratings | P5 | — |
| P7 Currency re-denomination job | P2, P4, P5 | — |
| P8 Real-time (Reverb, FCM) | P5, P6 | package install authorization |
| P9 Filament | P5–P8 | package install authorization |
| P10 Docs | every phase | — |

## Frozen decisions by area (summary)

- **Customers:** phone OTP only (E.164 `phone`, UNIQUE, NULL after
  anonymization); `first_name`/`last_name` required after first OTP;
  optional email, unique only when verified (generated `verified_email`);
  `status` active/suspended (admin only); 30-day recovery (`recovery_until`),
  recovery never bypasses suspension; anonymization blocked while
  obligations remain or **any wallet balance ≠ 0** (zero-balance settlement
  mechanism is an open business policy — funds are never cleared,
  converted or confiscated).
- **Catalog:** store-owned products shared by branches; per-branch prices,
  availability and option price deltas (composite FKs keep store/product
  integrity); multi-category products via `product_category`; one product
  image, one store logo; product favorites only.
- **Hours:** weekly slots + `branch_hour_exceptions` (closed/open windows);
  scheduled orders validated at their scheduled time.
- **Delivery:** platform-wide `delivery_areas` + `delivery_fee_rules`
  (area → city → global, most specific scope authoritative); no per-branch
  fee/radius; distance strategy replaceable (Haversine provisional);
  orders record `distance_method`.
- **Ratings:** products only, verified purchase, one per (user, product),
  admin moderation (`is_hidden`) excluded from the visible aggregate;
  placeholder rating is a separate setting.
- **PLUS:** memberships (logical subscription) + admin-confirmed cycles;
  immutable `snapshot_*` values plus operational `op_*` values converted on a
  default-currency switch with the conversion run recorded; discount and
  free delivery may combine; eligibility = store, minimum order, cap, usage
  limit; no coupons.
- **Money:** wallet funded only by order-payment reversals and refunds;
  per-currency balances, no conversion; payments track collected /
  reversed / refunded amounts; post-pickup cancellation needs
  `orders.cancel_after_pickup` and records driver handling, collected cash
  and caps; everything idempotent.
- **Real-time:** one Laravel Reverb server (private channels, two broadcast
  auth endpoints for apps and Filament), FCM for background push.
