# SYP operational currency recovery — 2026-11-13

## Recovery inventory

The backend started with 11 modified tracked files and one untracked CurrencyPolicy service; nothing was staged. These contained Claude's customer currency restrictions, profile/catalog/cart/checkout integration, shared Filament money inputs, historical settlement currency access, and a temporary demo-seeder policy override. All were retained and completed. Neither Flutter worktree had currency edits. Customer design references and an untracked test/auth_test.dart, and the driver's AndroidStudioProjects.lnk, remain outside these changes.

Incomplete/broken parts recovered: the original policy always offered the accounting default (USD locally), so it did not enforce SYP; catalog primary prices stayed in USD; PLUS prices lacked conversion; the demo seeder captured currencies before DemoPlatformSeeder created them. Flutter's formatters did not consistently localize SYP and customer formatting lost historical metadata after the offered currency list was restricted.

## Monetary architecture and decisions

- Database amounts are major units, DECIMAL(18,4); rates are DECIMAL(24,12), units per accounting base unit. PHP uses Brick BigDecimal and HALF_UP rounding to the target currency's decimals. Flutter uses Decimal and never performs exchange conversion.
- The local accounting/catalog base is USD. SYP is active, decimals=0, rate=13000, timestamp 2026-10-14. DemoPlatformSeeder explicitly labels 13000 as a demonstration rate, not market data. This is usable as configured development data; its business validity and denomination still require operator confirmation before catalog migration or a real-money test.
- Operational settings: WAFFRLI_OPERATIONAL_CURRENCY=SYP and WAFFRLI_CUSTOMER_CURRENCIES=SYP. Missing/inactive operational currency never falls back to USD. Future enabled codes can be configured explicitly without deleting currencies or rates.
- Customer catalog primary amounts are converted from accounting base to the selected operational currency. Checkout and cart share OrderPricingService, which converts units/options before multiplying quantity, rounds discounts/delivery in the order currency, and snapshots currency/base/rate. Payments, collection, refunds and settlements follow the frozen order currency.
- PLUS current plan prices and monetary benefit limits are converted on the server. Percentages are unchanged. Current member benefit presentation uses op_* values and op_currency_id; immutable snapshot_* columns remain intact. Cart previews do not consume entitlements.
- CurrencyConverter validates positive cross rates and raises a business error (422) instead of producing zero/negative prices. No new rate is invented or downloaded.
- Customer currencies.is_default and context.default_currency identify the operational default for mobile consumers. Database Currency.is_default still identifies the accounting base. API decimal-string amount structures remain compatible.
- Existing national SYP denomination is not inferred or changed. The existing CurrencyRedenominationService changes the platform accounting base, not the meaning of historical SYP units.

## History and administration

Read-only inspection verified WF261113-UMOLKC is delivered, currency USD, total 3.3400, frozen rate 1. Its cash payment is paid with amount and collected_amount both 3.3400 USD. It was never modified. Historical Flutter amounts retain their recorded code; no historical SYP equivalent is invented. Driver summaries and FinanceOverview already separate currencies.

Filament money inputs retain the truthful catalog base suffix and precision. Settlement preparation defaults to SYP, while outstanding historical cash remains settleable in its original currency. Advanced currency/rate administration is retained. Styling is unchanged.

**Remaining activation blocker:** catalog, delivery and PLUS administrative input must become SYP by executing the existing audited accounting-base switch after an operator confirms the rate and SYP unit. Entering SYP into a USD storage field or reciprocal conversion through four decimal places would risk relabelling or rounding drift. Consequently, no live catalog/base migration was executed. Until that switch, these administrative inputs correctly remain labelled USD. This acceptance criterion is pending, not completed.

The existing switch atomically converts catalog/option/minimum-order amounts, fee rules, fixed discounts, PLUS plan amounts and operational cycle values, rebases rates and preserves historical order/payment/wallet/refund/settlement/cycle records. See currency-switch.md. Take a local database backup; verify the target SYP rate/unit; enable WAFFRLI_CURRENCY_SWITCH_ENABLED only for the controlled switch; use Filament Platform > Currency re-denominations, permission currencies.change_default, confirm SYP; verify completed status and disable the switch flag afterwards. Do not rerun seeders or change the default flag manually.

## Verification and next end-to-end test

