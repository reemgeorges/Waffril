# WAFFRLI admin — table UX overhaul (2026-11-12)

Branch `feature/filament-table-ux-overhaul`, merged into `main`. Display only: no business rule, data ownership, financial calculation, order lifecycle, wallet, PLUS eligibility, API contract, database relationship, policy or permission changed. No migration.

Screenshots: `screenshots/table-ux/`.

| Folder | Contents |
|---|---|
| `before/` | `main` before this work, Arabic light, desktop and mobile |
| `after/` | Arabic light and English dark |
| `mobile/` | 390 px wide |
| `tablet/` | 820 px wide |

## 1. The table design system

| Concern | Convention | Where |
|---|---|---|
| Layout choice | **Sparse lists become card grids** (1 per row on phones, 2 on tablets, 3 on wide screens): Stores, Store → Branches, Store categories, Cities, Delivery areas, Payment methods, PLUS plans, Roles, Terms pages. **Data-heavy lists stay tables** with grouped columns: orders, payments, customers, ratings and so on. | `->contentGrid()` per resource |
| Information grouping | Each row leads with an **identity cell**: picture or initial, the name, and a description line with the related facts (store · city, phone, plan, period). Facts are not scattered across sparse columns. | `TextColumn::description()` |
| Pictures | A real logo or picture, otherwise a **placeholder**: the record's initial on a brand tint (for Arabic names, the letter after "ال"). No broken images or empty columns. | `Support/Avatar`, `Columns::picture()` |
| Density | Cells are 10 px high instead of 16 px, so a row is about 52 px instead of about 89 px. Headers are on one line, with no capitals in Arabic. | `theme.css` (tables, round 3) |
| Status | One readable badge with an icon and words (Active / Inactive / Deleted, Accepting / Orders paused, Visible / Hidden, Running / Scheduled / Ended). It replaces bare switches; colour is never the only signal. | `Columns::status()` |
| Related counts | A **chip link** ("4 branches ›", "9 products ›", "3 orders ›") opens the related list in context, with a hover state and a tooltip. | `Columns::countLink()`, `.wf-stat-link` |
| Actions | **One primary action per row** (small labelled outline button: Manage store, Prices & availability, View order, View customer, Approve, Record cash received, Edit). Everything less frequent sits in one labelled **More actions** menu. Dangerous items keep their confirmation. | `Support/RowActions` |
| Activate / deactivate | Two explicit, confirmed menu items in place of a switch in every row. Permission: `Resource::can('update')`. When a business service exists, the action goes through it (pause orders → `CatalogAdminService`, audited; drivers → `DriverAdminService`; currencies → `CurrencyManagement`; fee rules → `DeliveryFeeRuleService`). | `RowActions::activation()` / `switch(using:)` |
| Search | Every list has a **search placeholder that names what it searches** (e.g. "Order number, customer name or phone"). The input waits for a 400 ms pause in typing. | `searchPlaceholder()`, `TableDefaults` |
| Filters | They **apply immediately** (no hidden "Apply" step). A labelled button shows **"Filters · n"**. Active filters show as removable chips with "clear all". Yes/no filters offer **All**. The old "0" corner badge is gone. | `TableDefaults::register()` |
| Numbers and money | Amounts are end-aligned in tabular figures, always in **their own currency** (`Fields::formatMoney`; nothing is converted or mixed). Signed movements show + / − in green / red. | `TableDefaults` |
| Direction | Phones, e-mails, codes, order numbers and money read left to right inside Arabic: an isolate class for column values, and `Fields::ltr()` (Unicode isolates) for description lines. | `TableDefaults::LTR_VALUE`, `Fields::ltr()` |
| Labels | No raw enum values. Redenomination statuses, refund reasons, read state and default flags are now translated. | `lang/{ar,en}/admin.php` (`table.*`) |
| Responsive | On phones every row becomes a **stacked card** (native `stackedOnMobile`); secondary columns drop out (`visibleFrom('md' / 'lg')`), so nothing scrolls sideways and actions stay reachable. The toolbar wraps without an empty line. | `TableDefaults`, `visibleFrom()` |
| Empty and loading | Empty states have a branded icon and text. Loading uses Filament's own indicator. | existing + `theme.css` |
| Pagination | 25 rows per page by default; choose 10, 25, 50 or 100. | `TableDefaults` |
| Scope of CSS | Every rule is scoped to `.fi-ta-*`; forms and modals are untouched. The structure lives in PHP, not in CSS. | `theme.css` |

