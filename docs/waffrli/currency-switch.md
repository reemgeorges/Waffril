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

## Making NEW SYP the primary currency (2026-11-15)

**Target.** SYP becomes the accounting default. Its row keeps storing **old** pounds (`decimals` 0; 1 old pound = 1 qirsh). People see and type **new** pounds (`Denomination`, ×100 / ÷100 exactly once). After the switch:

- the catalog, fee rules, fixed offers and PLUS amounts are stored as whole old pounds;
- every new-pound amount an admin types is stored exactly (no 0.0001-USD step, no rounding);
- the APIs and Filament show new pounds;
- orders are priced with base = SYP (rate 1);
- USD and EUR stay in the database with re-based rates (USD = 1 / rate) for a future re-enable.

**Rate entry.** Currencies → SYP → *Exchange rate* is typed in **new** pounds per USD (e.g. `130`) and stored ×100 (13000 old). The switch screen shows both values and a preview of what 1 USD of catalog becomes.

**Rehearsal (2026-11-15).** A copy of the local database was switched in a separate database with the DEMO rate 13000 (130 new), then dropped. The real database was not touched.

- **Run:** completed. Default USD → SYP. Rates after the switch: USD 0.000076923077, EUR 0.000070769231, SYP 1.
- **Converted (121 values):**
  - 47 branch prices (e.g. 2.50 USD → 32,500 old = 325.00 new);
  - 54 option deltas;
  - 2 minimum orders (3.00 → 390.00);
  - 8 fee rules (base 1.00 → 130.00; per km 0.15 → 19.50);
  - 1 fixed offer (0.30 → 39.00);
  - 1 PLUS display price (1.00 → 130.00).
- **Rounding:** nothing rounded to zero.
- **History:** orders, items, options, payments, refunds, wallets, ledger, settlements and PLUS cycles were byte-identical. Only `plus_cycle_discounts.op_*` was converted, by design; its `snapshot_*` columns were unchanged.

**To execute (needs the owner's explicit approval of the rate):**

1. Back up MySQL.
2. In Currencies, set the SYP rate to the APPROVED new-pound rate per USD.
3. Set `WAFFRLI_CURRENCY_SWITCH_ENABLED=true` and restart the queue worker.
4. As a super admin, go to Platform → Currency re-denominations → *Switch default currency* → SYP, check the preview, type `SYP`.
5. Wait for *Completed*.
6. Set the flag back to `false`.
7. Verify: the default is SYP; a price shows correctly in Filament and in the app; WF261113-UMOLKC is still 3.34 USD.

**Rollback:** restore the backup taken in step 1. The run itself is atomic: any error leaves everything as it was.
