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
