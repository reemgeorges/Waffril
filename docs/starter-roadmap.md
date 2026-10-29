# Starter Roadmap

## Phase 0 — Emergency security fixes (2026-10-25)

Scope: close the most severe live exposure identified by the architecture audit and stop raw
exception-message leakage, without inventing new auth guards or touching the database schema.
Phase 1/2 (configurable multi-model Sanctum auth, OTP, module generator, etc.) are intentionally
**not** started here.

### What was found (verified against source before editing)

1. `routes/api.php` registered fully public, unauthenticated CRUD route groups for `User`, `Test`
   and `Category` (`/api/user/create`, `/api/user/update`, `/api/user/activate`,
   `/api/user/deactivate`, and the `test`/`category` equivalents) with **zero middleware**.
   Combined with `StoreUserRequest` accepting a client-supplied `email_verified_at` and
   `BasicRequest::authorize()` hardcoded to `true`, any anonymous caller could create/mutate any
   record, including `User` rows.
2. `app/Http/Traits/GeneralTrait.php::handleException()`'s catch-all `else` branch returned
   `$e->getMessage()` directly in the `error` field for any uncaught `\Exception`/`\Throwable`,
   with no environment gate — leaking raw exception detail (file paths, query fragments, etc.) to
   API clients in every environment, including production.
3. Test coverage was effectively nil (only the Laravel skeleton `/` route test existed).

`config/auth.php` defines only the default `web` session guard — there is no `admin` or `user`
guard configured yet. Attaching `auth:admin` / `auth:user` to the exposed routes would throw
(`Auth guard [admin] is not defined`), not secure them. Real guards are Phase 2 work.

### What was changed

- `routes/api.php` — the three public CRUD route groups (`user`, `test`, `category`) are commented
  out with a dated `TODO(phase-2)` note explaining why (no working guard exists yet) and what
  re-enabling requires. The pre-existing `GET /api/user` Sanctum-protected route is untouched.
  Verified via `php artisan route:list --path=api` that `api/user/*`, `api/test/*`, `api/category/*`
  (the disabled groups) are no longer registered.
- `app/Http/Traits/GeneralTrait.php` — `handleException()`'s final `else` branch now returns a
  generic `'Internal server error.'` message when `app()->environment('production')` is true, and
  the original `$e->getMessage()` otherwise (unchanged behavior for local/testing/staging
  debugging). The `{data, status, error, statusCode}` envelope shape is unchanged.
- `tests/Feature/PublicCrudRoutesDisabledTest.php` (new) — asserts every previously public
  create/update/activate/deactivate/show/all endpoint for `user`, `test`, `category` no longer
  returns a 2xx response, and that the still-guarded `GET /api/user` route correctly returns 401
  for an unauthenticated caller (regression guard against accidentally disabling the wrong route).
- `tests/Feature/GeneralTraitExceptionHandlingTest.php` (new) — invokes the real
  `GeneralTrait::handleException()` code path directly (no mocking of the fix) to prove the raw
  exception message is suppressed under `app()->environment('production')` and preserved
  otherwise, and that the envelope shape (`data`, `status`, `error`, `statusCode`) is unchanged.

### Known follow-up (not fixed here, out of this task's explicit scope)

- `routes/api/v1/admin.php` contains an `admin/test/*` CRUD block that sits inside the `admin`
  prefix group but **outside** the `auth:admin` middleware group — i.e. it is also unauthenticated.
  This mirrors defect #1 above but was not listed in this task's scope and was left untouched.
  Needs the same treatment (disable or guard) once a real `admin` guard exists — tracked for
  Phase 1/2.
- `GeneralTrait::handleQueryException()`'s default branch still returns
  `"Database error: " . $e->getMessage()` unconditionally (no production gate). Only the
  `handleException()` catch-all branch was in scope for this task; this is a candidate for the
  same production-message treatment in a future patch.
- Disabled routes currently resolve to HTTP 500 (not 404) because Laravel's routing
  `NotFoundHttpException` is itself caught by the same generic `handleException()` catch-all and
  wrapped into a 500 JSON envelope — this is pre-existing behavior, not introduced by this patch,
  and was intentionally not changed since it is outside the three listed Phase 0 items.
- No `admin`/`user` guards exist yet; re-enabling the disabled routes is Phase 2 work (configurable
  multi-model Sanctum auth).

## Phase 0.1 — Follow-up emergency fixes (2026-10-25)

Scope: close the two items explicitly deferred at the end of Phase 0 above — the second
unauthenticated-CRUD-inside-a-prefix-group instance (`routes/api/v1/admin.php`) and the
ungated `GeneralTrait::handleQueryException()` default branch — plus regression tests. Still no
new auth guards, no schema changes, no migrations/seeders executed.

### What was found (verified against source before editing)

1. `routes/api/v1/admin.php` contained a `Route::prefix('test')->group(...)` block (CRUD:
   all/all-paginated/show/create/update/activate/deactivate for `TestController`) positioned
   **inside** the top-level `Route::prefix('admin')` group but **outside** the
   `Route::middleware('auth:admin')->group(...)` block a few lines above it. It looked like an
   admin-protected path (`api/v1/admin/test/...`) but required no authentication at all — the same
   defect pattern as Phase 0 item #1, confirmed via `php artisan route:list --path=api` before the
   fix (the six `admin/test/*` routes were listed with no middleware restricting them) and after
   (they no longer appear).
2. `routes/api/v1/user.php` was checked for the same pattern and found to be entirely wrapped in a
   block comment (`/* ... */`, lines 10–41) — **no routes from this file are currently registered
   at all**, so there is no second live instance of the defect there. (Its own `auth:user` guard
   references would also fail today since no `user` guard is configured — out of scope, unchanged.)
3. `routes/api/v1/general.php`'s two `upload/{folder}/single` and `upload/{folder}/multiple`
   routes are also unauthenticated and mutate server state (write files to disk), but they are
   **not** an instance of the audited pattern (a route sitting inside a prefix+middleware group but
   escaping the middleware) — they are flat, standalone public routes with no surrounding guarded
   group to escape from. Left untouched; see "Known follow-up" below.
4. `app/Http/Traits/GeneralTrait.php::handleQueryException()`'s `default` case (i.e. any SQL error
   code not explicitly mapped to 1062/1451/1452) returned
   `"Database error: " . $e->getMessage()` unconditionally, with no `app()->environment('production')`
   gate — able to leak raw SQL fragments, bound values, table/column names and driver-specific text
   to any API client in every environment, including production. The three explicitly-mapped cases
   (1062 duplicate entry, 1451 FK restricted, 1452 FK violation) already return static, translated,
   detail-free messages and did not need changing.
5. `config/auth.php` still only defines the `web` guard (re-verified; unchanged from Phase 0).
   Confirms attaching `auth:admin` to the `admin/test/*` block is not a viable fix yet.

### What was changed

- `app/Http/Traits/GeneralTrait.php` — `handleQueryException()`'s `default` branch now returns a
  generic `'A database error occurred. Please try again later.'` message when
  `app()->environment('production')` is true, and the original `'Database error: ' . $e->getMessage()`
  otherwise (unchanged behavior for local/testing/staging debugging). The 1062/1451/1452 branches
  are untouched. The `{data, status, error, statusCode}` envelope shape is unchanged (still built via
  `internalServer()` → `apiResponse()`, status code 500).
- `routes/api/v1/admin.php` — the `Route::prefix('test')->group(...)` CRUD block is commented out
  with a dated `TODO(phase-2)` note, mirroring the Phase 0 `routes/api.php` treatment. The public
  `admin/ping` and `admin/login` routes and the `auth:admin`-wrapped `admin/logout` route are
  untouched. Verified via `php artisan route:list --path=api` that `api/v1/admin/test/*` no longer
  appears and that `api/v1/admin/ping`, `api/v1/admin/login`, `api/v1/admin/logout` still do.
- `tests/Feature/AdminTestCrudRouteDisabledTest.php` (new file) — asserts every previously
  unauthenticated `admin/test/*` endpoint no longer returns a 2xx response, and that
  `admin/ping` (intentionally public, unrelated to this fix) still returns 200.
- `tests/Feature/GeneralTraitQueryExceptionHandlingTest.php` (new file) — using `RefreshDatabase`
  against the real migrated `users` table, inserts a duplicate `email` to provoke a genuine
  `QueryException` (no mocking of the exception or of the fix), then invokes the real
  `handleQueryException()` and asserts: production hides the SQL/email/driver detail and returns
  the static generic message; non-production shows the detailed `"Database error: ..."` message
  containing the real exception text; and the 4-key envelope shape is preserved in both cases.
- `docs/starter-roadmap.md` — this Phase 0.1 section (existing Phase 0 section left unmodified).

The pre-existing two Phase 0 test files
(`tests/Feature/PublicCrudRoutesDisabledTest.php`, `tests/Feature/GeneralTraitExceptionHandlingTest.php`)
were not modified — only new test files/methods were added.

### Known follow-up (not fixed here, out of this task's explicit scope)

- `routes/api/v1/general.php`'s `upload/{folder}/single` and `upload/{folder}/multiple` routes are
  unauthenticated file-upload endpoints (disk-mutating). They do not match the audited
  "escaped-its-middleware-group" pattern (there is no surrounding guarded group for them to have
  escaped), so changing them was judged out of scope for a same-pattern-focused fix and was left
  untouched. Needs an explicit decision (intentionally public vs. needs a guard) in a future phase.
- `routes/api/v1/admin.php`'s `Route::middleware('auth:admin')->group(...)` around `admin/logout`
  is pre-existing code (not introduced by this patch) that will throw
  `Auth guard [admin] is not defined` the moment it is actually hit, since no `admin` guard exists
  in `config/auth.php`. Left untouched — creating guards is explicitly Phase 2 scope, and this
  task's instructions were to avoid attaching/relying on `auth:admin`/`auth:user`, not to remove
  pre-existing (already broken) usages of it.
- `routes/api/v1/user.php` is fully commented out; once Phase 2 defines a real `user` guard and
  this file is reinstated, it should be re-audited for the same escaped-middleware pattern before
  going live, since the current comment-out status is incidental, not a reviewed security
  boundary.
- `vendor/bin/pint --test` flags pre-existing style issues (quote style, ternary spacing, blank
  lines, import ordering, indentation) in both `app/Http/Traits/GeneralTrait.php` and
  `routes/api/v1/admin.php` that predate this patch (e.g. the untouched
  `(isset($e->errorInfo[1]))? ... : 0` ternary and pre-existing blank-line/indentation noise in the
  `admin` prefix group). These were intentionally **not** auto-fixed with `pint --write` here to
  avoid reformatting unrelated, pre-existing lines beyond this task's scope; flagged for a dedicated
  style-cleanup pass instead.
- Lang keys `messages.db_duplicate_entry`, `messages.db_fk_restricted`, `messages.db_fk_violation`
  referenced by `handleQueryException()`'s 1062/1451/1452 branches have no corresponding
  `lang/en/messages.php` file in this project (none exists under `lang/` or `resources/lang/`
  outside `vendor/`), so `__()` currently just echoes the key string back. Pre-existing, unrelated
  to the production-leak defect fixed here (those branches never included raw SQL), left untouched.

## Phase 0.2 — Unauthenticated file-upload endpoints (2026-10-25)

Scope: the one item deferred at the end of Phase 0.1 — `routes/api/v1/general.php`'s
`upload/{folder}/single` and `upload/{folder}/multiple` routes, which are unauthenticated and
disk-mutating but, unlike the Phase 0/0.1 defect pattern, were never inside a guarded group to
escape from (flat, standalone public routes). Still no new auth guards, no schema changes, no
migrations/seeders executed, no files deleted from real storage.

### What was found (verified against source before editing)

1. `routes/api/v1/general.php` (original, before this patch) registered both routes with only a
   `->where('folder', '[A-Za-z0-9_-]+')` route constraint and **no** middleware of its own. Every
   file under `routes/api/v1/*.php` is wired in `bootstrap/app.php`'s `withRouting(... then: ...)`
   closure with `Route::middleware('api')->prefix('api/v1')->group($file)` — i.e. only the base
   Laravel `api` middleware group (throttling + route-model substitution), confirmed by reading
   `bootstrap/app.php` lines 16–24. There is no authentication layer anywhere in the chain. Verified
   via `php artisan route:list --path=api` (before): both routes listed, pointing at
   `UploadController@single` / `UploadController@multiple`, no auth middleware shown.
2. Read `app/Http/Controllers/UploadController.php` in full (the only controller referenced by these
   routes; no separate upload service/trait exists — validation and disk write happen directly in
   the controller methods):
   - **Path traversal via `{folder}`: NO.** The route itself constrains `{folder}` to
     `[A-Za-z0-9_-]+` (`routes/api/v1/general.php`, now-disabled lines), and the controller
     additionally re-validates the same pattern with `preg_match('/^[A-Za-z0-9_-]+$/', $folder)`
     (`UploadController.php` lines 51 and 121) before ever calling `storeAs()`. Neither `/` nor `.`
     nor `\` can pass either check, so `../../etc`-style values cannot reach `storeAs()` with a
     path-escaping folder segment — confirmed by reading the regex and the `storeAs($folder, ...)`
     call sites (lines 59 and 132), not by assumption.
   - **Extension/MIME validation: YES.** Both `single()` (lines 20–40) and `multiple()`
     (lines 77–100) run a `Validator`/`$request->validate()` rule with a closure that checks
     `$value->getMimeType()` (Laravel's `finfo`-based content-sniffed MIME type, not the
     client-supplied `Content-Type` header) against a fixed allowlist of image/video/PDF/Word MIME
     types. Arbitrary `.php`/`.phtml`/`.exe` uploads with real executable content would fail this
     check and be rejected with a 422-style validation error (`requiredField`/validation exception
     path), *unless* an attacker crafted a valid polyglot file whose magic bytes match an allowed
     type — a residual risk inherent to content-sniffing MIME checks, noted but not newly
     introduced or fixable by a small patch here.
   - **File size limit: YES.** Both methods enforce `'max:51200'` (51200 KB = 50 MB) on the
     `file`/`files.*` rules (lines 24 and 82).
   - **Public disk exposure: YES, but not currently materialized in this checkout.** Both methods
     hardcode `$disk = 'public'` (lines 56 and 125). `config/filesystems.php` maps the `public` disk
     to `storage_path('app/public')` with `'visibility' => 'public'` and a configured symlink
     `public_path('storage') => storage_path('app/public')` (lines 41–48, 76–78). Verified via `ls`
     that `public/storage` does **not** currently exist in this checkout (`storage:link` has not
     been run) and `storage/app/public` contains only a `.gitignore` (no files to protect or avoid
     deleting). So today, newly uploaded files would not actually be served at a predictable
     `APP_URL/storage/...` URL in this specific environment — but the code path is designed to do so
     the moment `storage:link` is run (a routine deploy step), so the underlying public-disk +
     public-visibility configuration is a real, currently-latent exposure, not a hypothetical one.
     Filenames are `Str::uuid()`-based (lines 58, 131), not sequential/guessable, which mitigates
     (but does not eliminate, given zero accountability for who uploaded what) enumeration risk.
3. Net conclusion: the three "classic" upload vulnerabilities this task asked to check for
   (path traversal, missing MIME/extension validation, missing size limit) are **all already
   mitigated** in the existing controller code. The one concrete, unmitigated defect is complete
   absence of authentication/authorization on a disk-mutating, publicly reachable endpoint, with no
   working `admin`/`user` guard available yet (`config/auth.php` re-verified: still only the `web`
   guard). Per this task's explicit decision rule, the safest move is to disable the routes rather
   than invent new access rules or guards.

### What was changed

- `routes/api/v1/general.php` — both `Route::post('upload/{folder}/single', ...)` and
  `Route::post('upload/{folder}/multiple', ...)` are commented out with a dated
  `TODO(phase-2)` note explaining the authentication gap and confirming the underlying
  `UploadController` validation (folder regex, MIME allowlist, size cap) needs no change to
  re-enable them later. Verified via `php artisan route:list --path=api` that
  `api/v1/upload/{folder}/single` and `api/v1/upload/{folder}/multiple` no longer appear (6 routes
  before this patch → 4 routes after; see exact before/after listing in the final report).
- `tests/Feature/UploadRoutesDisabledTest.php` (new file) — asserts: (a) anonymous POSTs to both
  previously-public upload endpoints no longer return a 2xx and never write to the (faked) `public`
  disk; (b) path-traversal-style `{folder}` values (`..%2F..%2Fetc`, etc.) cannot reach the
  controller either, and still write nothing to disk; (c) a malformed/oversized/wrong-type payload
  sent to the disabled route still never succeeds (the route itself, not the payload shape, is what
  blocks it, since the underlying validation code is now unreachable); (d) the unrelated
  `admin/ping` route is unaffected. Uses `Storage::fake('public')` throughout, so no real file under
  `storage/app` is ever touched, read, or deleted by this test.
- `docs/starter-roadmap.md` — this Phase 0.2 section (existing Phase 0 / Phase 0.1 sections left
  unmodified).

The four pre-existing test files (`PublicCrudRoutesDisabledTest.php`,
`GeneralTraitExceptionHandlingTest.php`, `AdminTestCrudRouteDisabledTest.php`,
`GeneralTraitQueryExceptionHandlingTest.php`) were not modified.

### Known follow-up (not fixed here, out of this task's explicit scope)

- The underlying `UploadController::single()`/`multiple()` MIME-allowlist check is content-sniffing
  (`getMimeType()`), which is materially better than trusting the client `Content-Type` header, but
  is still theoretically bypassable by a polyglot file whose leading magic bytes satisfy an allowed
  MIME type while carrying attacker-controlled trailing content. No concrete exploit was
  constructed or is claimed; flagged only as residual risk inherent to MIME-sniffing-only validation,
  for consideration if/when these routes are re-enabled with real auth in Phase 2 (e.g. pairing with
  extension allowlisting and/or re-encoding uploaded images).
- The `public` disk's `storage:link` symlink does not currently exist in this checkout, so the
  public-disk exposure described above is latent, not live, in this specific environment. It
  becomes live the moment someone runs `php artisan storage:link` (a normal deploy step) unless the
  routes remain disabled or are re-enabled behind real authorization in Phase 2.
- No access-control layer for *serving* already-uploaded files was built or proposed here (none
  exists today; Laravel's `storage:link` symlink, if created, serves the `public` disk directory
  with zero application-level gating). This is an explicit non-goal for this phase per the task's
  instructions; a signed-URL or auth-gated download route is a candidate for a later phase, not
  built here.
- `vendor/bin/pint --test routes/api/v1/general.php` flags `no_extra_blank_lines` and
  `single_blank_line_at_eof` on blank-line padding that predates this patch (present in the file
  before any Phase 0.2 edits were made; the file was never committed to git in this repository, so
  there is no tracked "before" revision to diff against, but the blank lines above `use` and below
  the route block were already there when first read). Left untouched, consistent with the
  Phase 0/0.1 precedent of not auto-running `pint --write` across unrelated pre-existing lines.

## Phase 1 — CRUD lifecycle and model improvements (2026-10-25)

Scope: separate the three lifecycle concepts `BasicCrudService` had conflated
(`is_active` status, soft-delete/`deleted_at`, permanent delete), add the missing
columns additively, fix the `$fillable`-as-pairs and unique-on-update generator
defects, converge the generator templates onto one correct contract, and close
the "scaffolded routes are public by default" gap in `scaffold:routes`. No new
auth guards, no DB schema touched on any real connection, no previously
disabled route re-enabled.

This phase was implemented across two sessions: the first session completed
most of the lifecycle/migration/generator work before being interrupted; this
session began with a **read-only recovery audit** (verifying every file
below against actual source, with `php -l` on all touched files) before
resuming, then completed the remaining `scaffold:routes` fix, Pint pass, and
this write-up. Nothing from the first session's work was reverted or
restarted — this section documents the combined result.

### What was found (verified against source)

1. `app/Services/Basic/BasicCrudService.php` conflated three concepts:
   `activate()`/`deactivate()` called `restore()`/soft `delete()` (toggling
   `deleted_at`, not an independent status), and `delete()` called
   `forceDelete()` directly — a permanent, irreversible operation with no
   separate authorization gate.
2. `app/Models/BaseModel.php` / `BaseAuthModel.php` both unconditionally
   `use SoftDeletes`, but the `users`, `tests`, `categories` migrations had
   **no** `deleted_at` column — so any `withTrashed()`/`restore()`/soft
   `delete()` call against these tables threw a SQL "no such column" error.
   No model/migration anywhere defined an `is_active` column either.
3. `app/Console/Commands/CodeAfterMigrate/ScaffoldModelSchemaCommand.php`
   generated `$fillable` as `'col' => 'col'` pairs instead of a plain value
   list (harmless today since Eloquent's `isFillable()` matches by value,
   but semantically wrong).
4. Request classes are shared between store and update (e.g.
   `StoreUserRequest` used for both, per
   `UserController::setVariables()`), and their `unique:table,column` rules
   had no `ignore($id)` — updating a record without changing its own unique
   field always failed validation.
5. `app/Console/Commands/CodeBasics/GenerateBasicCrudServiceCommand.php` had
   two mutually-diverging generator stub variants, and neither matched the
   checked-in `BasicCrudService.php` — three disagreeing sources of truth
   for the same lifecycle contract.
6. `app/Console/Commands/CodeAfterMigrate/ScaffoldRouteCommand.php`
   (`scaffold:routes`) always appended a fully public, zero-middleware route
   group — the exact defect pattern Phase 0 had to clean up after the fact
   in `routes/api.php`. Its only other code path (admin/user "guarded
   injection") was dead: it read `$this->option('admin')`/`('user')`, which
   were never declared in the command signature, and targeted middleware
   aliases (`'admin-auth'`, `'user-auth'`) never registered in
   `bootstrap/app.php`. In practice the command had exactly one live
   behavior: public injection, unconditionally.

### What was changed

**Migration (additive only, never run against a real/configured DB connection):**

- `database/migrations/2026_10_25_120000_add_lifecycle_columns_to_users_tests_categories_tables.php`
  (new) — adds `deleted_at` (standard `softDeletes()`) and `is_active`
  (boolean, default `true`) to `users`, `tests`, `categories`, each column
  guarded by `Schema::hasColumn()` so it is a no-op if already present.
  Never drops/recreates/truncates a table or rewrites existing column data.
  `down()` only removes the two columns it added. Verified: no duplicate of
  this migration exists; it is the only migration touching these columns.

**Lifecycle contract (now one correct, converged contract across all three sources of truth):**

- `app/Services/Basic/BasicCrudService.php` — `activate()`/`deactivate()`
  now toggle `is_active` only (never touch `deleted_at`); `delete()`
  soft-deletes; new `restore()` clears `deleted_at`; new `forceDelete()` is
  a separate, explicitly-named permanent delete, never aliased to
  `delete()`.
- `app/Http/Controllers/FatherCrudController.php` — new `restore()` and
  `forceDelete()` controller methods added, same
  try/catch → `apiResponse()`/`handleException()` envelope as every other
  action. **Not wired to any route** in this phase (no permission system
  exists yet) — verified via a repo-wide grep across `routes/` that no
  route action references `@restore` or `@forceDelete`, and via a dedicated
  test (see below) that asserts this against the live route table.
- `app/Console/Commands/CodeBasics/GenerateBasicCrudServiceCommand.php` —
  the two diverging stub variants are gone; one stub now, which matches
  `BasicCrudService.php` exactly. The `--soft-deletes` CLI flag is kept as a
  documented no-op for backward compatibility (soft-delete support is now
  always present) rather than silently removed.

**Models:**

- `app/Models/BaseModel.php` / `BaseAuthModel.php` — added opt-in
  `scopeActive()` / `scopeInactive()` convenience scopes for the new
  `is_active` column. These are **not** global scopes (no automatic
  filtering), so they are safe to add even before every consuming table has
  the column.
- `app/Models/User.php`, `Category.php`, `Test.php` — `is_active` added to
  `$fillable` (in the same `'col' => 'col'` pair style as every pre-existing
  entry in these files, for visual consistency — not rewritten to the new
  plain-list style, since that would mean regenerating/reformatting an
  existing file rather than an additive, minimal change) and to `$casts` as
  `'is_active' => 'boolean'`.

**Validation fix:**

- `app/Http/Requests/Basic/BasicRequest.php` — new `withValidator()` hook.
  Whenever the request carries an `id` field (the update-path convention
  `BasicCrudService` already uses via `$request->id`), any `unique:` string
  rule is rewritten into `Rule::unique($table, $column)->ignore($id)`
  automatically. Centralized once in the shared base class — no
  per-request-class duplication, and it applies to every current and
  future `BasicRequest` subclass, generated or hand-written, without
  further changes needed in the generator's rule-string output.

**Generator fixes:**

- `app/Console/Commands/CodeAfterMigrate/ScaffoldModelSchemaCommand.php` —
  new `formatFillableBlock()`, wired in and used only for `$fillable`
  (confirmed by reading the call site: `$casts` still correctly uses the
  original key=>value `formatArrayBlock()`, since casts legitimately need
  pairs). Future-generated models get a plain `$fillable` list; no existing
  model file was regenerated as a side effect of this fix.
- `app/Console/Commands/CodeAfterMigrate/ScaffoldRequestSchemaCommand.php`
  — left its rule-string generation unchanged by design: the unique-on-update
  fix is centralized in `BasicRequest::withValidator()` (above) and applies
  automatically to every generated request, so no per-generator-template
  duplication of the `ignore()` logic was needed or added.
- `app/Console/Commands/CodeAfterMigrate/ScaffoldRouteCommand.php`
  (`scaffold:routes`) — **rewritten** this session. New default-safe
  behavior:
  - No flags at all → the route block is generated **fully commented out**
    (a visible, discoverable scaffold with zero live routes). This replaces
    "always public" as the default.
  - `--guard=NAME` → validated against `config("auth.guards.{NAME}")` at
    generation time (a pure config read, no DB/network access). If the
    guard is not configured, the command **fails** (non-zero exit, clear
    error) and writes nothing to the routes file — it never emits a
    middleware reference that would throw `Auth guard [NAME] is not
    defined` at request time. If configured (e.g. `sanctum` — Laravel
    Sanctum's own service provider registers this guard into
    `config('auth.guards.sanctum')` at boot time, even though
    `config/auth.php`'s guards array on disk only lists `web`; the
    pre-existing `GET /api/user` route already proves `auth:sanctum` works
    in this app), the routes are generated live, wrapped in
    `Route::middleware("auth:{guard}")`.
  - `--public` → explicit, deliberate opt-in to generate a live, fully
    public group — preserves the old capability, but only on request,
    matching this project's "generated public endpoints require explicit
    opt-in; secure routes by default" rule.
  - `--with-restore` / `--with-force-delete` → opt-in additions for the
    `restore()`/`forceDelete()` actions added to `BasicCrudService`/
    `FatherCrudController` in this phase. Both **require** `--guard` (the
    command refuses them with `--public` or with no guard at all) —
    `forceDelete` in particular is permanent and irreversible, so it must
    never be reachable without a deliberately configured guard.
  - The dead admin/user "guarded injection" code path (undeclared options,
    unregistered middleware aliases) was removed entirely rather than
    patched, since it never worked.
  - Idempotency preserved: re-running for the same model skips re-injection
    (checks for both the legacy `// {Model} PUBLIC ROUTES` marker and the
    new `// {Model} ROUTES (` marker, so a previously-generated block —
    live or since hand-disabled — is never duplicated).

