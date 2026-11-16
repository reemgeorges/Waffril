# Firebase Cloud Messaging — readiness check

`php artisan waffrli:fcm-check` (added 2026-11-16) tells you whether push
notifications can reach phones. **It never sends a notification**. It never
prints or stores a private key, an OAuth access token or a device token.

| Command | Network | What it does |
|---|---|---|
| `php artisan waffrli:fcm-check` | none | Local checks only (below). |
| `php artisan waffrli:fcm-check --online` | Google OAuth | Also asks Google for an access token with the service account. |
| `php artisan waffrli:fcm-check --validate-token=ID` | Google OAuth + FCM | Also runs an FCM `validate_only` request for device token `ID` (from `device_tokens.id`). FCM checks the message and the token **without delivering anything**. |
| `... --report` | — | Also writes `storage/app/diagnostics/fcm-readiness-YYYYMMDD-HHMMSS.json` (same content as the table, no secrets). |

The exit code is `1` when any check is `FAIL`; a `WARN` does not fail. You can
use it in a deployment checklist.

## Checks

| Check | PASS | WARN / FAIL |
|---|---|---|
| `push.enabled` | `WAFFRLI_PUSH_ENABLED=true` | false: nothing is pushed. FAIL in production, WARN elsewhere. |
| `push.driver` | `WAFFRLI_PUSH_DRIVER=fcm` | `fake` records messages only. FAIL in production, WARN elsewhere. |
| `credentials` | The project id and service-account email are found (the email is shown masked), and the private key loads locally with OpenSSL. | Missing or unreadable file, missing variables, or an invalid PEM key. FAIL when the driver is `fcm`. |
| `credentials.location` | — | WARN if the JSON file sits inside the project directory outside `storage/`, where it could be committed. |
| `queue` | A real queue connection; with the `database` queue, the number of waiting jobs and the age of the oldest. | `sync` (FAIL in production). WARN if the oldest job has waited over 10 minutes, which usually means no worker is running. |
| `device_tokens` | Active tokens per customers and drivers, and per platform. | WARN when there are none, or when tokens have not been seen for 60 days. |
| `failed_push_jobs_24h` | No failed `SendPushMessage` or `SendMarketingBroadcast` jobs in the last 24 h. | WARN with the count. |
| `oauth` (`--online`) | Google issued a `firebase.messaging` access token. | FAIL with the HTTP status. |
| `validate_only` (`--validate-token`) | FCM accepts the token. | FAIL when the token is unregistered or invalid, or the request fails. |

## Configuration (unchanged)

Credentials come only from the environment (`config/waffrli.php`, `push.fcm`).
Use one of these two forms:

- `FIREBASE_CREDENTIALS=/absolute/path/outside/the/repo/service-account.json`, or
- `FIREBASE_PROJECT_ID`, `FIREBASE_CLIENT_EMAIL` and `FIREBASE_PRIVATE_KEY`
  (with `\n` for the line breaks).

Then set `WAFFRLI_PUSH_ENABLED=true` and `WAFFRLI_PUSH_DRIVER=fcm`, run a
queue worker, and run:

```bash
php artisan config:clear
```

```bash
php artisan waffrli:fcm-check --online
```

To check one real phone without sending it anything, find its id in
`device_tokens` and run:

```bash
php artisan waffrli:fcm-check --validate-token=ID
```

## Result in the development environment used for this audit (2026-11-16)

This environment has no Firebase project and no credentials. Only the
local checks ran. The `--online` and `--validate-token` paths are covered by
automated tests against a faked Google and FCM (`tests/Feature/Push/FcmCheckCommandTest.php`).
They have **not** been run against real Firebase.

```
push.enabled          WARN  WAFFRLI_PUSH_ENABLED is false
push.driver           WARN  Driver "fake"
credentials           WARN  FCM credentials are not configured
queue                 WARN  QUEUE_CONNECTION=sync
device_tokens         WARN  Active tokens: customers 0, drivers 0
failed_push_jobs_24h  PASS
```

**Next step for production:** run the command with `--online` on the server
that holds the real credentials. Then run it with `--validate-token` for one
test phone.

## Push and OTP readiness audit (2026-11-16)

### How the pieces connect (verified in code and tests)

| Path | How it works | Evidence |
|---|---|---|
| Device registration | `POST /api/v1/{customer,driver}/device-tokens`. One active device per customer, a bounded number per driver; tokens are owned and validated. | `PushNotificationsTest` |
| Customer and driver notifications | `Notify::send` delivers database + live (Reverb) + `PushChannel` for every recipient and channel separately. `PushChannel` queues one `SendPushMessage` per **active** device. | `FirebaseOtpReadinessTest` (customer and driver devices both get a push job), `PushNotificationsTest` |
| Queued push delivery | `SendPushMessage` (3 tries, back-off 10/60/300 s) handles: skip when the user is online, dedupe per notification and device, deactivating invalid tokens, and a `failed()` hook after the last retry. Transport: `FcmHttpV1Transport` when `WAFFRLI_PUSH_DRIVER=fcm`, otherwise the fake. | `PushNotificationsTest`, `FirebaseOtpReadinessTest` |
| Marketing delivery status | The panel shows: processed recipients, in-app saved, push queued / accepted by Firebase / pending / failed / invalid / not needed, recipients without a phone, and "push off". | `MarketingDeliveryStatusTest` (sync and database queue, worker stopped then started) |
| Customer OTP | `PhoneOtpService::issue()` calls `TextMessenger::deliver()` **synchronously inside the request**. No job is involved, so OTP works without a queue worker. A failed send invalidates the code and the API answers 503. | `FirebaseOtpReadinessTest`, `CustomerIdentityTest` |
| OTP secrecy | Codes are stored hashed. The fake gateway logs "content withheld". No log line contains a code. No admin page reads the `otps` table. | `FirebaseOtpReadinessTest`, `FixedTestOtpTest` |
| Fixed development OTP | Allowlisted phones only, `local`/`testing` only, never production whatever the flags; nothing is sent. | `FixedTestOtpTest` |
| SMS / WhatsApp drivers | `WAFFRLI_SMS_DRIVER=fake` (refused in production) or `twilio`, which uses `TWILIO_*`. They are separate classes and are never mixed. | `FirebaseOtpReadinessTest` |

### Needs real credentials or a real phone (NOT tested here)

| Item | How to verify |
|---|---|
| Google issues an OAuth token for your service account | `php artisan waffrli:fcm-check --online` on the server. |
| FCM accepts a real device token | `php artisan waffrli:fcm-check --validate-token=ID` (dry run; nothing is delivered). |
| The phone displays the notification (Android and iOS, foreground and background), sound, tap opens the right screen, data payload read | Manual test on real customer and driver app builds. |
| APNs configuration in Firebase for iOS | Firebase console; an iOS device test. |
| Twilio SMS / WhatsApp actually delivered to a Syrian number | One OTP request with `WAFFRLI_SMS_DRIVER=twilio` on staging, to a test phone. |
| Driver app renders `marketing.message` | Before the first driver campaign. |

"Accepted by Firebase" (shown on marketing messages) is the strongest
evidence the server can have. It is not proof that a phone displayed the
message.

### Queue worker

- **OTP:** never needs a worker.
- **Pushes, marketing sends and broadcasts:** need a worker when
  `QUEUE_CONNECTION` is not `sync`. Without one, a marketing message stays
  "Queued", and after 2 minutes the panel says no worker has picked it up.
- **Check:** `php artisan waffrli:fcm-check` reports the waiting jobs and the
  age of the oldest one.
