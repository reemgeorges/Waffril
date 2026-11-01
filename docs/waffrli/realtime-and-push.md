# WAFFRLI realtime (ONE Reverb server) and push (FCM)

## 1. One WebSocket server: Laravel Reverb

- Package: `laravel/reverb` (Composer added only Reverb and `pusher/pusher-php-server`; nothing was upgraded).
- Config: `config/reverb.php` and `config/broadcasting.php`. Every key comes from the environment; nothing is committed.
- Hardening in `config/reverb.php`:
  - client events (whisper) are **disabled** (`REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM=none`), so clients only receive what the server publishes;
  - origins: see [Mobile clients and Origin](#mobile-clients-and-origin) below.
- Business code publishes through `App\Services\Realtime\Realtime::publish()` and notifications go through `Notify::send()`. Both run after the transaction commits, and both log failures instead of throwing, so a WebSocket outage never fails an order, a payment or a GPS update.
- Events are queued (`ShouldBroadcast`), so production needs a queue worker.

### Environment

| Variable | Purpose |
|---|---|
| `BROADCAST_CONNECTION=reverb` | enable broadcasting (default `log` in `.env.example`) |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | your own random values; the secret never leaves the server |
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | where Laravel and the clients reach Reverb (public TLS host in production) |
| `REVERB_SERVER_HOST`, `REVERB_SERVER_PORT` | where `php artisan reverb:start` listens |
| `REVERB_ALLOWED_ORIGINS` | host names (not URLs) whose web pages may open sockets, e.g. the admin panel host |
| `REVERB_ALLOW_ORIGINLESS_CLIENTS` | `true` to admit clients that send no `Origin` header (native customer/driver apps); default `false` |
| `REVERB_PUBLIC_HOST/PORT/SCHEME` (optional) | browser-facing values for Filament, if they differ from the above |

### Mobile clients and Origin

Reverb checks the **host** of the WebSocket `Origin` header against `allowed_origins`, and refuses everything else with error **4009 "Origin not allowed"**. A missing header counts as the empty host.

Measured on the Docker stack (2026-11-05) with `REVERB_ALLOWED_ORIGINS=localhost,127.0.0.1` and `REVERB_ALLOW_ORIGINLESS_CLIENTS=true`:

| Client sends | Result |
|---|---|
| no `Origin` (native app, server, CLI) | accepted |
| `Origin: http://localhost:8080` (the panel) | accepted |
| `Origin: https://evil.example` | refused (4009) |
| `Origin: http://localhost.evil.example` (look-alike) | refused (4009) |
| `Origin: null` (sandboxed iframe, `file://`) | accepted (its host is also empty) |

**Why accepting origin-less clients is safe here.** The Origin check only protects *browsers* from being used by another web site. Any native client can send any Origin it likes, so the check is not an authentication mechanism. WAFFRLI's protection is the channel authorization:

- every channel is **private**;
- there are no public channels;
- client events are disabled;
- a subscription needs a signature that only the server creates, after it has checked a customer or driver **bearer token** or an admin **session plus CSRF**;
- a page from another site cannot obtain one.

Verified by `node e2e/run.mjs`. A native (no Origin) customer client:

- subscribes to its own order and receives its status events;
- is refused on another customer's order and user channels (403);
- receives **no** GPS position of another customer's delivery;
- has a forged signature refused by Reverb itself.

`tests/Feature/Realtime/ReverbOriginConfigTest` checks the configuration parsing.

**Required production configuration:**

```dotenv
REVERB_ALLOWED_ORIGINS=admin.your-domain.com      # host names of web pages only — never '*'
REVERB_ALLOW_ORIGINLESS_CLIENTS=true             # customer and driver apps
```

If an app *does* send an Origin header (some WebView-based apps send their scheme or host), add that host to `REVERB_ALLOWED_ORIGINS`. Do not switch to `*`.

**Customer and driver app settings (Pusher protocol 7, e.g. `pusher-js`, `laravel-echo`, `pusher_channels_flutter`, PusherSwift or pusher-websocket-java):**

| Setting | Customer app | Driver app |
|---|---|---|
| key | `REVERB_APP_KEY` (public; never the secret) | same |
| host / port / TLS | the public Reverb host, `443`, `wss` (production); `localhost`, `8085`, `ws` (local Docker) | same |
| cluster | any placeholder (`mt1`); Reverb ignores it | same |
| auth endpoint | `POST {API}/api/v1/customer/broadcasting/auth` | `POST {API}/api/v1/driver/broadcasting/auth` |
| auth headers | `Authorization: Bearer <customer token>`, `Accept: application/json` | `Authorization: Bearer <driver token>`, `Accept: application/json` |
| channels | `private-user.{customerId}`, `private-order.{orderId}` | `private-driver.{driverId}`, `private-order.{orderId}` (while assigned or picked up) |
| events | `order.status_changed`, `driver.location`, `Illuminate\Notifications\Events\BroadcastNotificationCreated` | `order.status_changed`, notifications |

- **Reconnect:** Pusher clients reconnect automatically. After a reconnect they must re-authorize every private channel, which the libraries do; this is verified as WS.3 in the E2E run.
- **Leaving a channel:** when an `order.status_changed` payload has `tracking: false`, the app must unsubscribe from that `private-order.{id}`.
- **Logout:** disconnect the socket. A revoked token can no longer authorize a channel.

### Channel authorization endpoints (one identity each)

| Endpoint | Authenticates | Used by |
|---|---|---|
| `POST /api/v1/customer/broadcasting/auth` | Sanctum `user` token + `customer.active` | customer app |
| `POST /api/v1/driver/broadcasting/auth` | Sanctum `driver` token + `driver.active` | driver app |
| `POST /broadcasting/auth` | `admin_web` session (Filament) | admin panel |

All three use `BroadcastAuthController`:

- it validates `socket_id` and requires the `private-` prefix;
- it answers **503** when no Pusher-protocol broadcaster is configured, because the `log` and `null` drivers would accept any channel;
- a refusal returns 403.

### Channel matrix (`routes/channels.php`, `App\Support\Realtime\ChannelAccess`)

Names are declared without the prefix. Clients subscribe to `private-…`, or with Echo to `Echo.private('order.12')`; there is no double prefix.

| Channel | Who may subscribe | Re-checked when publishing |
|---|---|---|
| `private-user.{id}` | that customer, active (not suspended, deleted or anonymized) | — (own data only) |
| `private-driver.{id}` | that driver, active | — |
| `private-order.{id}` | its customer while pending, accepted, assigned or picked up; its **current** driver while assigned or picked up | GPS goes only to orders still assigned to that driver and still in delivery |
| `private-admin.orders` | admin with `orders.view` | — (identifiers only) |
| `private-admin.drivers-map` | admin with `drivers.track` | the map re-reads the DB with the viewer's permission |
| `private-admin.{id}` | that admin, active | — |

`admin.orders` and `admin.drivers-map` are registered before `admin.{id}` (first match wins).

Revocation:

- A reassignment, completion, cancellation or suspension blocks **new** subscriptions immediately, and GPS stops at the source.
- Pusher-protocol servers cannot force-unsubscribe an open socket. The status event therefore carries `tracking: false`, and clients must leave the channel when they receive it.
- A suspended customer's token is refused by `customer.active`, so it cannot re-authorize.

### What is published

| Event (`broadcastAs`) | Channels | Payload |
|---|---|---|
| `order.status_changed` | order, customer, current and previous driver, `admin.orders` | order id and number, from/to status, `driver_changed`, `tracking`, time |
| `driver.location` | trackable `order.{id}` channels + `admin.drivers-map` | driver id, lat, lng, time (real reported position only; no history) |
| `admin.operational` | `admin.orders` | type (`order.placed`, `order.cancellation_requested`, `order.problem_reported`, `plus.request_submitted`), id, reference |
| `Illuminate\Notifications\Events\BroadcastNotificationCreated` | `user.{id}` / `driver.{id}` | the stored notification (event + identifiers + text) |
| `database-notifications.sent` | `admin.{id}` | Filament bell refresh |

No payload carries addresses, phone numbers, amounts, notes or codes.

## 2. Push notifications (FCM HTTP v1)

| Piece | File |
|---|---|
| Transport contract | `App\Contracts\Push\PushTransport` |
| FCM v1 | `App\Services\Push\FcmHttpV1Transport` (service-account JWT, then an OAuth access token cached for 50 min, then `messages:send`) |
| Fake (tests and local; refused in production) | `App\Services\Push\FakePushTransport` |
| Queued send per token | `App\Jobs\Push\SendPushMessage`: 3 tries with backoff, dedupe per (notification, token), skipped while the recipient's channel is occupied (app open), unregistered tokens deactivated |
| Notification channel | `App\Notifications\Channels\PushChannel` (`database` + `broadcast` + push via `App\Notifications\AppNotification`) |
| Tokens | `App\Services\Push\DeviceTokenService`: customers get one active device; drivers up to 3; owner-only update/remove; a token moves to whoever signs in on the device |

### Environment

| Variable | Default | Meaning |
|---|---|---|
| `WAFFRLI_PUSH_ENABLED` | `false` | nothing is pushed until true |
| `WAFFRLI_PUSH_DRIVER` | `fake` | `fcm` for real delivery |
| `WAFFRLI_PUSH_QUEUE` | `default` | queue for push jobs |
| `WAFFRLI_PUSH_SKIP_WHEN_ONLINE` | `true` | skip the push when the Reverb channel is occupied |
| `FIREBASE_CREDENTIALS` | — | path to the service-account JSON **outside the repository** |
| `FIREBASE_PROJECT_ID`, `FIREBASE_CLIENT_EMAIL`, `FIREBASE_PRIVATE_KEY` | — | alternative to the JSON file (`\n` escapes allowed) |

Push messages are sent for:

- customer order progress (accepted, driver assigned, on the way, delivered, cancelled), a declined cancellation request, refunds, and PLUS approved or rejected;
- driver assignment, unassignment and cancellation;
- admin marketing messages.

**No real push has been sent from this work**: no Firebase credentials were available. The FCM transport is tested against `Http::fake` (signed JWT, request shape, UNREGISTERED handling, retry on 5xx). Real delivery needs a project with credentials and a device test.

### Marketing

- Filament **System → Marketing messages**: the history is visible with `notifications.view`; sending requires the new permission `notifications.marketing`.
- Audience: all active customers, or customers whose default address is in a city. Suspended, inactive, deleted and anonymized accounts are excluded.
- Every send is audited, and a message is sent at most once.

## 3. Filament

- `config/filament.php`:
  - Echo (bundled with Filament) connects only when `BROADCAST_CONNECTION=reverb` and `REVERB_APP_KEY` are set;
  - only the public key reaches the browser;
  - otherwise everything keeps polling.
- **Bell:**
  - Filament database notifications, polled every 30 s and live on `admin.{id}`;
  - sent to admins who can act: new order (`orders.view`), cancellation request (`orders.resolve_cancellation_requests`), driver problem report (`orders.view`), PLUS request (`plus.approve`).
- **Live lists:** a single `admin.orders` subscription re-renders the orders list, cancellation requests and the dashboard widgets.
- **Driver map:**
  - live on `admin.drivers-map` and `admin.orders`; each event triggers one server re-read, throttled to every 2 s;
  - finished, cancelled or reassigned deliveries disappear;
  - polling stays as the fallback, and the page shows a live or polling badge.
- **Notifications screen:** a type filter, e.g. `driver.problem_reported` for delivery problem reports.
- **Currency re-denominations:** see `currency-switch.md`.

## 4. Verified locally (2026-11-03)

- PHPUnit:
  - `tests/Feature/Realtime/RealtimeChannelsTest.php` (7): signatures, ownership, revocation, guard isolation, 503 without Reverb;
  - `FilamentRealtimeTest.php` (4);
  - `tests/Feature/Push/PushNotificationsTest.php` (8).
- Browser: Playwright/Chromium against `php artisan reverb:start` and `php artisan serve` on a MySQL 8 demo database.
  - The admin session authorized `private-admin.drivers-map`.
  - A driver's `POST /api/v1/driver/location` arrived as `driver.location`.
  - The map marker moved after about 2.2 s (2 s throttle; polling fallback 30 s).

## 5. Local run

```bash
php artisan reverb:start
```

```bash
php artisan queue:work
```

```bash
php artisan serve
```

## 6. Production checklist (not executed by this work)

1. Set the Reverb variables with fresh random values. Put Reverb behind TLS (wss) with a reverse proxy, set `REVERB_ALLOWED_ORIGINS` to the admin panel **host name** and `REVERB_ALLOW_ORIGINLESS_CLIENTS=true` for the mobile apps (see [Mobile clients and Origin](#mobile-clients-and-origin)); never `*`.
2. Run `php artisan reverb:start` and `php artisan queue:work` under a supervisor. Use `reverb:restart` after deploys.
3. If more than one Reverb node runs, enable `REVERB_SCALING_ENABLED` with Redis.
4. Install the Firebase service-account JSON outside the web root, readable only by the app user. Set `WAFFRLI_PUSH_DRIVER=fcm`, then enable push **after** a device test on staging.
5. Run `php artisan migrate` (additive: `marketing_broadcasts`, customer identity changes) on staging first, after a backup. Never run `migrate:fresh` on a shared database.
6. Grant `notifications.marketing` (and `currencies.change_default`) only to the intended roles.
7. Set `config:cache`, `route:cache` and `event:cache`. Note that `config/filament.php` reads env at cache time.