**Tests (new files only; the five pre-existing Phase 0/0.1/0.2 test files were not modified):**

- `tests/Feature/BasicCrudServiceLifecycleTest.php` (7 tests) — exercises
  `BasicCrudService`/the model-specific services directly against the real
  sqlite `:memory:` test database via `RefreshDatabase` (no new routes
  registered, to avoid any new exposure risk): `activate()` sets
  `is_active=true` without touching `deleted_at`; `deactivate()` sets
  `is_active=false` without touching `deleted_at`; `delete()` soft-deletes
  (excluded from default queries, reachable via `withTrashed()`,
  `is_active` untouched); `restore()` clears `deleted_at`; `forceDelete()`
  permanently removes the row (gone even from `withTrashed()`); and two
  route-table assertions confirming `forceDelete`/`restore` are not wired to
  any currently-registered route.
- `tests/Feature/BasicRequestUniqueOnUpdateTest.php` (4 tests) — exercises
  the real `app($this->updateRequest)` resolution path
  `FatherCrudController::update()` actually uses: updating a user without
  changing their own email passes; changing it to a *different* existing
  user's email still correctly fails; changing it to a brand-new email
  passes; the create-path duplicate-email rejection baseline is unchanged.
- `tests/Feature/ScaffoldRouteCommandSecurityTest.php` (8 tests, new this
  session) — runs the real Artisan command against a disposable temporary
  routes file under `routes/testing/` (created in `setUp()`, deleted in
  `tearDown()` regardless of outcome) — **never** against `routes/api.php`
  or any other live, registered route file. Covers: default-with-no-flags
  produces a disabled/commented scaffold; `--guard` with an unconfigured
  guard fails safely and writes nothing; `--guard=sanctum` (a real,
  already-configured guard) produces a live guarded group;
  `--public` produces a live unguarded group only on explicit opt-in;
  `--with-restore`/`--with-force-delete` are refused without a real guard
  and refused together with `--public`; both are added correctly behind a
  real guard; re-running for the same model does not duplicate the block;
  `--guard` and `--public` together are rejected.

**Style:**

- `app/Models/User.php`, `Category.php`, `Test.php` — the `protected $casts
  = [...]` block added in this phase had a 0-indentation bug on its opening
  line (inconsistent with the rest of each class). Fixed with a targeted
  edit to just that line in each file, since the whole `$casts` property is
  new in this phase (no pre-existing content there to disturb).
  `vendor/bin/pint --test` still flags `no_unused_imports` and
  `blank_line_after_namespace` in these three files — both pre-existing
  (an unused `Illuminate\Database\Eloquent\Model` import, and spacing after
  `namespace`), unrelated to this phase, intentionally left untouched.
- `app/Console/Commands/CodeAfterMigrate/ScaffoldRouteCommand.php` — fully
  rewritten this session, so `vendor/bin/pint` was run and auto-fixed
  directly on it (safe: no pre-existing unrelated content in the file to
  disturb).
