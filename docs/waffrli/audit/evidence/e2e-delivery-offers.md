# WAFFRLI E2E run — 2026-11-13T08:37:44.387Z

Target: http://127.0.0.1:8080 (audit stack without Docker: php artisan reverb:start, php artisan queue:work on a database queue, PHP built-in server, disposable SQLite database, Reverb ws://127.0.0.1:8085). Runner: `node e2e/run.mjs`.

**8 passed, 0 failed, 0 skipped** (8 steps).

Kinds: `http` real HTTP request to the API · `ws` real WebSocket to Reverb (Pusher protocol) · `browser` real Chromium on Filament · `fake-provider` SMS/push go to the local fake drivers (nothing is sent to a real SMS, WhatsApp or FCM service).

| # | Step | Kind | Result | ms | Detail |
|---|---|---|---|---|---|
| S3.1 | Drivers A and B connect; B cannot subscribe to A's channel | ws | PASS | 778 | A private-driver.5 200; B → A's channel 403; B own 200; forged signature refused by Reverb |
| S3.2 | Driver A registers a (fake) device token | http | PASS | 32 | token #12 (hash f37e4e5f135596de) |
| S3.3 | Admin sends an offer → only driver A receives offer.created (no customer data) | ws | PASS | 2488 | offer #12 → A after 624 ms; B received nothing; payload keys: event, offer_id, order_id, status, reason, at, offer; offer.delivery = {city, area, distance_km} (no customer address, coordinates, phone or notes); pickup =  |
| S3.4 | Push for the offer while A's app is connected (SKIP_WHEN_ONLINE=true) | push | PASS | 1 | queue worker: push.skipped_online {"token_id":12,"key":"notification:36267e7e-c66c-553d-85d6-f94823be6d82"}  (fake transport) |
| S3.5 | Driver B cannot read or accept A's offer | http | PASS | 68 | GET 404, accept 404 |
| S3.6 | GET /driver/offers/current is the authoritative pending offer | http | PASS | 30 | offer #12 pending, expires 2026-11-13T08:39:20+00:00 |
| S3.7 | Expiry: offer.withdrawn(expired) arrives; accepting afterwards is refused | ws | PASS | 87641 | withdrawn with status "expired" (627 ms after expires_at); late accept → 409 "This delivery offer is no longer available."; current offer: none |
| S3.8 | App closed: the next offer is pushed (fake FCM); reconnecting shows it | push | PASS | 2348 | offer #14: push.fake_delivered {"token_sha256":"f37e4e5f135596de","title":"عرض توصيل جديد"} ; after reconnect GET current → #14; rejected to clean up |
