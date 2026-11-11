# WAFFRLI admin panel — UX redesign (2026-11-11)

Branch: `feature/filament-admin-ux-redesign`, based on `main` at `6498708`.

Scope: the Filament panel's design system, dashboard, navigation, workflows and wording. It changes no business logic, API contract, permission, guard, schema, financial calculation or realtime event.

Before/after screenshots are in `docs/waffrli/screenshots/ux-redesign/`.

## 1. Design system

| Area | Decision |
|---|---|
| Body type | **IBM Plex Sans Arabic**, unchanged. It has the best legibility for dense Arabic UI among the open fonts we compared (Cairo, Tajawal, Noto Kufi Arabic, Readex Pro), true matching Latin, and is already self-hosted. |
| Display type | **Readex Pro** (OFL 1.1), self-hosted in `public/fonts/readex-pro` (500/600/700, Arabic + Latin subsets, 96 KB). Used only for page titles, section and modal headings, and key figures, through `--wf-font-display`. It gives headings a modern, distinctive shape without hurting reading text. |
| Colour | Unchanged identity: neutral graphite surfaces, brand green for primary actions and the active item, gold for brand details and PLUS. Operational tones are tokens (`--wf-tone-*`) and are never the only signal: each tile or metric also has an icon and words. |
| Rhythm | Page blocks are 24px apart instead of Filament's 32px. Cards use the same radius, border and shadow tokens in light and dark mode. |
| Dates | Panel-wide default without seconds, day first: `11 نوفمبر 2026، 21:03` / `11 Nov 2026, 21:03` (`TableDefaults::dateTimeFormat()`). |
| Accessibility | Visible 2px focus ring, `prefers-reduced-motion` respected (the live dot stops pulsing), logical CSS properties so RTL and LTR share one stylesheet. |

The theme source is `resources/css/filament/admin/theme.css`, compiled with `npm run build:admin-theme` into the committed `public/css/filament/admin/theme.css`.

## 2. Navigation

Groups follow the daily work, most used first. Configuration groups start collapsed. Visibility is still permission-aware.

| Group (ar / en) | Items |
|---|---|
| الطلبات والتوصيل / Orders & delivery | Orders, Cancellation requests, Live delivery map |
| المتاجر والمنتجات / Stores & products | Stores, Branches, Products, Public offers, Store categories, Product categories, Product ratings |
| العملاء والسائقون / Customers & drivers | Customers, Drivers, Marketing messages |
| المالية والتحصيل / Finance & collections | Driver cash settlements, Payments, Refunds, Wallets, Wallet movements, Payment methods |
| وفرلي بلس / WAFFRLI PLUS | PLUS requests, Subscriptions, Cycles, Plans |
| المدن ومناطق التوصيل (collapsed) | Cities, Delivery areas, Delivery fee rules |
| إعدادات المنصة (collapsed) | Platform settings, Currencies, Default currency switch, Advanced settings |
| الموظفون والصلاحيات (collapsed) | Staff, Roles and permissions, Activity log, Notification log |

## 3. Dashboard

Rebuilt by operational importance. All values come from the database.

1. **Needs your attention** (`OperationsOverview`): orders awaiting acceptance (with the oldest wait), overdue scheduled orders, orders waiting for a driver, cancellation requests, PLUS requests and cash settlements to review.
   - Each tile links to the exact list: an order tab, or a pre-filtered list.
   - A tile with work shows its tone, an icon and the words "Needs action".
   - Each tile is shown only to admins allowed to act on it.
2. **Today** (`TodayOverview`): orders received, delivered and cancelled; average delivery time; value of delivered orders per currency (never converted).
3. **14-day trend** (`OrdersTrendChart`): orders per day, split into delivered / in progress / cancelled. It shows an empty state when there is no data.
4. **Order pipeline** (`OrdersByStatusChart`): open orders stage by stage, each a link. Closed totals are a quiet footer.
5. **Orders awaiting acceptance** (`PendingOrdersTable`): overdue scheduled orders first. Each row has a labelled type/urgency badge, the store under the branch, the customer, the total and the wait time.
6. **Finance summary** (`FinanceOverview`): amounts at each currency's precision with thousands separators (display only; `rows()` keeps stored values), and the ledger check.
7. **Platform size** (`PlatformOverview`).