- `vendor/bin/pint --test` continues to flag pre-existing, unrelated style
  debt across `BasicCrudService.php`, `BaseModel.php`, `BaseAuthModel.php`,
  `BasicRequest.php`, `FatherCrudController.php`,
  `GenerateBasicCrudServiceCommand.php`, `ScaffoldModelSchemaCommand.php`,
  and `ScaffoldRequestSchemaCommand.php` (quote style, brace position,
  blank-line rules, import ordering, etc. spanning far more of each file
  than this phase's actual changes). Consistent with Phase 0/0.1/0.2
  precedent, these were **not** auto-fixed with `pint --write`, since doing
  so would reformat large amounts of pre-existing, unrelated code in files
  this phase only partially modified. Flagged here for a dedicated,
  separate style-cleanup pass.

### Test results

Full suite, sqlite `:memory:` per `phpunit.xml` (no real database touched):

```
php artisan test
Tests:    64 passed (330 assertions)
Duration: ~1.6s
```

56 pre-existing (Phase 0/0.1/0.2 + framework) + 8 new
(`ScaffoldRouteCommandSecurityTest`) — the `BasicCrudServiceLifecycleTest`
(7) and `BasicRequestUniqueOnUpdateTest` (4) tests from the interrupted
first session are included in that 56 and were re-verified in this session,
not merely assumed. 0 failures.

### Backward-compatibility / breaking-change notes (real for any actual deployment)

- **`activate()`/`deactivate()` semantics changed.** Previously these
  methods actually called `restore()` / soft `delete()` — i.e. "activate" a
  record un-soft-deleted it, and "deactivate" soft-deleted it. They now
  toggle an independent `is_active` boolean and never touch `deleted_at`.
  Any existing caller (UI, mobile client, integration) that relied on the
  old restore-based `activate`/delete-based `deactivate` behavior will see
  **different** behavior the moment this ships against a real database.
- **Pre-existing soft-deleted rows need a one-time reconciliation
  decision before real deployment.** Under the old code, a "deactivated"
  row *is* a soft-deleted row (`deleted_at` set). Under the new code, a
  deactivated row is an `is_active = false` row with `deleted_at` still
  `null`. If this ships against a real database that already has
  soft-deleted rows from the old `deactivate()` behavior, someone must
  explicitly decide: are those rows "soft-deleted" (restorable, excluded
  from default queries, as they are today) or "inactive" (included in
  default queries, filterable via the new `is_active` flag)? This phase
  does **not** make that call or touch any real data — it only adds the
  additive migration and the new code path. The additive migration's
  `is_active` column defaults every existing row (soft-deleted or not) to
  `true` (active), which is almost certainly NOT what's intended for rows
  that were soft-deleted under the old "deactivate = soft-delete" meaning —
  a deliberate, reviewed data migration/backfill decision is needed
  separately before this phase's code changes are deployed against
  production data.
- **`delete()` no longer force-deletes.** Previously `delete()` called
  `forceDelete()` — permanent and unrecoverable. It now performs a
  reversible soft delete. Any caller relying on the old permanent-delete
  behavior of `delete()` must be updated to call the new, separate
  `forceDelete()` method instead (which remains unrouted/unauthorized by
  design — see below).
- **`forceDelete()` and `restore()` are new, unrouted capabilities.**
  Neither is reachable via any currently-registered route. Wiring either to
  a live route requires a real authorization gate (Phase 2+) — attempting
  to do so before then would reintroduce exactly the kind of unauthenticated
  mutation risk Phase 0 had to fix.
- **`scaffold:routes` default behavior changed.** Previously running it
  with no flags produced a live, fully public route group. It now produces
  a disabled (commented-out) scaffold by default. Any existing script or
  documented workflow that calls `scaffold:routes <Model>` expecting a live
  public group **must** be updated to pass `--public` explicitly, or
  (preferably) `--guard=sanctum` (or a future `admin`/`user` guard once
  Phase 2 defines one).

### Remaining limitations / explicitly left for Phase 2+

- No permission/authorization system exists yet, so `restore()` and
  `forceDelete()` remain unrouted by design — wiring them up safely is
  Phase 2+ work (needs real guards + policies).
- The real-data reconciliation decision described above (what happens to
  rows that were soft-deleted under the old `deactivate()` meaning) has not
  been made and is explicitly out of this phase's scope — it requires a
  human decision about actual production data, not a code change.
- Pre-existing Pint style debt in the eight files listed above was
  intentionally left unfixed to avoid unrelated rewrites; a dedicated
  style-cleanup pass is still outstanding.
- `--guard=admin` / `--guard=user` will correctly fail-safe in
  `scaffold:routes` today (since neither guard is configured), which is
  correct now but means no generator-produced admin/user-guarded routes can
  exist until Phase 2 defines those guards in `config/auth.php`.
- This phase did not touch Auth or OTP (`config/auth.php`, `OtpChannelHelper`,
  `BaseAuthService`, anything under `app/Services/Functional/`) — unchanged,
  per explicit scope.

## Phase 2A — Configurable Authentication Foundation (2026-10-25)

### Scope

Build the multi-model Sanctum guard/provider foundation and the missing
`Admin`/`DeviceToken` infrastructure the existing auth services already
depended on, without implementing OTP delivery (reserved for Phase 3).

### What was found

1. `App\Models\Admin` and `App\Models\DeviceToken` were referenced live by
   `AdminAuthService`, `BaseAuthService`, `ResetPasswordService`,
   `CombinedAuthService`, `CurrentActor`, `CleanupExpiredTokensCommand` and
   `AdminAuthController`, but neither class nor table existed. Hitting the
   already-live `POST /api/v1/admin/login` route fataled with
   `Class "App\Models\Admin" not found`.
2. `config/auth.php` only defined the `web` guard. `BaseAuthService`,
   `CurrentActor`, `AdminAuthController::logout()` and `UserAuthController::logout()`
   already called `request->user('admin')`, `request->user('user')`,
   `Auth::guard('admin')`, `Auth::guard('user')` — all of which would throw
   `Auth guard [admin] is not defined` had the routes using them ever been
   reachable (`admin/logout` already was, and was therefore already broken).
3. The `users` table was missing every column `UserAuthService`/
   `BaseAuthService` already read or wrote: `status`, `social_id`,
   `prefix_phone`, `phone_number`, `otp_delivery_method`, `activated_at`,
   `email_status`, `phone_status`, `phone_verified_at`, `language`. Any code
   path touching them already hit a `QueryException` ("no such column").
4. Laravel Sanctum's `sanctum` guard driver resolves a bearer token to
   whichever model it actually belongs to — the guard's `provider` config
   key does not restrict which model's tokens it accepts. Simply adding
   `admin`/`user` guards with driver `sanctum` would **not**, by itself,
   stop a `User` token from satisfying `auth:admin` (and vice versa) — a
   real guard-isolation gap, not just a naming one.
5. `App\Models\Admin` (once created) needed `Spatie\Permission\Traits\HasRoles`
   just to not break the already-existing `AdminAuthService::login()` call
   `$admin->loadMissing(['roles', 'permissions'])`, which assumes that trait.
6. Discovered while writing the first Phase 2A test (not previously caught
   because nothing had ever loaded these classes): `LoginRequest::withValidator($validator)`
   and `SendResetOtpRequest::withValidator($validator)` (pre-existing code)
   had untyped signatures that became a **fatal** "declaration must be
   compatible" error against `BasicRequest::withValidator(Validator $validator): void`
   — the typed signature Phase 1 added to the parent class. This was a
   latent Phase 1 regression that simply had no test exercising either
   subclass until now.

### What was changed

**New additive migrations** (all guarded with `Schema::hasTable`/`hasColumn`,
none touch existing data):
- `database/migrations/2026_10_25_130000_create_admins_table.php` — new
  `admins` table (`name`, `email` unique, `password`, `is_active` default
  true, `remember_token`, timestamps, soft deletes).
- `database/migrations/2026_10_25_130100_create_device_tokens_table.php` —
  new `device_tokens` table (polymorphic `tokenable`, `token` unique,
  `platform`, `device_name`, `device_id`, `is_active`, `last_seen_at`,
  `revoked_at`, `meta` json, timestamps).
- `database/migrations/2026_10_25_130200_add_auth_columns_to_users_table.php` —
  additive columns on `users` listed in finding 3 above, all nullable or
  boolean-with-default so existing rows stay valid with no backfill.

**Models / factories / resources:**
- `app/Models/Admin.php` — extends `BaseAuthModel` (inherits `HasApiTokens`,
  `SoftDeletes`, the Phase 1 `is_active` scopes, file-cleanup hooks for
  free); adds `HasFactory`, `Notifiable`, `HasRoles`.
- `app/Models/DeviceToken.php` — plain `Model` (not `BaseModel`/`BaseAuthModel`
  — it's polymorphic metadata, not a lifecycle-managed CRUD resource), with
  a `tokenable()` morphTo and `meta` array cast.
- `database/factories/AdminFactory.php` — mirrors `UserFactory`'s shape;
  adds an `inactive()` state for tests.
- `app/Http/Resources/Model/AdminResource.php` — mirrors `UserResource`.

**Guards / middleware:**
- `config/auth.php` — added `admin` guard (`driver: sanctum`,
  `provider: admins`) and `user` guard (`driver: sanctum`, `provider: users`);
  added the `admins` provider (`App\Models\Admin`, overridable via
  `AUTH_ADMIN_MODEL`).
- `app/Http/Middleware/EnsureGuardIdentity.php` — **new**. Closes the
  Sanctum guard-isolation gap from finding 4: given a guard name parameter,
  confirms `$request->user($guard)` is actually an instance of that guard's
  configured provider model; otherwise throws `AuthenticationException`
  (rendered in the standard envelope by the existing
  `ApiExceptionServiceProvider` renderable — no new exception handling
  needed). Registered as the `guard.identity` alias in `bootstrap/app.php`.
  Usage: `->middleware(['auth:admin', 'guard.identity:admin'])`.

**Routes (already-live route strengthened, nothing newly enabled):**
- `routes/api/v1/admin.php` — the pre-existing, already-live
  `auth:admin` group around `admin/logout` now also requires
  `guard.identity:admin`. `admin/login` and `admin/ping` were already live
  and untouched; `routes/api.php` and `routes/api/v1/user.php` were **not**
  touched — `user.php`'s auth routes remain fully commented out, since
  `login`'s "not yet verified" branch and `register` both still depend on
  OTP delivery (Phase 3) to be meaningful end-to-end.

**Service-layer fixes:**
- `app/Services/Basic/BaseAuthService.php` — `issueToken()` now tags issued
  tokens with ability `[$this->guard, '*']` (kept `'*'` so nothing relying
  on the previous unrestricted-abilities default breaks; the guard tag is
  additive, forward-compatible groundwork for `->middleware('ability:admin')`
  style checks later).
- `app/Services/Functional/UserAuthService.php` /
  `app/Services/Functional/AdminAuthService.php` — `login()` now rejects a
  deactivated account (`is_active === false`) with the same generic
  `invalid_credentials`-style 400 response shape used for a wrong password
  (new `messages.account_inactive` key; no lang file exists in this project,
  so — consistent with every other `__('messages.*')` call already in this
  codebase — it currently resolves to the literal key). Soft-deleted
  accounts were already rejected for free: Eloquent's default `SoftDeletes`
  query scope excludes `deleted_at` rows unless `withTrashed()` is called,
  and neither `login()` query does.
- `app/Models/User.php` — added the Phase 2A columns from finding 3 to
  `$fillable`/`$casts`. `activated_at` and `phone_verified_at` were
  deliberately left out of `$fillable` — they're service-set, not
  client-mass-assignable.
- `app/Http/Requests/Model/Auth/LoginRequest.php` and
  `app/Http/Requests/Model/Auth/ResetPassword/SendResetOtpRequest.php` —
  fixed the latent Phase 1 regression from finding 6 by typing
  `withValidator(Validator $validator): void` to match the parent; no
  behavioral change, the method bodies are untouched.

### Tests

New: `tests/Feature/AuthGuardFoundationTest.php` (12 tests) — guard/provider
config shape; guard isolation both directions (an admin token rejected by a
`user`-guarded route and vice versa, via temporary routes registered inside
the test, touching no routes file); unauthenticated request still renders
in the standard envelope; inactive user rejected at login (via a temporary
test route exercising the real `UserAuthService::login()`); inactive admin
and soft-deleted admin rejected via the real, already-live
`POST /api/v1/admin/login` endpoint; that same endpoint now succeeding
end-to-end for an active admin; `admin/logout` accepting a matching admin
token and rejecting a user token end-to-end; issued tokens carrying the
guard ability.

### Test results

`php artisan test` → **76 passed (359 assertions)** — the 64 from Phase 0/0.1/0.2/1
plus the 12 new Phase 2A tests, zero regressions.

Pint: ran `pint --write` only on wholly-new files (`app/Models/Admin.php`,
`app/Http/Resources/Model/AdminResource.php`,
`tests/Feature/AuthGuardFoundationTest.php` — fixed `ordered_traits`,
`ordered_imports`, `fully_qualified_strict_types`) for the same reason as
every prior phase: no pre-existing file was reformatted wholesale.

### Backward-compatibility / breaking-change notes

1. `issueToken()`'s ability list changed from the implicit `['*']` default
   to `[$this->guard, '*']`. `'*'` is still present, so any caller doing
   `$token->can('*')` or not checking abilities at all is unaffected; this
   is additive.
2. `LoginRequest`/`SendResetOtpRequest::withValidator()` signatures changed
   from untyped to `(Validator $validator): void`. This is a type-safety
   fix for a signature that was already fatally incompatible with its
   parent — there is no working prior behavior to preserve.
3. Deactivating a user or admin (`is_active = false`) now blocks login where
   it previously did not (the column existed since Phase 1 but `login()`
   never checked it). Anyone relying on `is_active` being purely cosmetic
   should re-review before deploying this phase.
4. `admin/logout` now requires the bearer token to belong to an actual
   `Admin` row, not just any valid Sanctum token. If any existing client was
   depending on the old (broken) behavior — which, before this phase, threw
   a fatal missing-guard error on every request — there was no working
   prior behavior to preserve there either.

### Remaining limitations / explicitly left for Phase 2B/3

- OTP delivery, the `Otp` model/table, and everything `register()`/
  `verifyOtp()`/`resendRegisterOtp()`/password-reset depend on remain
  unimplemented by design (explicit Phase 2A exclusion, reserved for
  Phase 3). Calling those service methods today still fatals on the missing
  `Otp` model — unchanged from before this phase.
- `routes/api/v1/user.php` remains entirely disabled. Re-enabling even just
  `user/login` is deferred until Phase 3 makes `register`/`verifyOtp`
  usable too — enabling login alone would strand newly-registered users who
  can never complete verification through the API.
- No roles/permissions are seeded yet, and no route enforces `role:`/
  `permission:` middleware. `Admin` has `HasRoles` wired in (so
  `loadMissing(['roles','permissions'])` no longer breaks), but that's
  infrastructure, not policy — real authorization rules are Phase 2B/3 work.
- `CombinedAuthService` and `ResetPasswordService` were not modified; they
  compile and reference real classes now (since `Admin`/`DeviceToken` exist)
  but are not routed or covered by new tests — out of this phase's explicit
  scope.
- `App\Models\Admin` does not yet have the phone/OTP-channel columns
  (`prefix_phone`, `phone_number`, `otp_delivery_method`, `language`) that
  `BaseAuthService::requestPhoneChange()`/`verifyPhoneChangeOtp()`/
  `updateLanguage()` would need for an admin actor — not reachable today
  (no route calls them for `admin`), deferred until those flows are
  actually wired up.

## Phase 2B — Configurable Auth Modules, Middleware and Permissions (2026-10-25)

### Scope

Make the Phase 2A foundation reusable for future account types (Agent,
Customer, ...) instead of being Admin/User-specific hand-written code: a
declarative module registry, a generator, real Spatie roles/permissions
with correct `guard_name`, and OTP contracts with zero delivery
implementation (still reserved for Phase 3).

### What was found

1. `database/seeders/AdminSeeder.php` **hard-coded a live admin credential**
   (`admin@gmail.com` / `12345678`) directly in source — the exact thing
   CLAUDE.md's quality gate forbids, and something every clone of this
   starter kit would ship with the same known password.
2. Two generator commands, `make:auth-token` and `make:auth-user-token`,
   targeted `app_path('Http/Kernel.php')`, which has not existed since this
   app moved to Laravel 11/12-style bootstrapping. Both were already
   completely broken — not stylistically outdated, but fatal the moment
   either tried to register its middleware alias.
3. Building the permission/policy layer surfaced two design bugs during
   the first test run, both fixed before merging (see "What was changed"):
   checking `$actor->can('manage-admin')` against something only ever
   seeded as a *role* (permissions and roles are different checks in
   Spatie), and seeding the `manage-user` role under the `user` guard when
   its holder (an Admin) can only ever be checked under the `admin` guard.
4. Confirmed (by writing `UserAuthService`'s existing social-login branch
   into this phase's design discussion, not by changing it): logging in via
   `social_id` alone with **no password and no OAuth token verification of
   any kind** is a pre-existing authentication bypass. This phase does not
   fix `UserAuthService` (out of scope — Auth/OTP subsystems for the
   existing two modules stay untouched per every prior phase's constraint),
   but the new generator deliberately refuses to propagate this pattern
   into any new module (see "known limitations").

### What was changed

**OTP extension interfaces (declared only, zero delivery implementation):**
- `app/Contracts/Auth/OtpChannelProvider.php` — contract a Phase 3 channel
  adapter (email/sms/whatsapp/fake-testing-driver) must implement.
- `app/Contracts/Auth/OtpCodeGenerator.php` — contract for generating and
  atomically, single-use verifying a purpose-bound code. Neither interface
  is implemented or resolved from the container anywhere in this phase.

**Declarative module registry:**
- `config/auth_modules.php` — new. `modules.admin` / `modules.user` document
  the Phase 2A modules as-is (`managed_by_generator: false` — hand-written,
  not generator output); `management_guard` (default `admin`) says which
  guard's accounts hold `manage-{module}` roles over every module,
  including their own; `otp.channels` is an empty, Phase-3-fills-this-in
  map.
- `app/Providers/AuthModulesServiceProvider.php` — new, registered in
  `bootstrap/app.php`'s `withProviders()`. Fills in any guard/provider from
  `config/auth_modules.php` that isn't already hand-written in
  `config/auth.php` — additive-only ("fill gaps"), so Phase 2A's `admin`/
  `user` guards are completely untouched (they're already defined), and a
  module the generator creates from scratch needs **no `config/auth.php`
  edit at all**. This is what makes "configurable, not hardcoded" true for
  new modules.

**Permissions (Spatie), correct `guard_name`, policies:**
- `database/seeders/AuthModulesPermissionSeeder.php` — new, idempotent
  (`Role::findOrCreate`). Creates one `manage-{module}` role per configured
  module, all under `management_guard` — never assigns the role to any
  account (that stays a deliberate human decision, never scaffolding's side
  effect, per CLAUDE.md).
- `app/Policies/AdminPolicy.php` / `app/Policies/UserPolicy.php` — new,
  auto-discovered by Laravel's default `App\Models\X` → `App\Policies\XPolicy`
  convention. Typed to the generic `Illuminate\Contracts\Auth\Authenticatable`
  (not `App\Models\Admin` directly) so a non-Admin actor is denied instead
  of TypeErroring if ever checked against either policy. Not wired to any
  route yet — ready for whichever controller ends up managing these
  accounts.
- `database/seeders/AdminSeeder.php` — **security fix** for finding 1: now
  reads `SEED_ADMIN_EMAIL`/`SEED_ADMIN_PASSWORD`/`SEED_ADMIN_NAME` from the
  environment and does nothing (with a loud warning) if they aren't set.
  Never falls back to a hard-coded credential. Still idempotent
  (`updateOrCreate`), still never runs on its own.

**The generator — `app/Console/Commands/CodeBasics/MakeAuthModuleCommand.php`
(new), `php artisan make:auth-module {Name}`:**
- Generates: migration (new table only — `id`, `name`, `email` unique,
  `password`, `is_active` default true, `remember_token`, timestamps,
  `softDeletes()`, plus `prefix_phone`/`phone_number` if `--login` includes
  `phone`, plus `otp_delivery_method` if `--otp`), model (extends
  `BaseAuthModel`: `SoftDeletes`+`is_active` scopes + `HasRoles` for free),
  factory, auth service (extends `BaseAuthService`; `login()` supports
  email and, if requested, phone — both password+`Hash::check`-verified;
  `is_active`/soft-delete rejection is in the generated code from day one,
  not retrofitted like Admin/User needed in Phase 2A), controller, resource,
  facade (`getFacadeAccessor()` returns the service's FQCN directly — no
  `AppServiceProvider` edit needed; Laravel's container auto-resolves a
  parameterless-constructor concrete class without any binding), policy,
  and a routes file.
- **Secure by default**: the routes file is generated fully commented out
  unless `--enable-routes` is passed, exactly matching
  `ScaffoldRouteCommand`'s established convention; the live variant still
  only ever contains `login`/`logout`, with `logout` behind
  `['auth:{guard}', 'guard.identity:{guard}']`.
- **`'social'` as a `--login` identifier is accepted as a config declaration
  only and is never emitted in generated service code** — directly because
  of finding 4 above. A warning is printed when `--login` includes it.
- **`--registration` requires `--otp`**; without it the command errors
  (exit 1) and writes nothing. Even with both, no register/verify-OTP route
  is ever generated — only declared in config — because OTP delivery itself
  is still Phase 3.
- **Idempotent**: a `// AUTH-MODULE: {guard}` marker comment in
  `config/auth_modules.php` is checked before writing anything; if present,
  the command prints "already exists" and exits 0 with zero changes. Each
  individual file is also independently skip-if-exists unless `--force`.
- **`--dry-run`**: prints the exact file list that would be generated/
  modified without writing anything.
- **`--with-seeder`**: idempotently ensures `DatabaseSeeder::run()` calls
  `AuthModulesPermissionSeeder::class` (never executes it — seeding stays a
  separate, explicit `php artisan db:seed` step).
- **`--with-tests`**: generates `tests/Feature/{Name}AuthModuleTest.php`
  covering guard/provider config, inactive-login rejection, a happy-path
  login, and cross-guard token rejection — mirrors
  `AuthGuardFoundationTest`'s patterns. The generated test file explicitly
  notes it needs its migration run against a real dev/test database first;
  this generator never runs migrations itself.

**Retired (fixing finding 2 — "register custom middleware correctly for
Laravel 12"):**
- `app/Console/Commands/CodeAfterMigrate/GenerateAuthTokenMiddlewareCommand.php`
  (`make:auth-token`) and
  `app/Console/Commands/CodeBasics/GenerateAuthUserTokenMiddlewareCommand.php`
  (`make:auth-user-token`) now fail immediately (exit 1, zero file writes)
  with a message pointing at `make:auth-module`, instead of silently
  fataling on a missing `Http/Kernel.php` partway through a multi-step
  write. Their fragile `config/auth.php` regex-injection logic is also
  superseded by `AuthModulesServiceProvider` — rather than patch logic a
  better-designed replacement already covers, they were retired.

### Tests

- `tests/Feature/AuthModulesPermissionsTest.php` (7 tests) — role seeding
  per module under the correct guard, seeder idempotency, policy grants/
  denials for both Admin-self-management and Admin-manages-User, the
  `forceDelete` policy method always denying regardless of role, and a
  `User` actor being denied by the Admin-authored `UserPolicy`.
- `tests/Feature/MakeAuthModuleCommandTest.php` (8 tests) — `--dry-run`
  writes nothing; a real generation produces every expected file (each
  checked for valid PHP via `php -l`), registers `config/auth_modules.php`
  correctly, and includes the phone branch when requested; rerunning is a
  no-op; `--registration` without `--otp` is rejected and writes nothing;
  `'social'` is declared in config but never appears in generated service
  code; routes are commented out by default and live (with
  `guard.identity`) under `--enable-routes`; `--with-seeder` registers
  `AuthModulesPermissionSeeder` in `DatabaseSeeder` exactly once even
  across repeated invocations. Every test uses one disposable module name
  (`DemoAccount`) and `tearDown()` deletes every file it could have created
  and reverts `config/auth_modules.php` and `DatabaseSeeder.php` to their
  pre-test content — verified after the full run: zero stray files, zero
  leftover diffs in either edited file.

### Test results

`php artisan test` → **91 passed (415 assertions)** — the 76 from Phases
0 through 2A plus 15 new Phase 2B tests, zero regressions. `php -l` clean
on every new/modified file. `pint --write` run only on files that were
either wholly new or wholly rewritten this phase (every generated-stub
source file, the two retired commands, the three new test files) — for the
same reason as every prior phase, no pre-existing partially-modified file
was reformatted wholesale.

### Backward-compatibility / breaking-change notes

1. `AdminSeeder` behavior changed: it used to always create
   `admin@gmail.com` with a fixed password; it now does nothing unless
   `SEED_ADMIN_EMAIL`/`SEED_ADMIN_PASSWORD` are set in the environment. Any
   existing workflow relying on that hard-coded login must set those
   env vars (or create the account another way) — there is no
   automatic migration path for this, by design: a known, shared password
   is not something to preserve.
2. `make:auth-token` / `make:auth-user-token` now always exit 1 and do
   nothing. Both were already unconditionally fatal on a missing
   `Http/Kernel.php` before this change, so there is no previously-working
   behavior being removed — only the failure mode changed, from an
   unhandled fatal mid-write to a clean, immediate, side-effect-free error.
3. Everything else in this phase is additive (`config/auth_modules.php` is
   a new file; `AuthModulesServiceProvider` only fills gaps, never
   overrides `config/auth.php`; `AdminPolicy`/`UserPolicy` are new,
   auto-discovered, not-yet-wired-to-any-route files).

### Remaining limitations / explicitly left for Phase 3+

- OTP delivery remains fully unimplemented — the two new contracts in
  `app/Contracts/Auth/` have zero implementations and nothing resolves them
  from the container. `config('auth_modules.otp.channels')` is an empty map
  for Phase 3 to fill in.
- The pre-existing `UserAuthService::login()` social-login branch (finding
  4) — authenticating on a bare `social_id` with no password and no OAuth
  token verification — was **not fixed**; it is out of this phase's
  explicit scope (Auth/OTP subsystems for the two existing modules stay
  untouched, same constraint every prior phase operated under). The
  generator documents why it refuses to replicate this pattern, but the
  existing vulnerability in `UserAuthService` itself still needs a
  dedicated, explicitly-authorized fix.
- `AdminPolicy`/`UserPolicy` (and any generated `{Module}Policy`) are not
  enforced by any route — no controller currently calls
  `$this->authorize(...)` or is wrapped in `can:` middleware against them.
  Wiring real authorization into live endpoints is Phase 2B+/3 follow-up.
- `AuthModulesPermissionSeeder` only seeds roles, never assigns one to any
  account — there is intentionally no "first admin" bootstrap seeded by
  this phase beyond the now-opt-in `AdminSeeder`.
- `make:auth-module`'s generated migration, model, etc. are real,
  idempotent, syntax-checked file writes, but this generator never runs
  `php artisan migrate` itself — a generated module's table does not exist
  until a human runs that migration deliberately.
- Modules generated with `--login=phone` get `prefix_phone`/`phone_number`
  columns but no phone-format validation beyond what `BasicRequest`
  already provides generically — follow the Phase 2A `LoginRequest` pattern
  by hand if stricter validation is needed.

## Phase 3A — Secure Dynamic OTP Core (2026-10-25)

### Scope

Replace the always-fatal, never-actually-working OTP plumbing from
Phase 0/1 with a real, secure, reusable engine: hashed storage, atomic
single-use verification, cooldown, per-code attempt ceilings, rate limits
by subject/destination/IP, and real (but honestly-scoped) delivery
channels — without enabling any new live route.

### What was found

1. `App\Models\Otp` was referenced live by `App\Support\OtpChannelHelper`
   (and, inertly, inside a docblock comment in `BaseAuthModel`) but never
   existed. `App\Mail\OtpMail` was referenced the same way and also never
   existed. Every call through the old `OtpChannelHelper::send()`/`verify()`
   — i.e. every path in `BaseAuthService::register()`/`resendRegisterOtp()`/
   `sendResetOtp()`/`requestPhoneChange()`/`verifyPhoneChangeOtp()` and both
   `UserAuthService`/`AdminAuthService::verifyOtp()` — was already 100%
   fatal before this phase, regardless of any route being wired to it.
2. The old `OtpChannelHelper` design, had it ever run, stored the raw code
   in plaintext (`'otp' => $code` on the `Otp` row), had no resend cooldown,
   no per-code attempt ceiling, no rate limiting of any kind, and used a
   plain boolean `is_used` flag with a read-then-write (not atomic) consume
   step — a real race condition on top of everything else.
3. `App\Facades\Services\Auth\OtpFacade` returned the bare string
   `'OtpService'` as its accessor, which nothing bound in
   `AppServiceProvider` — any call through this facade would have fatally
   errored with "Target class [OtpService] does not exist."
4. A pre-existing naming inconsistency between two call sites that mean the
   same thing: `BaseAuthService::sendResetOtp()`'s admin branch uses
   purpose `'reset'`, while `App\Services\Functional\ResetPasswordService`
   uses `'reset_password'`. Not fixed this phase (see "known limitations").

### What was changed

**New, secure OTP engine:**
- `database/migrations/2026_10_25_140000_create_otps_table.php` — new
  `otps` table. `code_hash` only (no plaintext code column, ever);
  `destination_hash`/`ip_hash` (HMAC, not the raw values) for rate-limiting
  without duplicating PII; `attempts`/`max_attempts` (the ceiling is
  snapshotted at issue time, so a later config change doesn't retroactively
  affect in-flight codes); `consumed_at`/`invalidated_at` as two distinct
  terminal states (successfully used vs. superseded/locked-out).
- `app/Models/Otp.php` — new. Not a lifecycle-CRUD resource (same reasoning
  as `DeviceToken`, Phase 2A) — plain `Model`, no `BaseModel`.
- `app/Services/Auth/OtpService.php` — new. Implements
  `App\Contracts\Auth\OtpCodeGenerator` (declared, unimplemented, in
  Phase 2B). Reusable across any Eloquent model — nothing is hardcoded to
  `User`/`Admin`. Key methods: `issue()`, `verify()` (contract-literal
  `(string $subjectType, $subjectId, ...)` signature), `verifyForModel()`
  (the convenience form every real caller uses), `invalidate()`,
  `cleanupExpired()`. Security properties enforced inside the service, not
  left to callers:
  - `random_int()`-based generation (CSPRNG), length from
    `config('otp.code_length')`.
  - Only `Hash::make()`/`Hash::check()` ever touches the code — the same
    mechanism this app already uses for passwords.
  - Single-use, race-safe consumption: the verify flow runs inside
    `DB::transaction()` with `lockForUpdate()` on the selected row, and the
    actual consume step is a conditional
    `->whereNull('consumed_at')->update(...)` whose affected-row count is
    checked — a second verify() for an already-consumed code always fails.
  - A found-and-fixed ordering bug from this phase's own first draft: in
    `issue()`, channel delivery now happens *inside* the same transaction
    as invalidating the prior code and creating the new row. The first
    draft delivered *after* committing — meaning an unconfigured/throwing
    channel (e.g. `sms`) left a committed-but-never-delivered `Otp` row
    behind, which then blocked a legitimate retry via the resend cooldown.
    Caught by `test_sms_and_whatsapp_channels_throw_instead_of_pretending_to_deliver`
    during this phase's own test-writing, before merging.
  - Per-code attempt ceiling (`config('otp.max_attempts')`) independent of
    a broader per-actor/IP verify-request rate limit
    (`config('otp.max_verifies')` per `config('otp.verify_window_minutes')`)
    — the second one stops someone working around the first by just
    requesting fresh codes repeatedly.
  - Resend cooldown + send-rate-limit (`config('otp.max_sends')` per
    `config('otp.send_window_minutes')`), checked independently by
    (subject, purpose), by hashed destination (catches the same email/phone
    being hit across *different* accounts), and by IP when provided —
    via Laravel's cache-backed `RateLimiter`, not by scanning the `otps`
    table.
  - Every query scoped by `(verifiable_type, verifiable_id, purpose)` —
    cross-account and cross-purpose isolation is structural, not
    best-effort.
- `config/otp.php` — new. All the tuning knobs above, plus the
  channel-name => implementation-class map.
- `app/Exceptions/Otp/OtpException.php` — new. Carries its own HTTP status
  (429 for cooldown/rate-limit, 422/500 for misconfiguration); handled by a
  new branch in `GeneralTrait::handleException()`, same pattern as the
  existing `ValidationException`/`AuthenticationException`/`QueryException`
  branches — preserves the standard envelope with zero new response shapes.

**Delivery channels (`app/Services/Auth/Otp/Channels/`), all implementing
`App\Contracts\Auth\OtpChannelProvider`:**
- `FakeOtpChannel` — the default. Captures delivered codes in-memory for
  test assertions *only* in the `testing` environment; everywhere else it
  logs that a delivery happened without ever logging the code itself.
- `EmailOtpChannel` + `app/Mail/OtpMail.php` (new) — uses Laravel's own
  `Mail` facade truthfully. With this project's `MAIL_MAILER=log`/`array`
  (.env / phpunit.xml), "sending" writes to the log or an in-memory array —
  no real SMTP/provider integration is implemented or claimed.
- `SmsOtpChannel` / `WhatsAppOtpChannel` — satisfy the contract shape but
  **throw a clear `RuntimeException`** on `deliver()` rather than silently
  succeeding. No SMS gateway or WhatsApp Business API credentials exist
  anywhere in this project, and per explicit instruction this phase does
  not invent any — pretending delivery succeeded would be worse than
  refusing outright.

**Fixed, not rewritten (backward-compatible):**
- `app/Support/OtpChannelHelper.php` — rewritten internally to delegate to
  `OtpService`, but `send()`/`verify()` keep their *exact* original
  signatures. Every existing caller (`BaseAuthService`,
  `ResetPasswordService`, both `verifyOtp()` overrides) needed zero
  changes — they were calling a 100% broken method before; now they call a
  working one.
- `app/Facades/Services/Auth/OtpFacade.php` — fixed finding 3: now returns
  `OtpService::class` directly (same no-`AppServiceProvider`-binding-needed
  pattern established in Phase 2B's generated facades).
- `config/auth_modules.php` — `otp.channels` (the Phase 2B placeholder,
  previously empty) now points at the four real channel classes. Per-module
  `otp.enabled` flipped `true` for both `admin` and `user` (the engine
  genuinely works for both now) — this is a config *declaration* only; see
  "routes" below for why nothing is newly reachable.
- `app/Console/Commands/CleanupExpiredOtpsCommand.php` — new,
  `otp:cleanup-expired`, mirrors the existing `tokens:cleanup-expired`
  pattern. Hard-deletes (OTPs are transient secrets, not business records —
  no soft-delete/restore semantics apply) rows expired more than a day ago.

**Routes: nothing newly enabled.** No file under `routes/` was touched.
`routes/api/v1/user.php` stays fully commented out; `routes/api/v1/admin.php`
still only has `login`/`logout`/`ping` live. `BaseAuthService::sendResetOtp()`/
`register()`/etc. are reachable only through this phase's own tests (which
dispatch to temporary, test-local routes, same pattern Phase 2A/2B
established) — not through any route a real client could hit.

### Tests

`tests/Feature/OtpServiceTest.php` (21 tests) — hashed-not-plaintext storage,
correct-code success, wrong-code failure incrementing attempts, expiry,
replay (a consumed code's second verify attempt, same still-correct code),
max-attempts lockout (including that the *correct* code fails once locked),
cooldown blocking an immediate resend and succeeding after the window,
send-rate-limit by subject, send-rate-limit by destination (catching abuse
across two *different* accounts sharing a destination), verify-rate-limit
across multiple codes, cross-account isolation, cross-purpose isolation,
explicit invalidation, `cleanupExpired()` only touching long-expired rows,
the `OtpCodeGenerator` contract's literal signature, the email channel via
`Mail::fake()`, SMS/WhatsApp throwing, an unknown channel being rejected,
the `OtpChannelHelper` backward-compatible shim, and
`BaseAuthService::sendResetOtp()` working end-to-end for Admin for the
first time. `Cache::flush()` runs in `setUp()` — the `array` cache driver
backing `RateLimiter` in tests is not reset by `RefreshDatabase`, and
`RefreshDatabase` resets auto-increment ids per test, so without this a
rate-limit counter could leak from one test into the next test that
happens to reuse the same subject id.

**Note on "concurrent verification":** PHPUnit tests run single-threaded,
so true multi-process concurrency isn't exercised. What's tested instead —
the standard, accepted way to validate this in a synchronous test suite —
is sequential double-verification of the same code (the replay test): this
proves the single-use guarantee (the property concurrency protection
actually exists to protect) holds, via the same atomic conditional-UPDATE
path a real race would hit.

### Test results

`php artisan test` → **112 passed (458 assertions)** — the 91 from Phases
0 through 2B plus 21 new Phase 3A tests, zero regressions. `php -l` clean
on every new/modified file. `pint --write` run only on wholly-new files
(every file under `app/Services/Auth/`, `app/Exceptions/Otp/`,
`app/Models/Otp.php`, `config/otp.php`, the channel classes,
`CleanupExpiredOtpsCommand.php`, the new test file) — `OtpChannelHelper.php`
and `OtpFacade.php` were small, targeted edits to pre-existing files and
were not reformatted wholesale, same precedent as every prior phase.

### Backward-compatibility / breaking-change notes

1. `OtpChannelHelper::send()`/`verify()` signatures are unchanged; behavior
   changes from "always fatal" to "actually works" — there is no previously
   working behavior being altered.
2. `OtpFacade` changes from "always fatal" to "works" the same way.
3. `config('auth_modules.modules.{admin,user}.otp.enabled')` flipped from
   `false` to `true` — a declarative-only change (nothing reads this flag
   to gate route registration today), documenting that the engine is
   genuinely ready for both modules now.
4. Everything else is additive: new table, new model, new service, new
   config file, new exception type, new channel classes, new Mailable, new
   console command.

### Remaining limitations / explicitly left for Phase 3B+

- The `'reset'` vs `'reset_password'` purpose-string inconsistency (finding
  4) between `BaseAuthService` and `ResetPasswordService` was **not**
  reconciled — fixing it means changing one of those two already-tested
  call sites' behavior, out of this phase's scope (OTP *engine* security,
  not those services' business logic).
- `login_challenge` and `email_change` are supported *generically* by
  `OtpService` (it is purpose-string-agnostic — nothing special-cases
  these two) but no existing service method actually issues/verifies an
  OTP for either purpose yet. Wiring an actual 2FA step into
  `UserAuthService`/`AdminAuthService::login()`, or an email-change flow
  analogous to the existing `requestPhoneChange()`/`verifyPhoneChangeOtp()`,
  would change tested Phase 2A login/profile behavior and is left for
  whenever that feature is actually wanted.
- `make:auth-module`'s `--otp` flag still only adds an `otp_delivery_method`
  column and declares `otp.enabled`/`purposes` in config — it does not wire
  a generated module's `AuthService` to actually call `OtpService`
  (`verifyOtp()` still returns a 501 stub, same as Phase 2B). Teaching the
  generator to optionally wire real OTP calls is natural Phase 3B+ work,
  now that the engine genuinely exists.
- `SmsOtpChannel`/`WhatsAppOtpChannel` remain non-functional by design — no
  gateway credentials exist in this project. Making either real requires a
  human to choose a provider, supply real credentials via the environment,
  and implement `deliver()` against that provider's actual API.
- No route anywhere calls any OTP-dependent method — by design, per
  explicit instruction. `otp:cleanup-expired` exists but is not scheduled
  anywhere (no cron/`Schedule::command()` entry) — running it is a manual,
  deliberate step.

## Phase 3B — Complete OTP and Authentication Integration (2026-10-25)

### Scope

Make the Phase 3A OTP engine actually power every flow it was built for —
registration, password reset, phone/email change, an optional login
challenge — close the critical reset-password vulnerability Phase 3A's own
engine made newly fixable, disable the long-flagged bare-social_id login
bypass, fix a real transactional-consistency bug in `OtpService::issue()`
found while writing this phase's own tests, and make `make:auth-module --otp`
generate real flows instead of a 501 stub. Enable only what is actually
tested end-to-end.

### What was found

1. **Critical, pre-existing, unauthenticated account takeover**:
   `BaseAuthService::resetPassword()` updated any account's password from
   nothing but a client-supplied `actor` + `email`/`phone` + new password —
   with **no check that OTP verification had ever happened**. Anyone could
   POST `{actor:"admin", email:"victim@example.com", password:"anything"}`
   and it would just work. Compounding this, `BaseAuthService::verifyResetOtp()`
   was **fully commented out** (dead code) — the one FatherAuthController
   endpoint meant to gate this was already broken ("call to undefined
   method") regardless.
2. `UserAuthService::login()`'s social branch authenticated on a bare
   `social_id` with no password and no OAuth token verification at all —
   flagged in Phase 2B and Phase 3A, fixed this phase.
3. The `'reset'` vs `'reset_password'` purpose-string inconsistency flagged
   in Phase 3A, now fixed.
4. `BaseAuthService::register()` was unreachable on every call:
   `RegisterRequest` validated `first_name`/`gender`/`country`/`city`/
   `birth_date`/`phone` — none of which exist as columns on `users` or in
   `User::$fillable` — and never collected `name` at all, even though
   `users.name` is a required, NOT NULL column. `User::create($in)` was
   guaranteed to fatal on a database constraint violation every time.
5. **Found while writing this phase's own tests, fixed before merging**: a
   transactional-consistency bug in `OtpService::issue()` — Phase 3A
   delivered the OTP code (a real external side effect: email/SMS/WhatsApp)
   *inside* the same DB transaction that created the row. An unconfigured
   channel throwing left a committed-but-undelivered row behind, which then
   blocked a legitimate retry via the resend cooldown.
6. `ResendRegisterOtpRequest` and `RegisterVerifyRequest` both hardcoded
   `exists:users,id` on their `id` field — breaking the shared, guard-agnostic
   verify/resend endpoints for any non-User module (Admin, or anything
   `make:auth-module` generates). Found while writing this phase's
   generator test.
7. **Found and fixed mid-implementation, in my own first draft**:
   `sendResetOtp()`'s rewrite silently dropped the ability to pass an
   explicit `channel` in the request, always falling back to the account's
   stored `otp_delivery_method` — caught by my own
   `test_a_reset_grant_cannot_be_reused` test failing for an unrelated
   reason (no OTP was ever actually sent on the configured channel).

### What was changed

**Transactional-outbox fix for OTP delivery:**
- `database/migrations/2026_10_25_150000_add_delivery_tracking_to_otps_table.php` —
  additive `delivered_at`/`delivery_failed_at`/`last_delivery_error` columns.
- `app/Jobs/Otp/DeliverOtpNotificationJob.php` — new, `ShouldQueue`.
  `OtpService::issue()` now dispatches this via `->afterCommit()` from
  *inside* the same transaction that creates the `Otp` row, instead of
  calling the channel directly. With a real queue (database/redis), the
  actual send is deferred until the transaction durably commits; with the
  `sync` driver (this project's test/local default), it still only runs
  once the commit callback fires — never before, and never on rollback.
  The raw code is passed `Crypt::encryptString()`-ed, never in the clear,
  so even a durable `jobs` table row holds ciphertext, not the code — "never
  expose OTP codes" holds even in that window. Failure is not swallowed: a
  channel throwing (e.g. `SmsOtpChannel`) records the failure on the `Otp`
  row and re-throws, so the queue's normal failed-job handling applies —
  fails closed, never pretends success.
- `app/Services/Auth/OtpService.php` — `issue()` rewritten accordingly;
  channel name is still validated *before* any state change (fail fast on
  a typo'd channel, same as Phase 3A).

**Critical security fix — single-use, short-lived, account-bound password
reset grants:**
- `database/migrations/2026_10_25_150100_create_password_reset_grants_table.php` —
  new `password_reset_grants` table.
- `app/Models/PasswordResetGrant.php`, `app/Services/Auth/PasswordResetGrantService.php` —
  new. Same atomicity pattern as `OtpService::verifyForModel()`: a locked
  transaction, conditional UPDATE, affected-row-count check. `issue()`
  returns a raw opaque token (only its hash is stored); `consume()` is
  scoped by `(verifiable_type, verifiable_id)` so a grant for one account
  can never consume against another, regardless of token value. Not a
  broad Sanctum personal access token (the separate, still-unrouted
  `ResetPasswordService` used one of those — this is deliberately narrower:
  it can do exactly one thing, once).
- `BaseAuthService::verifyResetOtp()` — un-commented and properly
  implemented for the first time: on successful OTP verification, issues a
  grant and returns it as `reset_token`.
- `BaseAuthService::resetPassword()` — **now requires and atomically
  consumes that grant** before touching the password. No `reset_token` (or
  an invalid/expired/already-used one) → rejected, password untouched.
  `app/Http/Requests/Model/Auth/ResetPassword/ResetPasswordRequest.php` —
  added the required `reset_token` field (plus `actor`/`email`/phone
  fields needed to identify the account).

**Social login disabled:**
- `UserAuthService::login()` — the bare-`social_id` branch now rejects
  immediately instead of authenticating. `social_id` is still stored/
  declared; it simply can never authenticate on its own until backed by
  real, verified OAuth identity (Phase 3C+ work, not attempted here).

**Registration fixed and integrated:**
- `app/Http/Requests/Model/Auth/RegisterRequest.php` — rewritten to match
  the actual `users` schema (`name` required; `email` or
  `prefix_phone`+`phone_number`; `password`+confirmed;
  `otp_delivery_method` validated against `config('otp.channels')` directly
  instead of a hardcoded, drift-prone list — same fix applied to
  `SendResetOtpRequest`'s `channel` field).
- `BaseAuthService::register()` — fixed the phone-field bug (was checking
  `$in['phone']`, a field that was never collected under the corrected
  schema); duplicate-account handling (`checkExistingAccountStatus()`)
  pulled out of `UserAuthService` into `BaseAuthService` so it works for
  any module, not just User (dead code `UserAuthService::checkUserStatus()`
  removed).
- `app/Http/Requests/Model/Auth/RegisterVerifyRequest.php`,
  `ResendRegisterOtpRequest.php` — dropped the hardcoded `exists:users,id`
  rule (finding 6); the service layer's own `firstOrFail()`/`findOrFail()`
  already 404s correctly for a genuinely missing id, for whichever
  module's table that actually is.

**Per-module, per-action configurable OTP (not a flat purpose list):**
- `config/auth_modules.php` — each module's `otp` key is now
  `{enabled, actions: [...]}` where `actions` is a subset of `register,
  resend_register, reset_password, phone_change, email_change,
  login_challenge` — not the Phase 2B/3A flat `purposes` list. A new
  `require_login_challenge` key per module (both default `false`).
  `BaseAuthService::otpActionAllowed(string $action)` checks both before
  letting any OTP-backed method proceed, throwing a typed `OtpException`
  (422) otherwise — Admin can expose `reset_password` alone without
  `register` ever being reachable, by configuration, not scattered
  per-guard `if` statements.

**Email change (new — mirrors the existing phone-change pattern):**
- `BaseAuthService::requestEmailChange()`/`verifyEmailChangeOtp()` — new,
  purpose `email_change`, checks new-email uniqueness before applying.
- `FatherAuthController::requestEmailChange()`/`verifyEmailChangeOtp()` —
  new controller actions.

**Login challenge (new, optional, off by default):**
- `BaseAuthService::completeLogin()` — new shared "final step" of login():
  issues a token immediately, or — if `require_login_challenge` is set for
  this module — issues a `login_challenge` OTP instead and withholds the
  token until `verifyLoginChallenge()` confirms it. Both
  `UserAuthService`/`AdminAuthService::login()` now call this instead of
  duplicating token issuance.

**Routes — enabled only what is tested end-to-end:**
- `routes/api/v1/user.php` — previously the *entire* block was commented
  out, including login itself (not because login was insecure, but because
  the supporting guard/OTP infrastructure didn't exist). Now live:
  register, register/resend, register/verify, login, password/send-otp,
  password/verify-otp, password/reset, and (behind `auth:user`+
  `guard.identity:user`) request/verify phone-change, request/verify
  email-change, update-language, logout. Still deliberately NOT enabled:
  `/device-token` and `/me/profile` (not covered by this phase's tests) and
  anything `login_challenge`-related (off by default, inert).
- `routes/api/v1/admin.php` — added password/send-otp, password/verify-otp,
  password/reset alongside the already-live login/logout/ping.

**Generator — `make:auth-module --otp` now generates real flows:**
- New `--otp-actions=` option (comma subset of the six known actions;
  sensible default when `--otp` is passed alone). Validates `phone_change`
  requires `phone` in `--login`.
- `config/auth_modules.php`'s injected entry now writes the real
  `{enabled, actions}` shape (not the old flat `purposes` list) plus
  `require_login_challenge: false`.
- The generated service's `login()` now calls `completeLogin()` (gets
  login-challenge support for free); `register()`/`resendRegisterOtp()`/
  `sendResetOtp()`/`verifyResetOtp()`/`resetPassword()`/
  `requestPhoneChange()`/`requestEmailChange()` are all *inherited* from
  `BaseAuthService` — genuinely working, gated by the config this
  generator writes, not placeholders. Only `verifyOtp()` still needs a
  per-module body, and it's now a real implementation (mirroring
  `UserAuthService::verifyOtp()`) when `register` is enabled — a 501 stub
  only when `--otp` is omitted entirely.
- Migration/model stubs now add exactly the columns each enabled action
  needs (`status`/`activated_at` for `register`; `phone_status`/
  `phone_verified_at` for `phone_change`; `email_status` for
  `email_change`) — not a fixed, one-size-fits-all column set.
- Routes stubs (disabled-by-default and `--enable-routes` live variants)
  now include every enabled action's routes, not just login/logout.

### Tests

- `tests/Feature/PasswordResetGrantServiceTest.php` (7 tests) — issue/
  consume, double-consume rejection, account-bound isolation (including
  across different model *types* sharing the same id), expiry, reissue
  invalidating a prior grant, wrong-token rejection.
- `tests/Feature/AuthFlowsIntegrationTest.php` (15 tests) — registration
  end-to-end (activation + token on verify), duplicate-registration
  resend-not-duplicate, resend, password reset end-to-end, **resetting a
  password with no prior OTP verification is rejected** (the vulnerability
  fix, directly proven), a reset grant cannot be reused, admin password
  reset, phone change end-to-end, email change end-to-end (including a
  taken-email rejection), email change disabled for Admin (per-action
  gating proven), login challenge withholding and then issuing a token,
  the per-module gate rejecting `register` for Admin, and SMS-channel
  registration failing closed (500, not a lying 200).
- `tests/Feature/AuthRoutesLiveTest.php` (6 tests) — the same core flows
  exercised through the *actual* `routes/api/v1/{user,admin}.php` routes
  (not temporary test-local ones), plus a cross-guard rejection proving
  `guard.identity` still holds on every newly-enabled authenticated route.
- `tests/Feature/MakeAuthModuleCommandTest.php` — one new test generates a
  module with `--otp`, runs its migration against the shared sqlite
  `:memory:` test connection (explicitly, since this test class has no
  `RefreshDatabase` and that trait's "migrate once" caching is a
  process-wide static flag that would never pick up a migration file
  created mid-suite), and proves register → verify → login all genuinely
  work — not 501s. `tearDown()` drops the generated table and its
  migration-tracking row afterward so no later test in the suite sees it.

### Test results

`php artisan test` → **139 passed (585 assertions)** — the 132 from
Phases 0 through 3A plus 7 net new tests (several more were added; a few
early drafts of tests in this phase caught real bugs — listed above — and
were fixed before the final count). Zero regressions, confirmed by
multiple full-suite reruns for stability. `php -l` clean on every new/
modified file. `pint --write` run only on wholly-new files (both new
migrations, `PasswordResetGrant.php`, `PasswordResetGrantService.php`,
`DeliverOtpNotificationJob.php`, the three new test files,
`routes/api/v1/user.php` which was wholly rewritten, and
`MakeAuthModuleCommand.php` which has no pre-existing unrelated content to
disturb) — every edited pre-existing file (`BaseAuthService.php`,
`OtpService.php`, the `*Request.php` classes, etc.) had only its specific,
targeted lines changed, same precedent as every prior phase.

### Backward-compatibility / breaking-change notes

1. `resetPassword()` now requires `reset_token` — any caller relying on
   the old (critically insecure) no-verification behavior must adopt the
   verify → grant → reset flow. There is no migration path for this, by
   design: the old behavior was a severe vulnerability, not a feature.
2. `UserAuthService::login()`'s social branch now always rejects instead
   of authenticating — same reasoning; no prior "working" behavior is
   being removed, since it was a bypass.
3. `config('auth_modules.modules.*.otp.purposes')` (Phase 3A's flat list)
   is replaced by `otp.actions` (Phase 3B's per-action list) for Admin and
   User. Anything reading the old key directly (nothing in this codebase
   does) would need updating.
4. `AdminAuthService::login()`'s response now includes a `token_id` key it
   didn't before (via `completeLogin()`, matching `UserAuthService`'s
   existing shape) — additive, not breaking.
5. `RegisterRequest`'s accepted fields changed completely (finding 4) —
   any caller sending the old, schema-mismatched fields must switch to
   `name`/`email`/`prefix_phone`+`phone_number`/`password`/
   `otp_delivery_method`. There is no prior *working* behavior being
   changed here either — every old-shape call was already guaranteed to
   fatal.
6. `make:auth-module`'s generated `config/auth_modules.php` entry shape
   changed (`purposes` → `otp.actions` + `require_login_challenge`) —
   only affects modules generated after this phase; Admin/User's own
   entries were migrated to the new shape directly.

### Remaining limitations / risks / explicitly left for Phase 3C+

- **Real OAuth verification for social login is not implemented** — only
  disabled. Building it (provider token exchange, identity verification,
  binding to `social_id`) is substantial, separate work.
- **`SmsOtpChannel`/`WhatsAppOtpChannel` remain non-functional** — no
  gateway credentials exist anywhere in this project, and none were
  invented. A deployer must implement `deliver()` against a real provider
  before using either channel; until then, choosing `sms`/`whatsapp` fails
  closed (visibly, via the queued job's failure) rather than silently.
- **The separate, unrouted `App\Services\Functional\ResetPasswordService`
  was not touched.** It is dead code (no facade, no controller, no route —
  confirmed via grep) with its own, different reset-token design (a broad
  Sanctum token). The real, now-fixed reset flow lives in
  `BaseAuthService`/`FatherAuthController`, which is what's actually
  routed. Reconciling or removing the orphaned service is unaddressed.
- **`require_login_challenge` has no live route for `verifyLoginChallenge()`
  yet** — the feature exists and is tested (via a temporary route, with
  the config flag force-enabled), but since it defaults to `false` for
  both modules, nothing is reachable today. Turning it on for a real
  deployment requires adding that route deliberately, alongside the config
  change.
- **Device token (`/device-token`) and profile (`/me/profile`) routes
  remain disabled** for User — not covered by this phase's tests, so left
  alone per "enable only fully implemented and tested routes."
- **`CombinedAuthService` was not touched or integrated** — still separate,
  unrouted code from before Phase 2A.
- **No rate limiting or monitoring dashboard for OTP delivery failures** —
  `delivery_failed_at`/`last_delivery_error` are recorded on the `Otp` row
  for observability, but nothing currently surfaces or alerts on them; an
  operator would need to query the table directly.
- **Risk**: a generated module's `verifyOtp()` for `phone_change`/
  `email_change` delegates correctly, but if `--otp-actions` is customized
  to include `phone_change` without `--login` including `phone`, the
  generator now explicitly refuses (checked) — confirmed via
  `php -l`-level review, not a dedicated test this phase; worth a direct
  test if the generator sees further changes.

## Phase 3C — Authentication Integration Hardening (2026-10-25)

### Scope

Audit and harden what Phase 3B built: close the duplicate-delivery/retry
gaps in the OTP engine, make the two still-unrouted legacy auth services
safe instead of leaving them as latent traps, add enumeration resistance
and real destination validation, add the HTTP-layer rate limiting that
never existed, wire a real login-challenge route and a real policy-gated
admin endpoint, and fix a real bug this phase's own rate-limiting work
exposed in the global exception handler.

### What was found

1. **`OtpService::issue()`'s delivery job had no bounded retries and no
   idempotency guard.** A real queue driver retries a failed job by
   default; without `$tries`/`$backoff`, a permanently misconfigured
   channel would be retried indefinitely, and nothing stopped the job from
   re-delivering a code that was already confirmed delivered, already
   consumed, or already superseded by a newer one.
2. **Every login/register/reset/phone/email-change route had zero
   HTTP-layer rate limiting.** `OtpService`'s own limits only apply once a
   request reaches the OTP engine — `login()` (password brute-forcing)
   never touches it at all, and register/reset endpoints had no limiter
   independent of the engine's.
3. **Found while adding rate limiting, a real bug**: `GeneralTrait::handleException()`
   had no branch for `ThrottleRequestsException` — a 429 from the new
   `throttle:` middleware fell through to the generic catch-all and came
   back as a misleading 500. (A broader fix catching every
   `HttpExceptionInterface` was tried first and reverted — it also
   intercepted routing 404s, which ~30 pre-existing Phase 0/0.1/0.2 tests
   explicitly assert come back as 500 via that same catch-all; fixed
   narrowly to just `ThrottleRequestsException` instead.)
4. **`BaseAuthService::sendResetOtp()` leaked account existence** — a
   non-existent email/phone got a distinct "account not found" (400) while
   an existing one got "otp_sent" (200), letting any anonymous caller probe
   arbitrary addresses.
5. **`requestPhoneChange()`/`verifyPhoneChangeOtp()`/`requestEmailChange()`/
   `verifyEmailChangeOtp()` type-hinted the plain `Illuminate\Http\Request`** —
   zero validation on `otp`, `channel`, `prefix_phone`, `phone_number`, or
   the new `email`, so malformed input reached the service layer unchecked.
6. **The two still-unrouted legacy services were real latent traps**:
   `ResetPasswordService::resetPassword()` authenticated via `$request->user()`
   (any valid bearer token, not specifically its own reset token, and with
   no `is_active` check) and `verifyResetOtp()` issued a broad, default-
   abilities Sanctum token — the exact pattern Phase 3B fixed away from in
   the real flow. `CombinedAuthService::login()` issued tokens to
   deactivated accounts and returned errors outside the app's standard
   envelope.

### What was changed

**OTP delivery hardening (`app/Jobs/Otp/DeliverOtpNotificationJob.php`):**
- Bounded `$tries = 3` / `$backoff = 10` instead of Laravel's unbounded
  default.
- Skips delivery entirely if the code is already `delivered_at` (no
  re-send) or no longer `isUsable()` (consumed/invalidated/expired by the
  time the job actually runs).
- `failed()` hook records a distinct terminal failure state once all
  retries are exhausted; failure-recording itself is wrapped so a DB
  hiccup while recording a failure can't mask the real error.
- Documented, not "fixed": the one genuinely unavoidable race (deliver()
  succeeds, then recording that fact fails before committing) can
  redeliver the same still-valid code once — accepted as a UX nuisance,
  not a security issue, since the code is single-use at *verify* time
  regardless of how many times it was sent.

**Enumeration resistance:**
- `BaseAuthService::sendResetOtp()` and `ResetPasswordService::sendResetOtp()` —
  both now return the identical response whether or not the account
  exists; a code is only genuinely sent when it does. A rate-limit/cooldown
  `OtpException` (429) still propagates either way — it fires independent
  of whether this specific call found an account.

**Destination validation (new FormRequests, replacing plain `Request`):**
- `RequestPhoneChangeRequest`, `VerifyPhoneChangeRequest` (prefix/number
  regex-validated, E.164-ish), `RequestEmailChangeRequest`,
  `VerifyEmailChangeRequest` — wired into
  `FatherAuthController::requestPhoneChange()`/`verifyPhoneChangeOtp()`/
  `requestEmailChange()`/`verifyEmailChangeOtp()`.

**HTTP-layer rate limiting (new):**
- `AppServiceProvider::registerAuthRateLimiters()` — two named limiters,
  `auth-login` (10/minute) and `auth-otp-endpoint` (5/minute), both keyed
  by `ip|identifier` (not IP alone, so one attacker can't exhaust a single
  victim's budget from many IPs, and one noisy IP doesn't block everyone
  behind shared NAT).
- Applied via `throttle:` middleware to every route in
  `routes/api/v1/{user,admin}.php` that wasn't already behind one.
- `GeneralTrait::handleException()` — added the narrow
  `ThrottleRequestsException` branch (finding 3) so 429s actually come
  back as 429, in the standard envelope.

**Legacy service hardening (still unrouted; defense in depth):**
- `ResetPasswordService` — `verifyResetOtp()` now issues a
  `PasswordResetGrantService` grant instead of a broad Sanctum token;
  `resetPassword()` now requires and atomically consumes it;
  `sendResetOtp()` is enumeration-resistant, matching the real flow's
  fixes exactly.
- `CombinedAuthService` — `login()` now checks `is_active` before issuing
  a token for either branch, tags tokens with a guard ability, and returns
  errors through the standard `{data, status, error, statusCode}` envelope
  instead of a raw, differently-shaped JSON response.

**Login challenge — real, tested route:**
- `routes/api/v1/{user,admin}.php` — `login-challenge/verify` registered
  unconditionally (safe because `BaseAuthService::otpActionAllowed('login_challenge')`
  gates it at the service layer regardless of route presence — not in a
  module's `otp.actions`, the default, means the route exists but always
  422s). Proven both ways: default-422, and working end-to-end once a test
  turns the config flag on.

**Policy enforcement — first real use of `UserPolicy` (dead since Phase 2B):**
- `app/Http/Controllers/Admin/UserManagementController.php` — new.
  `all()`/`activate()`/`deactivate()`, each calling `Gate::authorize()`
  against `UserPolicy` before delegating to the existing `UserService`.
- `routes/api/v1/admin.php` — `users/all`, `users/activate`,
  `users/deactivate`, behind `auth:admin` + `guard.identity:admin`.
- **No role is granted by anything automatic** — `AuthModulesPermissionSeeder`
  only ever creates the `manage-user` role, never assigns it (confirmed by
  a dedicated test). An admin must be granted it by a deliberate, separate
  action outside any generator/seeder/migration in this codebase.

### Tests

New/extended this phase: `tests/Feature/OtpServiceTest.php` (+3: job
idempotency, skip-if-unusable, `failed()` terminal state),
`tests/Feature/AuthFlowsIntegrationTest.php` (+1: enumeration resistance),
`tests/Feature/AuthRoutesLiveTest.php` (+6: destination-validation
rejection ×2, login rate limit, per-identifier OTP rate limit, login
challenge default-422 and working-once-enabled), new
`tests/Feature/AdminUserManagementPolicyTest.php` (5: denied without the
role, role never auto-assigned, works once explicitly assigned, a User
token can't reach it, holding a *different* role doesn't grant access),
new `tests/Feature/UnroutedServicesHardeningTest.php` (7: both legacy
services' hardening, directly), and `tests/Feature/MakeAuthModuleCommandTest.php`'s
existing generator end-to-end test extended to also exercise the generated
module's inherited `sendResetOtp()`/`verifyResetOtp()`/`resetPassword()`
flow, not just register/verify/login.

### Test results

`php artisan test` → **161 passed (659 assertions)** — the 139 from
Phases 0 through 3B plus 22 new/extended this phase, zero regressions,
confirmed by repeated full-suite reruns for stability. `php -l` clean on
every file. `pint --write` run only on wholly-new files and the two
fully-rewritten legacy services (`ResetPasswordService.php`,
`CombinedAuthService.php`, both replaced via `Write` with no pre-existing
content left to disturb) — every edited pre-existing file had only its
specific, targeted lines changed.

### Backward-compatibility / breaking-change notes

1. `sendResetOtp()` (both `BaseAuthService` and `ResetPasswordService`)
   now always returns `otp_sent_if_account_exists` instead of distinctly
   erroring for a non-existent account — a deliberate security fix; any
   caller relying on distinguishing the two cases from the response must
   stop (that distinction was the vulnerability).
2. `requestPhoneChange`/`verifyPhoneChangeOtp`/`requestEmailChange`/
   `verifyEmailChangeOtp` now validate their input — a malformed phone
   number or missing OTP that previously reached the service layer
   unchecked now 400s at the request layer instead. No legitimate,
   well-formed caller is affected.
3. Every auth route now rate-limits — a client issuing more than 10
   logins/minute or 5 OTP-endpoint calls/minute (per ip+identifier) now
   gets 429 instead of being processed. Legitimate traffic patterns are
   expected to stay well under this.
4. `ResetPasswordService::verifyResetOtp()`'s response shape changed
   (`token` → `reset_token`, and it is no longer a bearer token at all) —
   breaking IF anything called this unrouted class directly, which nothing
   in this codebase does.
5. `CombinedAuthService::login()`'s error response shape changed (from a
   bespoke `{message}` 422 to the standard envelope) — same caveat, nothing
   in this codebase calls it.
6. `GeneralTrait::handleException()` now returns 429 (not 500) for a
   `ThrottleRequestsException` specifically — an additive, narrow fix;
   every other exception type's status code is unchanged.

### Remaining limitations / deployment requirements for Phase 3D+

- **Timing-based enumeration is not addressed** — `sendResetOtp()`'s
  response content no longer leaks account existence, but an attacker
  measuring response latency, or exhausting the per-destination OTP-engine
  rate limit (which only increments for an account that genuinely exists,
  since no code is issued for one that doesn't), could still infer
  existence with enough samples. Closing this fully would need a
  deliberate decoy-work/timing-normalization design, judged
  disproportionate to what this starter kit needs by default — documented
  here as an accepted residual risk, not silently ignored.
- **`register()`'s existing-account response (`need_login` vs
  `need_verify`) still distinguishes an existing account from a new one** —
  inherent to the registration UX (telling an existing user "you already
  have an account, log in instead" is the point), not changed this phase.
- **SMS/WhatsApp/OAuth remain exactly as fail-closed as Phase 3A/3B left
  them** — no credentials exist anywhere in this project, none were
  invented, and nothing in this phase changed that. A real deployment
  needs a human to choose providers and supply real credentials via the
  environment before any of `sms`/`whatsapp`/social login can work; until
  then they fail loudly (SMS/WhatsApp) or are rejected outright (social).
- **The rate limiters are in-process/cache-backed** (`RateLimiter` via
  whatever `CACHE_STORE` is configured — `database` by default per
  `.env`). In a multi-server deployment without a shared cache (e.g. Redis
  or a shared DB), each server enforces its own independent limit —
  effectively multiplying the real ceiling by server count. A production
  deployment behind a load balancer should configure a shared `CACHE_STORE`.
- **`UserManagementController` is deliberately minimal** (list/activate/
  deactivate only) — create/update/delete/restore are not exposed; a real
  deployment wanting full admin-manages-user CRUD needs to extend this
  deliberately, following the same `Gate::authorize()` pattern.
- **No audit log** of who activated/deactivated which account, or who was
  granted `manage-user`/`manage-admin` and when — an operator relying on
  this for compliance needs to add one.
- **`otp:cleanup-expired` and queue workers are still not scheduled
  anywhere** — a real deployment needs its own cron entry for cleanup and
  a running `php artisan queue:work` (or equivalent) process for the OTP
  delivery job to ever actually execute outside the `sync` driver's
  inline-execution behavior used in tests/local.

## Phase 4 — Unified Dynamic Module Generator (2026-10-25)

### Scope

Build `php artisan make:module {Name}` — a single, declarative, idempotent
generator for a non-auth CRUD module (migration, model, request, factory,
facade, service, controller, resource, routes, and optional policy/seeder/
tests/OpenAPI fragment), supporting `--fields=`, `--manifest=` (JSON), or
`--interactive`, secure-by-default routing (`--guard`/`--public`,
mutually exclusive), configurable `--crud=`, opt-in `--permissions`, a real
`--dry-run`, and safe `--force`-gated overwrite. Consolidate with, rather
than duplicate, the existing `scaffold:*` pipeline wherever it is already
safe to reuse.

### What was found

1. The existing `scaffold:*` command family splits cleanly into two groups:
   **DB-introspecting** (`scaffold:model-schema`, `scaffold:request-schema` —
   both query `information_schema.COLUMNS` against an already-migrated real
   table) and **DB-less-at-generation-time** (`scaffold:resource-schema`,
   `scaffold:service`, `scaffold:controller`, `scaffold:routes` — only
   `class_exists()`/config checks, no DB access at all). The first group is
   fundamentally incompatible with a genuinely dry `--dry-run` and a
   never-migrate-to-generate design; the second group is already safe to
   reuse as-is.
2. `scaffold:routes` (Phase 1) never received the Phase 2A `guard.identity`
   fix that auth-specific routes did — a generic (non-auth) CRUD module's
   guarded routes were exposed to the same Sanctum guard-driver-ignores-
   provider gap `EnsureGuardIdentity` exists to close.
3. This codebase's CRUD routes are flat (record id travels in the request
   body, not a URL segment — `BasicCrudService`'s convention), so Laravel's
   `can:` middleware can only check class-level abilities (`viewAny`,
   `create`) without route-model-binding; instance-level abilities
   (`update`, `delete`) would need either a URL-shape change or editing the
   shared `FatherCrudController`, both out of scope for a per-module
   generator.
4. **Found only by actually generating a module and running its real
   tests, not by inspecting file existence**: three real generator bugs —
   covered under "What was changed" below.

### What was changed

**`app/Console/Commands/MakeModuleCommand.php` — new.**
`make:module {name} {--fields=} {--manifest=} {--interactive} {--guard=}
{--public} {--crud=create,read,update,delete,activate,deactivate}
{--permissions} {--with-seeder} {--with-tests} {--with-openapi} {--dry-run}
{--force}`. Generates migration/model/request/factory/facade directly from
a normalized field-spec array (all three input modes converge on the same
shape); reuses `scaffold:resource-schema`, `scaffold:service`,
`scaffold:controller`, `scaffold:routes` via `$this->call()` for the pieces
they already handle safely. Every file write is independently
skip-if-exists (never overwritten without `--force`) — the same
partial-failure-safe idempotency `make:auth-module` (Phase 2B/3B) already
proved out, so an interrupted run is simply safe to re-run.
- `is_active` and `deleted_at` are always included in the generated
  migration/model, unconditionally — not configurable per-module, because
  `BaseModel` always uses `SoftDeletes` and `BasicCrudService::activate()`/
  `deactivate()` always write `is_active`; making either optional here
  would silently break the shared base classes' contract.
- Generated facades use the FQCN-direct-accessor pattern (`getFacadeAccessor()`
  returns the service's class name, not a string alias) — the same trick
  Phase 2B/3B's auth-module facades use — so Laravel's container
  auto-resolves the parameterless-constructor service with no
  `AppServiceProvider` edit needed.
- `--permissions` (requires `--guard`) generates a `{Name}Policy` and wires
  `can:viewAny`/`can:create` onto exactly the list/create route lines
  `scaffold:routes` just wrote, via a targeted string replacement safe only
  because it runs immediately afterward in the same invocation. `update`/
  `delete` are deliberately NOT wired — documented in the generated
  policy's own doc comment, pointing at `UserManagementController`'s
  (Phase 3C) `Gate::authorize()` pattern as the manual alternative.
- An initially-planned `--enable-routes` option was found to be redundant
  dead weight during design review (`scaffold:routes` already treats
  `--guard`/`--public` as the only "make it live" signal, with no separate
  concept) and was removed entirely before being left as a confusing no-op.

**`app/Console/Commands/CodeAfterMigrate/ScaffoldRouteCommand.php` — extended.**
- New `--with-delete` option (soft-delete route; previously no delete
  route existed in the base set at all) — validated the same way
  `--with-restore`/`--with-force-delete` already were: requires a real
  `--guard`, refused with `--public`.
- `buildBlock()`'s guarded group now pairs `auth:{guard}` with
  `guard.identity:{guard}` (same fix as Phase 2A) — a no-op for a
  model-agnostic guard like `sanctum`, genuinely protective for a
  model-specific one.

**Three real bugs caught by generating a real module and running its real
tests (not by inspecting generated file existence), all fixed:**
1. `scaffold:controller`'s generated controller hardcodes a reference to
   `App\Facades\Services\{Name}\{Name}Facade`, but the original plan never
   generated that file — confirmed broken via `class_exists()`, fixed by
   adding `facadeStub()`.
2. A unique field's factory value had no uniqueness guarantee
   (`fake()->words(3, true)`) — would eventually collide and flake; fixed
   by appending `->unique()` to the faker chain whenever the field is
   marked unique.
3. `is_active` was fillable but never cast to `boolean` on the generated
   model — caught by a real test failure (`assertFalse()` rejecting the
   raw un-cast `0`); fixed by always appending an `is_active` cast entry.

**One real bug caught only by the permanent end-to-end test added this
session (not present during exploratory manual smoke-testing):**
4. Default `--crud` includes `delete`, but `scaffold:routes --with-delete`
   requires a real `--guard` (not satisfied by `--public`) — so a
   guard-less/public default invocation made the internal `scaffold:routes`
   call fail outright, and `generateRoutes()` swallowed that failure
   silently, leaving a bare, uninformative routes file instead of the
   normal disabled scaffold. Fixed: `delete`/`restore`/`forceDelete` are
   now only forwarded to `scaffold:routes` when a real `--guard` is
   present; otherwise `make:module` emits a `$this->warn()` naming exactly
   which requested CRUD operation was skipped and why, and any non-zero
   `scaffold:routes` exit code now also surfaces a warning instead of being
   silently ignored.

### Tests

New `tests/Feature/MakeModuleCommandTest.php` (10 tests): dry-run writes
nothing; at-least-one-field and unknown-field-type rejection; `--guard`+
`--public` and `--permissions`-without-`--guard` rejection; full generation
produces every expected file with valid PHP syntax (`php -l`, parsed
directly rather than piped through `grep`, which isn't guaranteed on
Windows `cmd.exe`) plus correct `guard.identity`/`can:` wiring and casts;
idempotent re-run; default-crud-with-no-guard produces the proper disabled
scaffold (regression test for bug 4 above); a genuine end-to-end HTTP test
(create → list → deactivate → soft-delete, plus a cross-guard check) that
also had to work around a second real gotcha — `bootstrap/app.php` globs
and registers `routes/api/v1/*.php` once at application boot, which
happens in `setUp()` *before* the test method's `make:module` call ever
creates the file, so the test manually re-registers just that one file's
route group (`Route::middleware('api')->prefix('api/v1')->group(...)`)
afterward, matching `bootstrap/app.php`'s own registration exactly; and a
guard that `--crud=restore,forceDelete` without a guard degrades safely.
Also extended `tests/Feature/ScaffoldRouteCommandSecurityTest.php` (+1:
`--with-delete`) and updated one existing assertion for the new
`guard.identity` pairing.

### Test results

`php artisan test` → **172 passed (727 assertions)** — the 162 carried
over from Phases 0–3C plus 10 new this phase, zero regressions. `php -l`
clean on every generated-and-then-deleted exploratory file and on every
file this phase added. `pint --test` clean on `MakeModuleCommand.php`,
`ScaffoldRouteCommand.php`, and `ScaffoldRouteCommandSecurityTest.php`
unchanged; `pint --write` applied only to the new
`MakeModuleCommandTest.php` (import ordering/fully-qualified-strict-types),
re-verified green after.

### Backward-compatibility / breaking-change notes

1. `scaffold:routes`'s guarded route group now also carries
   `guard.identity:{guard}` middleware — additive and strictly narrowing;
   harmless for model-agnostic guards (`sanctum`), newly protective for
   model-specific ones. The one pre-existing test asserting the old
   middleware string was updated to match.
2. `scaffold:routes --with-delete` is new and opt-in; no existing caller
   passes it, so no existing generated route file is affected.
3. `scaffold:all-models` and the two DB-introspecting legacy commands
   (`scaffold:model-schema`, `scaffold:request-schema`) are completely
   untouched — `make:module` is purely additive, consolidating by
   composition (calling the already-safe commands), not by retiring
   anything. Per CLAUDE.md's "consolidate or safely retire ... only after
   verifying compatibility," full retirement-compatibility verification for
   the two introspecting commands was out of this phase's scope.

### Remaining limitations / deployment requirements for Phase 5+

- **No instance-level (`update`/`delete`) policy enforcement is wired for
  generated modules** — flat, id-in-body routes are incompatible with
  `can:` middleware's route-model-binding requirement without either
  changing every generated module's URL shape or editing the shared
  `FatherCrudController`. Documented in the generated policy's own doc
  comment; a deployment needing this must follow the
  `UserManagementController` pattern manually per module.
- **No relationships, filtering, search, or pagination customization** —
  explicitly Phase 5's scope, not attempted here.
- **No image/media handling, statistics, or OpenAPI aggregation** — the
  `--with-openapi` fragment is minimal and per-module only, not aggregated
  into a root document; Phase 8's scope.
- **`--interactive` mode is unverified by the automated test suite** — it
  depends on real stdin prompts (`$this->ask()`/`$this->choice()`/
  `$this->confirm()`), which is awkward to drive from PHPUnit; `--fields`
  and `--manifest` (which share the same downstream normalization code
  path) are fully covered instead. Low risk given the shared code path, but
  worth noting as an honest gap rather than a silent one.
- **Generated seeders are intentionally empty and never auto-run** —
  consistent with "no fabricated test results" and "never execute
  production seeders as a side effect of scaffolding," but means a
  deployment wanting real seed data for a generated module must write it
  by hand.

## Phase 5 — Relationships, Filtering, Searching and Pagination (2026-10-25)

### Scope

Add declarative relationship generation (`belongsTo`/`hasOne`/`hasMany`/
`belongsToMany`/`morphTo`/`morphMany`) to `make:module`, plus a safe,
allowlist-based client-requested eager-loading mechanism (`?include=`) —
without reinventing the search/filter/sort/pagination engine this
codebase already has.

### What was found

1. **`BaseModel` already has a complete, allowlist-based dynamic
   filtering/sorting/searching engine** (`scopeWithFilters()`,
   `$filterable`, `$dynamicFilterColumns`, `$sortableColumns`, `$search`,
   `applyMinMaxShortcuts()` for date/numeric ranges, dot-notation relation
   filters) — pre-existing, not written this phase. `isColumnAllowed()`/
   `isSortable()` already refuse any column not explicitly listed, which
   already satisfies "never accept arbitrary SQL column identifiers from
   client input; use allowlists." `BasicCrudService::allQuery()` already
   calls `$this->model::withFilters()`, so every module `make:module`
   generates gets this engine for free, secure-by-default (empty
   allowlists until a developer opts a column in).
2. **No equivalent allowlist existed for CLIENT-requested eager loading.**
   `BasicCrudService`'s `$relations` is fixed, service-code-defined
   (`setVariables()`), never client-controlled — safe, but also meant a
   client could never ask for a relation at all, and there was no
   mechanism to let them safely.
3. **No relationship generation existed in `make:module`** — `--fields`
   covered scalar columns only.
4. **Found only by generating a real module and exercising it over real
   HTTP, not by inspecting file existence**: belongsTo/morphTo relation
   columns were added to the migration and to validation, but NOT to the
   model's `$fillable` — `Model::create($request->validated())` (what
   `BasicCrudService::create()`/`update()` actually call) was silently
   dropping the foreign key even though it passed validation, leaving it
   null no matter what the client sent.

### What was changed

**`app/Models/BaseModel.php` — additive, 20 lines.** New
`protected array $allowedIncludes = []` (empty by default — opt-in only)
plus `getAllowedIncludes()`. Reuses the existing file's own established
conventions; the large pre-existing style inconsistencies already in this
file (mixed brace styles, etc.) were left untouched — `pint --write` was
deliberately NOT run on this file, to avoid an unrelated, unreviewable
reformat of code this phase didn't otherwise touch.

**`app/Services/Basic/BasicCrudService.php` — additive.** New
`resolveRelations(?Request $request = null)`: starts from the service's
own fixed `$relations`, then merges in whatever the client named via
`?include=`, intersected against `$this->model`'s `getAllowedIncludes()`
— anything not on that list is silently dropped, never reaching
`with()`/`load()`. `allQuery()`, `all()`, `allPaginated()`, `pureAll()`,
and `show()` now thread `$request` through to use it.
`create()`/`update()` deliberately left using the old fixed `$relations`
only — write-endpoint responses were judged out of this phase's "listing"
scope, kept to minimize blast radius. Fully backward compatible: a model
with no `$allowedIncludes` (every existing model) or a request with no
`?include=` behaves byte-for-byte as before.

**`app/Console/Commands/MakeModuleCommand.php` — extended.** New
`--relations=` option: semicolon-separated `name:type:RelatedModel[:param]`
specs.
- `belongsTo`/`morphTo`: adds an indexed (not `->constrained()`) FK column
  to the migration, a validation rule, a `$fillable` entry (the bug fix
  above), the relationship method, and the relation name to
  `$allowedIncludes`. Deliberately NOT a DB-level foreign key constraint —
  two independently-generated modules referencing each other have no
  guaranteed migration order, and a hard constraint pointing at a
  not-yet-existing table would fail the whole migration run; a developer
  can add the constraint by hand once both tables are confirmed to exist.
- `hasOne`/`hasMany`/`morphMany`: relationship method + `$allowedIncludes`
  entry only — their foreign key lives on the related table, which this
  module's own generation doesn't touch.
- `belongsToMany`: additionally plans a pivot-table migration (Laravel's
  own naming convention: both tables singularized, sorted alphabetically),
  guarded by `Schema::hasTable()` the same way the main migration already
  is. Checked against existing migration files **by table name**, not
  just exact path, before being queued — a pivot migration's filename
  always carries a fresh timestamp, so the usual exact-path
  `writeIfAbsent()` dedup can't catch a second, redundant file when the
  *other* side of the same relation is generated later.
- Self-referencing relations (`related === self`) are rejected outright —
  a generic, name-from-table-name pivot/column generator can't safely
  infer the distinct column names a self-reference needs (e.g.
  `user_id`/`friend_id`).
- A new `wireResourceRelations()` (same safe, targeted-string-replacement
  pattern as `wirePermissionMiddleware()`) wires each relation into the
  Resource `scaffold:resource-schema` just generated, each wrapped in
  `whenLoaded()` so an unloaded relation is simply omitted, never an
  extra query. References the related model's `{Related}Resource` by
  FQCN inside a closure (lazy — only resolved when a loaded relation is
  actually serialized), so generating modules out of dependency order
  fails loudly only if/when a client actually requests that relation, not
  at generation time.

### Tests

Extended `tests/Feature/MakeModuleCommandTest.php` (+6): unknown relation
type / missing related model / self-reference all rejected; `belongsTo`
generates the FK column, `$fillable` entry, model method, validation rule,
and wired Resource; `belongsToMany` generates an idempotent pivot
migration (re-running does not create a second file); and an end-to-end
test proving the full Phase 5 safety property — `?include=category`
genuinely returns the related record, no `?include=` omits it entirely
(zero extra query), and `?include=` naming a relation NOT in
`$allowedIncludes` is silently ignored. That last test uses its own
distinct disposable module name ("GadgetWidget") rather than reusing
"GadgetThing" — documented in the test class's own doc comment: every
`make:module` call internally triggers `scaffold:service`'s
`class_exists($modelClass)`, which autoloads (requires) the model file
into the single PHP process the whole test run shares; once a class name
is loaded once, PHP can never reload a different definition for it later
in the same run, so a test asserting on real class *behavior* (not just
file contents) needs a name no earlier behavior-asserting test in the
file already used.

### Test results

`php artisan test` → **178 passed (757 assertions)** — the 172 carried
over from Phases 0–4 plus 6 new this phase, zero regressions. `php -l`
clean on every file. `pint --write` applied to `MakeModuleCommand.php`
and `MakeModuleCommandTest.php` only (both wholly this phase's own code);
`BaseModel.php` and `BasicCrudService.php` were edited with only their
specific new lines touched, and deliberately not reformatted.

### Backward-compatibility / breaking-change notes

1. `BasicCrudService::allQuery()`'s signature gained an optional
   `?Request $request = null` parameter — additive, and the method is
   only ever called internally within the class (confirmed by search), so
   no external caller is affected.
2. `?include=` is new, additive, and opt-in on both sides (a model must
   list a relation in `$allowedIncludes`, and a client must explicitly
   request it) — no existing request or response shape changes unless
   both conditions are met.
3. `make:module`'s generated model's `$fillable` now also includes
   relation FK columns when `--relations` is used — additive; modules
   generated before this phase (with no relations) are unaffected.

### Remaining limitations / deployment requirements for Phase 6+

- **No DB-level foreign key constraints** are ever generated for
  relations — an accepted, documented tradeoff for cross-module migration
  ordering safety (see above). Referential integrity for generated
  relations is enforced at the application layer only, until a developer
  adds `->constrained()` by hand.
- **`create()`/`update()` responses don't honor `?include=`** — only
  listing (`all`/`allPaginated`/`pureAll`) and `show()` do. A caller
  wanting a freshly created/updated record's relations in the same
  response still needs a follow-up `show` request.
- **No nested-write support** — creating/updating a record's
  `belongsToMany`/`hasMany` relations (attach/sync/nested arrays) is not
  generated; only the `belongsTo`/`morphTo` foreign key on the record's
  own row is validated and mass-assignable.
- **Self-referencing relations are rejected outright**, not partially
  supported — a developer needing one must hand-write it after
  generation, following the model's own existing relationship methods as
  a template.
- **Generating modules out of dependency order** (the module with the
  relation before the module it references) produces a model/resource
  that only fails if/when a client actually requests that relation via
  `?include=` — not at generation time, and not for the main CRUD
  operations. Documented in `wireResourceRelations()`'s own doc comment;
  generating the referenced module first avoids this entirely.
- **No image/media management or statistics generation yet** — Phases 6
  and 7's scope, not attempted here.

## Phase 6 — Secure Image and Media Management (2026-10-25)

### Scope

Add a `--images=` option to `make:module` generating a secure per-attribute
upload/replace/delete/reorder/download sub-API (single or multiple images
per module), reusing — not reimplementing — `BaseModel`'s existing
file-cleanup mechanism, private-by-default storage, and no DB-level
destructive behavior.

### What was found

1. **`BaseModel` already has a working, soft-delete-safe file-cleanup
   mechanism** (`$fileAttributes`, `$fileDisk`, `cleanupReplacedFiles()`
   on `updating`, `cleanupAllFiles()` on `deleting`/`forceDeleted`,
   `$deleteFilesOnSoftDelete` defaulting to `false`) — pre-existing, not
   written this phase. It already satisfies "Soft Delete must not
   auto-destroy recoverable media" and "handle replace safely" by
   construction; this phase configures and exercises it per generated
   module rather than rebuilding it.
2. **The existing generic `UploadController`** (`single()`/`multiple()`,
   disabled since Phase 0.2 for being unauthenticated) stores to the
   `public` disk with no per-record ownership, ordering, or primary-image
   concept — a different, simpler use case than Phase 6's per-module,
   per-record media attributes. Left completely untouched; nothing in
   this phase retires or modifies it.
3. **`config/filesystems.php` already defines a private `local` disk**
   (the app's own default disk, not web-accessible via `storage:link`)
   alongside the public one — used as this phase's private-by-default
   choice with no new disk configuration needed.

### What was changed

**`app/Console/Commands/MakeModuleCommand.php` — extended.** New
`--images=name:single|multiple[:maxCount]` (semicolon-separated),
`--images-public` (opt into the web-accessible `public` disk instead of
the private `local` default), `--images-max-kb=` (per-file size cap,
default 10 MB). **`--images` requires `--guard`** — not satisfied by
`--public` — same reasoning as `--permissions`: unauthenticated
upload/delete/reorder is a materially bigger risk surface than plain CRUD.
- Migration: `single` → nullable string column; `multiple` → nullable
  json column (an ordered array of paths — index 0 is the primary image
  by convention; see below for why there's no separate `is_primary`
  column).
- Model: `$fileAttributes`/`$fileDisk` configured to reuse `BaseModel`'s
  existing mechanism unchanged; `multiple` columns cast to `array`.
  Image columns are deliberately **not** added to `$fillable` — they are
  only ever set via the new media controller's own
  `forceFill()->save()`, never through the generic `create()`/`update()`
  mass-assignment path (which has no validation rule for them either,
  since they aren't `--fields`).
- New `{Name}MediaController` (a separate class, not extending
  `FatherCrudController`) with one `upload{Attr}`/`delete{Attr}`/
  `download{Attr}` method set per attribute (`reorder{Attr}` too for
  `multiple`) — generating one method set per attribute, rather than one
  generic method keyed by an attribute-name string, so an unknown/mistyped
  attribute is a routing 404 rather than a runtime branch the controller
  has to defend against itself.
- Appended, always-guarded route group (`generateImageRoutes()`) — a
  dedicated method rather than reusing `scaffold:routes` (that command is
  purpose-built for the `FatherCrudController` pattern, not this bespoke
  controller), sharing the exact same URL prefix as the module's main CRUD
  group.

**Safety properties, enforced in the generated controller itself (see its
own doc comment):**
- Uploads are always stored under a generator-chosen UUID filename —
  never the client's original filename or any client-supplied path
  segment (prevents path traversal and filename collisions/overwrites).
- `delete`/`reorder`/`download` never act on a path the client merely
  *names* unless that exact string is already present on **that specific
  record's own** attribute value — a client can identify an existing
  image to target, but can never make the controller touch a path it
  doesn't already own (satisfies "never trust client-supplied storage
  paths" and "never delete files referenced by other records").
- A `multiple` attribute's reorder is only accepted when the submitted
  list is an **exact permutation** of the attribute's current paths — it
  can never smuggle in a new path or silently drop one. Moving a path to
  index 0 via reorder *is* "set primary" — no separate endpoint, since one
  would be redundant.
- If the DB save fails after a new file was already stored, that file is
  deleted again (a compensating action — true atomicity across a
  filesystem write and a DB write isn't possible, so this minimizes
  orphan risk instead).
- Replacing a `single` image goes through `forceFill()->save()`, which
  re-triggers `BaseModel`'s existing `cleanupReplacedFiles()` hook
  unchanged — the old file is deleted automatically.
- A soft-deleted record's files are never touched by any of this
  (`$deleteFilesOnSoftDelete` stays `false`) — only `forceDelete()`
  removes them, consistent with "Soft Delete must not auto-destroy
  recoverable media."

**Not built this phase:** an orphan-cleanup command. Judged genuinely
optional per the task's own phrasing ("optional orphan cleanup") and
deferred to keep this phase's scope bounded to the module-generation
surface; the file-cleanup hooks already prevent orphans from the
generated module's own normal lifecycle (upload/replace/delete/soft-
delete/restore/force-delete all handled), so the residual risk is narrow
(a file stored but never attached to a record, from a crashed/killed
process mid-upload) rather than an everyday occurrence.

### Tests

Extended `tests/Feature/MakeModuleCommandTest.php` (+5): `--images`
without `--guard` rejected; unknown image type rejected; full file-content
verification (migration columns, model's `$fileAttributes`/`$fileDisk`/
casts, image columns absent from `$fillable`, media controller syntax,
both route groups sharing one URL prefix — a real bug this test's prefix
assertion was written specifically to catch, see below); a full
single-image HTTP lifecycle (upload → replace-with-automatic-old-file-
cleanup → download → delete, plus a wrong-file-type rejection) using
`Storage::fake('local')` so no real file ever touches `storage/app`; and
a full multiple-image lifecycle proving all three controller safety
properties (max-count enforcement, delete-by-owned-path-only,
reorder-as-permutation-only) against real stored paths. The two HTTP
lifecycle tests use two more distinct disposable module names
("GadgetGizmo", "GadgetTrinket") beyond Phase 5's "GadgetWidget" — same
PHP class-identity reasoning documented in the test class's own doc
comment, now also covering `{Name}MediaController` (each one only ever
defines the upload/delete/[reorder]/download methods for its own
`--images` spec, so two tests sharing a name would collide the same way
two different field sets did in Phase 5).

### Errors found and fixed during this phase's own verification

1. **Real bug**: `generateImageRoutes()` originally used `$c['uri']`
   (plural kebab-case) for its route group's prefix, while the main CRUD
   group (written by the reused `scaffold:routes`) uses `$c['routeFile']`
   (singular) — splitting one module's API across two different URL
   prefixes. Caught by manually inspecting the generated routes file
   before writing permanent tests; fixed, and a permanent test
   (`substr_count` of the shared prefix) guards the regression.
2. **A missing-variable bug** in a doc comment: an unescaped `$fileAttributes`
   inside a PHP heredoc (meant as literal text in a comment) was
   interpreted as variable interpolation, crashing generation with
   "Undefined variable $fileAttributes" the first time `--images` was
   exercised at all. Fixed by escaping it (and a neighboring
   `$deleteFilesOnSoftDelete`) as `\$fileAttributes`.
3. **Test-only bug, not a generator bug**: the first draft of the
   single-image lifecycle test called the download endpoint with `GET`;
   every image route (like every other route in this codebase, including
   `show`) is `POST`. Fixed in the test.

### Test results

`php artisan test` → **183 passed (807 assertions)** — the 178 carried
over from Phases 0–5 plus 5 new this phase, zero regressions. `php -l`
clean on every generated-and-then-deleted exploratory file and on every
file this phase added. `pint --test` clean on `MakeModuleCommand.php` and
`MakeModuleCommandTest.php`.

### Backward-compatibility / breaking-change notes

1. `--images` is entirely new and opt-in — a module generated without it
   is byte-for-byte unaffected.
2. `BaseModel`/`BasicCrudService` were not modified this phase — only
   configured per generated module (`$fileAttributes`/`$fileDisk` on the
   generated model itself).

### Remaining limitations / deployment requirements for Phase 7+

- **No orphan-cleanup command** — see "Not built this phase" above; a
  deployment wanting one should follow `BaseModel::cleanupAllFiles()`'s
  own path-normalization helpers as a starting point.
- **One file per upload call** — a `multiple` attribute's upload endpoint
  accepts exactly one file per request (the client calls it repeatedly to
  build up a gallery); there is no bulk multi-file-in-one-request upload
  endpoint.
- **No nested-write support** (same boundary as Phase 5's relations) —
  image attributes are managed exclusively through their own dedicated
  endpoints, never through the generic `create()`/`update()` body.
- **DB-level `exists:`-style validation isn't meaningful for image
  columns** (they're storage paths, not foreign keys) — correctness here
  rests entirely on the controller's own "only ever touches this record's
  own, already-stored paths" invariant, documented and tested, not on a
  separate validation layer.
- **No image resizing/thumbnailing/optimization** — files are stored
  exactly as uploaded (after type/size validation); a deployment wanting
  derived sizes needs to add that deliberately.
- **No statistics generation yet** — Phase 7's scope, not attempted here.

## Phase 7 — Optional Dynamic Statistics Generator (2026-10-25)

### Scope

Add an opt-in `--stats` flag to `make:module` generating a `GET
{route}/stats` endpoint: totals, active/inactive/soft-deleted counts,
sum/avg/min/max for numeric fields, an optional date-range filter, an
allowlisted `group_by`, and filter-awareness — reusing the model's
existing dynamic-filter engine rather than building a second one.

### What was found

1. No existing aggregation/statistics infrastructure of any kind — this
   phase is additive from scratch, unlike Phases 5–6 which largely
   configured pre-existing `BaseModel` mechanisms.
2. Confirmed safe to reuse: `(clone $builder)->where(...)->count()` is
   the correct, idiomatic way to run several different aggregates off one
   shared, filtered base query in Eloquent — a plain PHP `clone` of a
   Builder is safe here because its mutable state (`wheres`, bindings,
   etc.) lives in plain arrays, which are copy-on-write in PHP; this is
   not a special case this phase had to work around.
3. **Found only by writing a real numerical-correctness test, not by
   inspecting generated file existence**: a pre-existing quirk in
   `BaseModel::applyDynamicFilters()` (Phase 5 vintage code, not touched
   this phase) — a request key containing an underscore (e.g.
   `price_gte`) is always parsed as `relation_column` (its CASE C
   branch), never as `column_operator` (CASE A, the branch that actually
   calls the operator-suffix parser, is only reached for a key with NO
   underscore at all — self-contradictory, since every suffix-operator
   key needs one). This means the `_gte`/`_lte`/`_gt`/`_lt`/`_like`/
   `_in`/`_nin` suffix convention most of that engine's own test/doc
   comments imply is unreachable for a plain top-level column. **Not
   fixed here** — found while writing this phase's own test, genuinely
   pre-existing, unrelated to what this phase was asked to build, and
   `BaseModel` is an 837-line shared file outside this phase's declared
   scope; fixing it safely would need its own dedicated phase with its
   own regression tests across every existing filter path. Documented
   here, and the test that found it uses `?{col}_min=`/`?{col}_max=`
   (`applyMinMaxShortcuts()`, a separate, simpler, already-correct code
   path that keys directly off `$dynamicFilterColumns` with no CASE A/B/C
   dispatch at all) and `?trashed=` (honored unconditionally, no
   allowlist) to prove filter-awareness instead.

### What was changed

**`app/Console/Commands/MakeModuleCommand.php` — extended.** New
`--stats` (boolean, **requires `--guard`** — same reasoning as
`--permissions`/`--images`: aggregate counts/sums are a bigger exposure
than plain CRUD, not satisfied by `--public`) and
`--stats-group-by=col1,col2` (an allowlist of `--fields` names a client
may request via `?group_by=` — validated at GENERATION time to actually
be real field names; a mismatch is a generation error, never silently
dropped).
- New `{Name}StatsController::index()`: builds `$base =
  {Name}::withFilters()` — the exact same dynamic filter/search engine
  (`BaseModel`, pre-existing, Phase 5) the list endpoint already uses —
  then derives every count/sum/avg/min/max from `(clone $base)`. This is
  what makes stats "filter-aware" for free: whatever a client could
  already filter the list endpoint with, it can filter stats with too,
  with no separate filtering logic for this phase to keep in sync.
- `soft_deleted` is deliberately **not** derived from that same filtered
  base — composing an explicit `onlyTrashed()` with whatever the
  client's own `?trashed=` already did inside `withFilters()` is
  genuinely ambiguous, so it's instead always an independent
  `{Name}::onlyTrashed()->count()`.
- `sum_{field}`/`avg_{field}`/`min_{field}`/`max_{field}` generated for
  every `--fields` entry whose type is `integer`/`bigInteger`/`decimal`/
  `float` — never for `string`/`text`/etc., where SUM/AVG are meaningless.
- `?from=`/`?to=` narrow every aggregate by `created_at` range — a plain
  two-bound `where()`, no raw SQL.
- `?group_by=` only ever runs against `self::GROUPABLE_FIELDS`, baked in
  at generation time from `--stats-group-by` — a client-supplied value
  not on that list is rejected (not silently ignored, not silently
  honored) — the same allowlist principle as Phase 5's `$allowedIncludes`
  and Phase 6's "only ever touches this record's own path."
- New route: `GET {route}/stats`, appended to the module's routes file
  behind the same guard, via a new `generateStatsRoute()` (not reusing
  `scaffold:routes`, which is purpose-built for the `FatherCrudController`
  pattern, not this bespoke controller — same reasoning as Phase 6's
  `generateImageRoutes()`).

### Tests

Extended `tests/Feature/MakeModuleCommandTest.php` (+3): `--stats`
without `--guard` rejected; `--stats-group-by` naming an unknown field
rejected at generation time; and one comprehensive end-to-end test
against real, known records (two active at $10/$20, one inactive at $30,
one soft-deleted at $40) proving: `total`/`active`/`inactive`/
`soft_deleted` exactly match real record state; `sum`/`avg`/`min`/`max`
are numerically exact (`assertEqualsWithDelta`, not just "truthy");
`?trashed=2` genuinely widens stats to include the soft-deleted record
(proving `$base` really goes through `withFilters()`, not a separately
hardcoded query); an allowlisted `?group_by=title` returns correct
per-group counts; and a real column name (`price`) that is NOT on the
`--stats-group-by` allowlist is rejected rather than honored. Uses a
fifth distinct disposable module name ("GadgetSprocket") for the same
PHP class-identity reason documented for Phases 5–6, now also covering
`{Name}StatsController`.

### Test results

`php artisan test` → **186 passed (828 assertions)** — the 183 carried
over from Phases 0–6 plus 3 new this phase, zero regressions. `php -l`
clean on every generated-and-then-deleted exploratory file and on every
file this phase added. `pint --test` clean on `MakeModuleCommand.php` and
`MakeModuleCommandTest.php`.

### Backward-compatibility / breaking-change notes

1. `--stats` is entirely new and opt-in — a module generated without it
   is byte-for-byte unaffected.
2. No change to `BaseModel`/`BasicCrudService` this phase — the pre-
   existing filter-engine quirk documented above was found, not touched.

### Remaining limitations / deployment requirements for Phase 8+

- **The pre-existing `_gte`/`_lte`/`_gt`/`_lt`/`_like`/`_in`/`_nin`
  suffix-operator ambiguity in `BaseModel::applyDynamicFilters()`**
  (documented above) remains unfixed — affects the list endpoint's
  filtering equally, not something this phase introduced or is
  responsible for resolving, but worth a dedicated follow-up phase given
  it makes a documented-sounding filter convention silently unreachable
  for plain top-level columns.
- **No relationship-aware stats** (e.g. `withCount()` across a
  `hasMany`/`belongsToMany` relation) — judged genuinely optional per the
  task's own "when safe" phrasing and deferred to keep this phase's scope
  to a solid, safe, extensible foundation rather than every possible
  aggregate shape; `--relations` and `--stats` can be combined today, but
  the stats endpoint does not yet reflect the relation at all.
- **`group_by` only ever returns a flat count per group** — no nested
  aggregation (e.g. sum-per-group) yet; a deployment wanting that can
  extend the generated controller's `selectRaw()` call directly.
- **No pagination on `?group_by=` results** — acceptable for a bounded
  allowlist of low-cardinality columns (the intended use case), but a
  `group_by` column with very high cardinality could return a very large
  result set; the allowlist itself (`--stats-group-by`) is the deployer's
  tool for keeping that bounded.
- **No caching** — every stats request re-runs every aggregate live; a
  deployment with large tables wanting cached/pre-aggregated stats needs
  to add that deliberately.

## Phase 8 — Seeders, Factories, Postman and OpenAPI (2026-10-26)

### Scope

Seeder/factory generation and credential safety were already handled by
earlier phases (Phase 4's `--with-seeder`/factory generation; Phase 2B's
`AdminSeeder` fix). This phase's real remaining scope: make
`--with-openapi`'s per-module fragment a COMPLETE and ACCURATE reflection
of a module's real endpoints (not the minimal placeholder Phase 4 left
it as), aggregate every module's fragment into one root OpenAPI document,
generate a matching Postman collection + environment template from the
same aggregate, and build a documentation-drift checker.

### What was found

1. Seeder/factory generation and the "never create known production
   credentials" requirement were already satisfied before this phase:
   `make:module --with-seeder` (Phase 4) generates an empty, never-auto-
   run seeder; `database/seeders/AdminSeeder.php` already reads its
   credential from the environment and refuses to run (loudly) rather
   than ever falling back to a hard-coded one (a Phase 2B fix). Neither
   needed further work here.
2. `symfony/yaml` (used by Laravel internally) is already a project
   dependency — no new package needed for OpenAPI generation/parsing.
3. Phase 4's `--with-openapi` fragment was a minimal placeholder (one
   path, no security, no request bodies) — explicitly documented at the
   time as "Phase 8 builds the full aggregation... on top of fragments
   like this one," but the fragment itself needed to become genuinely
   complete first, or aggregation and drift-checking would have nothing
   truthful to work from.
4. **Found only by writing the drift checker and a real test against
   it, not by inspecting generated file existence — twice:**
   - PHP auto-casts a numeric string array key like `'200'` to the
     integer `200`, so Symfony's `Yaml::dump()` emitted a bare `200:`
     instead of the OpenAPI-spec-required quoted string key `'200':` for
     every response code. Fixed with a targeted post-process
     (`dumpOpenApiYaml()`) that quotes any bare 3-digit key the dumper
     produced, rather than fighting PHP's array-key coercion upstream.
   - `--crud` was assumed (by an early draft of the rewritten
     `openApiStub()`) to gate whether `create`/`update`/`activate`/
     `deactivate` are documented — but `scaffold:routes`' own base route
     set (`ScaffoldRouteCommand::buildBlock()`) makes all four
     UNCONDITIONAL; `--crud` only ever controls whether
     `delete`/`restore`/`forceDelete` get ADDED on top (via
     `--with-delete`/`--with-restore`/`--with-force-delete`). An early
     version of this phase's own drift-check test caught the resulting
     real mismatch (docs under-reporting real endpoints for e.g.
     `--crud=create,read`); fixed by documenting those four
     unconditionally, matching reality instead of the assumption.

### What was changed

**`app/Console/Commands/MakeModuleCommand.php`'s `openApiStub()` —
rewritten.** Now builds a PHP array (schemas, paths, security, request
bodies) and serializes it via `Symfony\Component\Yaml\Yaml::dump()`
(far less error-prone than hand-formatted YAML strings) rather than a
hand-written heredoc. For a module with neither `--guard` nor
`--public`, the fragment has an EMPTY `paths` (its routes file only has
the disabled, commented-out scaffold — nothing live to document yet).
For a live module, every endpoint it actually has — CRUD, images,
stats — is documented with its real security requirement and request
schema. `--with-openapi`'s output path moved from
`docs/openapi/{module}.yaml` to `docs/openapi/modules/{module}.yaml`
(a clean, dedicated fragments directory, separate from the aggregate
`docs/openapi/openapi.yaml` the new build command writes) — done now,
before anything else depended on the old path, rather than as a later
breaking change.

**`app/Console/Commands/OpenApiBuildCommand.php` — new.**
`php artisan openapi:build [--postman]`. Globs
`docs/openapi/modules/*.yaml`, parses each, merges `paths` and
`components.schemas` (warns rather than silently overwriting if two
fragments ever document the exact same path), wraps the result in a
proper OpenAPI 3.0.3 envelope (`info`, `servers`, a `bearerAuth`
security scheme), and writes `docs/openapi/openapi.yaml` — always a full
rebuild from the fragments, never an incremental patch, so re-running it
is trivially idempotent. `--postman` derives a Postman v2.1 collection
(`docs/postman/collection.json`) and a matching environment template
(`docs/postman/environment.json`) from the SAME aggregated paths in the
same run, so the two artifacts can never describe a different endpoint
set from each other. The environment template's `bearer_token` value is
always blank — a developer pastes their own dev/test token into Postman
locally; never a real credential.

**`app/Console/Commands/DocsCheckDriftCommand.php` — new.**
`php artisan docs:check-drift`. Compares every `api/v1/*` path+method
`Route::getRoutes()` actually has registered against every path+method
`docs/openapi/openapi.yaml` documents (built by `openapi:build`).
Reports two distinct kinds of drift — registered-but-undocumented and
documented-but-not-really-registered (stale) — and exits non-zero on
either, intended for CI. Deliberately only cross-checks path+method
existence, not full security/request-body re-derivation from the route
table — a cheap, reliable signal for the most common and most damaging
kind of drift (an endpoint added/removed/renamed without its docs
following), while `make:module`'s own `openApiStub()` is what keeps
request-body/security detail in sync at generation time instead.

### Tests

New `tests/Feature/OpenApiAndPostmanGenerationTest.php` (6 tests): a
disabled-scaffold module's fragment has empty `paths`; a live guarded
module's fragment accurately reflects its real endpoints (including the
`--crud` unconditional-four regression guard, and a direct check of the
raw YAML text for the quoted-`'200'` fix — not just the parsed
structure, since PHP's own parser re-coerces the key back to an integer
on read, masking the issue if only checked that way); `openapi:build`
aggregates multiple fragments correctly and rebuilds idempotently on
re-run; `--postman` emits a collection with exactly as many items as the
OpenAPI document has paths, at least one bearer-authed request, and a
blank (never real) credential in the environment template;
`docs:check-drift` detects both missing and stale documentation; and a
realistic end-to-end pass showing a module that used `--with-openapi`
and was aggregated no longer appears in the checker's "missing docs"
output (not a full exit-code-0 assertion — this app's pre-existing,
hand-written auth routes, from `make:auth-module`, have no OpenAPI
fragment at all, so a totally clean drift check isn't achievable from
one module's test in isolation, and isn't what this test is actually
proving).

### Test results

`php artisan test` → **192 passed (866 assertions)** — the 186 carried
over from Phases 0–7 plus 6 new this phase, zero regressions (confirmed
via repeated reruns; one run reported 44.92s due to what a rerun
confirmed was transient disk I/O, not a real regression — the normal
duration is ~9s). `php -l` clean on every generated-and-then-deleted
exploratory file and on every file this phase added. `pint --test` clean
on `MakeModuleCommand.php`, `OpenApiBuildCommand.php`,
`DocsCheckDriftCommand.php`, `MakeModuleCommandTest.php`, and
`OpenApiAndPostmanGenerationTest.php`.

### Backward-compatibility / breaking-change notes

1. `--with-openapi`'s output path changed from `docs/openapi/{module}.yaml`
   to `docs/openapi/modules/{module}.yaml` — breaking only for a
   generated fragment's path, and only if something already depended on
   the old path; nothing in this codebase does, since `docs/openapi/`
   was empty before this phase (no fragment had ever been committed).
2. A regenerated (`--force`) module's fragment will look substantially
   different from Phase 4's minimal placeholder — expected and intended,
   not a regression; the old placeholder format documented almost
   nothing real.
3. `openapi:build`/`docs:check-drift` are both entirely new, standalone
   commands — no existing command or route was changed to add them.

### Remaining limitations / deployment requirements for Phase 9+

- **`make:auth-module`-generated routes (admin/user login, OTP, etc.)
  have no OpenAPI generation at all** — only `make:module`'s own
  fragments feed the aggregate/drift-check pipeline. A deployment running
  `docs:check-drift` today will always see these as "registered but not
  documented" until a future phase adds fragment generation to
  `make:auth-module` too, or someone hand-writes fragments for them under
  `docs/openapi/modules/`.
- **The pre-existing `BaseModel::applyDynamicFilters()` suffix-operator
  ambiguity** (Phase 7's finding) remains unfixed and un-revisited this
  phase.
- **No OpenAPI `components.responses`/error-schema documentation** — every
  operation documents only a bare `'200': {description: OK}`; this
  codebase's actual error envelope (`{data, status, error, statusCode}`)
  is not yet reflected in the generated schemas.
- **The drift checker doesn't verify request-body/security DETAIL**, only
  path+method existence — a fragment that documents the wrong validation
  rule for a real, correctly-named endpoint would not be caught; relies
  on `openApiStub()` itself staying correct at generation time instead.
- **No CI wiring yet** — `docs:check-drift` is built to be CI-friendly
  (a plain non-zero exit code) but nothing in this repository's own
  tooling runs it automatically on a PR; a deployment wanting that needs
  to add the workflow step itself.

## Phase 9 — Reusable Starter Installation and Distribution (2026-10-26)

### Scope

A safe, local-first way to start a new project FROM this starter kit
without manually copying files — `php artisan starter:new {path}` —
without ever touching GitHub or a package registry, and without ever
modifying or overwriting an existing project.

### What was found

1. `.env.example` already exists and is complete — usable as-is as the
   template for a new project's `.env`.
2. This specific checkout's own `.git` lives one level up, at
   `C:\laragon\www\` (confirmed via `git rev-parse --show-toplevel`), not
   inside `base_path()` — a monorepo-shaped detail of THIS particular
   working copy, not something the command needed to special-case (a
   normal standalone copy of this starter kit would have `.git` at its
   own root, where `--with-git-history` works exactly as designed); it
   only affected how this phase could safely test that one flag (see
   Tests below).
3. Symfony's `Finder::notPath()` filters results after full directory
   traversal; `Finder::exclude()` actually prevents descending into a
   matching directory at all — the correct choice here given `vendor/`
   alone can hold tens of thousands of files with nothing to learn from
   enumerating them first.

### What was changed

**`app/Console/Commands/StarterNewProjectCommand.php` — new.**
`php artisan starter:new {path} [--name=] [--with-git-history]`. Copies
this project into a new target directory using Symfony's `Finder`
(excluding `vendor/`, `node_modules/`, `public/build`, `public/hot`,
`public/storage`, and `.git` unless `--with-git-history` is passed —
whole directories never even traversed), while still copying the
DIRECTORY STRUCTURE of things like `storage/logs/` and
`storage/framework/{cache,sessions,views,testing}/` so Laravel has
somewhere to write at runtime, only excluding their individual runtime
files (`*.log`, cached views, session files, etc.) and each directory's
own `.gitignore` placeholder is preserved. Never copies `.env` (or
`.env.backup`/`.env.production`), the dev sqlite database file, or
`.phpunit.result.cache`.
- **Refuses outright** if the target path already exists and is not
  empty — no `--force` override for that case at all; the only way to
  target an existing path is for it to already be empty.
- Generates a brand-new `.env` from the copied `.env.example`, with a
  freshly generated `APP_KEY` (32 random bytes, base64-encoded — the
  same scheme `php artisan key:generate` itself uses, generated directly
  here rather than by shelling out to the new project's own artisan,
  which isn't runnable yet until `composer install` has populated its
  `vendor/`) — never the source project's own key, never its own `.env`
  at all.
- Runs nothing else automatically: no `composer install`, no migration,
  no seeder — each is printed as an explicit next step for the developer
  to run themselves, along with a Windows/Laragon-specific note (how
  Laragon auto-detects a project already under `C:\laragon\www\`, and
  what to do if the target is elsewhere).

**Not built this phase:** a full "reusable versioned components" /
package-extraction system (e.g. splitting the generator commands into
their own installable Composer package) and an upgrade strategy for
pulling later starter-kit improvements into an already-created project.
Judged out of reach within this phase's bounded scope — a real package
split is a substantially larger, separate undertaking (package
boundaries, a composer.json of its own, a release/version strategy) than
a local copy-and-rename command, and the task's own phrasing
("implement installer/package tooling **if feasible**") left room for
that judgment call. Documented as a real limitation below, not silently
skipped.

### Tests

New `tests/Feature/StarterNewProjectCommandTest.php` (4 tests): a full
copy correctly excludes `vendor/`/`node_modules/`/the dev sqlite
file/`.phpunit.result.cache` while still copying real source files; a
fresh `.env` is generated with a validly-shaped, freshly-random
`APP_KEY` (regex-matched, and cross-checked to differ from the source
project's own key when one exists) and the requested `--name`, with the
source's real `.env`/`.env.backup` never copied; the command refuses
outright (exit 1, nothing written) when the target already exists and
has ANY content; and it succeeds into a target that exists but is
genuinely empty. Every target is created under `sys_get_temp_dir()` —
well outside both this repo and the user's Laragon `www/` tree, so a
test run can never create or interfere with a sibling project there —
and removed in `tearDown()` regardless of pass/fail. `--with-git-history`
is reviewed but not independently tested: this checkout's own `.git`
lives outside `base_path()` entirely (see "What was found" above), so
there was no real `.git` inside the source tree to exercise that flag
against without fabricating one temporarily inside the live project — a
risk judged not worth taking for a flag whose logic is otherwise
identical to the already-tested `vendor`/`node_modules` exclusion
mechanism.

### Test results

`php artisan test` → **196 passed (885 assertions)** — the 192 carried
over from Phases 0–8 plus 4 new this phase, zero regressions. `php -l`
clean on `StarterNewProjectCommand.php`. A real manual smoke test (copy
into a scratchpad directory outside the Laragon `www/` tree, inspect the
result, confirm the non-empty-target refusal, clean up) was also run
before writing the permanent tests, per this phase's own quality gate
("do not declare a feature complete based only on generated file
existence"). `pint --test` clean on both new files.

### Backward-compatibility / breaking-change notes

None — `starter:new` is an entirely new, standalone command with no
interaction with any existing route, model, or other generator.

### Remaining limitations / deployment requirements for Phase 10+

- **No package-based reuse or upgrade strategy** — see "Not built this
  phase" above. Today, benefiting from a later improvement to this
  starter kit in an already-created project means manually porting the
  relevant files over; there is no `starter:upgrade` or composer-package
  update path.
- **No `laravel-starter new project-name` standalone CLI tool** — only
  an in-project artisan command (`php artisan starter:new`), which still
  requires an existing checkout of this starter kit to run FROM. A true
  standalone installer (usable before any checkout exists, e.g. via
  `composer create-project`) is future work.
- **`composer install` is never run automatically** — by design (a
  real, potentially slow side effect this command only ever prints as a
  next step), but it does mean the new project is not immediately
  runnable the moment `starter:new` finishes; the developer must still
  run it themselves.
- **No automated verification that a freshly created project actually
  boots** (e.g. `php artisan about` succeeding after `composer install`)
  — this phase's own manual smoke test confirmed the FILE copy and `.env`
  generation are correct, but did not run `composer install` against the
  copy (a genuinely slow, network-dependent step) to verify the result
  boots end-to-end. Phase 10's end-to-end validation work is the natural
  place to close this gap if it's judged worth the time cost.

## Phase 10 — Architecture Doctor and End-to-End Validation (2026-10-26)

### Scope

A read-only `php artisan starter:doctor` diagnostic sweep across this
starter kit's own configuration, plus end-to-end tests covering three
representative scenarios (basic CRUD, a complex module combining
relations/images/stats/permissions, and a full auth register-verify-
login flow) that exercise the complete generator suite built across
Phases 4-9 together, not each mechanism in isolation.

### What was found

1. **Real bug, found immediately on first real run**: `starter:doctor`'s
   `Schema::hasTable()` checks threw an UNCAUGHT `QueryException` the
   moment they ran against this very machine's actual dev `.env` (a
   MySQL connection to a database, `base`, that doesn't exist yet) —
   crashing the whole command and silently skipping every check still
   queued after it. A diagnostic tool fatally crashing on the exact
   condition it exists to diagnose defeats its own purpose. Fixed with
   an explicit, gracefully-caught connectivity probe
   (`canConnectToDatabase()`) that every DB-touching check consults
   first.
2. **A second real bug in that same probe, found by a test that failed
   only when run alongside others, not in isolation**: the probe
   originally cached its result in a function-local `static` variable —
   which in PHP is scoped to the *process*, not to one command
   invocation. Once any earlier PHPUnit test in the same run
   successfully connected, that cached `true` stuck for every
   *subsequent* test in the process, masking a later test's genuinely
   broken connection and letting the original uncaught-exception crash
   resurface. Fixed by moving the cache to an instance property instead
   (a fresh command instance is created per invocation, confirmed
   empirically by rerunning the now-passing regression test).
3. **A well-known Laravel testing gotcha, encountered for the first
   time in this whole test suite, confirmed from framework source**:
   `Illuminate\Auth\RequestGuard::user()` caches the resolved user for
   the guard instance's *lifetime* once resolved, and that guard
   instance (cached inside `AuthManager`) outlives a single simulated
   request in Laravel's own test harness. A test that authenticates as
   one Sanctum-token actor and later authenticates as a *different*
   actor within the same test method will keep resolving the FIRST
   actor, silently, unless the guard cache is explicitly forgotten
   (`app('auth')->forgetGuards()`) between them. Every prior test in
   this suite that authenticates as two different actors happened not
   to notice, because none of them asserted on actor *identity* after
   switching — only on response status, which stayed correct by
   coincidence. This phase's own Scenario B (`--permissions`, which is
   identity-sensitive) is the first test in the suite to actually be
   sensitive to this, and needed the fix to pass. Not a product bug —
   a real HTTP request always gets a fresh application boot — but a
   genuinely important finding for how this test suite's own future
   multi-actor tests need to be written.
4. **A real test-hygiene gap, not a product bug**: `make:auth-module`
   edits `config/auth_modules.php` — a real, committed project file, not
   a disposable generated one — in place. An earlier, interrupted run of
   this phase's own Scenario C test left a stray `demo_member` entry
   behind in that real file because its `tearDown()` didn't know to
   revert it. Cleaned up by hand, and the test rewritten to use the same
   snapshot-and-restore pattern `MakeAuthModuleCommandTest` (Phase 2B)
   already established for exactly this reason, rather than trying to
   surgically remove just the added block.
5. **Confirmed, not assumed**: `make:auth-module`'s generated routes use
   `Str::kebab($name)` for both the routes file name and the URL prefix
   (e.g. "demo-member"), which is a *different* string from the
   guard name default, `Str::snake($name)` (e.g. "demo_member") — this
   phase's Scenario C test initially (incorrectly) assumed they were the
   same string, caught by actually generating a real module and reading
   its real routes file rather than guessing from the guard name alone.
6. **Confirmed, not assumed**: editing `config/auth_modules.php` on disk
   mid-test has no effect on that test's own `config()` repository,
   which was already loaded at application boot before the edit — the
   exact same "file created after the app already booted" timing gap
   Phases 4-9's own route-registration workarounds already established,
   now also true for config. `MakeAuthModuleCommandTest`'s OTP-flow test
   (Phase 3B) had already solved this (`config(['auth_modules' =>
   include $path]);`); Scenario C needed the identical fix.

### What was changed

**`app/Console/Commands/StarterDoctorCommand.php` — new.**
`php artisan starter:doctor`. Twelve read-only checks (PHP version and
required extensions; framework version; every guard's provider and
model actually exist; every middleware alias resolves to a real class;
Spatie permission tables, if installed; every concrete model's table
exists on the current connection; `api/v1/*` route authorization
visibility — informational, since an unguarded route is often
intentional; every generator command is registered; every OTP channel
class exists and implements `OtpChannelProvider`; queue driver; storage
disk config and the `public/storage` symlink; documentation presence).
Each result is PASS, WARN, or FAIL — only a FAIL affects the exit code,
since several checks (migrations not run yet, `storage:link` not run
yet, no OpenAPI built yet) describe the normal, honest state of a
project that hasn't finished its own setup, not a defect.

**Not built this phase:** a dedicated doctor check that re-verifies the
documented-vs-registered route comparison `docs:check-drift` (Phase 8)
already does in full — `starter:doctor` only points at it (and only
when `docs/openapi/openapi.yaml` already exists) rather than
duplicating that logic.

**`tests/Feature/StarterE2EScenariosTest.php` — new.** Three scenarios,
each generating real fixtures, running real migrations against the
shared sqlite `:memory:` test connection, registering generated routes
by hand (the established timing workaround), exercising real HTTP
endpoints, and fully torn down afterward:
- **A** (`make:module`, basic): create → list (paginated) → deactivate
  (independent of soft-delete) → soft-delete (independent of is_active)
  → restore.
- **B** (`make:module`, complex): `belongsTo` relation to the real,
  pre-existing `Category` model; a `single` and a `multiple` image
  attribute; opt-in stats; `--permissions`-gated create/list, proven
  denied without the role and allowed once the exact role the generated
  policy checks for is granted — not just that the routes/policy files
  exist, but that the gate is real.
- **C** (`make:auth-module`, reused not reimplemented): register → verify
  (fake OTP channel) → login against the module's own real,
  guard-isolated generated routes.

### Tests

New `tests/Feature/StarterDoctorCommandTest.php` (4 tests): a clean run
against the real sqlite test environment exits 0; an unreachable
database is reported as a failure without crashing the command
(regression test for finding #1/#2 above — simulated by temporarily
pointing `database.default` at an unreachable MySQL connection);
an OTP channel pointing at a nonexistent class is reported as a failure;
a middleware alias pointing at a nonexistent class is reported as a
failure. New `tests/Feature/StarterE2EScenariosTest.php` (3 tests, one
per scenario, detailed above).

### Test results

`php artisan test` → **203 passed (953 assertions)** — the 196 carried
over from Phases 0–9 plus 7 new this phase, zero regressions. `php -l`
clean on every generated-and-then-deleted exploratory file (including
manual reproduction of the `starter:doctor` crash, and a manual
`make:auth-module DemoMember` run used to confirm the real routes-file
naming convention) and on every file this phase added. `config/auth_modules.php`
was manually cleaned of a stray entry left by an earlier interrupted
test run, confirmed clean (`grep` for every disposable name used this
phase returns nothing outside this phase's own test file) before and
after the final suite run. `pint --test` clean on
`StarterDoctorCommand.php`, `StarterDoctorCommandTest.php`, and
`StarterE2EScenariosTest.php`.

### Backward-compatibility / breaking-change notes

None — `starter:doctor` is an entirely new, read-only, standalone
command; the end-to-end tests exercise existing generators exactly as
documented, without modifying any of them.

### Final completion matrix, Phases 4–10

| Phase | Deliverable | Status |
|---|---|---|
| 4 | `make:module` (CRUD generator) | Complete, tested |
| 5 | Relationships, filtering, searching, pagination | Complete, tested (BaseModel's filter engine pre-existing; `?include=` allowlist new) |
| 6 | Secure image/media management | Complete, tested |
| 7 | Optional dynamic statistics | Complete, tested |
| 8 | Seeders/factories (already done pre-Phase 8) + OpenAPI/Postman generation + drift checking | Complete, tested |
| 9 | Reusable local starter installation (`starter:new`) | Complete, tested (package/upgrade-strategy distribution explicitly out of scope — see Phase 9's own limitations) |
| 10 | Architecture doctor + end-to-end validation | Complete, tested |

### Remaining limitations / honest gaps across Phases 4–10

- `BaseModel::applyDynamicFilters()`'s suffix-operator ambiguity (Phase 7)
  remains unfixed.
- `make:auth-module`-generated routes have no OpenAPI generation at all
  (Phase 8) — `docs:check-drift` will always flag them.
- No package-based reuse/upgrade strategy, no standalone
  pre-checkout installer (Phase 9).
- No relationship-aware stats, no nested-write support for
  relations/images, no response/error-schema OpenAPI documentation
  (Phases 5-8).
- No CI wiring for `docs:check-drift` (Phase 8) or `starter:doctor`
  (Phase 10) — both are CI-ready (plain exit codes) but nothing in this
  repo invokes them automatically yet.
- `starter:doctor`'s route-authorization check and `docs:check-drift`
  are both visibility/comparison tools, not authorization proofs —
  Phase 10's own Scenario B is the only place in this whole project that
  actually proves a generated `--permissions` gate denies-then-allows
  over real HTTP with a real actor identity switch.

## Phase 11 — Final Hardening and Quality Improvements (2026-10-26)

Driven by an independent review of the Phase 0–10 codebase. Every
reported defect is independently verified against the actual code
before any fix — some reported items turned out to already be handled
correctly (noted explicitly below, not silently skipped).

### Phase 11A — Dynamic Filtering

**What was found (independently verified, not just trusted):**
`app/Support/HasDynamicFiltering.php`'s `?active=` filter hardcoded the
target column to `status`. This trait is mixed into `App\Models\BaseAuthModel`
(line 17), and — confirmed by reading the file — `BaseAuthModel`'s OWN
copy of `scopeWithFilters()` is commented out (lines 274-317), so the
trait's version is the ONLY live implementation for every model that
extends `BaseAuthModel`: `Admin` and `User`. Both of those models use
`is_active`, not `status` — confirmed by their own migrations and by
`BaseAuthModel::scopeActive()`/`scopeInactive()` a few lines above the
trait's injection point, which already query `is_active`. This was a
real, live bug: `GET /api/v1/admin/users/all?active=0` or `?active=1`
targeted a column neither table has.

`App\Models\BaseModel` (the separate, non-auth base class `make:module`-
generated models extend) has its own, different `scopeWithFilters()` and
was never affected — confirmed by re-reading it; this bug was scoped to
`HasDynamicFiltering.php`/`BaseAuthModel` only.

**What was changed:** Added `protected string $activeColumn = 'is_active';`
to the trait; `?active=` now queries `$this->activeColumn ?? 'is_active'`
instead of the hardcoded `'status'`. A genuinely legacy model that uses a
real `status` column can restore the old behavior by overriding that one
property — nothing was re-hardcoded in the other direction.
Search/sort/date-range/allowlist enforcement in this same file were
reviewed and already correct (same `$dynamicFilterColumns`/`$allowedOps`
allowlist pattern as `BaseModel`, empty by default) — not touched, since
nothing there was actually broken.

**Tests:** New `tests/Feature/HasDynamicFilteringActiveColumnTest.php`
(3 tests, RefreshDatabase): `?active=1` returns only active users;
`?active=0` returns only inactive users; the separate `?status=`
soft-delete filter (0=onlyTrashed, 2=withTrashed) still works correctly,
unaffected by the column-name fix.

**Results:** `php artisan test` → 206 passed (970 assertions) — 203
carried over plus 3 new, zero regressions.

### Phase 11B — Image and Media Safety

**What was found (independently verified):** `BaseModel::cleanupReplacedFiles()`/
`cleanupAllFiles()` ran synchronously on the `updating`/`deleting`/
`forceDeleted` model events — which fire BEFORE the actual SQL statement
commits. If the triggering `save()`/`delete()` happened inside a
caller's `DB::transaction()` that later rolled back for an unrelated
reason, the OLD file had already been permanently deleted from disk by
the time the rollback reverted the DB row back to pointing at that same,
now-missing path. Filesystem writes can't be rolled back the way SQL
can — this was a real, reproducible defect, confirmed by a test that
fails without the fix and passes with it.

Reviewed and confirmed ALREADY correct, not touched: soft-delete safety
(`deleteFilesOnSoftDelete` defaults `false`, so a soft delete never
triggers cleanup at all); permanent-deletion authorization
(`forceDelete` routes already require `--guard`, Phase 4); no
cross-record file sharing (every upload gets a unique generator-chosen
UUID filename, Phase 6, so one record's cleanup can never touch another
record's file).

**What was changed:** Every actual `deleteFileQuietly()` call inside
both methods is now deferred via `DB::afterCommit(fn () => ...)` —
Laravel runs that callback immediately if there's no active transaction
(the common case; zero behavior change there, confirmed by a test) and
only after a genuine commit otherwise, so a rollback now leaves both the
DB row and the file exactly as they were.

**Tests:** New `tests/Feature/BaseModelFileCleanupTransactionSafetyTest.php`
(4 tests): a replaced file survives a rolled-back transaction (the core
regression test); a replaced file is still deleted normally when the
update genuinely commits (proves the fix doesn't just defer cleanup
forever); the generated image endpoints reject a request with no auth
token at all (401, real HTTP); and — the one genuinely new verification
this phase added rather than just confirming existing behavior — a
forced real DB failure (`Model::saving()` listener throwing) immediately
after a real file upload is proven to clean up the orphaned file via
the existing Phase 6 compensating-action logic, which had never
previously been exercised against an actual forced failure.

**Results:** `php artisan test` → 210 passed (990 assertions) — 206
carried over plus 4 new, zero regressions.

**Noted, not separately tested:** a pure storage-write failure (disk
unwritable, distinct from the DB-failure-after-successful-store case
tested above) would propagate as an exception caught by the generated
controller's own top-level try/catch, already a safe fallback by
construction — reviewed, not given its own dedicated test, since
simulating an actual filesystem failure under `Storage::fake()` isn't
meaningfully different from the DB-failure case already covered.

### Phase 11C — Authorization and Permissions

**What was found (independently verified):** `MakeModuleCommand::wirePermissionMiddleware()`
only ever wired `can:viewAny`/`can:create` onto the list/create routes —
exactly as Phase 4 had documented at the time, as a deliberate, bounded
limitation (this module's routes are flat, id-in-body, so `can:`
middleware's route-model-binding can't resolve an instance for
`update`/`delete`/etc.). The generated `{Name}Policy` already had
`update()`/`delete()`/`restore()`/`forceDelete()` methods — but nothing
in the whole generated module ever called `Gate::authorize()` for any
of them. Confirmed by reading every file involved (the policy, the
controller, the route-wiring code) before writing a single line of fix.

**A second, pre-existing, real bug found and fixed in the SAME pass**:
Laravel's console signature parser (`Illuminate\Console\Parser::parse()`)
treats ANY `{...}` substring in a command's `$signature` string as an
argument/option definition — including literal braces inside an
option's own DESCRIPTION text. `--manifest=`, `--stats`,
`--with-seeder`, `--with-tests`, and `--with-openapi`'s descriptions
(all written in earlier phases, e.g. `docs/openapi/modules/{module}.yaml`)
all had literal `{...}` placeholders in their help text, silently
truncating their own `--help` output ever since. This phase's OWN new
`--owner-column` option used the same `{column}`/`{key}` style and hit
the SAME bug much more severely: it accidentally introduced a bogus
REQUIRED POSITIONAL ARGUMENT named `key`, breaking `make:module`
entirely for every invocation (`Not enough arguments (missing: "key")`)
until caught by the very first real smoke test. All six descriptions
were rewritten to avoid literal curly braces (angle brackets or prose
instead), and `--help` output was re-verified afterward to show every
description in full, un-truncated.

**What was changed:**
- `{Name}Policy` gained `view()` (new — for `show`), `activate()`/
  `deactivate()` (new — both simply delegate to `update()`, since
  toggling active state is the same "can modify this record"
  permission as any other field change, not a separate tier).
  `forceDelete()` still always denies by default — confirmed as a
  deliberate, conservative choice (permanent deletion held to a higher
  bar than regular management access), not touched.
- New `--owner-column=` option: when set (requires `--permissions`),
  `view()`/`update()` also allow an actor whose own id matches that
  column on the target record — "ownership-based authorization... when
  configured," opt-in and explicit, not auto-inferred from relations.
- New `wireControllerPermissionOverrides()`: generates `show`/`update`/
  `activate`/`deactivate`/`destroy`/`restore`/`forceDelete` OVERRIDE
  methods on the module's OWN `{Name}Controller` (not the shared
  `FatherCrudController`, which every other module — most without any
  policy at all — also extends, so modifying it directly would have
  risked denying access on modules that never opted into
  `--permissions`). Each override resolves the target record using the
  EXACT SAME lookup scope `BasicCrudService`'s own corresponding method
  already uses (confirmed by reading it: `show()`/`restore()`/
  `forceDelete()` use `withTrashed()`, the rest don't) — authorizing
  against a record the underlying operation wouldn't even find would
  have been a correctness bug layered on top of the authorization fix.
- `{Name}MediaController`'s upload/delete now check the `update`
  ability, download checks `view`; `{Name}StatsController`'s `index()`
  checks `viewAny` — all only when `--permissions` is set, against the
  SAME generated policy, resolved from the SAME record upload/delete/
  download already load. "Secure image operations and statistics
  endpoints," as asked.

**Tests:** New `tests/Feature/MakeModulePermissionEnforcementTest.php`
(2 tests): one comprehensive sweep proving EVERY instance action
(`show`/`update`/`activate`/`deactivate`/`delete`/`restore`/
`forceDelete`, plus image upload and stats) is denied (403) for an
authenticated actor with no role, and succeeds for an actor holding the
exact `manage-{key}` role the policy checks for — including confirming
`forceDelete` stays denied even for that manager, by design; and a
second test proving `--owner-column` grants access to a record's own
owner with no manager role at all, while still denying an unrelated
actor.

**Results:** `php artisan test` → 212 passed (1027 assertions) — 210
carried over plus 2 new, zero regressions.

### Phase 11D — Secure OTP Defaults

**What was found (independently verified, by tracing the actual
request-to-delivery call chain, not by assuming the review's claim was
accurate as stated):** `RegisterRequest::rules()`'s
`otp_delivery_method` field was validated with
`Rule::in(array_keys(config('otp.channels')))` — and `config('otp.channels')`
unconditionally includes `'fake' => FakeOtpChannel::class` (it has to,
for tests/local dev with zero providers configured to work at all).
This meant ANY caller, in ANY environment including production, could
explicitly request `otp_delivery_method: "fake"`.
`FakeOtpChannel::deliver()` never throws outside the `testing`
environment — it logs a code-withheld message and returns normally — so
a production caller choosing "fake" received an apparent SUCCESS
response while the code was never actually delivered anywhere reachable
by a real user. The SAME unrestricted allowlist pattern was also
present in two more request classes: `RequestPhoneChangeRequest` and
`ResetPassword\SendResetOtpRequest`.

Reviewed and confirmed ALREADY correct, not touched: OTP hashing
(`Hash::make`/`Hash::check`, never plaintext), expiration, single-use
atomic consumption, cooldown, and the dual send/verify rate limits (all
in `OtpService`, none of this phase's changes touch that logic at all);
`SmsOtpChannel`/`WhatsAppOtpChannel` already fail loudly (throw) since
no real provider is configured for either — exactly the "fail securely"
behavior this phase asked for, already present since Phase 3A;
`DeliverOtpNotificationJob` already only records `delivered_at` after a
genuinely successful (non-throwing) `deliver()` call, records failures
distinctly, and never logs the raw code — closing the "fake" channel
upstream (in `OtpService::resolveChannel()`, before a job is ever
dispatched) means this already-well-designed job needed no changes;
`config('otp.default_channel')` turned out to be dead/unused
configuration (confirmed by searching every real call site) — nothing
in the actual delivery pipeline falls back to it, so its own
`env(..., 'fake')` default, while worth noting, isn't the exploitable
path.

**What was changed:**
- `OtpService::resolveChannel()` — the single choke point every
  delivery attempt passes through regardless of which request field or
  caller named the channel — now throws a clear `OtpException` (422) if
  `$channel === 'fake'` and `app()->environment('production')`. This is
  the authoritative fix: it closes the gap even for a hypothetical
  future caller that bypasses FormRequest validation entirely.
- `RegisterRequest`, `RequestPhoneChangeRequest`, and
  `ResetPassword\SendResetOtpRequest` each gained an `allowedOtpChannels()`
  helper excluding `'fake'` from the validation allowlist specifically
  in production — defense-in-depth, and a clearer 422 validation error
  at the request boundary instead of only a later service-layer
  exception.

**Tests:** New `tests/Feature/OtpSecureChannelDefaultsTest.php` (5
tests): `OtpService::issue()` with channel "fake" throws in a
simulated production environment; the fake channel still works
completely normally outside production (regression guard — every other
OTP-dependent test in this suite relies on this); SMS/WhatsApp channels
are confirmed unaffected, still failing loudly regardless of
environment; `RegisterRequest`'s validation rejects "fake" in production
and still accepts it outside production. "Production" is simulated by
temporarily overriding the `app()` container's `'env'` binding directly
— restored in `tearDown()` regardless of pass/fail, since every other
test in the whole suite depends on the real `testing` environment being
intact.

**Results:** `php artisan test` → 217 passed (1036 assertions) — 212
carried over plus 5 new, zero regressions.

**Noted, not separately fixed:** `config('otp.default_channel')`'s own
`env('OTP_DEFAULT_CHANNEL', 'fake')` default is technically still
"unsafe-looking" but is dead code today (nothing falls back to it) —
left as-is rather than changed, to avoid touching configuration no real
code path currently reads, which would be a change with no test able to
observe it.

### Phase 11E — OpenAPI and Postman

**What was found (independently verified):** `make:auth-module` had NO
OpenAPI generation at all — confirmed by grepping the whole file for
"openapi" (zero matches) and by Phase 8's own roadmap notes already
naming this as a known gap. `make:module`'s own fragment (Phase 8) was
otherwise solid, but its `/all/paginated`/`/all`/`/show` operations
never documented `?page=`/`?per_page=`/`?include=`, and `/stats` never
documented `?from=`/`?to=`/`?group_by=` — all genuinely functional query
parameters that were simply undocumented. Deliberately did NOT add
`?search=`/sort/filter query param documentation: `make:module` never
actually populates a generated model's `$search`/`$dynamicFilterColumns`/
`$sortableColumns` (Phase 5's deliberate secure-by-default design) — so
those stay empty until a developer opts in by hand, and documenting them
as available would itself be inaccurate documentation, the same "never
document a disabled thing as active" principle this file already
applies to routes.

**What was changed:**
- New shared trait `app/Console/Commands/Concerns/BuildsOpenApiFragments.php`
  — `openApiOperation()`/`dumpOpenApiYaml()` extracted out of
  `MakeModuleCommand` (where Phase 8 first wrote them) so
  `MakeAuthModuleCommand`'s new fragment generation (below) shares the
  exact same envelope-building and status-code-quoting-fix logic rather
  than a second, hand-maintained copy that could silently drift from the
  first — exactly the kind of duplication risk this same phase's own
  11C work had already run into with a copy-pasted brace bug across
  several option descriptions (see 11C's own notes above).
- New `make:auth-module --with-openapi`: generates
  `docs/openapi/modules/<module>.yaml` for an auth module. Reuses
  `publicRouteLines()`/`authenticatedRouteLines()` — the EXACT same
  methods that already build the real routes file — so the fragment can
  never drift from whichever routes this module actually gets. Request
  body schemas are read directly off `FatherAuthController`'s own real
  parameter types via PHP reflection (not a hand-duplicated field list)
  — the SHARED, pre-existing FormRequest classes it type-hints
  (`RegisterRequest`, `LoginRequest`, etc.) are introspected live, so the
  documented fields/required-ness can't drift from the actual validation
  rules either. An endpoint with no FormRequest parameter at all (e.g.
  `logout`) correctly gets no `requestBody` section rather than an empty
  one.
- `openApiOperation()` gained an optional `$queryParameters` argument;
  `make:module`'s fragment now documents `page`/`per_page` on
  `/all/paginated`, `?include=` (enum'd to the module's own declared
  relation names) on `/all/paginated`/`/all`/`/show` when `--relations`
  was used, and `from`/`to`/`group_by` (enum'd to the `--stats-group-by`
  allowlist) on `/stats` when `--stats` was used.

**Tests:** New `tests/Feature/MakeAuthModuleOpenApiTest.php` (4 tests):
a disabled (no `--enable-routes`) auth module's fragment has empty
`paths`; an enabled module's fragment accurately reflects its real
endpoints (public vs guarded, request fields and required-ness read
from the real `RegisterRequest`, `logout` correctly has no `requestBody`,
an action NOT actually enabled for this module's `--login`/`--otp-actions`
choice is correctly absent); `openapi:build` aggregates BOTH an auth
module's and a regular module's fragments together into one document;
and `docs:check-drift` no longer flags an auth module that used
`--with-openapi`. Extended the existing
`tests/Feature/OpenApiAndPostmanGenerationTest.php` with assertions for
the new `page`/`per_page` parameters (and confirming `?include=` is
correctly ABSENT when no `--relations` were declared).

**Results:** `php artisan test` → 221 passed (1066 assertions) — 217
carried over plus 4 new tests (plus extended assertions on an existing
one), zero regressions.

**Already correct, confirmed not touched:** `openapi:build --postman`
and `docs:check-drift` (both Phase 8) needed no changes — re-verified
against the new, richer fragment shapes (query parameters, auth-module
paths) and both continued to work exactly as before.

### Phase 11F — Starter Installer

**What was found (independently verified by actually reading this real
checkout's own `storage/` tree, not by assuming the review's claims):**

1. **Real bug**: PHP's `fnmatch()` function matches a leading dot with
   `*` by default (no `FNM_PERIOD` flag was passed) — so `starter:new`'s
   broad exclusion patterns like `"storage/framework/sessions/*"` were
   ALSO excluding that directory's own `.gitignore` placeholder,
   directly contradicting the command's own stated design ("the
   directory itself... still needs to exist... only its runtime
   CONTENTS are excluded"). Confirmed concretely: this checkout's real
   `storage/framework/cache/data/.gitignore` and
   `storage/framework/sessions/.gitignore` both exist and would both
   have been silently dropped from every project `starter:new` ever
   created.
2. **Real gap**: "do not copy... existing uploads" was never actually
   enforced. `storage/app/public/*` and `storage/app/private/*` — the
   disk roots `make:module --images` (Phase 6) stores real uploaded
   files under — were not in the exclusion list at all. A deployment
   with real user-uploaded images would have had every one of them
   copied into every new project created from it.

Confirmed already correct, not touched: the non-empty-target refusal,
fresh `.env`/`APP_KEY` generation, no automatic `composer install`/
migration/seeding, and the Windows/Laragon installation notes (all
Phase 9) needed no changes.

**What was changed:**
- `isExcludedFile()` now checks `basename($relativePath) === '.gitignore'`
  FIRST, unconditionally keeping any `.gitignore` file regardless of
  which excluded directory it lives in — fixing bug #1 for every
  currently-excluded directory at once, not just the ones a test happens
  to cover.
- Added `storage/app/public/*` and `storage/app/private/*` to the
  exclusion patterns — fixing bug #2. Their own `.gitignore` placeholders
  still survive, via the same fix above.
- New: "provide a realistic, maintainable update strategy for shared
  starter tooling." A full package-registry/auto-merge system was
  judged a substantially larger undertaking than this command's own
  scope (already an accepted Phase 9 limitation, not half-built here
  either). The one fact any REAL future upgrade/diff workflow needs
  first is knowing exactly which commit of the source starter kit a
  project started from — `starter:new` now writes a small
  `.starter-kit.json` manifest into the new project recording the
  source path, its git commit (read via `git rev-parse HEAD`, `null` if
  the source isn't a git repository), and a ready-to-use
  `git log <commit>..HEAD -- app/Console/Commands ...` hint — a
  realistic, git-native workflow needing no new tooling or package
  registry, only this one recorded fact.

**Tests:** Extended `tests/Feature/StarterNewProjectCommandTest.php`
(+3): every `.gitignore` placeholder under the excluded runtime
directories survives the copy; a real (disposable, test-only) file
temporarily placed under `storage/app/public` and `storage/app/private`
in the SOURCE project is confirmed NOT copied, while those directories'
own `.gitignore` placeholders still are; and `.starter-kit.json` is
written with the correct source path, a validly-shaped 40-character git
commit hash, and the upgrade hint.

**Results:** `php artisan test` → 224 passed (1083 assertions) — 221
carried over plus 3 new, zero regressions.

### Phase 11G — Code Quality

**Debugging statements / abandoned files:** swept `app/Console/Commands/`,
`app/Models/`, `app/Services/`, `app/Http/`, `tests/Feature/` for
`var_dump`/`dd`/`dump`/`fwrite(STDERR`/`*.bak`/`*.orig`/`*~` — none
found. All apparent matches (`Yaml::dump()`, `$validator->errors()->add()`)
are legitimate, non-debugging calls.

**Unsafe defaults:** swept `app/Models/*.php` for `$guarded = []` or a
wildcard `fillable`/`guarded` entry — none found; every model already
declares an explicit `$fillable` list.

**A real, untracked `base.zip` containing a genuine `.env`** (not just
`.env.example`) was found sitting at the project root during this sweep
— 1120 bytes, distinct from the example file, confirmed via `unzip -l`
(listing only; contents were never extracted or displayed, per this
project's own "never display or commit credentials" rule). Confirmed via
`git status`/`git log --all -- base.zip` that it has **never** been
committed to this repository's history — so there is nothing to purge
from git itself. This is almost certainly the archive an external
reviewer used to produce the Phase 11 task. **Action taken:** added
`*.zip` to `.gitignore` so one can never be accidentally `git add`ed in
the future. **Action deliberately NOT taken:** the file itself was not
deleted — removing it is a one-line decision for whoever owns it, not
something to do unilaterally on a security-sensitive artifact that might
still be needed. **Recommended follow-up for the project owner (not
executed here):** confirm whether this exact archive was the one shared
externally, and if so, rotate whatever credentials the enclosed `.env`
contained, since it must be treated as exposed.

**Obsolete/duplicated generator commands — inspected, not removed:**
read the full `php artisan list` generator inventory and every command
body that looked like a candidate.
- `make:auth-token` / `make:auth-user-token` were already retired safely
  in an earlier phase ("Writes nothing; always fails" with a pointer to
  `make:auth-module") — confirmed still correct, no action needed.
- `make:mcm` (`CreateModelControllerMigrationCommand`) genuinely
  overlaps `make:module`'s scope (model+migration+controller+resource+
  request+service+facade) but is a much weaker, pre-`make:module`
  pipeline: fully interactive by default, produces empty stub classes
  ("// Your service methods here"), and has no validation, policy,
  routes, tests, or OpenAPI output. It is a real duplication finding,
  but — unlike the already-dead auth-token commands — it is not a pure
  no-op double: it still runs and produces *something*, and this phase
  has not verified nothing external calls it. Consistent with Phase 4's
  own precedent ("consolidate by composition, retire nothing without
  verified compatibility"), it was left working and NOT disabled;
  documented here as a strong candidate for a future, explicitly-
  authorized retirement in favor of `make:module`.
- `make:salon-model` / `make:tenant-model` reference domain concepts
  (a `salons` table, a `migrations/tenant` folder) that exist **nowhere
  else** in this codebase — confirmed by grepping `app/`, `database/`,
  `routes/` for "salon"/"tenant": the only other hits are
  `app/Http/Helpers/README.md` (documenting a `SalonIdHelper`,
  `SetSalonIdForRequest` middleware, and `SalonIdServiceProvider` that
  **do not exist as classes anywhere in this codebase** — confirmed via
  `find`) and one already-fixed Phase 1 comment noting a prior stub
  imported a `TenantService` "from a namespace that does not exist in
  this app." Together these confirm this starter kit was derived from an
  earlier, unrelated salon-booking/multi-tenant project, and cleanup was
  incomplete: these two commands and that one README are leftover,
  dead, out-of-scope cruft, not generic starter-kit architecture. Left
  in place (not deleted) for the same reason as `make:mcm` — removing a
  command file is a reversible-via-git but still real change this phase
  did not have standing authorization to make unilaterally — but flagged
  here explicitly, with the evidence, as safe to delete on the project
  owner's own say-so.

**Final consolidated Pint pass:** ran Pint across every file touched in
Phases 11A–11F plus `tests/Feature/` as a whole; fixed import-ordering
style debt in two pre-existing test files unrelated to Phase 11's own
changes (`MakeAuthModuleCommandTest.php`, `OtpServiceTest.php`) and
confirmed no fixes were needed in any Phase 11A–11F file itself (already
clean from their own per-phase Pint runs).

**Results:** `php artisan test` → 224 passed (1083 assertions), zero
regressions from the Pint sweep.

### Phase 11H — Final End-to-End Validation

Three of the four required scenarios already existed from Phase 10's
own end-to-end work (`tests/Feature/StarterE2EScenariosTest.php`):
A (basic CRUD lifecycle: create/paginated-list/activate-independent-of-
soft-delete/soft-delete/restore), B (relations + single/multiple images
+ stats + role-based `--permissions`), C (auth module register -> verify
-> login against its own generated routes). Two real gaps against this
phase's exact requirements were identified and closed:

- **Record-level (ownership) authorization** — a genuinely new Phase
  11C feature (`--owner-column`) — was not exercised by any end-to-end
  HTTP scenario test. Already covered by real HTTP requests in
  `tests/Feature/MakeModulePermissionEnforcementTest.php` (owner gets
  200 with no manager role; an unrelated stranger gets 403), added in
  Phase 11C itself — not duplicated again here, consistent with this
  project's "no unnecessary... abstractions" (and by extension,
  redundant-test) guidance.
- **Scenario D — a fresh-project chain** (`starter:new` + module
  generation + Postman/OpenAPI + `starter:doctor`, all working
  together) did not exist anywhere. Added
  `test_scenario_d_fresh_project_generation_docs_and_doctor_chain()`:
  runs `starter:new` into a disposable temp directory (reusing, not
  re-asserting in detail, `StarterNewProjectCommandTest`'s own
  independent-copy guarantees), then — inside this already-booted test
  app, since the fresh copy has no `vendor/` of its own to run artisan
  against — generates a disposable module with `--with-openapi`, runs
  `openapi:build --postman`, confirms the generated paths/schema/Postman
  collection/environment files are correct, runs `docs:check-drift` and
  confirms it reports no drift for the new module, and finally runs
  `starter:doctor` end-to-end and asserts a clean (exit 0) result that
  correctly reports every core generator command as registered. This is
  the first test in the whole suite that chains all four of these tools
  together rather than exercising each in isolation.

**Results:** `php artisan test` → 225 passed (1099 assertions) — 224
carried over plus 1 new (itself asserting 16 conditions), zero
regressions.

### Post-11H cleanup — legacy command and secret-hygiene removal (2026-10-26)

A follow-up pass to act on the findings Phase 11G deliberately left for
the project owner's own decision, now explicitly authorized.

**Removed (confirmed unused, zero references outside their own files
before deletion — checked by grepping every `.php`/`.md` file in the
project, `bootstrap/app.php`'s `->withCommands([])` for an explicit
registration list, which was empty since Laravel 12 auto-discovers
`app/Console/Commands/*.php` by convention, and `tests/` for any
coverage):**
- `app/Console/Commands/MakeSalonModelCommand.php` (`make:salon-model`)
- `app/Console/Commands/MakeTenantModelCommand.php` (`make:tenant-model`)
- `app/Console/Commands/CreateModelControllerMigrationCommand.php`
  (`make:mcm`) — confirmed, on closer look, this was a genuine
  duplicate predecessor of `make:module` (same model/controller/
  resource/request/service/facade scope) with no feature `make:module`
  doesn't already cover better (validated requests, policies, routes,
  tests, OpenAPI) — not just inert cruft like the two above, but also
  safe to remove outright: nothing calls it, and it has no test
  coverage to preserve.
- `app/Http/Helpers/README.md` (and the now-empty `app/Http/Helpers/`
  directory) — documented a `SalonIdHelper`/`SetSalonIdForRequest`/
  `SalonIdServiceProvider` system that does not exist as actual code
  anywhere in this project (confirmed via `find`/`grep` before
  deleting) — stale documentation for a feature from the unrelated
  prior project this starter kit was derived from, not this reusable
  starter kit's own architecture.

Verified after removal: `php artisan list` no longer shows
`salon`/`tenant`/`mcm`; `php artisan --version` and `starter:doctor`
both still boot and run cleanly; zero dangling references to any
removed class/file remain anywhere in `app/`, `routes/`, `config/`,
`bootstrap/`, `tests/`, or `docs/` (only this roadmap's own historical
Phase 11G entry still names them, which is correct — it's a record of
what was found, not live code).

**`base.zip` — inspected, deliberately NOT deleted:** `unzip -l`
(listing only; contents were never extracted or displayed) confirms it
still contains a real `.env` (1120 bytes) alongside `.env.example`
(1086 bytes). `git ls-files --error-unmatch base.zip` confirms it is
**not tracked** by git (never committed — nothing to purge from
history), and `git check-ignore -v base.zip` confirms it **is**
actively ignored (matches the `*.zip` rule added in Phase 11G), so it
cannot be accidentally `git add`ed going forward either. **Recommendation
(for the project owner to act on, not executed here):** if this exact
archive was ever shared outside this machine (e.g. with an external
reviewer), treat the credentials inside its `.env` as compromised and
rotate them; otherwise it is safe to delete manually (`rm base.zip`)
once you've confirmed you don't need it — it plays no role in anything
this codebase does.

**`.gitignore` hardened:** the previous `.env`/`.env.backup`/
`.env.production` enumeration is replaced with a wildcard `.env*` plus
an explicit `!.env.example` exception (the one `.env*` file that must
stay tracked, since it has no real secrets and is the template fresh
clones copy from) — this now excludes *any* future `.env.*` variant
(`.env.local`, `.env.staging`, ...) by default instead of only the
three names already known. Added generic temporary/backup-file patterns
(`*.tmp`, `*.bak`, `*.orig`, `*~`) alongside the existing `*.zip`/
`*.log`/IDE-file rules.

**Pint:** no PHP files were edited by this cleanup (only deletions plus
a `.gitignore` change), so there was nothing new for Pint to check in
this change set. `./vendor/bin/pint --test` across the whole project
does report pre-existing style debt in ~90 files untouched by any
Phase 0–11 work — deliberately left alone here: a full-repo reformat is
unrelated, unreviewed scope far beyond "clean up the legacy commands
and secret hygiene," and risks masking a real behavior change inside a
sweeping style diff. Left as a known, pre-existing item, not a
regression introduced by this cleanup.

**Live verification:** `php artisan starter:doctor` run directly
(not just inside a test) against the real project after all deletions:
every generator command (`make:module`, `make:auth-module`,
`openapi:build`, `docs:check-drift`, `starter:new`, `starter:doctor`)
still reports `[PASS] ... is registered`; all 4 OTP channel providers,
both storage disks, and the OpenAPI doc check all pass. The one
`[FAIL]` (database unreachable) is this environment's live dev database
connection, unrelated to anything this cleanup touched — the test suite
itself uses an isolated in-memory SQLite connection (configured in
`phpunit.xml`) and is unaffected, which is exactly why it still passes
in full below.

**Results:** `php artisan test` → 225 passed (1083 assertions), zero
regressions. Committed as a follow-up to the Phase 11 initial commit
(`7ff0b5b`).