Automated results and commit identifiers are reported with the handoff. PHPUnit uses SQLite :memory:, with legacy multi-currency suites explicitly configured for USD/SYP/EUR; SypOperationalCurrencyTest overrides to SYP-only and covers catalog conversion, missing rates, inactive SYP, rejected USD checkout, unchanged history, preview entitlement safety, cart/checkout/payment equality, driver collection, settlement and refund. CurrencyRedenominationTest covers atomic base conversion and history preservation.

Executed: targeted Laravel commerce/redenomination/PLUS/driver regression (42 tests, 658 assertions, passed); targeted policy/currency/driver/refund/settlement suite (65 tests, 566 assertions, passed); final SYP-only suite including full COD/settlement/refund flow (5 tests, 47 assertions, passed). These runs overlap and must not be summed as unique tests. Full Laravel suite: 668 tests, 9426 assertions, 23 skipped, 2 failures and 1 error (642 successful cases). Failures are PushNotificationsTest and DriverOfferApiTest expecting skipped_online but receiving sent; error is OpenSSL test-key export in PushNotificationsTest. Those notification files were not changed. Windows also reported unavailable grep from existing test helpers. MySQL-only concurrency scenarios were not executed.

Both Flutter analyzers passed with no issues. Customer organized suites (test/app, test/core, test/features, test/goldens): 495 passed. The literal full flutter test run also encountered the pre-existing untracked test/auth_test.dart without main; that file was left untouched. Driver full suite: 218 passed. Both x86_64 debug APK builds succeeded. Driver build warns about firebase_core's Kotlin Gradle plugin compatibility with future Flutter releases; no Firebase changes were made. Pint and git diff --check passed.

Local listeners were verified on 8001 (API), 8080 (Reverb), and 3306 (MySQL). This does not establish queue worker health or actual FCM delivery. No emulator interaction, installation, deployment or push was performed. Debug APKs are at each Flutter project's build/app/outputs/flutter-apk/app-x86_64-debug.apk.

1. Confirm the approved SYP unit and rate, back up MySQL, then perform the audited switch described above. Verify every history field on WF261113-UMOLKC is unchanged.
2. Verify the API is on 8001, Reverb on 8080, and the existing queue worker is healthy. Keep Firebase and all existing credentials unchanged. Use the existing local app configuration with emulator host 10.0.2.2:8001; do not print its contents.
3. Install the two debug APKs on the designated test emulator. Start a new customer cart; check a product and option in Arabic and English; compare catalog, preview and checkout SYP values. Explicit USD currency selection via API must return 422.
4. Test PLUS with remaining free deliveries: preview twice without consumption, checkout once with exactly one entitlement consumed, delivery zero. Then test exhausted entitlement delivery percentage and store merchandise percentage separately.
5. Place a new COD order. Record subtotal, merchandise discounts, delivery fee, final total and cash due. Accept and assign in Filament, pick up in driver app, check GPS/Reverb/FCM, collect exactly the backend cash due and complete delivery. A different amount must fail.
6. Reconcile the new driver's SYP collection to its SYP settlement; issue a partial SYP refund and verify its wallet ledger currency and amount. Reopen WF261113-UMOLKC in customer, driver and Filament history: USD 3.34 must remain USD 3.34. Never sum USD and SYP balances.

## Changed-file groups

- Backend: config/waffrli.php; app/Services/Currency/CurrencyPolicy.php; app/Services/Functional/Currency/CurrencyConverter.php; app/Services/Customer/{CartService,CustomerCatalogService,CustomerProfileService}.php; app/Services/Order/{CheckoutService,OrderPricingService}.php; app/Http/Controllers/Customer/{CustomerPlusController,CustomerProfileController,CustomerWalletController}.php; app/Http/Resources/Model/Customer/CustomerProfileResource.php; app/Filament/Support/Fields.php; app/Filament/Resources/DriverCashSettlements/Pages/ListDriverCashSettlements.php; database/seeders/WaffrliDemoSeeder.php; phpunit.xml; tests/Feature/Currency/CurrencyRedenominationTest.php; tests/Feature/Customer/SypOperationalCurrencyTest.php; this note.
- Customer: lib/core/money/money.dart; lib/core/widgets/money_text.dart; lib/features/plus/presentation/plus_screens.dart; test/core/syp_money_test.dart.
- Driver: lib/core/money/money.dart; the cash, settlement, dashboard, delivery history, delivery completion/details and offer presentation screens; test/syp_money_test.dart.

