# WAFFRLI Driver API — Bruno collection

[Bruno](https://www.usebruno.com/) is a free, open-source API client. A collection is just a folder of plain-text `.bru` files, so it is shared and versioned through Git like code. There is no Bruno account and no cloud workspace.

```
api-tests/bruno/WAFFRLI-Driver/
├── bruno.json                     collection manifest
├── collection.bru                 shared headers + Bearer auth ({{driver_token}})
├── environments/
│   ├── Local.bru                  base_url http://127.0.0.1:8000
│   └── Shared-Dev.bru             base_url of a shared dev server (edit locally)
├── .env.example                   template for your private .env (committed, no secrets)
├── .env                           YOUR secrets: git-ignored, never committed
├── fixtures/delivery-proof.png    sample photo for the multipart delivery request
├── 01 Authentication … 09 Notifications
└── 10 Negative and Authorization Tests
```

## Variables

| Variable | Source |
|---|---|
| `base_url` | environment file |
| `locale` | environment file (`en` or `ar`, sent as `Accept-Language`) |
| `driver_phone`, `driver_password` | your private `.env` (`process.env.WAFFRLI_DRIVER_*`) |
| `driver_token`, `driver_id` | captured by **Login (success)** as runtime variables, never written to disk |
| `order_id`, `order_status`, `cash_due`, `currency_code` | captured by **List my active orders** |
| `notification_id` | captured by **List my notifications** |
| `foreign_order_id` | your `.env` (`WAFFRLI_FOREIGN_ORDER_ID`): an order of ANOTHER driver |
| `latitude`, `longitude` | environment file (Damascus demo coordinates) |

Nothing secret is committed. The password lives only in your `.env`, and the token is a runtime variable (`driver_token` is also declared as a Bruno *secret* variable). If you ever type a value into the Bruno UI for a secret variable, Bruno keeps it on your machine only.

## Prepare a local demo driver (once per database)

Run each command on a local or demo database only. The commands refuse to run in production.

```bash
php artisan migrate
```

```bash
php artisan db:seed --class=WaffrliDemoSeeder
```

```bash
php artisan waffrli:demo-driver-password
```

The last command lists the demo drivers (id, phone, active deliveries) and asks which one to use. It then asks for a password twice at a hidden prompt.

- It only accepts **demo** drivers, whose fictional phones are `+1 202 555 02xx`.
- It never prints the password, and revokes that driver's old tokens.
- Driver `+12025550201` (Samer, `DEMO-0031` assigned) is the one the collection expects.

Find the id of another driver's order for the authorization test (DEMO-0032 belongs to driver 2):

```bash
php artisan tinker --execute 'echo App\Models\Order::where("order_number","DEMO-0032")->value("id");'
```

Create your private `.env` next to `bruno.json`:

```bash
cp api-tests/bruno/WAFFRLI-Driver/.env.example api-tests/bruno/WAFFRLI-Driver/.env
```

Then fill in `WAFFRLI_DRIVER_PASSWORD` and `WAFFRLI_FOREIGN_ORDER_ID`. Start the API:

```bash
php artisan serve
```

## Use it in the Bruno app

1. Install Bruno (desktop, free).
2. **Open Collection** and select `api-tests/bruno/WAFFRLI-Driver`.
3. Pick the **Local** environment (top right).
4. Run **01 Authentication / Login (success)**. Every other request then uses the captured token.
5. Run requests one by one, or use **Run** on the collection. The runner follows the folder order:
   - login and profile
   - availability
   - the active order
   - GPS
   - pickup and delivery
   - cash
   - notifications
   - negative tests (logout comes last)

> The run **changes data**: it picks up and delivers the demo driver's active order. Run it again after re-seeding a fresh demo database, or point it at a driver that still has an assigned order.

## Two developers sharing the collection through GitHub

- **Commit:** everything in this folder except `.env`, which `.gitignore` already excludes. That includes requests, tests, scripts and environments without secrets.
- **Each developer keeps privately:**
  - their own `.env` (driver phone and password, foreign order id)
  - their own local database
  - optionally their own copy of `environments/Shared-Dev.bru` values
- **Use different demo drivers** so you never fight over the same order. For example, developer A uses `+12025550201` (driver 1) and developer B uses `+12025550203` (driver 3, an order already picked up). Each sets their own password with `waffrli:demo-driver-password`.
- **Shared dev server:** set `base_url` in `Shared-Dev.bru` to the team server. If its hostname is private, keep that edit local (`git update-index --skip-worktree environments/Shared-Dev.bru`) instead of committing it.
- **Adding a request:**
  1. Create it in Bruno; it becomes a `.bru` file.
  2. Add `tests { … }` assertions.
  3. Open a pull request.
  4. The reviewer can run the same collection locally.
- **Never commit:** tokens, passwords, real customer data, or `.env`. Before pushing, check with `git status` that no `.env` appears.

## Command line (optional)

The Bruno CLI runs the same collection and tests, which is useful before a pull request or in CI:

```bash
npx @usebruno/cli@2 --version
```

```bash
cd api-tests/bruno/WAFFRLI-Driver && npx @usebruno/cli@2 run --env Local
```

To save a report:

```bash
cd api-tests/bruno/WAFFRLI-Driver && npx @usebruno/cli@2 run --env Local --reporter-json /tmp/bruno-report.json
```

Verified (2026-11-02) with Bruno CLI 2.15.1 against a fresh MySQL 8 demo database: 37 requests, 126 tests, all passing.
