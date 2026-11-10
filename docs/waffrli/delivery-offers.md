# Delivery offers: driver accept/reject dispatch (B1 foundation, 2026-11-10)

**Status: not active.**

- **B1** added the schema, the model, the settings and the domain service with its invariants.
- **B2** (2026-11-10) added the driver API (`GET offers/current`, `GET offers/{id}`, `POST offers/{id}/accept`, `POST offers/{id}/reject`), the Reverb `offer.*` events on `private-driver.{id}` and the FCM offer push. See "B2 additions" below.
- **B3** (2026-11-10) added automatic reassignment: fair rotation, the per-offer expiry job, the scheduled sweep, the no-driver queue with a single admin alert, and auto-recovery. See "B3 additions" below.
- **B4** (2026-11-10) added the Filament dispatch management: send, withdraw, restart, emergency assignment, the live panel, offer history, the "Waiting for driver" queue and the validated settings. See "B4 additions" below.
- The switch is still **off**. See the "Activation checklist" below; activate only after explicit approval.
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

## B3 additions (2026-11-10): automatic reassignment

### Flow

1. An admin offers the order to the first driver (`offerByAdmin`, round N, attempt 1). B4 adds the screen.
2. The offer queues `ExpireDeliveryOffer` with a delay of the timeout (90 s).
3. The driver's answer, or the deadline, decides what happens next:
   - **Accept:** the order is `assigned` and the dispatch ends. Late jobs do nothing.
   - **Reject, expiry, or the driver going offline or being deactivated:** the offer is closed (history kept), and `AdvanceDeliveryDispatch` offers the order to the next driver of the same round (`DeliveryDispatcher::advance`).
4. When the round reaches `dispatch.max_attempts_per_round` (10) or nobody eligible is left, the order goes to the admin queue:
   - `dispatch_state = no_driver`, `no_driver_since` set;
   - the status stays `accepted`, so the order is never dropped;
   - admins holding `orders.assign` get the Filament alert `order.no_driver_available` **once**. `no_driver_alerted_at` is set and is cleared only when an admin starts a round.

### Driver selection: fair rotation

`DriverRecommendationService::dispatchCandidates` starts from the existing eligibility: an active, `available` driver in the order's city with no assigned or picked-up delivery. It excludes drivers answering another offer and drivers already offered this order in the current round. Candidates are ranked by:

1. the longest wait since the driver's last offer (never offered first);
2. the fewest deliveries completed today (operations time zone);
3. the shortest distance to the branch, **only** for positions reported within `dispatch.location_fresh_minutes` (10). Drivers send GPS only while delivering, so idle positions are usually stale and are ignored;
4. the driver id, for a stable order.

The choice and its reasons are stored in `selection_meta`: rank, last offer, deliveries today, distance, `location_used`, and `recovery` when the offer came from recovery. The pool is one query; there are no per-driver queries.

### Recovery rules (`dispatch.auto_recovery`, on)

Orders in the queue are retried oldest first, at most one new offer each per run. A driver can hold only one pending offer, so the oldest waiting order claims a newly free driver first.

| Rule | When it applies |
|---|---|
| 1. Same round | While the round has attempts left: offer to an eligible driver **not yet offered in this round**, e.g. a driver who just came online. Drivers who rejected or let an offer expire are never retried within the round. |
| 2. New automatic round | Only when nobody is left in the round, **and** the order has waited at least `dispatch.recovery_round_cooldown_minutes` (5), **and** fewer than `dispatch.max_auto_rounds` (2) rounds were started automatically since the last admin-started round. A new round may offer previously declining drivers again. |
| 3. Otherwise | The order waits for an admin: restart or direct assignment (B4). An admin-started round resets the automatic-round count and re-arms the alert. |

Recovery runs:

- immediately when a driver sets themselves `available` (`RecoverDeliveryDispatch`);
- in every sweep.

### Expiry and the safety net

- **`ExpireDeliveryOffer`** is delayed to `expires_at`.
  - Run early, it releases itself until the deadline.
  - At the deadline it expires the offer and advances the dispatch.
  - Duplicates and late runs are no-ops.
  - The server clock decides; acceptance after `expires_at` is refused regardless of jobs.
- **`waffrli:dispatch-sweep`** runs every 15 s (`routes/console.php`, `withoutOverlapping`). It:
  1. expires overdue offers whose job was lost and advances them;
  2. advances orders still `offering` without a pending offer (a lost `AdvanceDeliveryDispatch`);
  3. runs recovery.

  It is bounded by `dispatch.sweep_batch` (50) and does nothing while offers are disabled.
- **Concurrency:** every step locks the order row first (same order as B1: order, offer, driver). The unique markers guarantee at most one pending offer per order, so jobs, the sweep and API calls can overlap safely. Verified on MySQL with separate processes:
  - 6 simultaneous dispatch steps create exactly one next offer;
  - the expiry job racing acceptance at the deadline never assigns twice.

### Settings

