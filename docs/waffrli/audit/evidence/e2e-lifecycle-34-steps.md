# WAFFRLI E2E run — 2026-11-13T08:30:31.822Z

Target: http://127.0.0.1:8080 (audit stack without Docker: php artisan reverb:start, php artisan queue:work on a database queue, PHP built-in server, disposable SQLite database, Reverb ws://127.0.0.1:8085). Runner: `node e2e/run.mjs`.

**34 passed, 0 failed, 0 skipped** (34 steps).

Kinds: `http` real HTTP request to the API · `ws` real WebSocket to Reverb (Pusher protocol) · `browser` real Chromium on Filament · `fake-provider` SMS/push go to the local fake drivers (nothing is sent to a real SMS, WhatsApp or FCM service).

| # | Step | Kind | Result | ms | Detail |
|---|---|---|---|---|---|
| 0.1 | Stack reachable (/up, /admin/login, Reverb) | http | PASS | 139 | Reverb socket_id 355287283.383931396; Origin localhost accepted; no Origin (native app) accepted; https://evil.example and localhost.evil.example refused (4009) |
| 1 | Customer authenticates (phone OTP via fake SMS + dev peek) | http+fake-provider | PASS | 538 | customer #1; the OTP was read from the development-only peek route (fake SMS driver), never from a real SMS |
| 2 | Address and delivery city | http | PASS | 70 | address #1 in Damascus; default currency USD |
| E1 | Scheduled order paid by wallet, cancelled by the customer, wallet restored | http | PASS | 401 | WF261113-IOOS5D scheduled for 2026-11-13T11:30:32+00:00; cancelled; wallet back to 1; second cancel 409 |
| E2 | Duplicate and concurrent checkouts | http | PASS | 452 | 4 parallel requests with one key → 1 order (4×200, others -); replay returns it; new key on the consumed cart → 404 |
| 3-5 | Browse branches, product with options, cart with server-priced preview | http | PASS | 193 | Chicken shawarma wrap (1 option(s): Bread, Extras) ×2 at Mazzeh branch; preview total 4.50 |
| FCM.1 | Register a device token (fake FCM transport) | http+fake-provider | PASS | 34 | token #6 (hash def8340eaabbebaf); WAFFRLI_PUSH_DRIVER=fake: nothing is sent to Firebase |
| 6 | PLUS membership and benefits | http | PASS | 26 | active cycle: true; free deliveries left: 6; discounts: 3 |
| 7-8 | Checkout: wallet payment + cash remainder (+ PLUS free delivery) | http | PASS | 104 | WF261113-3JQLY3 total 4.50 USD = wallet 1.00 + cash 3.50; free delivery true |
| WS.1 | Customer connects to Reverb and subscribes to its order channel | ws | PASS | 94 | socket 895454715.464848722: private-order.45 subscribed (auth signed by /api/v1/customer/broadcasting/auth) |
| LOGIN.D | Driver logs in (phone + password) and is available | http | PASS | 388 | driver #5 available; private-driver.5 subscribed |
| WS.2 | Channel authorization denies foreign channels (real auth endpoint + Reverb) | ws | PASS | 788 | native client (no Origin): own channel 200; other customer's order 403, user 403; driver → admin map 403; forged signature → refused by Reverb |
| 9 | Order visible in Filament (admin session login) | browser | PASS | 2456 | WF261113-3JQLY3 listed and opened; the panel's own Echo client subscribed to private-admin.orders and private-admin.drivers-map |
| 10 | Admin accepts the order in Filament → customer and admin receive the event | browser+ws | PASS | 1401 | customer socket got order.status_changed(accepted) after 724 ms; admin browser too |
| FCM.2 | Push for "accepted" handled by the queue worker (fake FCM, observed in the log by token hash) | fake-provider | PASS | 0 | queue worker → SendPushMessage → fake transport: push.fake_delivered for token hash def8340eaabbebaf. NOT delivered to a real device. |
| 11 | Admin assigns the driver in Filament → driver receives the event | browser+ws | PASS | 2046 | driver socket got order.status_changed(assigned) after 458 ms |
| 12 | Driver API sees the assigned order | http | PASS | 41 | cash to collect 3.50 USD |
| 13-14 | Driver GPS update → driver.location reaches the customer (WS) and the admin map channel (browser) | http+ws+browser | PASS | 949 | customer got it after 863 ms; admin map channel got it; the other customer's socket received nothing (isolation) |
| 15 | Filament driver map shows the new position | browser | PASS | 1304 | marker at 33.5102, 36.2946 |
| WS.3 | Customer socket reconnects and keeps receiving events | ws | PASS | 700 | new socket 364429182.206000988; event after 515 ms |
| FCM.3 | Unregistered device token is deactivated by the push job (fake FCM "UNREGISTERED") | fake-provider | PASS | 37 | token #7 registered (replaces the previous one on the same device); checked after the next notification |
| WS.4 | Native client (no Origin header) of the customer receives the order events | ws | PASS | 85 | socket 332547828.82424219 without Origin subscribed to private-order.45 with the app's signed authorization |
| 16 | Driver picks up → customer receives picked_up | http+ws | PASS | 862 | after 797 ms (browser-style and native clients) |
| 17 | Driver delivers and collects the cash remainder | http+ws | PASS | 1095 | collected 3.50; wrong amount 422; event after 905 ms; duplicate 409 |
| FCM.3b | The unregistered token is now inactive | fake-provider | PASS | 28 | token #7 deactivated by SendPushMessage |
| 18 | History, payments, wallet ledger and driver cash records | http | PASS | 192 | history pending→accepted→assigned→picked_up→delivered; wallet 1.00/1.00, cash 3.50/3.50; wallet ledger has the order_payment entry; driver cash collection listed (unsettled: {"currencies":[{"currency":"USD","to_collect_o |
| E7 | Admin refunds part of the delivered order in Filament → customer wallet credited | browser+http | PASS | 2155 | refund 1.00 recorded; customer USD wallet 0 → 1; listed in /customer/refunds |
| 19 | Notifications in English and Arabic | http | PASS | 62 | 5 order notifications; e.g. "Order WF261113-3JQLY3" / "الطلب WF261113-3JQLY3" |
| E4 | Customer rates a delivered product | http | PASS | 33 | rated 5 |
| E3 | PLUS custom request | http | PASS | 38 | one already pending (409) |
| E5 | Currency presentation: display currency converts prices for display only | http | PASS | 163 | 2.50 USD shown as 2.30 EUR |
| E6 | Suspension: an admin deactivates a customer → its token is refused; re-activation restores access | http | PASS | 402 | suspended → 403; re-activated → 200 |
| 20 | Unauthorized access is denied across apps and accounts | http | PASS | 153 | no token → customer/me 401; customer token → driver/me 401; driver token → customer/me 401; customer token → admin/cities/all 401; other customer → this order 404; customer → admin panel route without session 302 |
| 1.1 | Single session: an older customer token is revoked by a new login | http | PASS | 45462 | previous token → 401 after the new login |
