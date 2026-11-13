# WAFFRLI E2E run — 2026-11-13T08:35:54.418Z

Target: http://127.0.0.1:8080 (audit stack without Docker: php artisan reverb:start, php artisan queue:work on a database queue, PHP built-in server, disposable SQLite database, Reverb ws://127.0.0.1:8085). Runner: `node e2e/run.mjs`.

**8 passed, 4 failed, 0 skipped** (12 steps).

> **Reading this run (code 4df35ff).** S4, S5, S7 and S7.b are the evidence from this file. S3.3, S3.4, S3.6 and S3.7 failed for an environment reason: the disposable SQLite database was locked by the queue worker while the admin offer was being created (SQLite allows one writer; production uses MySQL), so no offer was created and the dependent steps had nothing to observe. The same offer scenarios were re-run on the same code a few minutes later and passed 8/8: see [e2e-delivery-offers.md](e2e-delivery-offers.md).


Kinds: `http` real HTTP request to the API · `ws` real WebSocket to Reverb (Pusher protocol) · `browser` real Chromium on Filament · `fake-provider` SMS/push go to the local fake drivers (nothing is sent to a real SMS, WhatsApp or FCM service).

| # | Step | Kind | Result | ms | Detail |
|---|---|---|---|---|---|
| S3.1 | Drivers A and B connect; B cannot subscribe to A's channel | ws | PASS | 803 | A private-driver.5 200; B → A's channel 403; B own 200; forged signature refused by Reverb |
| S3.2 | Driver A registers a (fake) device token | http | PASS | 44 | token #10 (hash bb09eda15d2e30d7) |
| S3.3 | Admin sends an offer → only driver A receives offer.created (no customer data) | ws | FAIL | 10400 | driverA: offer.created not received within 10000 ms (got: nothing) |
| S3.4 | Push for the offer while A's app is connected (SKIP_WHEN_ONLINE=true) | push | FAIL | 20154 | no push outcome in the worker log |
| S3.5 | Driver B cannot read or accept A's offer | http | PASS | 76 | GET 404, accept 404 |
| S3.6 | GET /driver/offers/current is the authoritative pending offer | http | FAIL | 32 | {"offer":null} |
| S3.7 | Expiry: offer.withdrawn(expired) arrives; accepting afterwards is refused | ws | FAIL | 0 | driverA: offer.withdrawn not received within NaN ms (got: nothing) |
| S3.8 | App closed: the next offer is pushed (fake FCM); reconnecting shows it | push | PASS | 2920 | offer #9: push.fake_delivered {"token_sha256":"bb09eda15d2e30d7","title":"عرض توصيل جديد"} ; after reconnect GET current → #9; rejected to clean up |
| S4 | New customer order → admin bell updates live and the order appears without reload | browser | PASS | 7003 | order WF261113-NUJDXU: bell 23 → 24 after 1899 ms without reload; list re-rendered with the order; 1 database notification(s) for this admin |
| S5 | PLUS request → admin notified; approval → customer notified live, stored, unread count, push | ws | PASS | 3037 | admin bell: "New PLUS request"; customer live event "plus.request_approved" after 392 ms ("WAFFRLI PLUS"); stored and listed; unread 17→18→17 after mark-read; push: push.skipped_online {"token_id":11,"key":"notification: |
| S7 | Reverb stopped: checkout and acceptance succeed; notification stored; push still sent; list polls | outage | PASS | 4114 | order WF261113-ETTROQ placed and accepted with Reverb down; customer notification stored; presence unknown → push sent (push.fake_delivered {"token_sha256":"ea38d3d04947d965","title":"Order ); broadcast failure logged: y |
| S7.b | Reverb restarted: clients reconnect and re-authorize | ws | PASS | 3104 | new socket 660751363.45826563; private-user.5 authorized again |
