# WAFFRLI — Database + Filament Admin Panel (implemented 2026-10-31)

This phase implements the full frozen ERD (plus `admin_audit_logs`), the
admin business services, and a Filament 5 admin panel. It adds **no**
customer, driver or merchant APIs; the 62 existing `api/*` routes are
unchanged.

## Panel access

| Item | Value |
|---|---|
| URL | `/admin` (login `/admin/login`) |
| Guard | `admin_web` (session, provider `admins`) — separate from the `admin` Sanctum API guard |
| Permissions | Spatie, always on guard `admin` (`Admin::$guard_name`) |
| Who may log in | active, non-deleted `admins` rows (`Admin::canAccessPanel`) |
| Super Admin | role `super-admin`; resolved in `AdminPermissions::allows` (no `Gate::before`), so abilities that are hard-false stay false for everyone |
| Locales | ar (RTL) / en, switched from the user menu (`/admin/locale/{ar,en}`) |

First-time setup on a development machine (never as a deploy side effect):

```bash
php artisan migrate
```

```bash
php artisan db:seed --class=AdminAccessSeeder
```

```bash
php artisan db:seed --class=PaymentMethodSeeder
```

```bash
php artisan waffrli:create-super-admin --email=you@example.com
```

The password is typed at a hidden prompt; nothing is hard-coded or printed.
`AdminAccessSeeder` creates the permissions and the `super-admin` role but
assigns nothing. Neither seeder is called from `DatabaseSeeder`.

Scheduled commands (`routes/console.php`): `waffrli:flag-urgent-orders`
(every minute; flags late scheduled orders, never cancels) and
`waffrli:sync-plus-cycles` (every five minutes).

## Permissions (guard `admin`)

| Group | Permissions |
|---|---|
| platform | settings.manage, currencies.manage, currencies.change_default (not usable: switching is disabled), cities.manage, delivery.manage |
| customers | customers.view, customers.suspend, customers.anonymize |
| drivers | drivers.view, drivers.manage, drivers.track |
| catalog | catalog.view, catalog.manage, ratings.moderate, offers.manage |
| orders | orders.view, orders.accept, orders.assign, orders.cancel, orders.cancel_after_pickup, orders.resolve_cancellation_requests, refunds.create |
| finance | payments.view, payment_methods.manage, settlements.manage |
| plus | plus.view, plus.manage, plus.approve |
| system | notifications.view, admins.manage, roles.manage, audit.view |

Abilities that are always false, Super Admin included: force delete,
replicate, switching the default currency, and editing or deleting wallet
transactions, payments, refunds, status history or audit logs.

## Deliberately disabled / not implemented

- **Default-currency switch:** the action is visible but disabled. The P7
  re-denomination job does not exist yet. Initializing the *first* default
  is allowed.
- **Wallet:** no create, edit or balance action. Money enters only through
  refunds and cancellation reversals (`WalletLedger`). What happens to
  remaining funds when an account is closed is an **unresolved policy**, so
  anonymization is blocked while any wallet balance is non-zero.
- **Online payment methods:** always stored inactive; only wallet and cash
  can be enabled.
- **Driver map:** reads stored driver positions and polls every 30 seconds.
  Reverb is not installed, so there are no live pushes.
- **Notifications:** view only. FCM is not installed and nothing is sent.
- **Merchant area:** none. No payouts, coupons, store or driver ratings,
  support chat, or image galleries.

## Audit

`App\Services\Audit\AdminAudit::log()` records every business action
(accept, assign, cancel, refund, settle, suspend, anonymize, PLUS decisions,
currency, settings and role changes) in the append-only `admin_audit_logs`
table, readable with `audit.view`.

## Tests

Tests are under `tests/Feature/Admin`. They run on SQLite and cover:

- panel smoke tests and the guard
- Livewire forms and actions
- permission-limited employees
- orders, refunds and wallet
- the PLUS lifecycle
- customer accounts
- catalog integrity
- the schema inventory
- console commands

MySQL-only CHECK and generated-column behaviour needs a MySQL 8 server and
was **not run** in this phase.