| Setting | Default | Notes |
|---|---|---|
| `dispatch.recovery_round_cooldown_minutes` | 5 | Minutes in the queue before an automatic new round. |
| `dispatch.max_auto_rounds` | 2 | Clamped to 0–10. |

Both are in addition to the B1 settings. `config/dispatch.php` also gains `location_fresh_minutes` (env `WAFFRLI_DISPATCH_LOCATION_FRESH_MINUTES`) and `sweep_batch`.

### Local development

Run these from `C:\laragon\www\Waffril`:

```bash
php artisan serve --host=0.0.0.0 --port=8000   # API
php artisan reverb:start                       # realtime (Pusher protocol) on :8080
php artisan queue:work --tries=3 --sleep=1     # jobs: offer expiry, next driver, pushes, broadcasts
php artisan schedule:work                      # scheduler: runs the 15 s sweep and the existing tasks
```

- **The queue worker** is required for the delayed `ExpireDeliveryOffer` jobs, `AdvanceDeliveryDispatch`, pushes and broadcasts (the queue is `database`).
- **`schedule:work` is necessary locally** once offers are enabled: it is the only thing that runs `waffrli:dispatch-sweep`, and it already runs `waffrli:flag-urgent-orders` every minute. It is not needed while offers are disabled; the sweep then only reports "disabled".
- **Interrupted workers:** delayed jobs stay in the `jobs` table and run when a worker starts again. Jobs that were lost or failed are covered by the sweep within 15 s: overdue offers are expired and the dispatch moves on. Even with no worker **and** no scheduler, an expired offer can never be accepted (the server compares `expires_at`), and `GET /driver/offers/current` expires it on read.
- **Production:** a cron running `php artisan schedule:run` every minute (sub-minute tasks keep it running for the minute) plus a supervised `queue:work`. Not configured by this work.

## B4 additions (2026-11-10): Filament dispatch management

### Order page actions

While `dispatch.offers_enabled` is on:

| Action | Permission | Behavior |
|---|---|---|
| Send delivery offer | `orders.assign` | First offer of an accepted order. The admin picks from the dispatch candidates of a new round (active, available, same city, free; fairest first, with GPS freshness, deliveries today and last offer). The order **stays `accepted`**, and `driver_id` is set only when the driver accepts. |
| Restart dispatch | `orders.assign` | A new admin round with a chosen driver. It withdraws any pending offer (`admin_restart`) in the same transaction, keeps the history, re-arms the no-driver alert and resets the automatic-round count. |
| Withdraw offer | `orders.assign` | **Pauses** the dispatch: the pending offer is cancelled (`admin_withdrawn`), `dispatch_state` is cleared, and **no** driver is offered automatically until an admin sends or restarts. Requires confirmation. |
| Emergency assignment | **`orders.force_assign`** (new) | Assigns without the driver's acceptance, for exceptions only. Requires a warning confirmation and a written reason (at least 5 characters). The usual status and eligibility rules apply. A pending offer is withdrawn atomically (`admin_override`), and the action is audited as `order.driver_force_assigned` with the reason and the withdrawn offer. |

**While offers are on:**

- The old "Assign driver" button is hidden, and `OrderAdminService::assignDriver` refuses (409), so nothing bypasses driver acceptance.
- "Unassign" still works and returns the order to `accepted`, ready for a new offer.

**While offers are off,** the page and `assignDriver` behave exactly as before, and none of the new actions appear.

### Dispatch panel and history

- **"Delivery dispatch" panel:** a section on the order page, shown when offers are on or the order has offers. It shows:
  - the state: waiting for the driver, choosing the next driver, no driver available, paused, not started, accepted by the driver, or closed;
  - a "needs attention" badge;
  - the round and attempts (n of 10);
  - the current offer: driver, source, sent time, expiry and a live countdown (informational; the server decides);
  - the last answer with its reason;
  - what happens next: automatic reassignment, recovery state, automatic rounds used, the next possible automatic round, whether admins were alerted.
- **Refreshing:** the panel polls every 5 s while an offer is out, and every 15 s while the order waits for a driver. It also refreshes on `admin.orders` realtime events.
- **"Delivery offers" table:** a read-only history of every offer, oldest first. It shows round/attempt, driver, source, sender, status, sent and answered times, the reason (rejection with note, withdrawal or expiry) and "why this driver" (rank, deliveries today, fresh-GPS distance, recovery).

### "Waiting for driver" queue

- **Location:** a tab on the orders list, shown while offers are on or such orders exist.
- **Contents:** accepted orders with `dispatch_state` of `offering` or `no_driver`, no-driver orders first, then the oldest.
- **Columns:** restaurant, order age, dispatch state, round and attempts, latest offer status, and "needs attention".
- **Highlighting:** no-driver rows are highlighted. The tab badge counts the waiting orders and turns red when one has no driver. Each row opens the order page (its dispatch panel).

### Admin monitoring

- **Live refresh:** every offer change (sent, rejected, expired, accepted, withdrawn, automatic reassignment) and every entry into the no-driver queue publishes `admin.operational` with type `order.dispatch_updated` on `admin.orders`. Open order pages and lists refresh live, with no bell alert, so there is no spam.
- **Bell alert:** only for "order waiting for a driver" (B3), sent to admins with `orders.assign`, once until an admin acts.
- **Audit log:** `order.offer_sent`, `order.offer_withdrawn`, `order.dispatch_restarted` and `order.driver_force_assigned`.