## 2. Audit matrix — all 64 tables

**Verification for every row:** the page or tab was loaded in a real browser (Chromium) in Arabic light and English dark against the demo data. All 63 pages returned HTTP 200 with **no horizontal overflow and no JavaScript error** (126 loads); the dashboard widget was checked on the dashboard. Rows that also had a dedicated check list it in the last column.

Abbreviations: **S** = search, **F** = filters, **md / lg** = column shown from tablet / desktop up.

### Catalog

| Page / table | Original problems | Changes implemented | Search and filters | Responsive | Screenshot | Verification |
|---|---|---|---|---|---|---|
| **Stores** (reference) | Name at one edge, actions at the other, a huge empty gap; rows about 89 px; logo column often empty; bare toggle; counts looked like plain numbers. | **Card grid.** Logo or initial, name and categories, status badge; branch and product **chip links** to the store's tabs; PLUS chip only when set. **Manage store** is the primary action; More actions holds branches, products, Deactivate / Activate (confirmed), delete, restore. | S: store name. F: categories (multiple), status, deleted. | 1 / 2 / 3 columns | `after/admin_stores.*`, `mobile/admin_stores.*` | Browser: chip → `/stores/{id}/edit?relation=branches`; Deactivate → confirm → Inactive → Activate. PHPUnit `test_stores_are_cards…`, `test_deactivate_and_activate…` |
| Store → Products | Sparse; "sold in branches" was a bare number; toggle; image column hidden when empty. | Picture or initial, name and categories; **"Priced in 3 of 4 branches" / "Not priced yet"** chip (opens branch prices); stars (opens ratings); status. Primary **Prices & availability**; More: edit details, ratings, activation. | S: product name. F: categories, **branch pricing (priced / not priced)**, status, deleted. | rating from md | `after/admin_stores_1_edit_relation_products.*` | Browser filter 9 → 3 → reset 9; PHPUnit `test_store_products_can_be_filtered…` |
| Store → Branches | Five sparse columns, two toggles. | **Card grid:** name and city, Accepting / Paused / Inactive badge, "5 priced products ›" chip, minimum order. Primary **Prices & availability**; More: edit, opening hours, **Pause / Resume orders (audited service)**, activation. | S: name. F: city, accepting orders. | 1 / 2 / 3 columns | `after/admin_stores_1_edit_relation_branches.*` | PHPUnit `test_pausing_a_branch…uses_the_audited_service` |
| All products | Store in a separate column; category badges stretched the row; toggle. | Identity (picture, name, "store — categories"), pricing chip, stars, status. Same primary action and More menu as the store tab. | S: name. F: store (searchable), categories, pricing, status, deleted. | rating from md | — | sweep |
| All branches | Five columns, two icon columns. | Name with "store · city", orders-state badge, priced-products chip, status. More: edit, hours, pause / resume (audited, unchanged), activation, delete, restore. | S: name. F: store, city, accepting orders, status, deleted. | count from md | — | sweep |
| Product categories | Toggle; sort number as a column. | Name with "Under X / Top level", **products chip** (opens the products list filtered), status, position from md. Primary Edit; More: activation, **safe delete**, restore. | S: name. F: parent, status, deleted. | position from md | — | sweep |
| Store categories | Sparse table. | **Card grid:** picture or initial, name, position, status, **stores chip** (opens Stores filtered). | S: name. F: status, deleted. | cards | — | sweep |
| Public offers | Three target columns, two date columns, icon. | Store with "branch · product" (or All); discount badge; **period in one column**; **Running / Scheduled / Ended / Inactive** state. | F: store, **offer state**, deleted. | period from md | — | sweep |
| Ratings queue, Product → Ratings, Customer → Ratings, Order → Ratings | Number score; icon for hidden; repeated column in context. | Picture and product (hidden on the product's own tab), customer with phone (hidden on the customer's tab), **★★★★☆**, comment, **Visible / Hidden** badge (reason on hover), relative date. **Hide / Show again** is the primary button (`ratings.moderate`). | S: product or customer. F: **score (multiple)**, moderation state, with comment, store. | picture and date from md | `after/admin_products_1_edit_relation_ratings.*` | Browser: score 5 → 4 rows, hidden → 2. PHPUnit `test_ratings_hide_the_column…` |
| Product → Branch prices | Plain columns, icon. | Branch with city, price, availability badge. Primary **Edit price** (audited); More: mark (un)available (audited service), remove. | S: branch. F: availability. | stacked | — | sweep |
| Product → Option groups | min / max as two numbers. | Name with kind, **"Choose 1–3"**, options count, status. | F: deleted. | stacked | — | sweep |
| Product → Options | Group in its own column. | Name with group, "Up to n each", status. | S: option. F: **group**, deleted. | stacked | — | sweep |
| Product → Option prices per branch | Five plain columns. | Option with group, branch, "+0.5", availability. More: availability (audited), remove. | F: **branch**, availability. | stacked | — | sweep |
| Branch → Products & prices | Plain. | Picture, product with categories, price, availability. Actions as for Branch prices. | S: product. F: availability. | stacked | — | sweep |
| Branch → Opening hours | Two time columns. | Day plus **"09:00 – 23:00"** (LTR), in week order. | — | stacked | — | sweep |
| Branch → Exceptions | Two date columns. | Kind badge, period in one column, reason, created by (lg). | — | created-by from lg | — | sweep |

