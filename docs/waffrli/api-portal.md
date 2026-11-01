# WAFFRLI shared API portal (Hoppscotch Community Edition)

The portal is a browser-based API workspace for the team. Each developer has their **own account**, and they share one **WAFFRLI workspace** with the collections and environments. Requests are executable from the browser, and edits are shared live. It is an *addition* to the other two tools, not a replacement:

- **Bruno** (`api-tests/bruno/`) stays the version-controlled, CI-runnable source of truth.
- **Scalar** (`/docs`) is the OpenAPI reference.

| | |
|---|---|
| Product | Hoppscotch Community Edition, self-hosted, free (MIT). Image `hoppscotch/hoppscotch:2026.9.0`, pinned |
| Why it fits | Team workspaces with roles (owner, editor, viewer), live-synced shared collections and environments, e-mail invitations, per-user secrets, example responses, scripts and tests, a collection runner. All of this is available in the free self-hosted edition (verified below). |
| Runs as | Compose profile **`portal`**: `hoppscotch` (app, admin dashboard and backend on one port), `hoppscotch-db` (its own PostgreSQL 16, not published), and `hoppscotch-migrate` (one-shot schema migration) |
| URL (local) | **http://localhost:3000**: app; `/admin`: admin dashboard |
| Sign-in | E-mail magic link. Locally the e-mails arrive in **Mailpit**, http://localhost:8025 |
| Data | Docker volume `hoppscotch-db`, which survives restarts and rebuilds |

## 1. Start it

Start the stack and the portal, then run the one-time onboarding:

```bash
docker compose up -d
```

```bash
docker compose --profile portal up -d
```

```bash
docker compose run --rm --no-deps -e HOPPSCOTCH_URL=http://hoppscotch node node scripts/portal/onboard.mjs
```

- The setup scripts do all of this with `--portal` (Bash) or `-Portal` (PowerShell).
- **Onboarding** configures e-mail sign-in through the SMTP server in `.env` (Mailpit locally). Hoppscotch stores that in its own database.
- Until onboarding is done, **and until the first administrator signs in**, Hoppscotch's onboarding endpoint is open. This is why the portal binds to `127.0.0.1` only, and why you should sign in to `/admin` right away (step 2.1).

Stop the portal (data is kept) with:

```bash
docker compose --profile portal stop
```

## 2. Two developers: accounts and collaboration

### 2.1 First developer (workspace owner)

1. Open http://localhost:3000/admin and choose **Continue with Email**. Enter your e-mail, open the link from Mailpit (http://localhost:8025) **in the same browser**, and you are signed in. The **first** user to sign in to the admin dashboard becomes the portal administrator, and the onboarding endpoint closes (verified: `canReRunOnboarding` becomes `false`).
2. Open http://localhost:3000, choose **Login → Continue with Email**, and confirm again through Mailpit.
3. Create the workspace: the workspace switcher (top right) → **+ New workspace** → name it `WAFFRLI`.
4. **Import the collections.** In the `WAFFRLI` workspace, go to Collections → **Import** → **Import from Hoppscotch** and choose `api-tests/hoppscotch/waffrli-collections.json`. You get 3 collections with **223 requests**: all Bruno requests, with example responses and scripts. Nothing is retyped.
5. **Import the environments.** Environments → **Workspace Environments** → Import → `api-tests/hoppscotch/waffrli-environments.json`. You get `WAFFRLI Local` (`base_url` `http://localhost:8080`) and `WAFFRLI Shared-Dev`.
6. **Invite** the second developer: the workspace switcher → **Invite** → their e-mail, role **Editor**.

### 2.2 Second developer

1. Open the invitation e-mail. Locally it is in Mailpit; on a shared server, in their mailbox.
2. Sign in when asked (magic link), then choose **Join**.
3. Switch to the `WAFFRLI` workspace. The shared collections and environments are there.

### 2.3 Working together

- **Shared:** collections, folders, requests (URL, headers, body, auth, scripts, example responses), and the team environments' **initial values**. A saved change (Ctrl+S) appears immediately for the other members.
- **Personal:**
  - **Secrets**, e.g. `admin_password`, `driver_password`, `dev_otp_token`, and the captured `*_token` values. These are stored only in your browser and never sent to the server; verified, see §4.
  - The current values that scripts set.
  - Your history and your personal workspace.
- **Enter your secrets** once per browser: Environments → `WAFFRLI Local` → ⋮ → **Edit** → **Secrets** → **Current value**. See the per-collection `.env.example` files in `api-tests/bruno/*/` for which values to use.
- **Select** `WAFFRLI Local` in the environment selector (top), then **Send**.

The login requests capture their tokens through their *post-request scripts*, so the protected requests that follow are authorised automatically:

- **Customer** — *Request OTP* → *Dev - read last OTP* → *Verify OTP (login)*, which sets `customer_token`;
- **Driver** — *Login (success)*, which sets `driver_token`;
- **Admin** — *Login (success)*, which sets `admin_token`.

- The **Test Results** tab shows the same assertions as the Bruno collections. They are converted automatically by a small compatibility layer at the top of each script.
- **Collection runner:** right-click a collection → **Run** executes a whole folder in order, like `bru run`.

Requests go from your browser to the API. Use the default **Browser** interceptor; the API's CORS allows it. Do not enable the *Proxy* interceptor: it sends requests through `proxy.hoppscotch.io`, an external service.

## 3. Keeping the portal in sync with the code

The generated files come from the Bruno collections and the real API examples:

```bash
python3 api-tests/bruno/generators/hoppscotch.py
```

