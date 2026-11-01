# WAFFRLI E2E run — 2026-11-01T13:06:18.504Z

Target: http://localhost:8080 (Docker Compose stack, MySQL 8.4, Redis queue worker, Reverb ws://localhost:8085). Runner: `node e2e/run.mjs`.

**33 passed, 0 failed, 0 skipped** (33 steps).

Kinds: `http` real HTTP request to the API · `ws` real WebSocket to Reverb (Pusher protocol) · `browser` real Chromium on Filament · `fake-provider` SMS/push go to the local fake drivers (nothing is sent to a real SMS, WhatsApp or FCM service).

| # | Step | Kind | Result | ms | Detail |
|---|---|---|---|---|---|
| 0.1 | Stack reachable (/up, /admin/login, Reverb) | http | PASS | 602 | Reverb socket_id 502545720.193737830 (Origin http://localhost:8080); a client without Origin → refused 4009 Origin not allowed |
| 1 | Customer authenticates (phone OTP via fake SMS + dev peek) | http+fake-provider | PASS | 1175 | customer #1; the OTP was read from the development-only peek route (fake SMS driver), never from a real SMS |
| 2 | Address and delivery city | http | PASS | 103 | address #1 in Damascus; default currency USD |
| E1 | Scheduled order paid by wallet, cancelled by the customer, wallet restored | http | PASS | 1440 | WF261101-GDJXXE scheduled for 2026-11-01T16:06:21+00:00; cancelled; wallet back to 1; second cancel 409 |
| E2 | Duplicate and concurrent checkouts | http | PASS | 1066 | 4 parallel requests with one key → 1 order (4×200, others -); replay returns it; new key on the consumed cart → 404 |
| 3-5 | Browse branches, product with options, cart with server-priced preview | http | PASS | 429 | Chicken shawarma wrap (1 option(s): Bread, Extras) ×2 at Mazzeh branch; preview total 5.34 |
| FCM.1 | Register a device token (fake FCM transport) | http+fake-provider | PASS | 45 | token #15 (hash afa4f26bce17d478); WAFFRLI_PUSH_DRIVER=fake: nothing is sent to Firebase |
| 6 | PLUS membership and benefits | http | PASS | 44 | active cycle: true; free deliveries left: 0; discounts: 3 |
| 7-8 | Checkout: wallet payment + cash remainder (+ PLUS free delivery) | http | PASS | 171 | WF261101-LD7HEE total 5.34 USD = wallet 1.00 + cash 4.34; free delivery false |
| WS.1 | Customer connects to Reverb and subscribes to its order channel | ws | PASS | 122 | socket 589818213.894687928: private-order.52 subscribed (auth signed by /api/v1/customer/broadcasting/auth) |
| LOGIN.D | Driver logs in (phone + password) and is available | http | PASS | 504 | driver #1 available; private-driver.1 subscribed |
| WS.2 | Channel authorization denies foreign channels (real auth endpoint + Reverb) | ws | PASS | 962 | own 200; other customer's order 403, user 403; driver → admin map 403; forged signature → refused by Reverb |
| 9 | Order visible in Filament (admin session login) | browser | PASS | 4353 | WF261101-LD7HEE listed and opened; the panel's own Echo client subscribed to private-admin.orders and private-admin.drivers-map |
| 10 | Admin accepts the order in Filament → customer and admin receive the event | browser+ws | PASS | 1209 | customer socket got order.status_changed(accepted) after 878 ms; admin browser too |
| FCM.2 | Push for "accepted" handled by the queue worker (fake FCM, observed in the log by token hash) | fake-provider | PASS | 3 | queue worker → SendPushMessage → fake transport: push.fake_delivered for token hash afa4f26bce17d478. NOT delivered to a real device. |
| 11 | Admin assigns the driver in Filament → driver receives the event | browser+ws | PASS | 2008 | driver socket got order.status_changed(assigned) after 541 ms |
| 12 | Driver API sees the assigned order | http | PASS | 49 | cash to collect 4.34 USD |
| 13-14 | Driver GPS update → driver.location reaches the customer (WS) and the admin map channel (browser) | http+ws+browser | PASS | 928 | customer got it after 827 ms; admin map channel got it; the other customer's socket received nothing (isolation) |
| 15 | Filament driver map shows the new position | browser | PASS | 1212 | marker at 33.51482, 36.29212 |
| WS.3 | Customer socket reconnects and keeps receiving events | ws | PASS | 804 | new socket 327156231.893312654; event after 615 ms |
| FCM.3 | Unregistered device token is deactivated by the push job (fake FCM "UNREGISTERED") | fake-provider | PASS | 48 | token #16 registered (replaces the previous one on the same device); checked after the next notification |
| 16 | Driver picks up → customer receives picked_up | http+ws | PASS | 925 | after 850 ms |
| 17 | Driver delivers and collects the cash remainder | http+ws | PASS | 1058 | collected 4.34; wrong amount 422; event after 899 ms; duplicate 409 |
| FCM.3b | The unregistered token is now inactive | fake-provider | PASS | 43 | token #16 deactivated by SendPushMessage |
| 18 | History, payments, wallet ledger and driver cash records | http | PASS | 299 | history pending→accepted→assigned→picked_up→delivered; wallet 1.00/1.00, cash 4.34/4.34; wallet ledger has the order_payment entry; driver cash collection listed (unsettled: {"currencies":[{"currency":"USD","to_collect_o |
| E7 | Admin refunds part of the delivered order in Filament → customer wallet credited | browser+http | PASS | 2382 | refund 1.00 recorded; customer USD wallet 0 → 1; listed in /customer/refunds |
| 19 | Notifications in English and Arabic | http | PASS | 90 | 5 order notifications; e.g. "Order WF261101-LD7HEE" / "الطلب WF261101-LD7HEE" |
| E4 | Customer rates a delivered product | http | PASS | 45 | rated 5 |
| E3 | PLUS custom request | http | PASS | 40 | one already pending (409) |
| E5 | Currency presentation: display currency converts prices for display only | http | PASS | 173 | 2.50 USD shown as 2.30 EUR |
| E6 | Suspension: an admin deactivates a customer → its token is refused; re-activation restores access | http | PASS | 527 | suspended → 403; re-activated → 200 |
| 20 | Unauthorized access is denied across apps and accounts | http | PASS | 251 | no token → customer/me 401; customer token → driver/me 401; driver token → customer/me 401; customer token → admin/cities/all 401; other customer → this order 404; customer → admin panel route without session 302 |
| 1.1 | Single session: an older customer token is revoked by a new login | http | PASS | 39173 | previous token → 401 after the new login |
