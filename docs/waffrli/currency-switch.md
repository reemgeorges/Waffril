# Default-currency switch (re-denomination)

`App\Services\Currency\CurrencyRedenominationService`. Filament: **Platform → Currency re-denominations → Switch default currency**.

## What it does

An exchange rate is the number of units of a currency per 1 unit of the default. Switching from F to T uses the rate already stored on T, `r` (T per 1 F):

| Data | Effect |
|---|---|
| Branch prices, option price deltas, branch minimum orders | × r, rounded HALF_UP to T's decimals |
| Delivery fee rules (base, per km) | × r, rounded |
| Offers | fixed amounts × r; percentages unchanged |
| PLUS plan display prices, store discount fixed values, max discount, minimum order | × r, rounded |
| PLUS cycle discounts still expressed in F (`op_*`) | × r; `op_currency_id` = T (`snapshot_*` stay as history) |
| Exchange rates | T = 1 (new default); F = 1/r; every other currency c ÷ r (12 decimals) |
| Orders, items, payments, refunds, wallets, wallet ledger, settlements, PLUS cycles | **never changed** (they carry their own currency) |

No policy for remaining wallet funds is involved: wallets stay in their own currency.

## Safety

- **Feature flag:** `WAFFRLI_CURRENCY_SWITCH_ENABLED`, `false` by default.
- **Permission:** `currencies.change_default`. The admin must type the target currency code to confirm.
- **Idempotency key:** each request has a unique key.
- **One run at a time:** the existing database unique `inflight_marker` allows only one pending or running run.
- **One transaction** claims the run, locks every currency `FOR UPDATE`, converts, re-bases the rates and marks the run completed.
  - A crash or error rolls back to exactly the previous state.
  - A lock timeout or deadlock leaves the run **pending**. Resume it with:

    ```bash
    php artisan waffrli:currency-switch:resume {id}
    ```

  - Any other error marks the run **failed** with the reason. A failed run frees the slot for a new request.
- **Checkout serialization:** checkout takes a **shared lock** on the default currency, so no order is priced half-converted.
- **Stale requests:** if the default or the target rate changed since the request, the run is refused (409).
- **Rounding to zero:** an amount that would round to zero (e.g. a fixed discount) aborts the run before anything changes.
- **Audit:** `currency.redenomination_requested` and `currency.redenomination_completed`.

## Verification

`tests/Feature/Currency/CurrencyRedenominationTest.php` (6 tests, SQLite and MySQL):

- flag and permission;
- conversion and rounding of every catalog table;
- rate re-basing;
- byte-identical history tables;
- idempotent re-run;
- checkout in the new default and in the former one;
- atomic failure (rounding to zero after branch prices were already converted, all rolled back);
- stale refusal;
- resume command;
- the Filament action gating.

`tests/Feature/Currency/CurrencyRedenominationMySqlConcurrencyTest.php` (4 tests, MySQL 8 only, with a second real connection):

- a checkout holding the shared lock blocks the switch: lock wait timeout, the run stays pending, nothing changes, and it completes once after the checkout commits;
- a running switch blocks checkout pricing;
- two workers on the same run convert once;
- the database refuses a second in-flight run and a second default.

Result (2026-11-03, MySQL 8, `waffrli_test`): 10 of 10 passed.

The tests passed, so the switch may be enabled per environment by setting `WAFFRLI_CURRENCY_SWITCH_ENABLED=true`. It stays off by default. Run the same test classes against the target MySQL version first, and take a database backup before the first real switch.
