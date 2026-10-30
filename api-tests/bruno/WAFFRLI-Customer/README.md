# WAFFRLI Customer API — Bruno collection

[Bruno](https://www.usebruno.com/) is a free, open-source API client. A collection is a folder of plain-text `.bru` files, shared and versioned through Git like code. There is no Bruno account and no cloud workspace.

```
api-tests/bruno/WAFFRLI-Customer/
├── bruno.json                     collection manifest
├── collection.bru                 shared headers + Bearer auth ({{customer_token}})
├── environments/
│   ├── Local.bru                  base_url http://127.0.0.1:8000
│   └── Shared-Dev.bru             base_url of a shared dev server (edit locally)
├── .env.example                   template for your private .env (committed, no secrets)
├── .env                           YOUR values: git-ignored, never committed
├── fixtures/avatar.png            sample image for the avatar upload
├── 01 Authentication … 15 Device Tokens
└── 16 Authorization and Negative Tests
```

Folders: Authentication, Profile, Addresses, Cities, Stores and Branches, Products and Search, Favorites, Ratings, Carts, Checkout, Orders, Wallet and Payments, PLUS, Notifications, Device Tokens, Authorization and Negative Tests.

## How login works (phone OTP)

Customers have no password. The collection:

1. requests an OTP for `customer_phone` (`POST /api/v1/customer/auth/otp/request`);
2. reads that code through the **development-only** route `GET /api/v1/dev/otp` (header `X-Dev-Token`);
3. verifies it and stores the returned token in `customer_token` (runtime variable, never written to disk).

`GET /api/v1/dev/otp` is **not registered** unless ALL of these hold on the server, so it can never exist in production:

- `APP_ENV` is `local` or `testing`;
- `WAFFRLI_SMS_DRIVER=fake` (no real SMS/WhatsApp is sent; the fake keeps the last message per phone, encrypted in the cache, for 15 minutes);
- `WAFFRLI_DEV_OTP_PEEK_TOKEN` is set, and the request sends the same value in `X-Dev-Token`.

Against a server that sends real SMS there is no peek route. Type the received code into the `otp_code` secret variable in the Bruno app instead.

## Variables

| Variable | Source |
|---|---|
| `base_url`, `locale`, `latitude`, `longitude`, `search_term`, `preferred_store` | environment file |
| `customer_phone` | your `.env` (`WAFFRLI_CUSTOMER_PHONE`), e.g. a demo customer `+12025550101` |
| `dev_otp_token` | your `.env` (`WAFFRLI_DEV_OTP_PEEK_TOKEN`), equal to the server's value |
| `otp_code`, `customer_token`, `registration_token` | captured at runtime; declared as Bruno *secret* variables |
| `customer_id`, `branch_id`, `product_id`, `cart_id`, `order_id`, … | captured by earlier requests in the run |

Nothing secret is committed. The peek token lives only in your `.env` and the server's environment.

## Prepare a local server (once per database)

Run these on a local, disposable database only.

```bash
php artisan migrate
```

```bash
php artisan db:seed --class=WaffrliDemoSeeder
```

Add to the server's local `.env` (never commit it):

```
APP_ENV=local
WAFFRLI_SMS_DRIVER=fake
WAFFRLI_DEV_OTP_PEEK_TOKEN=<a long random value of your own>
```

Optional, for the realtime requests (otherwise they answer 503 and the tests accept that). Generate your own id, key and secret:

```
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=<id>
REVERB_APP_KEY=<key>
REVERB_APP_SECRET=<secret>
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
```

```bash
php artisan reverb:start
```

Create the collection's private `.env`:

```bash
cp api-tests/bruno/WAFFRLI-Customer/.env.example api-tests/bruno/WAFFRLI-Customer/.env
```

Fill in `WAFFRLI_DEV_OTP_PEEK_TOKEN`, then start the API:

```bash
php artisan serve
```

## Use it in the Bruno app

1. **Open Collection** and select `api-tests/bruno/WAFFRLI-Customer`.
2. Pick the **Local** environment.
3. Run **01 Authentication** in order (request, read, verify). Every later request uses the captured token.
4. Or use **Run** on the whole collection.

Things to know about a full run:

- **It changes data.** It registers one NEW development account (random `+1 202 555 3xxx–9xxx` phone), places one order and then cancels it, adds and removes an address, a favorite and a device token, and rates one delivered product.
- **OTP limits apply.** A new code for the same phone needs 60 seconds, and an IP may request 5 codes per minute. Wait about a minute between two full runs.
- **One active device.** Logging in revokes the account's other sessions, so two people must not use the same customer phone at the same time.
- **Logout comes last.** The final folder logs out and checks that the token is refused.

## Two developers sharing the collection through GitHub

- **Commit:** everything in this folder except `.env`, which `.gitignore` excludes.
- **Each developer keeps privately:**
  - their own `.env` (customer phone, peek token);
  - their own local database and server `.env`.
- **Use different demo customers** (one active device per account). For example, developer A uses `+12025550101` (customer 1) and developer B uses `+12025550102` (customer 2). Customer 1 is in Damascus and has a delivered order for the rating test.
- **Shared dev server:** set `base_url` in `Shared-Dev.bru`. If the hostname is private, keep that edit local (`git update-index --skip-worktree environments/Shared-Dev.bru`). The shared server needs the same fake-SMS and peek-token settings, or you enter OTPs by hand.
- **Adding a request:** create it in Bruno, add `tests { … }`, and open a pull request. If you maintain the collection with a generator script, keep this README in sync.
- **Never commit:** tokens, OTPs, peek tokens, real customer data or `.env`. Check `git status` before pushing.

## Command line

```bash
cd api-tests/bruno/WAFFRLI-Customer && npx @usebruno/cli@2 run --env Local
```

With a JSON report:

```bash
cd api-tests/bruno/WAFFRLI-Customer && npx @usebruno/cli@2 run --env Local --reporter-json /tmp/bruno-customer.json
```

Verified (2026-11-03) with Bruno CLI 2.15.1 against a freshly seeded MySQL 8 demo database, with a local Reverb server running: 91 requests and 286 tests, all passing. The driver collection was re-run on the same server: 37 requests and 126 tests, all passing.
