# WAFFRLI admin panel — UX redesign (2026-11-11)

Branch: `feature/filament-admin-ux-redesign`, based on `main` at `6498708`.

Scope: the Filament panel's design system, dashboard, navigation, workflows and wording. It changes no business logic, API contract, permission, guard, schema, financial calculation or realtime event.

Before/after screenshots are in `docs/waffrli/screenshots/ux-redesign/`.

## 0. Round 2 — visual redesign of the whole panel

Round 1 rebuilt the dashboard content, navigation, workflows and wording. Round 2 makes the redesign visible on every page.

| Area | What changed | Where |
|---|---|---|
| Sidebar | Deep brand-green panel in light **and** dark mode. Readable group labels, hairline group separators, gold active marker and icon, gold counters, and a brand footer card. The logo area of the top bar continues the green column and narrows with the collapsed sidebar. | `theme.css` (sidebar / brand column), `brand/sidebar-footer.blade.php` |
| Top bar | Translucent surface with blur | `theme.css` |
| Page headers | Display-font titles with a short gold signature rule. View/edit pages are titled with the record ("الطلب DEMO-0028", "تعديل مشاوي الشامي", "Lina Haddad") instead of "عرض طلب". | `AdminResource::getRecordTitle()` |
| Dashboard hero | A branded green header: greeting, date, live chips (deliveries in progress, orders awaiting acceptance) and shortcut buttons, each permission-aware. The logo is a soft watermark. | `Pages/Dashboard::getHeader()`, `dashboard/hero.blade.php` |
| KPI cards | Today's received / delivered / cancelled / average delivery time, each with an icon chip, a 14-day **sparkline from real data** (inline SVG, no JS) and the change since yesterday written in words. A value card per currency. | `TodayOverview::series()/sparkline()` |
| Platform cards | Icon chips, display-font values; they render with the page (not lazily) | `theme.css`, `PlatformOverview` |
| Order page | A progress stepper (accepted → driver → on the way → delivered, each with its time; cancelled orders shown in red). Section icons, the status and store in the subheading, and a readable distance method. | `orders/progress.blade.php`, `OrderResource::infolist()` |
| Tables (all) | Rounded card, pill search field, framed filter/column buttons, quiet row actions (green or red only on hover), clickable-row tint, branded empty states. Deleting is an icon with a tooltip. Every "create" button has a plus. A "no" flag is a grey dash, never a red cross. Amounts and rates have no storage padding (`0.2000` → `0.2`). | `TableDefaults`, `theme.css` |
| Forms (all) | Section icons in a soft brand chip, stronger field labels, hover state on inputs | `theme.css` |
| Readable values | Activity log: actions ("إسناد سائق لطلب"), records ("طلب #35") and details as "Label: value" instead of keys/JSON. Notification log: type names and the actual message. Advanced settings: setting names with the key underneath. The raw key stays as a tooltip. | `Support/Readable.php`, `admin.audit_actions`, `admin.notification_types` |
| Platform settings | Tabs (General and support / Orders and delivery / Catalog and PLUS), with sections side by side and section icons, instead of one long column | `PlatformSettingsPage::form()` |
| Drivers | The form is split into "Driver and sign-in" and "Work area and vehicle", with help texts. The list shows the vehicle under the name and an availability badge with an icon. | `DriverResource` |
| Customers, currencies, offers, fee rules, delivery areas | Name with e-mail underneath (id hidden); currency code badges, readable rates and a star for the default; discount badges; one readable "0 – 5 km" distance band; the raw coordinates box hidden by default | respective resources |

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
