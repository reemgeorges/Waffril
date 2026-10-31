# WAFFRLI end-to-end checks (Docker stack)

These checks run against the **running local Docker stack** (`compose.yaml`), not mocks:

- real HTTP to the API;
- a real WebSocket client on the one Reverb server, speaking the Pusher protocol;
- a real Chromium driving the Filament panel.

SMS and push go to the local **fake** providers. Nothing is sent to a real SMS, WhatsApp or FCM service, and the reports say so on every step that involves them.

| Script | What it checks | Writes |
|---|---|---|
| `node e2e/run.mjs` | The full order lifecycle (scenario below) plus the extra scenarios | `e2e/results/latest.md` and `latest.json` |
| `node e2e/filament-qa.mjs` | Every panel page in AR/RTL and EN/LTR, dark and light, desktop and mobile | `e2e/results/filament/report.md` and screenshots |

`e2e/results/` is git-ignored. The PHPUnit suites (`php artisan test`) remain the main regression net, and these scripts complement them on the real stack.

## Prerequisites

- The stack is up and seeded, with essential and demo data (`scripts/setup.ps1` or `scripts/setup.sh`).
- Node 22+, and Playwright with Chromium:

  ```bash
  npm i --no-save playwright
  ```

  ```bash
  npx playwright install chromium
  ```

- Copy `e2e/.env.example` to `e2e/.env` (git-ignored) and fill it in:
  - a local Super Admin (`docker compose exec app php artisan waffrli:create-super-admin`);
  - an **idle** demo driver, meaning one with no assigned or picked-up order, whose password you set with `docker compose exec app php artisan waffrli:demo-driver-password`;
  - two demo customers. `customer-01` (`+12025550101`) has PLUS and a USD wallet.

The root `.env` provides `REVERB_APP_KEY` and `WAFFRLI_DEV_OTP_PEEK_TOKEN`. The customer OTP is read through the development-only peek route, which exists only with `APP_ENV=local`, `WAFFRLI_SMS_DRIVER=fake` and a peek token.

## Run

```bash
node e2e/run.mjs
```

```bash
node e2e/filament-qa.mjs
```

On Windows these commands work as-is in PowerShell, from the project folder.

## Things to know

- **OTP limits are real.** Each phone gets 5 OTP sends per hour and a 60-second cooldown, and each IP gets 5 per minute. One `run.mjs` uses 3 sends: two for customer-01 (login, then the single-session check) and one for the second customer. Re-running within the hour can exhaust customer-01's budget, and the run then fails at step 1 with a 429. Wait, or use another demo customer.
- **Wallet balance:** wallets are credited only by refunds (there is no top-up, by design). E1 pays 0.50 from the wallet and cancels, which returns it. Step 7-8 then spends the whole balance, and with no balance left it pays cash only and says so. E7's refund credits 1.00, so the next run has a wallet balance again.
- **Reverb origins:** the runner sends `Origin: http://localhost:8080` the way a browser does. It also checks that a client **without** an Origin header is refused (4009). See `docs/waffrli/realtime-and-push.md` about native mobile clients.
- **Push (fake FCM):** step FCM.2 finds the queue worker's `push.fake_delivered` log line by token **hash**. FCM.3 registers a `fake-unregistered-…` token and checks that the push job deactivates it, the same handling as an FCM `UNREGISTERED` answer.
- **Data:** the runner creates real records in the **development** database: orders, payments, notifications and ratings. Never point it at a shared, staging or production server. Afterwards the demo seeder refuses to re-seed, because non-demo orders exist (a safety guard). Restore a backup, or rebuild the local database, to get a clean demo dataset back.

## Scenario (`run.mjs`)

| Step | What happens |
|---|---|
| 0.1 | Stack reachable. A Reverb connection succeeds with an Origin and is refused without one. |
| 1 | Customer phone-OTP login (fake SMS plus the dev peek route). |
| 2 | Default address and delivery city. |
| E1 | A scheduled order paid partly from the wallet. The customer cancels it, the wallet amount returns, and a second cancel answers 409. |
| E2 | Four parallel checkouts with the same Idempotency-Key produce one order; a replay returns the same order; a new key on the consumed cart is refused. |
| 3-5 | Branches, then a product with required options, then the cart with its server-priced preview. |
| FCM.1 | Register a device token (fake FCM). |
| 6 | PLUS membership and benefits. |
| 7-8 | Checkout: wallet (`max`) plus a cash remainder, with PLUS free delivery. Payments must sum to the total. |
| WS.1 | Customer WebSocket subscribes to `private-order.{id}`, authorized by the real endpoint. |
| LOGIN.D | Driver logs in, is set available, and subscribes to `private-driver.{id}`. |
| WS.2 | The other customer is refused on this order's and this user's channels; the driver is refused on the admin map; Reverb rejects a forged signature. |
| 9 | Filament: the order is listed and opened, and the panel's Echo subscribes to the admin channels. |
| 10 | Filament **Accept**. The customer socket and the admin browser receive `order.status_changed`. |
| FCM.2 | The queue worker sends the push for "accepted" through the fake transport. |
| 11 | Filament **Assign** driver. The driver socket receives the event. |
| 12 | The Driver API lists the order with its cash to collect. |
| 13-14 | Driver GPS update. `driver.location` reaches the customer socket and the admin map channel, and the other customer receives nothing. |
| 15 | The Filament map marker moves to the new position. |
| WS.3 | The customer socket reconnects and keeps receiving events. |
| FCM.3 | An unregistered token is deactivated by the push job (checked in FCM.3b). |
| 16-17 | Pickup, then delivery with cash. A wrong cash amount answers 422, and a duplicate delivery 409. |
| 18 | Status history, payments, wallet ledger entry, the customer's previous orders, and the driver's cash collections and settlements. |
| E7 | Filament **Refund** of 1.00 on the delivered order. The customer's wallet is credited and the refund is listed. |
| 19 | The same notifications in English and Arabic. |
| E4 / E3 / E5 | Rating the delivered product, a PLUS request, and display-currency presentation. |
| E6 | The admin API suspends a customer, whose token gets 403; re-activation restores access. |
| 20 | Cross-app and cross-account access is denied. |
| 1.1 | Single session: a new login revokes the previous token. |
