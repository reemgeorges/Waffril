# Report 2 — Notification and realtime audit (2026-11-13)

Branch `feature/api-notifications-integrity-audit`. Evidence files are in [evidence/](evidence/).

**Isolation.**
- **Stack:** every end-to-end test ran on a disposable stack: a separate worktree, a fresh SQLite database seeded with demo data, throwaway Reverb credentials and admin/driver passwords, the fake SMS transport and the fake FCM transport.
- **Nothing real was touched:** your databases, `.env` and Firebase were not used, and no real SMS, push or WhatsApp message was sent.

## 1. Overall readiness

| Channel | Customers | Drivers | Admins | Status (exactly what was tested) |
|---|---|---|---|---|
| **Database (in-app list)** | yes | yes | Filament bell | **Implemented and verified end-to-end.** Stored, listed by the API, unread count, mark one/all read, isolation, en/ar; admin bell persisted and shown in a real browser |
| **Reverb (live WebSocket)** | yes | yes | yes | **Implemented and verified end-to-end against a real Reverb server** (one server, Pusher protocol, real queue worker, real Chromium). Not verified from a real phone |
| **FCM push** | yes | yes | — | **Implemented and verified with fake/mock providers. FCM implementation verified with mocks; real-device delivery NOT verified.** |
| **Polling fallback** | app's job | app's job | yes (30 s bell, list on load) | Panel verified with Reverb stopped. Mobile polling is a Flutter responsibility |
| **Configuration (this repository's `.env`)** | | | | Reverb configured (Docker host `reverb`); push enabled with `WAFFRLI_PUSH_DRIVER=fake`; all four `FIREBASE_*` variables empty. **FCM: implemented but not configured.** Your Laragon `.env` was not inspected and may differ |

### Defects found and fixed

Each defect below was reproduced by a failing test before it was fixed, and each now has a regression test.

| # | Defect | Effect before the fix | Fix |
|---|---|---|---|
| N-1 | Laravel stops at the first channel that throws. With a sync queue (a Laragon setup without a worker) and Reverb unreachable, the `broadcast` channel threw. | The push to a phone with the app closed was never queued, and later recipients lost even their in-app notification. | `Notify::send` delivers each recipient and each channel separately; one notification id per recipient (`33e3754`) |
| N-2 | Driver "assigned / unassigned / cancelled" notifications used `$driver->notify()` **inside the business transaction**. | With the same outage, an admin's **driver assignment or cancellation was rolled back** (`BroadcastException`). | They go through `Notify::send` (after commit, isolated) (`4df35ff`) |
| N-3 | Admin alerts: one admin's bell-refresh broadcast failing stopped the loop. | Later admins lost the alert. | Delivered per admin (`4df35ff`) |
| N-4 | Re-registering a refreshed FCM token from the **same device** added a second active token. | One phone was pushed twice. Stale rows used up a driver's 3-device limit, pushing the driver's **other phone** out of delivery offers. | Same `device_id` → the old token is retired (`4df35ff`) |
| N-5 | **Driver** logout left the device's push tokens active (customer logout already deactivated them). | A phone that was signed out or handed over kept receiving delivery offers. | Logout deactivates the device's tokens; optional `device_id`, otherwise all of the driver's tokens (`4df35ff`) |
| N-6 | (API) the realtime `order.status_changed.status_labels` carried the changed admin wording. | — | Fixed with the API vocabulary (Report 1, A-1) |

Regression tests:
- `NotificationDeliveryResilienceTest` (4);
- `DeviceTokenLifecycleTest` (3);
- `ApiLabelVocabularyTest` (4).

N-1 to N-3 were each proven: the tests fail on the old code and pass on the new.

## 2. Architecture (as implemented)

```
business service (order state machine, checkout, PLUS, refunds, dispatch, admin actions)
   │  inside a DB transaction
   ├─ Realtime::publish(event)  ──after commit──▶ queued ShouldBroadcast job ─▶ Reverb ─▶ subscribed clients
   └─ Notify::send(user, notification) ──after commit──▶ per recipient, per channel:
         database   → notifications table (in-app list; API + Filament bell)
         broadcast  → queued BroadcastNotificationCreated ─▶ Reverb private-user.{id} / private-driver.{id}
         push       → PushChannel ─▶ one queued SendPushMessage per active device token
                         └─ job: inactive token? expired offer? channel occupied (SKIP_WHEN_ONLINE)? duplicate?
                              ─▶ FCM HTTP v1 (or fake) ─▶ phone; UNREGISTERED → token deactivated; 5xx/429 → retry ×3
AdminAlerts::send(type)  ──after commit──▶ Filament database notification per admin holding the permission
                                           + DatabaseNotificationsSent broadcast on private-admin.{id} (bell refresh)
AdminOperationalEvent    ─▶ private-admin.orders (orders list, cancellations, dashboard re-render)
```

| Piece | Responsibility |
|---|---|
| **Database notifications** | The source of truth for the in-app list (`notifications` table). Always written when `Notify::send` runs, except during demo seeding (muted). |
| **Laravel Reverb** | The single WebSocket server for customers, drivers and admins. It only delivers to clients that are connected now, and does not replay missed events. |
| **FCM** | Reaches phones whose app is in the background or closed. Disabled unless `WAFFRLI_PUSH_ENABLED=true`. Real delivery needs `WAFFRLI_PUSH_DRIVER=fcm` plus credentials. |
| **Queue workers** | Run broadcast jobs, push jobs, offer expiries (`ExpireDeliveryOffer`) and dispatch advancement. Without a worker, use `QUEUE_CONNECTION=sync` (everything then runs inline; after N-1 to N-3 that is safe, but slower). |
| **Notification classes** | `CustomerOrderUpdate`, `CustomerPlusUpdate`, `MarketingMessage`, `DriverOrderUpdate`, `DriverDeliveryOfferCreated` (push only), `DriverDeliveryProblemReported` (admin database only). |
| **Device tokens** | `POST {customer\|driver}/device-tokens`. Customers: one active device. Drivers: up to 3 devices, one token per device. Logout deactivates them. |
| **Broadcast channels** | `private-user.{id}`, `private-driver.{id}`, `private-order.{id}`, `private-admin.orders`, `private-admin.drivers-map`, `private-admin.{id}`. All private, client events disabled. |
| **Polling fallback** | Filament: the bell polls every 30 s, and lists reload on navigation. Apps: re-fetch the API on resume and on reconnect (Flutter). |

**Optional and environment-dependent parts.**
- **Broadcasting:** needs `BROADCAST_CONNECTION=reverb` and the `REVERB_*` variables. With `log`/`null`, nothing is live and the auth endpoints return 503.
- **Push:** off unless `WAFFRLI_PUSH_ENABLED=true`. Real delivery needs `WAFFRLI_PUSH_DRIVER=fcm` and the `FIREBASE_*` credentials.
- **Delivery offers:** off unless `WAFFRLI_DELIVERY_OFFERS_ENABLED=true`.
- **Filament Echo:** connects only with `BROADCAST_CONNECTION=reverb` and a key.

### Customer app

| App state | What happens |
|---|---|
| Open, socket connected | Live: `order.status_changed` on `private-order.{id}`; notifications as `Illuminate\Notifications\Events\BroadcastNotificationCreated` on `private-user.{id}`. Push is **skipped** while `private-user.{id}` is occupied (`SKIP_WHEN_ONLINE=true`). |
| Background | If the OS keeps the socket open, the channel still counts as occupied, so **the push is skipped and nothing is shown** unless the app shows a local notification for live events. See §6. |
| Closed | Socket closed → channel unoccupied → push via FCM (once configured). The database notification is always there. |
| No internet | Nothing arrives. Reverb keeps the dead connection "occupied" for **about 3 minutes (measured: 176 s)**, so pushes in that window are skipped. Afterwards pushes are sent and FCM queues them until the phone is back. |
| Reconnecting | Pusher clients reconnect and re-authorize (verified, WS.3). **Missed live events are not replayed:** the app must re-fetch the order and `GET notifications`. |

### Driver app

| App state | What happens |
|---|---|
| Open | `offer.created` / `offer.accepted` / `offer.withdrawn` on `private-driver.{id}` (only that driver; verified). Order updates as notifications. Offer push skipped while the channel is occupied (verified: `push.skipped_online`). |
| Background | Same caveat as customers. An offer has a 90 s deadline, so a skipped push can mean a missed offer. |
| Closed | Offer pushed with high priority, `android.ttl` and `apns-expiration` set to the deadline (verified with the fake transport: `push.fake_delivered`). After the deadline the job drops it (`skipped_expired`, PHPUnit). On opening, `GET /driver/offers/current` is authoritative (verified). |
| Withdrawn or expired offers | `offer.withdrawn` with the final status (verified 0.6–0.8 s after the deadline). There is **no push** for withdrawal: the app learns it on reconnect via `offers/current`. |

### Filament administration

- **Bell.** Database notifications for:
  - new order (`orders.view`);
  - cancellation request (`orders.resolve_cancellation_requests`);
  - driver problem report (`orders.view`);
  - order waiting for a driver (`orders.assign`);
  - PLUS request (`plus.approve`).
- **Live delivery.** Over `private-admin.{id}`, the bell went 23 → 24 in 1.9 s without reload; the orders list re-rendered over `private-admin.orders`.
- **Without Reverb.** The bell polls every 30 s, and lists show new records on navigation (verified with Reverb stopped).

## 3. Actual configuration (no secrets shown)

| Variable | Repository `.env` (Docker profile) | Effect |
|---|---|---|
| `BROADCAST_CONNECTION` | `reverb` | live events on |
| `REVERB_APP_ID/KEY/SECRET` | set (hidden) | — |
| `REVERB_HOST` / `PORT` / `SCHEME` | `reverb` / `8080` / `http` | the Docker service name; Laragon needs `127.0.0.1` |
| `REVERB_ALLOWED_ORIGINS` / `ALLOW_ORIGINLESS_CLIENTS` | `localhost,127.0.0.1` / `true` | native apps allowed |
| `QUEUE_CONNECTION` | `redis` | needs Redis and `queue:work` |
| `WAFFRLI_PUSH_ENABLED` / `DRIVER` / `QUEUE` / `SKIP_WHEN_ONLINE` | `true` / **`fake`** / `default` / `true` | push jobs run, but nothing reaches Firebase |
| `FIREBASE_CREDENTIALS` / `PROJECT_ID` / `CLIENT_EMAIL` / `PRIVATE_KEY` | **all empty** | real FCM impossible |
| `WAFFRLI_DELIVERY_OFFERS_ENABLED` | not set (off) | direct assignment |
| `WAFFRLI_SMS_DRIVER` | `fake` | — |

## 4. Event matrix

Columns:
- **DB** = stored in-app notification;
- **Reverb** = live event;
- **FCM** = push job (when enabled);
- **Queue** = needs a worker (or sync);
- **Verified**: E2E = real Reverb + worker + browser (fake FCM); PU = PHPUnit.

### Customer

| Event | Trigger | DB | Reverb | FCM | Queue | Verified |
|---|---|---|---|---|---|---|
| Order submitted | checkout | — (not notified by design: the app has the checkout response) | `order.status_changed` is not sent; admins get `order.placed` | — | — | E2E (admin side) |
| Order accepted | admin / state machine | yes `order.accepted` | `order.status_changed` (order, user, admin) + notification | yes | yes | E2E steps 10, FCM.2 |
| Driver assigned | admin assign / offer accepted | yes `order.assigned` | yes | yes | yes | E2E 11 |
| Picked up / on the way | driver | yes `order.picked_up` | yes | yes | yes | E2E 16 |
| Delivered | driver | yes `order.delivered` | yes | yes | yes | E2E 17, 19 |
| Cancelled (any actor) | customer, admin, system | yes `order.cancelled` | yes | yes | yes | E2E E1 |
| Cancellation request approved | admin | yes (as `order.cancelled`) | yes | yes | yes | PU |
| Cancellation request rejected | admin | yes `order.cancellation_rejected` | notification | yes | yes | PU |
| Full or partial refund | admin | yes `order.refunded` | notification | yes | yes | E2E E7 |
| PLUS request approved / rejected (any type) | admin | yes `plus.request_approved` / `_rejected` | notification | yes | yes | E2E S5 (live 0.39 s, unread +1, mark-read) |
| PLUS cycle start / expiry / renewal reminder | — | **not implemented** (no notification exists) | — | — | — | — |
| Marketing broadcast | admin (`notifications.marketing`) | yes `marketing.message` | notification | yes | yes (job) | PU |
| Driver position | driver GPS | — | `driver.location` on the order (assigned / picked up only) | — | yes | E2E 13-14 |

### Driver

| Event | Trigger | DB | Reverb | FCM | Queue | Verified |
|---|---|---|---|---|---|---|
| New delivery offer | dispatcher / admin | — (push + live only, by design) | `offer.created` (that driver only) | yes, high priority, TTL = deadline | yes | E2E S3.3, S3.4, S3.8 |
| Offer accepted | driver | — | `offer.accepted` (other devices) | — | yes | PU |
| Offer rejected, expired or cancelled | driver, timeout, admin | — | `offer.withdrawn` (+status) | — | yes (expiry job) | E2E S3.7 |
| Assigned (direct) | admin | yes `driver.order_assigned` | notification + `order.status_changed` | yes | yes | E2E 11, PU |
| Unassigned / reassigned | admin | yes `driver.order_unassigned` | yes | yes | yes | PU |
| Order cancelled | admin | yes `driver.order_cancelled` | yes | yes | yes | PU |

### Admin (Filament)

| Event | Recipients | DB (bell) | Reverb | Verified |
|---|---|---|---|---|
| New order | `orders.view` | yes | `admin.operational` + bell refresh | E2E S4 (bell +1 in 1.9 s) |
| New cancellation request | `orders.resolve_cancellation_requests` | yes | yes | PU |
| Order waiting for a driver (no driver available) | `orders.assign` | yes (once per entry) | yes | PU |
| Driver problem report | `orders.view` | yes | yes | PU |
| New PLUS request | `plus.approve` | yes | yes | E2E S5 |
| Delivery-offer changes | `orders.view` | — | `order.dispatch_updated` (screen refresh only) | PU |

## 5. Verified test results

| Level | What | Result |
|---|---|---|
| **Real WebSocket (local, not Docker)** | `node e2e/run.mjs`: the full order lifecycle against `php artisan reverb:start`, `queue:work`, Chromium | **34/34**, before and after the fixes ([evidence](evidence/e2e-lifecycle-34-steps.md)) |
| Real WebSocket | Delivery offers: targeting, isolation, no customer data, skip-when-online, expiry, late accept 409, push when closed, reconnect refresh | **8/8** on the fixed code ([evidence](evidence/e2e-delivery-offers.md)) |
| Real WebSocket + browser | Admin bell live (S4); PLUS request → admin; approval → customer live + stored + unread + mark-read (S5) | PASS ([evidence](evidence/e2e-notification-scenarios.md), [screenshot](evidence/admin-bell-live.png)) |
| Outage | Reverb stopped: checkout and acceptance succeed, the notification is stored, presence is unknown so the push is sent, the worker logs broadcast failures, the panel shows the order on load; Reverb restarted, clients re-authorize (S7, S7.b) | PASS |
| Measured | Silent network loss: the channel stays "occupied" for **176 s** with Reverb defaults (`ping_interval` 60 s, prune every 60 s) | measured |
| Mocked FCM (PHPUnit, `Http::fake`) | Signed JWT, OAuth token cached for 50 min, request shape, `UNREGISTERED`/404 → token deactivated, 5xx/429 → retry, 401 → token cache cleared, expired offer → dropped, dedupe per (notification, token) | 14 push tests pass |
| PHPUnit (all) | Full suite, SQLite / MySQL 8 | 641 passed, 22 skipped / 656 passed, 7 skipped; 0 failed |
| **Real device FCM** | — | **NOT verified: no Firebase project credentials or registered test device were available. FCM implementation verified with mocks; real-device delivery NOT verified.** |

## 6. Missing functionality and limitations

| Item | Impact | What closes it |
|---|---|---|
| Real FCM not configured | No phone receives a push today | Firebase project, service-account JSON, `WAFFRLI_PUSH_DRIVER=fcm`, a device test on staging (§7) |
| `WAFFRLI_PUSH_SKIP_WHEN_ONLINE=true` + presence | A backgrounded app with an open socket, or a phone that lost signal (up to ~3 min), gets **no push**. It matters most for 90-second delivery offers | **Recommended:** set `false` in production (always push; FCM does not show notification messages while the app is in the foreground, and the app dedupes by `notification_id` / `offer_id`). Optionally set `REVERB_APP_PING_INTERVAL=15` to shorten stale presence. Or make the apps close the socket when backgrounded |
| Presence is per user, not per device | A driver with two phones: one open app suppresses the push to the other | Same as above (`false`) |
| No replay of missed live events | After a reconnect the app must re-read state | Flutter: re-fetch on reconnect / resume (§8) |
| No push for offer withdrawal | A closed app learns about a withdrawal only on opening | Flutter: always check `offers/current` before showing an offer from a push; the push also carries `expires_at` |
| PLUS lifecycle notifications | No reminder before a cycle ends and no "membership ended" message | Not implemented (business decision) |
| No Android `channel_id` or APNs sound in the payload | Android uses the default channel; iOS is silent unless the app configures it | Flutter: create the channel and set foreground presentation options; or add the fields after a device test |
| `INVALID_ARGUMENT` treated as an invalid token | A malformed message (not a token problem) would deactivate a token | Low risk: payloads are server-built and small |

## 7. Deployment requirements

### Local Windows Laragon (do not assume it matches Docker)

1. In the Laragon `.env`:
   - **Broadcasting:**
     - `BROADCAST_CONNECTION=reverb`;
     - `REVERB_APP_ID/KEY/SECRET`: your own random values;
     - `REVERB_HOST=127.0.0.1`, `REVERB_PORT=8080`, `REVERB_SCHEME=http`;
     - `REVERB_SERVER_HOST=0.0.0.0`, `REVERB_SERVER_PORT=8080`;
     - `REVERB_ALLOWED_ORIGINS=localhost,127.0.0.1,<your .test host>`, `REVERB_ALLOW_ORIGINLESS_CLIENTS=true`.
   - **Queue:** `QUEUE_CONNECTION=database`, or `sync` if you don't want to run a worker. Use `redis` only if Redis runs in Laragon.
   - **Push:** `WAFFRLI_PUSH_ENABLED=true` and `WAFFRLI_PUSH_DRIVER=fake` until Firebase is ready.
2. Run `php artisan config:clear`.
3. Open three terminals in the project folder:

```bash
php artisan reverb:start
```

```bash
php artisan queue:work
```

```bash
php artisan schedule:work
```

`schedule:work` is only needed with delivery offers: it runs the dispatch safety sweep every 15 seconds.

4. A phone on the same Wi-Fi needs your PC's LAN IP as the Reverb host, and the port open in Windows Firewall.

### Production (Linux)

1. **Reverb behind TLS (wss).**
   - Run `php artisan reverb:start --host=127.0.0.1 --port=8080` under Supervisor or systemd.
   - Nginx proxies `wss://ws.your-domain` (443) to it, with WebSocket upgrade headers.
   - Client-facing settings: `REVERB_HOST=ws.your-domain`, `REVERB_PORT=443`, `REVERB_SCHEME=https`.
   - `REVERB_ALLOWED_ORIGINS=admin.your-domain` (never `*`) and `REVERB_ALLOW_ORIGINLESS_CLIENTS=true`.
   - Run `php artisan reverb:restart` after deploys.
   - With more than one node, set `REVERB_SCALING_ENABLED=true` with Redis.
2. **Queue workers** under Supervisor: `php artisan queue:work --tries=3 --max-time=3600`, two or more processes; `php artisan queue:restart` after deploys. `QUEUE_CONNECTION=redis` (or `database`). Never `sync` in production.
3. **Scheduler:** a cron entry for `php artisan schedule:run` every minute. The 15-second dispatch sweep needs `schedule:work` under Supervisor.
4. **FCM.**
   - Create a Firebase project with Android and iOS apps; for iOS, upload the APNs key to Firebase.
   - Create a service account with the "Firebase Cloud Messaging API Admin" role. Store its JSON outside the web root, readable only by the app user, and set `FIREBASE_CREDENTIALS=/path/key.json` and `FIREBASE_PROJECT_ID`.
   - Set `WAFFRLI_PUSH_DRIVER=fcm` and `WAFFRLI_PUSH_ENABLED=true` on **staging** first. Register a test phone's token through the app and trigger an order on staging. Check `push.sent` in the log and the notification on the phone. Only then enable it in production.
   - Recommended: `WAFFRLI_PUSH_SKIP_WHEN_ONLINE=false` (§6).
5. **Caches.** Run `config:cache`, `route:cache` and `event:cache` after changing the environment. Filament reads Echo settings at cache time.
6. **Monitoring.**
   - Alert on Supervisor process restarts and on `failed_jobs` growth.
   - Alert on the log lines `notify.failed`, `realtime.publish_failed`, `push.failed`, `admin_alert.failed`, `push.token_invalidated` (rate) and `push.presence_unknown` (Reverb unreachable from the app).
   - Run a WebSocket health check on the wss endpoint.

## 8. Flutter integration requirements

**Endpoints.** Both apps use the bearer token and the `Accept-Language: ar|en` header.

| Purpose | Customer | Driver |
|---|---|---|
| List notifications (paginated, `?unread=1`) | `GET /api/v1/customer/notifications` (includes `unread_count`) | `GET /api/v1/driver/notifications` |
| Unread count | `GET /api/v1/customer/notifications/unread-count` | from the list |
| Mark one read | `POST /api/v1/customer/notifications/{uuid}/read` | `POST /api/v1/driver/notifications/{uuid}/read` |
| Mark all / several read | `POST /api/v1/customer/notifications/read` | `POST /api/v1/driver/notifications/read` (optional `ids[]`) |
| Register a token | `POST /api/v1/customer/device-tokens` `{token, platform: android\|ios, device_id, device_name}` | `POST /api/v1/driver/device-tokens` (same body) |
| Rotate / remove a token | `PATCH` / `DELETE …/device-tokens/{id}` | same |
| Logout (stops push to this device) | `POST /api/v1/customer/auth/logout` `{device_id}` | `POST /api/v1/driver/logout` `{device_id}` |
| Channel authorization | `POST /api/v1/customer/broadcasting/auth` | `POST /api/v1/driver/broadcasting/auth` |
| Authoritative offer | — | `GET /api/v1/driver/offers/current`, `GET /api/v1/driver/offers/{id}` |

**Reverb (Pusher protocol 7).**
- **Connection:** use the public key `REVERB_APP_KEY` and the public host/port (wss, 443 in production).
- **Authorization:** the endpoint above, with headers `Authorization: Bearer …` and `Accept: application/json`.

| Channel | Who subscribes | Events (`broadcastAs`) |
|---|---|---|
| `private-user.{customerId}` | customer | `Illuminate\Notifications\Events\BroadcastNotificationCreated` (payload `{event, title, message, order_id?, order_number?, plus_request_id?, id, type: "notification.created"}`); `order.status_changed` |
| `private-order.{orderId}` | customer while pending → picked up; current driver while assigned / picked up | `order.status_changed` `{order_id, order_number, from, to/status, status_labels{ar,en}, driver_changed, tracking, at}`, `driver.location` `{driver_id, lat, lng, at}` |
| `private-driver.{driverId}` | driver | `offer.created` / `offer.accepted` / `offer.withdrawn` `{event, offer_id, order_id, status, reason, at, offer}`; notifications; `order.status_changed` |

**Push payload.**
- **Notification block:** title and body in the recipient's language.
- **`data` fields:** `event`, `notification_id`, plus identifiers (`order_id`, `order_number`, `plus_request_id`, `offer_id`, `expires_at` for offers).
- **Delivery settings:** Android priority HIGH; APNs priority 10; offers also carry a TTL and an APNs expiration.

**The backend handles:**
- storing every notification;
- choosing recipients and permissions;
- the recipient's language;
- deduplicating pushes per notification and device;
- dropping expired offer pushes;
- deactivating invalid tokens;
- retries;
- one token per device;
- stopping push on logout;
- channel authorization and revocation (`tracking: false`);
- never failing a business action because of a notification.

**Flutter must:**
1. Register the FCM token after login and on every `onTokenRefresh`, always with the same stable `device_id`. Send `device_id` on logout.
2. Create an Android notification channel (high importance for drivers), and request iOS notification permission and foreground presentation options.
3. Deduplicate by `notification_id` (and `offer_id` for offers): the same event can arrive live **and** by push.
4. **On app resume and on socket reconnect:** re-fetch `GET notifications` / `unread-count`, the open order, and for drivers `GET offers/current`. Live events are not replayed.
5. Offers: treat any non-`pending` status as final; ignore `offer.created` whose `expires_at` has passed; always confirm with `offers/current` before showing the accept screen from a push.
6. Leave `private-order.{id}` when `order.status_changed` has `tracking: false`.
7. Either close the WebSocket when the app goes to the background (so the push is not skipped), **or** have the backend set `WAFFRLI_PUSH_SKIP_WHEN_ONLINE=false` (recommended).
8. Handle the push tap: open the screen from `data.event` and the identifiers, then mark the notification read with `notification_id`.
