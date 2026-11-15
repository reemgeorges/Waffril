# WAFFRLI NEW SYP: end-to-end test report (2026-11-15)

**Scope:** a live local test of the NEW Syrian pound (100 old SYP = 1 new SYP) across Laravel/Filament, the customer app and the driver app.

**Environment:** local only. `APP_ENV=local`, database `waffril` (MySQL 8.4, Laragon), API `127.0.0.1:8001`, Reverb `:8080`, emulator `WAFFRLI_Test` (Android 15) reaching `10.0.2.2`.

**Not a certification of real prices.** The SYP rate in the database (13000 old SYP per USD = 130 new SYP per USD) is a DEMONSTRATION rate. It was **not** approved, and Filament SYP price input stays disabled.

## 1. Environment and safety

| Item | State |
|---|---|
| MySQL | Running (Laragon) |
| API `php artisan serve` on 8001 | Running (started outside this session) |
| Reverb on 8080 | Started (`reverb:start --debug`) |
| Queue worker | Started (`queue:work --tries=3`) |
| Scheduler | Not started; not needed |
| `.env` (non-secret values read via config) | `APP_ENV=local`, SMS `fake`, push `fcm` (project `waffrli-dev`), `WAFFRLI_PUSH_SKIP_WHEN_ONLINE=false`, operational SYP, display denomination `new`, stored `old`, approved rate **empty**, dispatch offers **off** |
| Backup before testing | Full `mysqldump` → `%USERPROFILE%\.waffrli\backups\waffril-before-new-syp-e2e-20261115-121635.sql` (215 KB, complete) |
| Emulator | `WAFFRLI_Test`. Latest customer and driver debug APKs installed with `install -r` |
| Historical order `WF261113-UMOLKC` | Unchanged: delivered, 3.34 USD, rate 1, updated 2026-11-13 17:55:17 |

**No data was deleted.** No base switch or redenomination was run, no rate was changed, no real SMS was sent, and pushes went only to E2E test tokens.

## 2. Test records created (left in place)

| Record | Notes |
|---|---|
| Customer #14 `+12025550190` "E2E NewSYP" | Registered through the app UI. Default address "WAFFRLI NEW SYP E2E TEST Mazzeh" |
| Customer #12 `+12025550191` "E2E NewSYP API" | API registration. Owns the API orders, the 100 SYP wallet credit and the PLUS membership |
| Customer #13 `+12025550192` "E2E NewSYP Isolation" | Privacy checks |
| Driver #9 `+12025550291` "E2E NewSYP Driver App" | Driver app. Password only in `%USERPROFILE%\.waffrli` |
| Driver #10 `+12025550292` "E2E NewSYP Driver API" | API |
| Order #5 `WF261115-2UWWLG` | **UI E2E order**: delivered, cash collected, settled (settlement #2) |
| Order #3 `WF261115-4G4SEG` | API order: delivered, settled (settlement #1), partial refund 100 SYP (refund #1) |
| Order #4 `WF261115-BKEMIA` | API wallet order 50.50 SYP: cancelled, wallet reversed |
| Order #2 `WF261115-JVMUSU` | Left by an interrupted first API run; cancelled by admin |
| Order #6 `WF261115-BDCFNI` | PLUS order: cancelled, free delivery restored |
| PLUS plan #1 `e2e-new-syp-plus` | 1.0000 USD, Pizza Roma 10%. Membership/cycle #1 for customer #12 |

## 3. NEW SYP: example calculations (real values)

Stored SYP is OLD pounds. The API and every interface show NEW pounds (÷100, once).

| Item | Stored base | Old SYP (stored) | Shown (new) |
|---|---|---|---|
| Chicken shawarma | 2.5000 USD | 32,500 | **325** |
| Tannour bread option | 0.1000 USD | 1,300 | **+13** |
| Garlic sauce option | 0.2500 USD | 3,250 | **+32.50** |
| Mixed grill (15% offer) | 8.0000 USD | 104,000 → 88,400 | **1,040 → 884** |
| Delivery fee (Damascus 0–3 km band: 0.75 + 0.15 × 1.574 km) | 0.9861 USD | 12,819 | **128.19** |
| Fee rule shown in Filament | 1.00 USD + 0.20 USD/km | 13,000 + 2,600/km | **130 + 26/km** |
| PLUS plan price | 1.0000 USD | 13,000 | **130** |
| Rate | 13000 old per USD | | **130** new per USD |

**UI E2E order `WF261115-2UWWLG`.** Items: 2 × (325 + 13 + 32.50) = 741, plus 1 × 1,040, which gives a subtotal of 1,781. Offer −156. Delivery 128.19. **Total 1,753.19 SYP**, stored as 175,319 old.