### Operational

| Page / table | Original problems | Changes implemented | Search and filters | Responsive | Screenshot | Verification |
|---|---|---|---|---|---|---|
| **Orders** (with work tabs) | **No customer column**; date in its own column; the "View" link was easy to miss. | Order number with age; status; type / urgency badge (+ scheduled time); **customer with phone**; branch with "store · city" (md); driver or "Not assigned" (lg); total in the order currency; primary **View order**. The dispatch-queue columns (waiting-for-driver tab) are kept. | S: **order number, customer name or phone**. F: status, type, urgent, branch, city, **driver**, **driver assigned**, date range. | branch from md, driver from lg | `after/admin_orders.*`, `mobile/admin_orders.*` | Browser: phone search → 5, pending → 6, unassigned → 12. PHPUnit `test_search_finds_customers_and_orders_by_phone` |
| Cancellation requests (and Order tab) | Statuses not coloured; resolve actions as plain links. | Order with "customer · phone", **coloured status** with resolver, order status (md), note (md), requested time. **Approve** (primary) and **Reject** (outlined) only on pending requests, with `orders.resolve_cancellation_requests` and their confirmations. | S: order number, customer. F: status (default: pending). | note from md | — | sweep |
| Pending orders (dashboard widget) | Waiting time and scheduled time in separate columns. | Order with waiting time, type (+ scheduled time), **customer with phone**, branch (md), total, **View order** button. | — | branch from md | dashboard | sweep |
| Order → Items | Seven numeric columns. | Product with options and note, **"2 × USD 4.00"** with offer discount, line total in the order currency. | — | stacked | — | sweep |
| Order → Status history | From / to as two columns. | New status badge with "from …", actor with driver, note (md), time. | — | note from md | — | sweep |
| Order → Delivery offers | Nine columns with full timestamps. | Driver with round / attempt, outcome badge with reason, source with offered by (md), offered / answered time, selection (lg). | — | stacked | — | PHPUnit `DispatchFilamentTest` |
| **Drivers** | Icon column for active; phone as plain text; five actions in a row. | Name with vehicle, phone (md), city (lg), availability badge, **active deliveries chip** (opens on-the-road orders for the driver), status, last location (lg). Primary **Manage driver**; More: activate / deactivate / force offline (audited services, confirmed). | S: name or phone. F: availability, **on a delivery**, city, status. | phone / city / last-seen from md / lg | `after/admin_drivers.*`, `mobile/admin_drivers.*` | Browser: available → 7, on a delivery → 5 |
| Driver → Cash collections | Date of settlement could be empty or confusing. | Order with time, amount in its currency, settlement day badge or **"Unsettled"**. | — | stacked | — | sweep |

### Customers