The header has the greeting and date, plus shortcuts: new orders, live delivery map, and add store / product. Each shortcut is shown only when its page is allowed.

## 4. Workflows

- **Orders list tabs:**
  - All, Awaiting acceptance, Overdue scheduled (when any), Waiting for driver (dispatch queue, unchanged rules), Accepted without a driver (direct assignment), Out for delivery, Delivered, Cancelled;
  - each tab has its live count, and the dashboard links straight to them.
- **Orders table:** type and urgency in one labelled badge; the city is under the branch; the age is under the date; relations are eager-loaded.
- **Stores:**
  - The form has a main column (details, contact) and a side column (visibility, logo, PLUS discount).
  - A missing **store category** can be created from the store form, with the same fields and rules as the category page, by staff allowed to create categories.
  - The edit page shows **Store details, Branches and Products as one tab bar**.
  - In the list, the branch and product counts open those lists filtered on the store. The row menu adds "Add a branch / product to this store", with the store pre-selected.
- **Products:**
  - Same layout.
  - A missing **product category** can be created from the product form.
  - Product categories stay platform-wide.
  - The edit page combines the details with branch prices, options and ratings tabs.
- **Branches:** sections for details, location and contact, and taking orders, with explanations of "active" vs "accepting orders". The edit page combines the details with opening hours, exceptions and products.
- **Categories:**
  - The URL name (slug) fills itself from the English name and sits in a collapsed "More settings" section; validation is unchanged.
  - The store-category list shows a store count that opens the filtered store list.
  - Slugs are hidden from tables (they can be shown again from the column menu).

## 5. Terminology (examples)

Display labels only. Enum values, columns, API field names and validation keys are unchanged.

| Before | After | Why |
|---|---|---|
| معلق (order status) | بانتظار القبول | Says what the order waits for. The customer and driver APIs return this label as `status_label` (the `status` value is unchanged). |
| تم الاستلام من الفرع | في الطريق إلى العميل | What the customer and staff care about |
| الطلبات المعلقة | طلبات بانتظار القبول | |
| تسويات مفتوحة أو متنازع عليها | تسويات مالية تحتاج مراجعة | |
| متنازع عليها / مفتوحة (settlement) | يوجد فرق في المبلغ / لم تُسوَّ بعد | Describes the actual state |
| المسترد | المبالغ المستردة | It is an amount, not a balance |
| المعرّف (slug) | الاسم في الرابط (hidden by default) | |
| معكوس / عكس دفعة | أُعيد المبلغ / إعادة مبلغ دفعة | |
| إتمام إخفاء الهوية | حذف البيانات الشخصية نهائياً | |
| لقطة الدورة | مزايا الدورة المعتمدة | |
| إعادة تقويم العملة | تبديل العملة الأساسية | |
| سجل التدقيق | سجل النشاطات | |
| الإعدادات المخزنة | الإعدادات المتقدمة | |
| فرض عدم الاتصال | تحويله إلى غير متاح | |
| Notices naming Reverb, FCM or env variables | Plain operational sentences | |

English was updated for parity (for example "Pending" → "Awaiting acceptance"). `TranslationCompletenessTest` guards that every key exists in both languages.

## 6. What did not change

Services, policies, permissions, guards, order state machine, dispatch rules, wallet ledger, PLUS rules, currency logic, broadcasting, audit logging, migrations and API routes.

New contextual actions are links to existing pages or Filament's relationship create-option, which uses the same form fields and validation as the resource. Server-side checks still apply.

Two tests were adjusted, only for the new tab set: `DispatchFilamentTest` now asserts that the dispatch queue tab is present or absent rather than the exact tab list.
