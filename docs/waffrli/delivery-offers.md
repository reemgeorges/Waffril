# Delivery offers: driver accept/reject dispatch (B1 foundation, 2026-11-10)

**Status: not active.**

- **B1** added the schema, the model, the settings and the domain service with its invariants.
- **B2** (2026-11-10) added the driver API (`GET offers/current`, `GET offers/{id}`, `POST offers/{id}/accept`, `POST offers/{id}/reject`), the Reverb `offer.*` events on `private-driver.{id}` and the FCM offer push. See "B2 additions" below.
- No job and no admin screen creates offers yet.
- `config('dispatch.offers_enabled')` (env `WAFFRLI_DELIVERY_OFFERS_ENABLED`) is **off** by default. While it is off, no offer can be created, and the existing direct admin assignment (`OrderAdminService::assignDriver`) remains the only assignment path, behaving exactly as before.

## Workflow (target)

1. The admin offers an **accepted** order to one driver (round 1, attempt 1).
2. The order **stays `accepted`**, so the customer still sees "accepted" and no driver.
3. The driver accepts in time, and the order becomes `assigned` through the existing `OrderStateMachine`. Customer notifications and realtime are unchanged.
4. If the driver rejects, or the offer expires (default 90 s), the next eligible driver is offered automatically (B3), up to the per-round cap (default 10).
5. If no driver is eligible, the order waits in the admin queue (`dispatch_state = no_driver`). It is never dropped.

## Schema

### `delivery_offers` (new)

| Column | Notes |
|---|---|
| `order_id`, `driver_id` | FK, RESTRICT |
| `round`, `attempt` | ≥ 1. A round starts when an admin picks a driver; attempts count within the round. |
| `status` | `pending \| accepted \| rejected \| expired \| cancelled` (`App\Enums\DeliveryOfferStatus`) |
| `source` | `admin \| auto`. `admin` requires `offered_by_admin_id`. |
| `offered_at`, `expires_at` | Server time. `expires_at > offered_at`. |
| `responded_at` | Null exactly while pending. |
| `reject_reason`, `reject_note` | Only when rejected: `too_far \| busy \| vehicle_issue \| other`. |
| `cancel_reason` | Only when cancelled: `admin_override \| admin_restart \| order_cancelled \| order_unassignable \| driver_unavailable`. |
| `selection_meta` | JSON: how the system chose the driver (B3). |
| `pending_order_marker`, `pending_driver_marker` | Generated (STORED): `order_id` / `driver_id` while pending, otherwise NULL. |

**Unique indexes** (these enforce the invariants on MySQL and SQLite):

| Index | Invariant |
|---|---|
| `uq_do_one_pending_order` | At most one pending offer per order. |
| `uq_do_one_pending_driver` | At most one pending offer per driver. |
| `uq_do_order_round_driver` | A driver is offered an order at most once per round, so a driver who rejected or let an offer expire is never re-offered in that round. |
| `uq_do_order_round_attempt` | One offer per attempt number. |

**Other indexes:** `idx_do_status_expires` (expiry sweep) and `idx_do_driver_offered` (fair rotation).

**MySQL CHECK constraints:** `chk_do_status`, `chk_do_source`, `chk_do_admin_source`, `chk_do_round_attempt`, `chk_do_window`, `chk_do_responded`, `chk_do_reject_reason`, `chk_do_cancel_reason`. Nullable operands are tested with `IS [NOT] NULL`, because MySQL treats an UNKNOWN check as passing.

### `orders` (additive columns; existing rows get NULL/0)

| Column | Notes |
|---|---|
| `dispatch_state` | `NULL \| offering \| no_driver`, only while `status = accepted` (`chk_orders_dispatch_accepted_only`). |
| `dispatch_round`, `dispatch_attempts` | Current round and the attempts used in it. |
| `no_driver_since` | Set exactly while `dispatch_state = no_driver` (`chk_orders_no_driver_since`). |
| `no_driver_alerted_at` | When admins were last alerted, so an unchanged condition is not re-announced. |
| `assigned_via_offer_id` | FK to `delivery_offers` (RESTRICT): the accepted offer behind an assignment. NULL for direct admin assignments. |

Plus `idx_orders_dispatch_state`.

## Configuration