| Page / table | Original problems | Changes implemented | Search and filters | Responsive | Screenshot | Verification |
|---|---|---|---|---|---|---|
| **Customers** | ID column; e-mail hidden; deletion as a separate icon column; orders as a bare number. | Name with **phone (LTR)**; e-mail (lg); **one account badge** (Active / Suspended / Deletion requested); PLUS badge with plan and end date; **orders chip** (opens the customer's Orders tab); joined date (lg). Primary **View customer**. | S: **name, phone or e-mail** (one box). F: account status, PLUS, **has ordered**, deletion requested. | e-mail / joined from lg, orders from md | `after/admin_customers.*`, `mobile/admin_customers.*` | Browser search "Lina" → 1; PHPUnit phone search |
| Customer → Orders (also Driver → Orders) | Plain columns, "Open" link. | Order with date, status, branch with store (md), total; **View order** button. | S: order number. F: status. | branch from md | — | sweep |
| Customer → Wallets | Currency code as text. | Currency badge with name, balance in **that** currency, last movement; **Movements** button. | — | stacked | — | sweep |
| Customer → Addresses | Five sparse columns, icon. | Label with city, address with details, **Default** badge. | — | stacked | — | sweep |
| Customer → PLUS history | Repeated the customer column. | Same as PLUS requests, with the customer column hidden on this tab. | as PLUS requests | as PLUS requests | — | sweep |

### Financial

| Page / table | Original problems | Changes implemented | Search and filters | Responsive | Screenshot | Verification |
|---|---|---|---|---|---|---|
| **Payments** (and Order tab) | **Ten columns**, four amount columns mostly zero, uncoloured status. | Order with customer (hidden on the order's own tab); method with collector (md); **amount** with "collected / reversed / refunded" underneath only when not zero; **coloured status**; paid date (lg). Always in the payment's own currency. | S: order number, customer name or phone. F: status (multiple), method, **currency**, **paid date range**. | method from md, date from lg | `after/admin_order-payments.*` | Browser: pending → 13; PHPUnit `test_an_orders_payments_tab…` |
| Refunds (and Order tab) | **Raw reason code** ("customer_request"); date column first. | Order with date, amount in its currency, **reason in words**, method (md), recorded by (lg). | S: order number. F: currency, date range. | stacked | — | sweep |
| Wallets | Name and phone concatenated in one string. | Customer with phone (LTR), currency badge, balance in that currency (grey when 0), last movement (md); **Movements** button. | S: customer name or phone. F: currency, non-zero (toggle). | last movement from md | — | sweep |
| **Wallet ledger** (and Wallet tab) | ID column; balance-after as a separate bare number. | Date with #id, customer with phone (hidden inside a wallet), type (md), **signed amount** (green / red) with **"Balance after"** underneath, order link. Read only (audit trail). | S: customer, phone or order number. F: type (multiple), **direction**, date range. | type from md | `after/admin_wallet-transactions.*` | Browser: debit → 4 |
| **Driver cash settlements** (and Driver tab) | Eight columns; three actions in a row. | Business day with driver, expected, **received with difference**, coloured status with settled-by, note (lg). Primary **Record cash received** / **Resolve dispute** (`settlements.manage`, confirmed); More: refresh. | S: driver name. F: status, driver, currency. | note from lg | `after/admin_driver-cash-settlements.*` | sweep |

### PLUS

| Page / table | Original problems | Changes implemented | Search and filters | Responsive | Screenshot | Verification |
|---|---|---|---|---|---|---|
| **PLUS requests** | Nine columns; four actions in a row. | Customer with "phone · requested … ago" (hidden inside a customer); **type with requested plan**; **status with reviewer and time**; customer note (lg); technical columns can be turned on. **Approve** (primary) and **Reject** on pending requests (`plus.approve`); Details under More. | S: **name, phone or e-mail**. F: status tabs, type, plan, source, date range. | note from lg | `after/admin_plus-requests.*`, `mobile/admin_plus-requests.*` | Browser: type renew → 3 |
| PLUS memberships | Name and phone in one string; raw status. | Member with phone, plan with renewal policy, coloured status, current cycle end with **"2 of 5 used"** (md). | S: name or phone. F: status, **plan**. | cycle from md | — | sweep |
| PLUS cycles (and Membership tab) | Seven columns. | Member (hidden inside a membership), plan with "Cycle n", **period in one column**, free deliveries used (md), coloured status. | S: name or phone. F: status. | stacked | — | sweep |
| PLUS plans | Seven-column sparse table. | **Card grid:** name and code, status, "30 days", "5 free deliveries per cycle", store discounts chip, **members chip** (opens memberships filtered by plan). | S: code. F: deleted. | cards | — | sweep |
| Plan → Store discounts, Membership → Exceptions, Cycle → Discounts | Four or five limit columns, mostly empty. | Store, then a discount badge with **limits in words** ("Up to 5 · Min. order 10 · once per cycle"); exceptions show **"Excluded"**; status / approver. | S: store (plan discounts). | stacked | — | sweep |

### Settings and administration

| Page / table | Original problems | Changes implemented | Search and filters | Responsive | Screenshot | Verification |
|---|---|---|---|---|---|---|
| **Cities** | Raw latitude / longitude columns; toggle. | **Card grid:** name, coverage radius, **branches chip** and **delivery-areas chip** (open the lists filtered), status. Coordinates stay in the form. | S: name. F: status. | cards | `after/admin_cities.*` | sweep |
| **Currencies** | Seven columns; four actions in a row; star icon column. | Code badge, name with symbol and decimals, rate with "updated … ago", **Default** badge (only on the default), status. Primary Edit (`CurrencyManagement`); More: activate, deactivate, initialise default. | S: code. F: status. | stacked | `after/admin_currencies.*` | browser check (empty badge fixed) |
| Currency redenominations | Raw status value ("completed"). | "SYP → SYN × rate", **translated, coloured status** with the error underneath, run and finish times. | — | finish time from md | — | sweep |
| Delivery areas | Toggle; bounding box column. | **Card grid:** name and city, status. | S: name. F: city, status. | cards | — | sweep |
| Delivery fee rules | City and area in separate, mostly empty columns. | Scope badge with "city › area", distance band, **fee as a formula** ("1 + 0.5 per km"), status. Edit / toggle through `DeliveryFeeRuleService`. | F: city, status. | stacked | — | sweep |
| Payment methods | Code and sort order shown as columns. | **Card grid:** name, kind badge (online flagged "not available yet"), status, position. | S: name. | cards | — | sweep |
| Advanced settings | Icon column for money settings. | Readable name with the technical key, value (LTR) marked "Money amount", last change (md). | S: setting. | stacked | — | sweep |
| Roles | Three sparse columns. | **Card grid:** role name, permissions count, staff count. Edit keeps its audit entry. | S: name. | cards | — | sweep |
| Staff | E-mail in its own column; icon status. | Name with e-mail, roles, status badge, joined (md). | S: **name or e-mail**. F: **role**, status, deleted. | joined from md | — | sweep |
| Activity log | Separate date, admin and subject columns. | **Relative time with exact time on hover, with who did it**; action badge with its subject; details (md). | S: action. F: admin, action (searchable), **date range**. | details from md | — | sweep |
| Notification log | Icon for read. | Type with recipient, text, **Read / Unread** badge, time. | F: type, recipient, read. | stacked | — | sweep |
| Terms pages | Six columns for two records. | **Card per page:** page and title, Published / Draft, "Version n · Published date", who updated it and when. | — | cards | — | sweep |
| FAQ | Publish actions as links in the row. | Primary Edit; More: publish / unpublish / delete / restore (`ContentService`, unchanged). | S: question. F: status, deleted. | position from md, updated-by from lg | — | sweep |
| Marketing messages | Date column first; raw recipient number. | Title with date, audience, status, **"120 recipients"**, sent by (md). | S: title. | sent-by from md | — | sweep |

## 3. What was not changed

- **Services:** every write still goes through the same service or model write as before. The only addition: stores, products, branches, categories, cities, delivery areas and offers gained **Deactivate / Activate** menu items. They write the same `is_active` field the old switch wrote, behind the same `can('update')` check, and now with a confirmation.
- **Policies, permissions and visibility:** all `visible()` / `authorize()` rules on actions are unchanged.
- **Data and money:**
  - no migration, no API change, no change to money formatting rules;
  - each amount is still shown in its own record's currency;
  - fee rules, offers, minimum orders and plan discounts show plain numbers as before, because those records carry no currency.

## 4. Test results

The full results are in the final report and the merge commit. In summary:

- **New tests:** `tests/Feature/Admin/AdminTableUxTest.php`, 9 tests covering:
  - the shared conventions;
  - the Stores cards and count links;
  - confirmed activation with its permission check;
  - the audited branch pause;
  - the branch-pricing filter;
  - phone search on customers and orders;
  - the contextual rating and payment columns;
  - placeholders and LTR isolates.
- **Browser sweep:** 63 pages × 2 themes = 126 loads, all HTTP 200, no horizontal overflow, no JavaScript errors.
- **Filter and search checks in the browser:** 14 of 14 matched database counts. The relation-manager filter was checked through the UI.