## SYP presentation and admin input — 2026-11-15

No schema, stored amount, rate or base currency was changed. `App\Services\Currency\OperationalMoney` is the single boundary between base-stored catalog amounts and the operational currency:

- **Display.** Filament shows base-stored amounts (branch prices, option deltas, minimum orders, delivery fee rules, fixed offer and PLUS discounts, PLUS limits) in SYP through the same `CurrencyConverter` as the customer catalog and checkout. The stored base value and the rate, with its approval state, are in the tooltip. Transactional records (orders, payments, refunds, wallets, settlements, collections) are shown in their own frozen currency. A non-SYP record is labelled as a separate historical currency and is never converted. Dashboards never add currencies together.
- **Format.** `Fields::formatMoney` gives "15,000 ل.س" in Arabic and "15,000 SYP" / "3.34 USD" in English, isolated left-to-right like the mobile apps.
- **Admin input.** Admins type SYP only when `WAFFRLI_APPROVED_OPERATIONAL_RATE` equals the stored SYP rate exactly (for example `13000`). Otherwise they type base amounts as before, with a live SYP preview marked "exchange rate not confirmed". Changing the stored rate revokes the approval.
- **Exactness.** A typed SYP amount is saved as the 4-decimal base amount that converts back to exactly that SYP amount. One storable step is 0.0001 USD, which is 1.3 SYP at 13000, so about 23 % of whole SYP amounts (for example 15,000) have no exact equivalent. Those are rejected with the nearest storable values (14,999 / 15,001) rather than rounded silently. Saving an unchanged value keeps the stored amount byte for byte, so prices never drift. Per-km fees are exact per km, but a fee for a fractional distance is computed on the base amount and then rounded. Only the audited accounting-base switch removes these limits.
- **Pricing fixes.** PLUS cycle amounts are converted from their recorded `op_currency_id` (never assumed to be in the base, so no double conversion). The wallet part of a checkout is kept in whole units of the order currency, so the cash due is always collectable.

**Still required from an operator:** confirm the real SYP rate and denomination (13000 is a demonstration rate and implies pre-redenomination pounds). Then either set `WAFFRLI_APPROVED_OPERATIONAL_RATE` or run the audited base switch.

## New Syrian pound denomination — 2026-11-15

The owner adopted the NEW Syrian pound (100 old SYP = 1 new SYP). This is a fixed legal ratio, not an exchange rate.

- **What is stored is OLD.** The SYP row has rate 13000 per USD and 0 decimals, so every stored SYP amount (catalog conversions, orders, items, payments, wallets and ledger, refunds, settlements, PLUS `op_*` in SYP, rates) is in old pounds. Nothing was rewritten.
- **One boundary.** `App\Support\Money\Denomination` (config `waffrli.currency.denominations.SYP`: stored `old`, display `new`, factor 100, 2 display decimals):
  - divides once on the way out (customer and driver API serializers, Filament `Fields::formatMoney`/`formatAmount`);
  - multiplies once on the way in (customer `wallet_amount` in `CustomerCartController`, driver `cash_collected` in `DriverOrderController`, Filament `Fields::money` and `Fields::recordMoney`).
  - Services and the pricing engine work only in stored units.
- **Exact.** 1 old pound = 1 qirsh, so every stored integer is an exact new amount with 2 decimals; nothing is rounded. A typed amount with more than 2 decimals is refused. Typing the old-unit number is 100× too much and is refused by the exact cash check, never silently divided.
- **API.** SYP amounts are new pounds. Currency objects report `decimals: 2` and `denomination: "new"`, and SYP rates are restated (13000 → 130). The apps only format: qirsh are shown only when present.
- **Rates.** `WAFFRLI_APPROVED_OPERATIONAL_RATE` is given in new SYP per USD (for example 130 for the stored 13000). It is not set: the 13000/130 value is still a demonstration rate, so admin SYP input stays disabled.
- **History.** USD records stay USD. Old-pound SYP records are shown in new pounds by the fixed ratio, and the order view keeps "Recorded as … SYP (old pounds)" for audit.
- **After a future redenomination of the stored data,** set `WAFFRLI_SYP_STORED_DENOMINATION=new`. The factor then becomes 1 and nothing is converted twice.
- **Not updated:** the static examples in `docs/waffrli/openapi/examples.json` still show old-unit SYP numbers.
