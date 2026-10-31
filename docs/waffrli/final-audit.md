# WAFFRLI — final audit, Arabic/English localization and brand theme

Branch: `feature/final-audit-i18n`, based on `origin/feature/customer-api-realtime` at `791c208`.

The base was verified before this work started. On MySQL 8 it passed 472 of 472 tests, run from a pinned worktree. The earlier branches were not modified.

## 1. Audit — confirmed problems and fixes

| # | Area | Finding | Fix |
|---|---|---|---|
| 1 | i18n | 65 `messages.*` keys were used in code but defined in no language. `messages.unauthenticated` reached clients as a raw key on every 401, and so did `forbidden` and the `db_*` handler errors. | All defined in AR and EN. A test now resolves every literal key used in code. |
| 2 | i18n | There were no `ar` validation, auth, pagination or passwords files, so Arabic API validation errors came back in English. | Professional Arabic files: all 195 validation keys, custom messages and attribute names. |
| 3 | i18n | Notification classes contained hard-coded Arabic and English strings. | Moved to `lang/*/notifications.php`. |
| 4 | i18n | The roles screen showed a raw key for the `notifications.marketing` permission. | Label added. |
| 5 | i18n / API | The PLUS `eligible-discounts.note` was a hard-coded English sentence. | Translated. |
| 6 | i18n / API | Machine values (payment status, wallet type, PLUS statuses, availability and others) had no display label. | Additive `*_label` fields. |
| 7 | Data | `marketing_broadcasts` used `*_ar` / `*_en` columns. | Spatie JSON `title`/`body`, migrated without data loss. |
| 8 | Feature | The `maintenance.message` setting was stored but never shown. | Bilingual setting, shown when ordering is paused. |
| 9 | Bug | `PlatformSettings::get()` decoded values twice; it only worked for scalars by accident. | Raw read. |
| 10 | Bug | Business days used UTC instead of the platform timezone. The settlement "prepare" form could not select today for 3 hours after local midnight, and the "Delivered today" tile counted the UTC day. | Platform timezone. Found by running the suite just after midnight in Damascus; also present on the base. |
| 11 | Tests / demo | Two tests failed every day between 00:00 and 03:00 Damascus time: driver 6's two demo settlements merged into one, and a test passed a UTC date as the business day. | Fixed. |
| 12 | Privacy | Filament avatars were loaded from `ui-avatars.com`, sending every admin's name to a third party. | Local initials SVG. |
| 13 | Security | `symfony/mime` CVE-2026-45070 (medium). | Patched to 7.4.22. `composer audit` and `npm audit` are clean. |
| 14 | Ops | `otp:cleanup-expired` and `tokens:cleanup-expired` existed but were never scheduled. | Scheduled daily; a test pins the schedule. |
| 15 | Contracts | No machine-readable contract covered the 88 Customer and Driver operations. | `waffrli:api-contract` generates `docs/waffrli/openapi/mobile-api.yaml` from the routes, with a drift test. |
| 16 | Admin UI | Amounts were shown at storage scale (`USD 4.5800`). | Each currency's own precision (`USD 4.58`, `SYP 66,127`). |
| 17 | Dead code | A second, unused financial ledger and broken Firebase/Excel helpers. | Removed (section 3). |

