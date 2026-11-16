# Error monitoring (Monitoring → Errors)

Added 2026-11-16. The admin panel lists unexpected application errors,
grouped. Nothing is sent to an outside service.

## Correlation id

Every HTTP response carries an `X-Request-Id` header. A well-formed incoming
`X-Request-Id` is reused, for example from Cloudflare, a proxy or the mobile
app: 8–64 characters, letters, digits and `._:-`. Otherwise the server
generates a UUID. The same id is:

- in the Laravel log context of the request (`request_id` on every log line),
- stored on the error group (`last_request_id`) and searchable in the panel.

JSON bodies are **not** changed: the `data/status/error/statusCode` envelope
of the customer and driver APIs stays exactly as before. The id is only a
response header.

## What is recorded

`App\Services\Monitoring\ErrorMonitor` runs as an exception reporter. Laravel's
normal logging is unchanged.

- **Not recorded** (expected outcomes): business-rule refusals
  (`BusinessRuleException`), validation, authentication, authorization,
  missing records, CSRF, throttling and any HTTP status below 500.
- **Grouping:** exception class, plus the first application (non-vendor)
  frame, plus the message with numbers and quoted values normalised. A repeat
  adds to `occurrences` atomically and updates the last request id, method,
  path, route and user (`admin:5`, `customer:12`, `driver:3`).
- **Scrubbing** (`App\Services\Monitoring\Scrubber`) happens before
  anything is stored. It removes:
  - PEM keys, bearer, JWT, Sanctum and Google tokens;
  - `password` / `token` / `otp` / `code` / `secret` / `authorization`
    values;
  - e-mail addresses, phone numbers and long opaque strings.

  The trace keeps `file:line class->function` only, never argument values.
  The path is stored without its query string. Request bodies and headers
  are never stored.
- **Regression:** a resolved group that happens again is reopened and marked
  "came back" (`regressed_at`). An ignored group keeps counting silently.
- The reporter never throws, so a failure in it cannot hide the original
  error.

## Panel and permissions

| Permission | Allows |
|---|---|
| `errors.view` | Open Monitoring → Errors: list, filters (status, error type), search (error, message, file, request id) and the detail panel. |
| `errors.manage` | Resolve, ignore and reopen. Each action is written to the activity log. |

Super admins have both. Existing installations: run the essential access
seeder once so the two new permissions exist for roles. It is idempotent and
creates no accounts:

```bash
php artisan db:seed --class="Database\Seeders\AdminAccessSeeder"
```

## Retention

`php artisan waffrli:prune-errors` runs daily at 00:35 (`routes/console.php`).
It deletes only rows of `error_events`:

- resolved or ignored groups not seen for `WAFFRLI_ERROR_RETENTION_DAYS`
  (default 30),
- any group not seen for `WAFFRLI_ERROR_STALE_RETENTION_DAYS` (default 90).

Use `--dry-run` to count only. To switch the whole feature off, set
`WAFFRLI_ERROR_MONITOR_ENABLED=false`; logging continues as before.