### Settings (Platform settings, "Delivery offers" section)

The section says whether offers are live. The values are validated on save (form and `PlatformSettingsService`):

| Setting | Default | Range |
|---|---|---|
| Offer acceptance time | 90 s | 30–300 |
| Offers per round | 10 | 1–50 |
| Automatic retry | on | |
| Minutes before an automatic new round | 5 | 1–1440 |
| Automatic new rounds | 2 | 0–10 |
| Rotation strategy | `fair_rotation` | the only implemented strategy |
| GPS freshness | 10 min | 1–120 |

The switch itself (`WAFFRLI_DELIVERY_OFFERS_ENABLED`) is an environment variable and is not editable in the panel.

### Schema

The migration `2026_11_10_120000_add_admin_withdrawn_offer_cancel_reason` adds `admin_withdrawn` to `chk_do_cancel_reason` (MySQL). Its `down()` maps such rows to `admin_override`.

### Tests

- `DispatchFilamentTest` (12 tests): Filament actions, permissions, eligibility, panel and polling, history, queue, withdraw and restart, emergency assignment, concurrent admins, settings validation, the disabled switch and customer compatibility.
- `DeliveryOfferConcurrencyTest` (MySQL): now also races emergency assignment against driver acceptance.
- In 4 earlier B1/B2 tests that exercise an admin override while offers are on, direct `assignDriver` became `forceAssignDriver`. Their assertions are unchanged.

## Activation checklist (not executed; needs explicit approval)

1. **Code and database:**
   - All migrations up to `2026_11_10_120000_add_admin_withdrawn_offer_cancel_reason` applied: `php artisan migrate --force`. Locally this is already done.
   - Permissions seeded, so `orders.force_assign` exists: `php artisan db:seed --class="Database\Seeders\AdminAccessSeeder"`. Read the seeder before running it in production.
2. **Permissions:**
   - Dispatchers need `orders.view` and `orders.assign`.
   - Grant `orders.force_assign` only to supervisors.
   - Alerts go to admins with `orders.assign`.
3. **Queue worker:** `php artisan queue:work --tries=3 --sleep=1`, supervised in production. It runs the expiry, next-driver, recovery, push and broadcast jobs.
4. **Scheduler:** `php artisan schedule:work` locally, or a cron running `php artisan schedule:run` every minute in production. It runs `waffrli:dispatch-sweep` every 15 s. Check with `php artisan schedule:list`.
5. **Reverb:** `php artisan reverb:start`, with `REVERB_ALLOW_ORIGINLESS_CLIENTS=true` for the mobile apps. The admin panel listens on `admin.orders`, drivers on `private-driver.{id}`.
6. **Firebase:**
   - `WAFFRLI_PUSH_ENABLED=true`, `WAFFRLI_PUSH_DRIVER=fcm` and the service-account credentials, outside the repository.
   - **Register the driver Android app (`com.waffrli.driver`) in the Firebase project** and give the driver app its own `google-services.json`.
7. **Driver app readiness:** the WAFFRLI Driver app must implement, at minimum:
   - sign-in, availability and device-token registration;
   - the offer screen (`GET /driver/offers/current`, accept, reject);
   - Reverb `offer.*` events and FCM `driver.offer_created` handling;
   - the active-delivery flow (pickup and deliver).

   Until then, drivers cannot answer offers, and every order would end in the no-driver queue.
8. **Settings review:** timeout, attempts, recovery and GPS freshness in Platform settings.
9. **End-to-end test (local, with test drivers):**
   - send an offer, then accept;
   - reject leading to automatic reassignment;
   - timeout leading to reassignment;
   - every driver declining, ending in the no-driver queue with one alert, then recovery when a driver comes online;
   - withdraw and restart;
   - emergency assignment;
   - order cancellation during an offer;
   - the customer app showing "accepted" until a driver accepts, then "driver assigned".
10. **Switch on:** set `WAFFRLI_DELIVERY_OFFERS_ENABLED=true`, run `php artisan config:clear`, then restart the API, the queue worker and the scheduler.
11. **Rollback:** set the variable back to `false` and run `config:clear`.
    - Pending offers stop being created.
    - Existing pending offers can still be answered or expired by the sweep while it runs. Withdraw them first for a clean switch-off.
    - The direct assignment returns.

## Next milestones

| Milestone | Scope |
|---|---|
| B2 | **Done (2026-11-10):** driver API, resources, Reverb and FCM events. |
| B3 | **Done (2026-11-10):** automatic redispatch, expiry job and sweep, the no-driver queue and its alerts, auto-recovery. |
| B4 | **Done (2026-11-10):** Filament send offer, dispatch panel and history, queue, withdraw, restart and emergency assignment, settings. The switch stays off until approved. |
| B5 | Docs, OpenAPI and Bruno. |