| Key | Where | Default | Notes |
|---|---|---|---|
| `dispatch.offers_enabled` | `config/dispatch.php`, env `WAFFRLI_DELIVERY_OFFERS_ENABLED` | `false` | Master switch. Deliberately not a platform setting, so it can't be enabled from the panel before the workflow is complete. |
| `dispatch.offer_timeout_seconds` | Platform settings, group `dispatch` | 90 | Clamped to 30–300 (`dispatch.timeout_bounds_seconds`). |
| `dispatch.max_attempts_per_round` | Platform settings | 10 | Clamped to 1–50. |
| `dispatch.auto_recovery` | Platform settings | `true` | Retry queued orders when a driver becomes available (B3). |

The settings already appear in the admin settings page under "Delivery offers (not active yet)". They have no effect while the switch is off.

## Domain service: `App\Services\Delivery\DeliveryOfferService`

| Method | Behavior |
|---|---|
| `offerByAdmin(order, driver, admin)` | Requires `orders.assign`. Starts a new round. |
| `offerAutomatically(order, driver, meta)` | The next attempt of the current round (used by B3). |
| `accept(offer, driver)` | Idempotent for the same driver. Expired or late accepts → 409 (the expiry is recorded). Another driver's offer → 404. Order no longer `accepted` → 409. Driver no longer eligible → 409. |
| `reject(offer, driver, reason, note)` | Idempotent. Validates the reason. |
| `expire(offer)` | Idempotent. True only when it expired something. |
| `cancelPendingForOrder(order, reason)` | Withdraws the order's pending offer. |
| `cancelPendingForDriver(driver, reason)` | Withdraws the driver's pending offer. |
| `markNoDriver(order)` | True only when the state changed, so admins are alerted once per condition. |
| `OrderStateMachine` hook | When an order leaves `accepted` (direct admin assignment, cancellation), its pending offer is withdrawn (`admin_override` / `order_cancelled`) and the dispatch columns are cleared in the same transaction. This is a no-op for orders without a dispatch. |

**Concurrency rules:**

- Lock the order row first, then the offer, then the driver, in every method.
- Use server time only.
- Rely on the unique markers as the last line of defense: a lost race becomes 409.

## Tests

- **`tests/Feature/Delivery/DeliveryOfferServiceTest.php`**: 21 domain tests. They run on SQLite by default and also on MySQL.
- **`tests/Feature/Delivery/DeliveryOfferMySqlSchemaTest.php`**: CHECK constraints, markers and RESTRICT foreign keys, in the `mysql` group.
- **`tests/Feature/Admin/WaffrliMySqlSchemaTest.php`**: registers every new constraint and generated column.

To run the MySQL group:

```bash
DB_CONNECTION=mysql DB_DATABASE=waffrli_test php artisan test --group mysql
```

## B2 additions (2026-11-10)

| Piece | File |
|---|---|
| Controller (own offers only; others 404) | `App\Http\Controllers\Driver\DriverOfferController` |
| Payload (privacy-limited, server timing) | `App\Http\Resources\Model\Driver\DriverOfferResource` |
| Reject validation | `App\Http\Requests\Model\Driver\DriverOfferRejectRequest` (400 on invalid reason) |
| Reverb events (published after commit by the service) | `App\Events\Realtime\DeliveryOfferChanged` (`offer.created \| offer.accepted \| offer.withdrawn`) |
| FCM push (one per device, deduplicated, never after the deadline) | `App\Notifications\DriverDeliveryOfferCreated` |
| Push deadline | `SendPushMessage` (`skipped_expired`) and `FcmHttpV1Transport` (TTL); optional, other messages unchanged |
| Direct-assignment link | `OrderAdminService::assignDriver` / `unassignDriver` clear `assigned_via_offer_id` (a direct (re)assignment is not an accepted offer) |

**Tests:**

- `DriverOfferApiTest` (13 tests): authorization, privacy, accept and reject, duplicates, expiry, events, push and compatibility.
- `DeliveryOfferConcurrencyTest` (3 tests, `mysql` group): real separate processes against `waffrli_test`, with checks that the processes overlapped.

**Documentation:** Bruno folder `WAFFRLI-Driver/12 Delivery Offers` (Logout moved to `13`), OpenAPI, the mobile contract and the portal export were regenerated.

## Next milestones

| Milestone | Scope |
|---|---|
| B2 | **Done (2026-11-10):** driver API, resources, Reverb and FCM events. |
| B3 | Automatic redispatch, expiry job and sweep, the no-driver queue and its alerts, auto-recovery. |
| B4 | Filament: send offer, dispatch panel and history, queue, override and restart, then enabling the switch. |
| B5 | Docs, OpenAPI and Bruno. |