The total was identical in:
- customer cart, checkout, receipt, order details and tracking (Arabic and English);
- Filament orders list and order view;
- payments page;
- driver dashboard, cash due, deliver sheet, collections, history and settlement;
- DB payment `amount = collected = 175,319` old;
- settlement expected = received = 175,319 old.

The Filament order view adds "Recorded as 175,319 SYP (old pounds)" for audit.

## 4. Backend/API (live HTTP harness, 50 of 51 checks passed)

Real calls to `127.0.0.1:8001`. Expectations were recomputed independently from the stored USD and the stored rate.

**Passed:**
- OTP registration (fake SMS) and addresses.
- Only SYP is offered, with `decimals: 2`, `denomination: "new"` and rate `130`.
- Every catalog price and option delta is converted once.
- Cart subtotal and fee equal the fee-rule quote.
- Checkout COD; idempotent replay returns the same order.
- Order frozen rate is 13000; the API total is the stored total ÷ 100.
- Admin accept/assign (OrderAdminService). Assignment to an offline driver is correctly refused.
- Driver login and availability; GPS update accepted.
- Pickup.
- Deliver with the old-unit number → **422**; with a sub-qirsh amount → **422**; with the exact new amount → **200**.
- Payment collected; driver summary, collections and settlement in new SYP; settlement settled.
- Partial refund of 10,000 old shown as **100.00**.
- Wallet checkout `wallet_amount=50.50` stored as **5,050** old. `wallet_amount=10000` (old number) is refused; 3 decimals are refused. Cancellation restores the wallet to 100.00.
- Customer/driver token isolation (401). Another customer's cart and order give 404.
- Historical USD order untouched.

**One failed check, a harness bug:** an order-list ownership check expected only this run's order IDs. It was re-verified separately: all listed orders belong to the caller, and the other customer sees none.

## 5. Filament (real pages rendered in-process as admin #1, Arabic and English)

**Method.** Real GET requests through the HTTP kernel against the live DB. This is not a browser session, because the admin password was not available and was not reset.

| Page | Result |
|---|---|
| Orders | 1,753.19 / 2,988.19 / 1,818.19 SYP. Historical **3.34 USD** labelled "Recorded in USD (no SYP equivalent was recorded)" |
| Order view | 1,781 / 156 / 0 / 128.19 / 1,753.19, plus the old-pound audit note |
| Payments | Matches, including wallet 50.50 and cash 1,767.69 |
| Refunds | 100 |
| Settlements | 2,988.19 |
| Delivery fee rules | 130 + 26/km, 97.50 + 19.50/km, … |
| Offers (fixed) | 39 |