Checked and found sound (no change needed):
- OTP security (hashed, purpose-bound, cooldown, attempt ceiling, single use).
- Guard isolation (customer, driver, admin tokens and the admin session).
- One active customer device.
- Order ownership (404 for other customers' orders).
- Driver assignment and GPS permissions.
- Reverb private channels, re-checked at send time.
- Device-token ownership.
- Rate limits; only the six sign-in endpoints are public, and all are throttled.
- Atomic wallet debit and PLUS benefit consumption.
- Refund idempotency.
- Multi-currency rounding.
- Currency redenomination (flag off; MySQL concurrency tests pass).
- Account deletion with 30-day recovery; anonymization blocked while the wallet balance is non-zero.
- No N+1 queries in catalog listings (tested).

Documented, not changed:
- `docs/openapi/openapi.yaml` and `docs/postman/*` belong to the starter's module generator. They have contained only test-generated modules since the initial commit, and generator tests rewrite them on each run. The WAFFRLI contract is the new `docs/waffrli/openapi/mobile-api.yaml`.
- The generator tests scaffold into the real project tree. An interrupted run leaves files behind: 113 untracked files in this session, which were cleaned up. Running the suite in an isolated checkout avoids this.
- `TestController`, `CategoryController`, `UserController` and `FatherAuthController` are starter-kit examples. Their routes are commented out or behind `WAFFRLI_LEGACY_USER_AUTH`, and a test uses `UserController`. They are kept.
- `UploadController` still has Arabic validation text, but its routes are disabled by design (`UploadRoutesDisabledTest`).

## 2. Requirements → code matrix

| Requirement | Implementation | Status | Evidence |
|---|---|---|---|
| Customer phone OTP auth, SMS → WhatsApp fallback, limits, one device, recovery | `CustomerAuthService`, `PhoneOtpService`, `TextMessenger` | COMPLETE (real provider: BLOCKED, credentials) | `CustomerIdentityTest`, Bruno customer |
| Profile, addresses, delivery city, display currency, deletion and recovery | `CustomerProfileService`, `CustomerAccountService` | COMPLETE | `CustomerIdentityTest` |
| City-scoped catalog, offers, search, favorites, verified ratings | `CustomerCatalogService`, `ProductRatingService` | COMPLETE | `CustomerCatalogTest` (N+1 ceiling) |
| Carts, idempotent checkout, wallet + cash, PLUS priority, snapshots | `CartService`, `CheckoutService`, `OrderPricingService` | COMPLETE | `CustomerCommerceTest`, Bruno |
| Orders, cancel before acceptance, cancellation request, refunds history | `CustomerOrderService`, `OrderAdminService`, `RefundService` | COMPLETE | Commerce and realtime tests |
| Wallet and payments (read only), currencies | `CustomerWalletController` | COMPLETE | Bruno |
| PLUS customer APIs | `CustomerPlusController`, `PlusService` | COMPLETE | Commerce tests, Bruno |
| Driver API (auth, orders, GPS, cash, notifications) | `routes/api/v1/driver.php` | COMPLETE | `DriverApiTest`, Bruno driver |
| One Reverb server with private-channel authorization | `routes/channels.php`, `ChannelAccess`, `BroadcastAuthController` | COMPLETE | `RealtimeChannelsTest`, browser live-map check |
| FCM push, device tokens, notifications API, marketing | `App\Services\Push\*`, `AppNotification` | COMPLETE (real FCM: BLOCKED, credentials) | `PushNotificationsTest` |
| Filament realtime (bell, live lists, live map) | `AdminAlerts`, operations listener, `DriverMapPage` | COMPLETE | `FilamentRealtimeTest`, browser |
| Safe default-currency switch | `CurrencyRedenominationService` | COMPLETE, flag off by default | `tests/Feature/Currency` (incl. 4 MySQL concurrency tests) |
| Full AR/EN localization: API, validation, notifications | lang files, `Label`, `SetRequestLocale` | COMPLETE | `tests/Feature/Localization/*` |
| Spatie database translations, Filament AR/EN inputs, fallback | models' `$translatable`, `Fields::translatable`, `Translatable::fallback` | COMPLETE | `DatabaseTranslationsTest` |
| Filament language switch (per admin), RTL/LTR | `admins.locale`, `SetAdminPanelLocale`, switch view, login listener | COMPLETE | `AdminLocaleAndBrandTest`, browser |
| Premium brand theme, logo, favicon, dark/light | `resources/css/filament/admin/theme.css`, `Brand`, `public/images/brand` | COMPLETE | Browser screenshots |
| FCM/Reverb in the recipient's language | `HasLocalePreference`, per-admin alerts, `status_labels` | COMPLETE | `NotificationLocalizationTest` |
| OpenAPI from implemented contracts, drift check | `waffrli:api-contract` | COMPLETE (paths, auth, headers; bodies in Bruno) | `MobileApiContractTest` |
| Residual wallet funds on closed accounts | — | BLOCKED: unresolved business policy (not invented) | Anonymization refused while the balance is non-zero |
| Real SMS/WhatsApp/FCM delivery | Twilio and FCM adapters | BLOCKED: provider credentials and device tests | Fake transports in tests only |
| Online card payments, merchant payouts, coupons, merchant area | — | Not approved | — |

## 3. Files removed (and why each removal was safe)

Each file had no reference in `app/`, `routes/`, `config/`, `database/`, `tests/`, docs or providers. That means no container binding, route, command, schedule, Filament discovery path, or string/reflection use. Each also imported classes or packages that do not exist here, so any call would have been a fatal error. The full suite passed after removal.

- `app/Services/Functional/FinancialService.php`, `ManualTransactionService.php`, `ProductSaleService.php` — an unused second ledger from the starter (`Invoice`, `Payment`, `Subscription`, `FinancialTransaction`, `Expense` do not exist).
- `app/Services/Functional/Excel/ExcelService.php`, `app/Http/Traits/FileExportReportTrait.php` — required maatwebsite/excel, dompdf and phpspreadsheet, none of which is installed.
- `app/Http/Traits/Firebase.php` — called an undefined `Firebase2::test()`, used an uninstalled Google client and a hard-coded credentials path.
- `app/Http/Traits/HasFcmToken.php` — unused; superseded by `DeviceTokenService`.

No table, migration or historical data was touched.

## 4. Database migrations and backfill strategy

All migrations are additive or data-preserving and idempotent, and each has a `down()`.

| Migration | Change | Data strategy |
|---|---|---|
| `2026_11_04_100000_translate_maintenance_message_setting` | `maintenance.message` becomes `{ar, en}` | An existing string is kept verbatim and assigned to ONE language by script (Arabic letters → `ar`, otherwise `en`). Nothing is machine-translated. |
| `2026_11_04_100100_marketing_broadcasts_translatable_text` | `title_*`/`body_*` → JSON `title`/`body` | Values are copied before the old columns are dropped; `down()` restores them. Verified up/down on MySQL 8 and SQLite. |
| `2026_11_04_110000_add_locale_to_admins_table` | `admins.locale` (nullable) | Existing admins keep the platform default. |

**Fallback strategy.** Every Spatie read falls back in this order: requested locale, then `APP_FALLBACK_LOCALE`, then any language that exists (`Translatable::fallback(fallbackAny: true)`). A value present in only one language is shown rather than left empty. Filament still requires both languages for required content fields.

**History.** Order, payment, refund and PLUS snapshot columns are never rewritten.

## 5. Arabic/English inventory

Every file exists in both `lang/ar` and `lang/en` with the same keys and placeholders (enforced by `TranslationCompletenessTest`).

| File | Keys per language | Content |
|---|---|---|
| `admin.php` | 628 | Filament UI and the shared status vocabulary (also used by API `*_label`) |
| `messages.php` | 170 | API messages, general errors, customer and driver domain messages |
| `notifications.php` | 16 | Customer, driver and admin notification texts |
| `validation.php` | 195 | All Laravel rules, custom messages, attribute names |
| `auth.php`, `passwords.php`, `pagination.php` | 3 / 5 / 2 | Framework texts |

**Database-translated fields (Spatie `{ar, en}`):**
- store categories (`name`);
- stores (`name`, `description`);
- branches (`name`, `address`);
- branch hour exceptions (`reason`);
- product categories (`name`, `description`);
- products (`name`, `description`);
- option groups and options (`name`);
- offers (`name`);
- PLUS plans (`name`, `description`);
- cities and delivery areas (`name`);
- payment methods and currencies (`name`);
- marketing messages (`title`, `body`);
- the maintenance message setting.

**Never translated:** IDs, enum and status values, currency codes, phone numbers, order numbers, and customer-authored notes and comments.

## 6. Filament language switching

- **Storage.** Each admin's `admins.locale` is applied on every request. The resolution order is: admin preference, then session, then a one-year `admin_locale` cookie (login page), then `APP_LOCALE`.
- **Switch.** A visible AR | EN control in the header and on the login page. It is a POST with a CSRF token, and the choice is saved on the admin. A choice made on the login page is saved at sign-in (`ApplyAdminLocaleOnLogin`). Different admins never affect each other.
- **Direction.** Filament renders `<html lang dir>` from the locale; Arabic is RTL.
- **Livewire.** Requests keep the language because panel middleware is persistent.
- **Bilingual inputs.** The AR and EN inputs of translatable fields keep their own text direction regardless of the panel language. Technical fields (slug, code, email, phone) are always LTR.

## 7. Brand theme

- **Tokens.** Sampled from the supplied logo (`App\Filament\Support\Brand`, `--wf-*`):
  - forest `#071B12`, emerald `#20A053`, gold `#D9AD59`, ivory `#F6EDD6`;
  - primary actions use `#0E7A44` (5.4:1 against white text);
  - neutrals are forest-tinted, with warm ivory for light mode.
- **Theme.** Source in `resources/css/filament/admin/theme.css`.
  - Built with `npm run build:admin-theme` (Tailwind CLI) into `public/css/filament/admin/theme.css`, and loaded with `->theme(asset())`, so production needs no Vite manifest.
  - Covers sidebar, topbar, cards, KPI tiles, tables, modals, the login page and the driver map, in dark (the default) and light mode.
  - Gold focus rings; reduced-motion support; bidi-safe numbers.
- **Logo.** The original artwork is unchanged; only the outer background is masked.
  - `public/images/brand`: 96/256 px PNG+WebP, 768 px WebP, favicon 32/48/192 px, `favicon.ico`, apple-touch icon.
  - The favicon is the emblem cropped from the logo on the logo's own green.
- **Font.** Self-hosted IBM Plex Sans Arabic (OFL) in `public/fonts`; no font CDN.
- **Plugin choice.** Considered `asignua/filament-translatable-fields` and `happenv-com/filament-translatable`.
  - Both declare Filament 5 support, but `asignua` 1.0.3 was published days before this work.
  - The existing tested `Fields::translatable()` helper already covers separate AR/EN inputs, per-language validation and preserving the other language.
  - No plugin was added.

## 8. Verification (2026-10-31)

- **PHPUnit, SQLite:** 499 tests, all passing; 13 skipped (9 pre-existing, 4 MySQL-only concurrency tests).
- **PHPUnit, MySQL 8:** 499 tests, all passing, run from an isolated worktree of commit `3e1126a` (the final code; later commits add documentation only). One test was skipped: the marketing-migration round trip runs on SQLite only, and that migration was checked up and down on MySQL by hand.
- **Bruno CLI 2.15.1 on a freshly seeded MySQL 8 demo database:**
  - Customer: 91 requests and 286 tests, all passing.
  - Driver: 37 requests and 126 tests, all passing.
- **Browser (Playwright/Chromium, real seeded data), 21 captures:**
  - desktop 1440×900 and mobile 390×844;
  - login, dashboard, product edit, orders, order view, PLUS requests, driver map and a modal;
  - AR/RTL and EN/LTR, dark and light.
  - Automated checks on every capture: correct `lang`/`dir`, font loaded, logo present, no horizontal scroll, no raw translation keys.
  - Live map: the Arabic panel connected to Reverb, `private-admin.drivers-map` was authorized by the admin session, a real driver GPS POST arrived, and the marker moved after 2.15 s (polling fallback 30 s).
  - Screenshots: `docs/waffrli/screenshots/final-audit/`. They were taken before commit 9a, so the order tables still show storage-scale amounts there.
- **Builds:** `npm run build` (Vite) and `npm run build:admin-theme` succeed; the committed theme equals a fresh build.
- **Security:** `composer audit` and `npm audit --omit=dev` report 0 vulnerabilities.
- **Lint and format:** `php -l` on every changed PHP file; Pint on changed files.
- **Not run:** a static analyser (none is installed), real SMS/WhatsApp/FCM delivery (no credentials), a Reverb load test, and wss/TLS deployment.

## 9. Remaining external requirements

- **Twilio (SMS/WhatsApp) and Firebase credentials**, followed by tests on real devices.
- **A business decision on residual wallet funds** before closed accounts can be anonymized.
- **Production deployment:**
  - Reverb behind TLS with restricted origins;
  - queue workers and the scheduler under supervision;
  - run `php artisan migrate` on staging first (all three migrations are additive);
  - grant `notifications.marketing` and `currencies.change_default` only to the intended roles;
  - set `WAFFRLI_CURRENCY_SWITCH_ENABLED=true` only after re-running `tests/Feature/Currency` against the production MySQL version.
