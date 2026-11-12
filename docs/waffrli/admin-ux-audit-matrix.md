# WAFFRLI admin panel — page-by-page UX audit matrix (2026-11-12)

Branch `feature/filament-admin-ux-complete`. This matrix covers every Filament resource, page and relation manager that the panel registers: 37 resources and pages, and 29 relation managers.

## Legend

| Column | Meaning |
|---|---|
| **Nav** | Where it sits in the menu. "↳ Parent" means it is a sub-item that shows under its parent. |
| **Search** | What the search box finds |
| **Filters / tabs** | Filters and work tabs |
| **Sort** | Default order |
| **Row actions** | Row actions, permission-aware |
| **Readable** | Plain labels, no raw keys or JSON, formatted money and dates |
| **Context** | Links to the related records |

✓ means checked and done. — means not applicable.

Common to every table (`TableDefaults`, theme):
- dates show day first, without seconds;
- amounts and rates are not padded (`0.2000` → `0.2`);
- a "no" flag is a grey dash, not a red cross;
- delete and restore are icons with a tooltip;
- "create" buttons have a plus;
- tables, empty states and toolbars use one style;
- RTL and LTR alignment;
- no horizontal page scroll at 390 px.

## Resources and pages

| Page | Nav | Search | Filters / tabs | Sort | Row actions | Readable | Context |
|---|---|---|---|---|---|---|---|
| Dashboard | top | — | — | — | Shortcuts (permission-aware) | ✓ | Every tile, chip and KPI opens its list |
| Orders | Orders & delivery | Order number | Tabs: awaiting acceptance, overdue scheduled, waiting for driver / dispatch queue, out for delivery, delivered, cancelled. Filters: status, branch, city, type, urgent, date range. | Newest | View | One labelled type and urgency badge; branch with city; date with age | Order page: progress stepper; items, payments, history, cancellation requests, refunds, delivery offers and **ratings** tabs |
| Cancellation requests | Orders & delivery | **Order number, customer** (new) | Status (default: pending) | Newest | Approve, reject (permission-aware) | Customer shown under the order | Order link |
| Live delivery map | Orders & delivery | — | City, delivery status | — | — | ✓ | — |
| Stores | Stores & products (**the catalog hub**) | Name | Category, deleted | — | Edit; branches / products of this store; delete, restore. Header: **Categories & ratings** (store categories, product categories, ratings queue) and New store | Categories under the name; logo and PLUS discount columns only when any store has one | Counts open the store page on the Branches or Products tab. Store page tabs **Details / Branches / Products** with **Add branch / Add product**; a new product opens its branch prices next, a new branch its products and prices. |
| All branches | **not in the sidebar** (store page) | Name | City, store | Newest | Edit, pause orders, delete | ✓ | Breadcrumb "Stores › Branches"; **Back to stores**; branch page tabs products & prices, opening hours, exceptions |
| All products | **not in the sidebar** (store page) | Name | Store, category, deleted | Newest | Edit, delete | Rating ★ | Breadcrumb "Stores › store › Products › product"; tabs branch prices, option groups, options, option prices per branch, ratings |
| Ratings queue | **not in the sidebar** (Stores → Categories & ratings) | Product | Hidden, stars | Newest | Hide, show again (`ratings.moderate`) | ✓ | Also on Product, Customer and delivered Order pages |
| Public offers | Stores & products | Store | Store, type | Start date | Edit, delete | Discount badge (`10%`, `2`) | — |
| Store categories | **not in the sidebar** (Stores → Categories & ratings; inline in the store form) | Name, URL name | Deleted | Display order | Edit; **safe delete** (moves the stores); restore | URL name hidden by default | The store count opens the filtered stores; **Back to stores** |
| Product categories | **not in the sidebar** (Stores → Categories & ratings; inline in the product form) | Name | Parent, deleted | Display order | Edit; **safe delete** (moves the products); restore | ✓ | URL name generated from the English name when left empty |
| Customers | Customers & drivers | Name, phone, e-mail | Account status, **PLUS** (member / awaiting review / none), deleted | Newest | View | **PLUS badge, plan and end date** | Customer page tabs: profile (with a **PLUS section**), orders, wallets, addresses, **PLUS history**, **ratings** |
| Drivers | Customers & drivers | Name, phone | City, availability, active | **Name** (new) | Edit, set unavailable | Vehicle under the name; availability icon | Driver page: orders, cash collections and settlements tabs |
| Driver cash settlements | Finance | Driver | Status | Business day | Prepare, record, resolve | Plain statuses ("difference in the amount") | Driver link |
| Payments | Finance | Order number | Method, status | Newest | — | Money per currency | Order link |
| ↳ Refunds | under Payments | Order number | — | Newest | — | ✓ | Order link |
| Wallets | Finance | Customer | Currency, non-zero balance | Newest | View | Title "customer · currency" | Movements tab |
| ↳ Wallet movements | under Wallets | **Customer, order number** (new) | Type | Newest | — | Customer name with phone | Order |
| Payment methods | Finance | — (2 rows) | — | Order | Edit | ✓ | — |
| PLUS requests | WAFFRLI PLUS | **Customer name, phone, e-mail** | **Tabs by real status** (pending with count / approved / rejected / withdrawn / all); **type, plan, source, date range** | Newest | **Details and history**; approve, reject (`plus.approve`) | Coloured, labelled status; **reviewer and date**; review note | Customer link; the customer page has PLUS history |
| PLUS subscriptions | WAFFRLI PLUS | Customer | Status | **Newest** (new) | View; renewal policy; free-delivery override | Title is the customer name | Cycles and exceptions tabs |
| ↳ PLUS cycles | under subscriptions | **Customer** (new) | Status | Start | View | ✓ | Discounts tab |
| PLUS plans | WAFFRLI PLUS | Code | Active | **Display order** (new) | Edit | ✓ | Store discounts tab |
| **Terms & Conditions** (new) | App content | — | — | — | Edit | Draft / published, version, editor | Arabic and English tabs |
| **FAQ** (new) | App content | Question | Published, deleted | Display order | Publish / unpublish, edit, delete, restore | Draft / published | — |
| Marketing messages | App content | **Title** (new) | — | Newest | Send | ✓ | — |
| Cities / Delivery areas / Delivery fee rules | Cities & delivery zones (collapsed) | Name | City, scope | ✓ | Edit, quote preview | Coordinates box hidden; one distance band ("0 – 5 km") | — |
| Platform settings | Platform settings (collapsed) | — | **Tabs** by topic | — | Save | Section icons; **fallback categories** | — |
| ↳ Advanced settings | under Platform settings | Setting name | — | Name | — | Readable names, key underneath | — |
| Currencies, ↳ default currency switch | Platform settings | Code | Active | Code | Edit, set default (guarded) | Readable rates, star for the default | — |
| Staff / Roles | Staff & permissions (collapsed) | Name, e-mail | Active | **Name** (new) | Edit | ✓ | — |
| Activity log | Staff & permissions | Action | Staff member, action | Newest | — | **Plain actions, records and details**; raw key in the tooltip | — |
| Notification log | Staff & permissions | — | Type, recipient, read | Newest | — | **Plain type and message** | — |

## Relation managers

All 29 now use translated record names in their buttons, dialogs and empty states (`TranslatedRelationLabels`, `admin.relation_labels`).

| Owner page | Relation tabs |
|---|---|
| Store | Branches (create in a dialog, pause / activate inline, open for prices), Products (create in a dialog, rating, branch count) |
| Branch | Products and prices, weekly hours, special hours / closures |
| Product | Option groups, options, branch prices, branch option prices, ratings |
| Order | Items, payments, status history, cancellation requests, refunds, delivery offers, ratings (delivered orders) |
| Customer | Orders, wallets, addresses, PLUS history, ratings |
| Driver | Cash collections, cash settlements |
| PLUS subscription | Cycles, exceptions |
| PLUS cycle | Discounts |
| PLUS plan | Store discounts |
| Wallet | Movements |

## Verification

- `tests/Feature/Admin/FilamentResourceIntegrityTest` renders every resource page, record page and relation manager against real rows; Terms and FAQ were added to the dataset.
- `AdminWorkflowOverhaulTest`, `AppContentTest` and `AdminUxRedesignTest` cover the new workflows.
- Screenshots: `docs/waffrli/screenshots/ux-complete/`.
