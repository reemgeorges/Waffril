# WAFFRLI E2E run — 2026-11-01T14:05:42.540Z

Target: http://localhost:3000 (Docker Compose stack, MySQL 8.4, Redis queue worker, Reverb -). Runner: `node e2e/run.mjs`.

**9 passed, 0 failed, 0 skipped** (9 steps).

Kinds: `http` real HTTP request to the API · `ws` real WebSocket to Reverb (Pusher protocol) · `browser` real Chromium on Filament · `fake-provider` SMS/push go to the local fake drivers (nothing is sent to a real SMS, WhatsApp or FCM service).

| # | Step | Kind | Result | ms | Detail |
|---|---|---|---|---|---|
| P0 | Portal reachable and onboarded (e-mail sign-in) | http | PASS | 30 | http://localhost:3000 — onboarding completed |
| P1 | Developer A signs in to the portal admin dashboard (first user becomes administrator) | browser | PASS | 6792 | admin dashboard: /admin/; onboarding can be re-run by anonymous callers: false |
| P2 | Developers A and B sign in to the app with SEPARATE accounts (magic link) | browser | PASS | 10258 | dev-a@waffrli.local (cmvxvdpl…) and dev-b@waffrli.local (cmvxvivm…), separate browser profiles |
| P3 | Developer A creates the "WAFFRLI" workspace, imports all collections and team environments | browser+graphql | PASS | 1505 | 3 collections, 223 requests (all Bruno requests, imported — not retyped); environments: WAFFRLI Local, WAFFRLI Shared-Dev |
| P4 | Developer A invites B; B accepts the e-mailed invitation in the UI | browser | PASS | 26 | B is a member of "WAFFRLI" with role EDITOR |
| P5 | Developer B sees the shared collections and environments in the UI | browser | PASS | 7309 | WAFFRLI — Customer API, WAFFRLI — Driver API, WAFFRLI — Admin API visible; team environments WAFFRLI Shared-Dev, WAFFRLI Local |
| P6 | Developer B edits a shared request in the UI and saves it; A sees the change | browser | PASS | 11733 | B added header X-Waffrli-Note: e2e-1793541976097 and saved; A's view of the shared workspace contains it |
| P7 | Developer B sends real requests from the portal; converted Bruno assertions pass | browser | PASS | 5230 | GET <<base_url>>/api/v1/admin/ping → 200 {"status":"ok"} shown in B's browser |
| P8 | Token management: B logs in with LOCAL secrets, the token is captured and used; nothing secret is synced to A | browser | PASS | 18724 | Login (success) 200 (8 assertions passed) → admin_token captured by the converted script → Cities/List all 200 (5 passed); A's shared environment holds no secret value |