- **Dashboard** (via the widgets' own data methods): SYP and USD rows kept separate, USD flagged as historical/separate. Today's sales 2,988.19 SYP.
- **Relation managers and widgets** load via Livewire after page load, so they are covered by the automated Livewire tests, not by this rendering.
- **Rate gate:** `approved_operational_rate = null` → `inputEnabled = false`. The price input stays a USD input, with the preview "≈ 325 ل.س · exchange rate not confirmed". **SYP price editing was not enabled or tested live**, as required.

## 6. Customer app (emulator, real UI)

**Flow:** registration (+1 country, OTP from the dev peek, never printed) → address with emulator GPS → home → Shami Grill Mazzeh menu → product options → cart → checkout COD → order confirmation → details → live tracking → delivered.

Then, in English: orders, tracking, PLUS screen, and wallet with payments and refunds (as customer #12).

**Arabic:** `325 ل.س`, `32.50 ل.س`, `1,753.19 ل.س`, `−50.50`.

**English:** `1,753.19 SYP`, `130 SYP`, `100 SYP`.

The currency picker is hidden (SYP only). Screenshots are in `screenshots/new-syp-e2e/c*.png`.

**Findings:**
- **Fixed:** home/branch offer badges showed `خصم 15.0000%`. Now `15% off` (customer `d63af6a`).
- **Minor, open:** the "order placed" confirmation screen does not update its status card live; the order details and tracking screens do.
- **Minor, open:** right after the first address is saved, the home header still says "add your address" until the screen reloads.
- **Minor, open:** the receipt line "1 × 1,040 = 884" mixes the pre-offer unit price with the after-offer line total.
- **Not testable via adb:** Arabic-Indic digit typing (`adb input text` cannot type non-ASCII). That parsing is covered by the driver widget tests.

## 7. Driver app (emulator, real UI)

- Demo driver #1 showed historical **3.34 USD**, not relabelled.
- E2E driver #9: login, availability on, live assignment (badge plus FCM notification displayed), cash to collect 1,753.19, GPS sharing.
- Pickup, deliver:
  - typing **175319** (old number) is refused in-app: "must be exactly 1,753.19 ل.س";
  - typing **1,753.19** is accepted.
- Dashboard after delivery, collections, settlement (settled 1,753.19 / 1,753.19), history "Cash collected 1,753.19 SYP".
- All checked in Arabic and English. Screenshots: `screenshots/new-syp-e2e/d*.png`.

## 8. Realtime, FCM and GPS

These are four different observations:

| Observation | Evidence |
|---|---|
| Event emitted | Queue processed `OrderStatusChanged`, `DriverLocationUpdated` and notifications |
| Delivered by Reverb | Debug log: broadcasts to `private-user.14`, `private-driver.9`, `private-order.5` (`driver.location`), `private-admin.*` |
| Received/displayed in-app | Customer details screen "live updates working", accepted → on the way → delivered without reload. Tracking showed "driver location · 12:43" with a map marker. The driver app received the assignment live |
| FCM accepted by Firebase | `push.sent`: 4 for customer #14's token, 1 for driver #9's token. No `push.failed` in this session |
| Displayed on device | Seen in `dumpsys notification`: customer "تم قبول طلبك WF261115-2UWWLG"; driver "تم إسناد توصيل جديد إليك: WF261115-2UWWLG". The others were not observed (app in foreground) |

**GPS:**
- Emulator geo-fix → driver foreground location service (type location) → `POST driver/location` stored 33.5170/36.2540, then 33.5160/36.2520 → broadcast once the customer was subscribed.
- Reverb's debug log shows no broadcast for channels without subscribers; the first two updates happened before the customer opened tracking.

## 9. PLUS (live API; 14 of 15 checks passed)

**Passed:**
- Plan price **130.00 SYP**; request → admin `approveNew` → cycle with 10 free deliveries.
- Two cart previews consume nothing.
- Pizza Roma 10% = **46.80** of 468.00.
- Free delivery: fee 0, original fee shown.
- Checkout total 421.20 equals the preview; it consumed exactly 1 free delivery.
- Order snapshot: SYP, rate 13000, PLUS discount 4,680 old.
- Cancellation restores the free delivery.

**One harness expectation was wrong:** the API sends percentages as stored (`"10.0000"`). The app normalizes them (`percentText`), and the screens show `10%`.

## 10. Automated tests

| Project | Command | Result |
|---|---|---|
| Laravel | `php artisan test` (full, after the fixes) | **657 passed, 23 skipped, 0 failed** (exit 0, 214 s). The run before the last fix had 1 timing failure (see 4 below) |
| Laravel | Push and delivery suites after the fixes | 75 passed, 10 skipped |
| Customer | `flutter analyze` | No issues (the `/logout` prefix is gone) |
| Customer | `flutter test test/app test/core test/features test/goldens` | 497 passed |
| Customer | `flutter test` (all) | 497 passed + 1 load failure: untracked empty `test/auth_test.dart` (user file, not touched) |
| Driver | `flutter analyze` | No issues |
| Driver | `flutter test` | 240 passed |
| Both apps | `flutter build apk --debug --split-per-abi --target-platform android-x64 --dart-define-from-file=env/local.json` | Built and installed |

**The previous failures were test-environment defects, not notification or currency regressions:**

1. **`PushNotificationsTest` / `DriverOfferApiTest` (`skipped_online` expected, `sent` received).** The developer `.env` sets `WAFFRLI_PUSH_SKIP_WHEN_ONLINE=false` for device testing, and `phpunit.xml` did not pin it. Fixed by pinning `true` in `phpunit.xml`. Notification code is unchanged.
2. **`PushNotificationsTest` OpenSSL error.** Windows PHP has no default `openssl.cnf` unless `OPENSSL_CONF` is set. The test now uses PHP's bundled `extras/ssl/openssl.cnf` when needed.
3. **`DeliveryOfferServiceTest` timing.** It travelled 89 s from the real clock against a 90 s deadline, so a slow full run crossed it. Fixed by freezing time first.
4. **`DriverOfferApiTest > current offer … timed by the server`, same race.** `remaining_seconds` was 88 instead of 90 ± 1 under full-suite load. Fixed by freezing time first.

No application code was changed for these.

## 11. Remaining blockers and decisions for the owner

1. **Exchange rate.** 13000 old (130 new) per USD is a DEMO rate. Real prices need an approved rate: set `WAFFRLI_APPROVED_OPERATIONAL_RATE` (in new SYP per USD).
2. **Filament SYP price editing** stays disabled until that approval.
   - Even after approval, the 4-decimal USD storage cannot represent about 23% of qirsh amounts (they are refused with the nearest valid values).
   - Only the audited accounting-base switch removes this.
3. **Docs drift.** `docs/waffrli/openapi/examples.json` still shows old-unit SYP numbers.
4. **Minor customer UX items** listed in §6.
5. **Test data** from §2 is left in place. Delete or keep it on your decision; nothing was deleted.
