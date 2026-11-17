# Admin lifecycle alerts and per-admin timezone (2026-11-17)

## 1. Order lifecycle alerts (bell + toast)

All alerts go through `App\Services\Notifications\AdminAlerts::send()`. It
stores a Filament database notification (title and body in the admin's own
locale, ar/en), sends the realtime toast and refreshes the bell over the
existing Reverb channel. Only **active admins with the permission** receive it.
It is sent **after the database commit** and is **idempotent** (dedupe key
held in the cache for 3 days).

| Event | Alert key | Permission | Source | Dedupe key |
|---|---|---|---|---|
| Order placed | `order.placed` | `orders.view` | existing | — |
| Driver accepts an offer → order assigned | `order.driver_accepted` | `orders.assign` | `OrderStateMachine` (actor = driver) | status-history row |
| Driver rejects an offer | `order.offer_rejected` | `orders.assign` | `DeliveryOfferService::finalize()` | offer id + status |
| Offer expires | `order.offer_expired` | `orders.assign` | `DeliveryOfferService::finalize()` | offer id + status |
| No driver available | `order.no_driver_available` | `orders.assign` | existing | — |
| Picked up | `order.picked_up` | `orders.view` | `OrderStateMachine` (actor = driver) | status-history row |
| Delivered | `order.delivered` | `orders.view` | `OrderStateMachine` (actor = driver) | status-history row |
| Customer cancels | `order.cancelled_by_customer` | `orders.view` | `OrderStateMachine` (actor = customer) | status-history row |
| Cancellation request | `order.cancellation_requested` | `orders.resolve_cancellation_requests` | existing | — |
| Problem report | `order.problem_reported` | `orders.view` | existing | — |

The bell re-translates each stored alert into the viewer's language using all
of its stored parameters (order reference, driver name), each bidi-isolated.

**No alert** is sent for:
- changes an admin makes (direct assignment, reassignment, unassignment, admin
  cancellation, refunds): the acting admin already sees them, and the other
  screens refresh in realtime as before;
- GPS pings or refresh-only events.

Transitions and dispatch are unchanged: the alert is a side effect called
after the history row is written.

## 2. Times in each admin's browser timezone

- **Storage stays UTC.** Nothing in the database, the API or the mobile
  responses changed.
- A small head script (`resources/views/filament/time/browser-timezone.blade.php`)
  stores `Intl.DateTimeFormat().resolvedOptions().timeZone` in the cookie
  `waffrli_tz`. This cookie is not encrypted and holds only the zone name. If
  the page was rendered in a different zone, the script reloads it **once**
  (per zone, per tab).
- `SetAdminDisplayTimezone` (panel + Livewire persistent middleware) validates
  the zone and calls `FilamentTimezone::set()`. Only Region/City names and UTC
  are accepted; raw offsets and abbreviations are refused. Legacy names that
  browsers still report (`Asia/Calcutta`, `Europe/Kiev`, `US/Pacific`, …) are
  mapped to the canonical zone, because PHP builds that use the system tzdata
  may not know them. Every
  `dateTime()`/`date()` column and entry, and every `DateTimePicker`, then
  converts automatically, DST included. The value is held per request in the
  container, so admins in different zones never affect each other.
- Hand-written formats use `App\Support\Time\AdminTimezone::format()`.
- **Date filters** (`DateRangeFilter`) treat the picked days as the admin's
  local days and query `[local start of "from", local start of the day after
  "until")` in UTC.
- **Not converted, on purpose:** wall-clock values (branch opening hours) and
  calendar dates (`business_date` of cash settlements). Dashboard aggregates
  keep using the operations business day.
- **Fallback** (first request before the cookie exists, cookies blocked, or an
  invalid value): `WAFFRLI_ADMIN_DISPLAY_TIMEZONE`, then the operations
  timezone setting, then UTC.
- Switching ar/en changes only the language; the instant shown is the same.

### Tests

- `tests/Feature/Admin/AdminTimezoneTest.php`
- `tests/Feature/Delivery/OrderLifecycleAlertsTest.php`

### End-to-end check (2026-11-17)

Run on a disposable copy of the QA database, with a real Reverb server, two
Chromium admin sessions and driver actions sent to the driver HTTP API:

- **Dispatcher** (super-admin, English, Asia/Damascus) received one toast
  (about 0.5–1.2 s) and one bell entry each for: declined, accepted, picked
  up, delivered and expired.
- **Viewer** (`orders.view` only, Arabic, America/Los_Angeles) received only
  picked up and delivered.
- **Inactive admin:** nothing.
- **Nothing alerted:** GPS pings and offers created by admins.
- **Repeated driver requests** (reject, accept, pick up and deliver each sent
  twice; the expiry sweep run twice) created no second alert.
- **Not covered by this run:** a driver going offline while an offer is
  pending. That cancels the offer without an alert, and the next driver is
  offered automatically. If no driver remains, the existing "waiting for a
  driver" alert fires.

## Manual acceptance

1. Log in to the admin panel in two browsers, or as two admins, with the system
   timezone set to Asia/Damascus and to America/Los_Angeles. Open the same
   order: the times differ by the zone offset. Then switch to عربي: the times
   do not change.
2. On Orders, filter "Created from/until" on one day. Orders from late evening
   local time belong to that day, not the next.
3. As a driver (mobile app or API), accept an offer, pick up and deliver. Each
   step shows a toast and a bell entry for admins with the permission. Then
   reject an offer and let one expire: you get one alert each.