To re-sync after the API changes, delete the three `WAFFRLI — …` collections in the workspace and import `waffrli-collections.json` again (§2.1, step 4).

> **Edits made to the generated collections are overwritten by a re-sync.** Keep your own experiments in an additional collection, which a re-sync never touches. Change shared request definitions in Bruno (`api-tests/bruno/generators/*.py`), so they are reviewed and versioned.

`node e2e/portal-collab.mjs` performs exactly this sync automatically. It uses the same `importCollectionsFromJSON` call as the app's importer.

## 4. Verified (2026-11-01, Docker, real Chromium)

The verification script is `node e2e/portal-collab.mjs`, with its report in `e2e/results/portal.md`. Two separate browser profiles and two separate accounts were used (`dev-a@waffrli.local`, `dev-b@waffrli.local`). Result: **9 passed, 0 failed.**

| # | Verified |
|---|---|
| P0 | Portal reachable and onboarded (e-mail sign-in) |
| P1 | A signs in to the admin dashboard and becomes administrator. Anonymous onboarding re-runs are then refused (`canReRunOnboarding: false`). |
| P2 | A and B sign in with **separate** accounts by magic link |
| P3 | A creates the `WAFFRLI` workspace and imports **3 collections / 223 requests** and 2 team environments |
| P4 | A invites B by e-mail, and B accepts **in the UI** as Editor |
| P5 | B sees the shared collections and environments in the UI |
| P6 | B edits a shared request in the UI (adds a header) and saves it; **A's workspace contains the change** |
| P7 | B sends a real request from the portal: `GET <<base_url>>/api/v1/admin/ping` → **200** `{"status":"ok"}` |
| P8 | B enters admin credentials as **local secrets** and sends *Login (success)*: **200**, 8 converted assertions passed, and `admin_token` captured. B then sends *Cities / List all* with `Authorization: Bearer …`: **200**, 5 assertions passed. The **shared** environment stored on the server contains **no** secret value. |

Also verified:

- **UI import into the team workspace.** Import → *Import from Hoppscotch* with `waffrli-collections.json` gave 3 collections and 223 requests, with the collection-level bearer auth preserved.
- **Persistence.** `docker compose --profile portal restart` left users (2), teams (1), members (2), collections (40), requests (223) and environments (2) unchanged, and onboarding stayed closed.

**Not verified:** access from two *different computers*. Both browser profiles ran on one machine against `localhost`. See §5 for a shared deployment.

## 5. Shared (remote) deployment: HTTPS

The local setup listens on `127.0.0.1` over plain HTTP, which is fine for one machine. For two developers on different computers, deploy it to a **development or staging** server (never production) behind HTTPS:

1. **Reverse proxy with TLS** (Caddy, Nginx or Traefik) for e.g. `https://api-portal.dev.example.com`, forwarding to `127.0.0.1:3000`. WebSocket upgrade is required for `/backend/graphql`, which carries the live updates.
2. In `.env`:
   - `HOPPSCOTCH_URL=https://api-portal.dev.example.com`
   - `HOPPSCOTCH_WS_URL=wss://api-portal.dev.example.com`
   - `HOPPSCOTCH_SECURE_COOKIES=true`
   - fresh random `HOPPSCOTCH_JWT_SECRET`, `HOPPSCOTCH_SESSION_SECRET`, `HOPPSCOTCH_DB_PASSWORD`, and a 32-character `HOPPSCOTCH_DATA_ENCRYPTION_KEY`
3. **Real SMTP**, so invitations and sign-in links reach real mailboxes:
   - `HOPPSCOTCH_SMTP_HOST`, `HOPPSCOTCH_SMTP_PORT`, `HOPPSCOTCH_SMTP_USER`, `HOPPSCOTCH_SMTP_PASSWORD`, `HOPPSCOTCH_SMTP_SECURE=true`;
   - run the onboarding script **immediately** after the first start, and sign in to `/admin` right away;
   - alternatively, configure Google, GitHub or Microsoft OAuth in the admin dashboard.
4. **Restrict access:** VPN or IP allow-list at the proxy.
5. **Shared-Dev API:** set `base_url` in the `WAFFRLI Shared-Dev` team environment to the shared API's HTTPS URL. That API's CORS must allow the portal's origin; the current configuration allows `*` without credentials.
6. **Back up** the portal database:

   ```bash
   docker compose exec -T hoppscotch-db pg_dump -U hoppscotch hoppscotch | gzip > backups/hoppscotch-$(date +%F).sql.gz
   ```

Secrets in the shared deployment still stay in each developer's browser. The server only stores collections, initial values and accounts.

## 6. Differences from Bruno and Scalar

| | Bruno | Hoppscotch portal | Scalar |
|---|---|---|---|
| Where | Git repository, desktop app or CLI | Browser, shared server | Browser, read-only |
| Collaboration | Through Git (pull requests) | Live shared workspace, accounts, roles, invitations | — |
| Source of truth | **Yes** (generated from `generators/*.py`) | Imported copy (re-sync, §3) | Generated from routes, rules and examples |
| Scripts/tests | Native | Converted (compatibility layer); same assertions | — |
| CI | `bru run` | — | Drift test |
| Secrets | `.env` per collection (git-ignored) | Browser-local secrets | — |

Known limitations:

- **File uploads:** multipart requests import with their file fields; pick the file in the UI before sending.
- **Rate limits:** OTP requests are limited (5 per hour per phone, a 60 s cooldown and 5 per minute per IP), exactly as with Bruno.
- **Default URL:** the portal's default new-request URL is `https://echo.hoppscotch.io`, an external echo service. Simply don't send to it.
