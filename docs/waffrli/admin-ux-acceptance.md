# WAFFRLI admin — PR #3 acceptance matrix (corrections, 2026-11-12)

PR #3 (`feature/filament-admin-ux-complete`) was rejected because the five catalog lists were still in the sidebar: they were moved under Stores as sub-items, not removed. This page lists each acceptance criterion, where it is implemented, how to reach it, how it was verified, and the evidence.

**How it was verified.** Every flow below ran in a real browser (Playwright, Chromium) against the panel served by PHP. The data was the project's own demo seeder (7 stores, 18 branches, 28 products, real PLUS cycles and requests), in a separate scratch database. Two accounts were used: a Super Admin and a **catalog manager** (role `catalog-manager`: catalog, ratings and content permissions only). Screenshots are in `screenshots/ux-complete/flows/` (flows) and `screenshots/ux-complete/after/` (final pages: Arabic light, English dark, mobile 390 px).

**Before:** `screenshots/ux-complete/before-pr3-sidebar.ar-light.png` (PR #3 as rejected, with the five entries nested under Stores and Store categories).
**After:** `screenshots/ux-complete/flows/G1-sidebar-ar.png`, `G2-sidebar-en.png`.

## Acceptance matrix

| # | Requirement | Files | How to access | Test performed | Screenshot | Result |
|---|---|---|---|---|---|---|
| 1 | Products, Product ratings, Product categories, Store categories and Branches are **not in the sidebar** (not sub-items), and their routes stay protected | `Products/ProductResource`, `ProductRatings/ProductRatingResource`, `Categories/CategoryResource`, `StoreCategories/StoreCategoryResource`, `Branches/BranchResource` (`$shouldRegisterNavigation = false`) | Sidebar as Super Admin, in Arabic and English | Flow G in the browser: the sidebar labels were read in both languages, and none of the five is present. PHPUnit `test_redundant_catalog_entries_are_not_in_the_sidebar_but_stay_reachable_in_context` (ar + en): the labels are absent from the panel navigation, each page returns 200 for the Super Admin and **403** for an employee with `orders.view` only | `flows/G1-sidebar-ar.png`, `flows/G2-sidebar-en.png` | **Pass** |
| 2 | Stores is the catalog hub: store → details / branches / products | `Stores/StoreResource` (keyed relations, count links, row actions), `Stores/Pages/ListStores` (**Categories & ratings** menu), `Stores/RelationManagers/*`, `Concerns/UnderStores`, `Concerns/InStoreContext` | Stores → a store → tabs Details / Branches / Products | Flow A and Flow B. The branch and product counts open the matching tab. Stores stays highlighted on product, branch, category and ratings pages (`getNavigationItemActiveRoutePattern`, asserted in the test above) | `flows/A1-stores.png`, `A2-store-products-tab.png`, `B1-store-branches-tab.png` | **Pass** |
| 3 | **Add product** / **Add branch** present and working inside the store | `Stores/RelationManagers/ProductsRelationManager`, `BranchesRelationManager` | Store → Products → **Add product**; Store → Branches → **Add branch** | Flow A created product #28 as the catalog manager and was redirected to its branch prices. Flow B created branch #12 and was redirected to its products and prices. PHPUnit `test_new_store_products_and_branches_open_their_pricing_next` | `A4-product-form-filled.png`, `A5-product-branch-prices.png`, `B2-add-branch-dialog.png`, `B3-new-branch-products-prices.png` | **Pass** |
| 4 | Ownership unchanged: the store owns the product; each branch sets its price, availability and option prices | No schema or financial change; `Products/RelationManagers/BranchPricesRelationManager`, `BranchOptionPricesRelationManager`, `Branches/RelationManagers/BranchProductsRelationManager` | Product → **Branch prices** / **Option prices per branch**; Branch → **Products & prices** | Flow A added a branch price (1 row saved). Flow B priced a product and switched its availability. Existing pricing tests are unchanged and passing | `A6-price-dialog.png`, `A7-branch-price-saved.png`, `B4-branch-product-priced.png`, `B5-availability-changed.png` | **Pass** |
| 5 | Categories: contextual select, inline create, safe reassignment on delete | `Fields::slug` / `Fields::uniqueSlug` (URL name generated when empty), `Services/Catalog/CategoryDeletionService`, `Support/SafeCategoryDelete` | Store form → Categories → **+**; Product form → Categories → **+**; Stores → Categories & ratings → a category → Delete | Flow A: inline product category plus an existing one; the URL name was generated (`levantine-starters-2`). Flow C: store #7 saved with an existing category and an inline-created one. **Bug found and fixed:** the inline dialog could not save, because the browser's `required` check on the URL name ran before it was filled. PHPUnit: `test_a_new_category_gets_its_url_name_from_the_english_name`, the safe-delete tests (`test_deleting_a_store_category_…`, `test_deleting_a_product_category_moves_its_products`) | `A3-inline-product-category.png`, `C1-inline-store-category.png`, `C2-store-form-two-categories.png`, `C3-store-created.png`, `modal-safe-delete.ar-light.png` | **Pass** |
| 6 | Ratings via Store → Product → Ratings, plus Customer and Order | `Products/RelationManagers/RatingsRelationManager`, `Customers/…/RatingsRelationManager`, `Orders/…/RatingsRelationManager` (translated empty states) | Store → Products → a product → **Product ratings**; the queue: Stores → Categories & ratings → Ratings | Flow A: the ratings tab opened, and hide/show moderation was visible on product 1. PHPUnit `test_catalog_staff_reach_categories_and_ratings_from_the_stores_page` (the queue link is shown only with `ratings.moderate`) | `A8-product-ratings-tab.png`, `A9-product-ratings-moderation.png` | **Pass** |
| 7 | Customer PLUS, against real demo customers | `Support/PlusStatus`, `Customers/CustomerResource` | Customers → PLUS column / filter → a member → PLUS section and **PLUS history** | Flow D: the "active member" filter returned 2 (Karim, Lina) and "awaiting review" returned 4, matching the database. The membership section shows the plan, cycle dates and free deliveries | `D1-customers-plus-column.png`, `D2-customers-filter-active-members.png`, `D3-customer-membership.png`, `D4-customer-plus-history.png` | **Pass** |
| 8 | PLUS Requests: search by name, phone, e-mail; filter by status, type, plan, date | `PlusRequests/PlusRequestResource`, `Pages/ListPlusRequests` | PLUS requests | Flow E. Each count was checked against the database:<br>• tabs: Pending 4, Approved 8, Rejected 1;<br>• search by name, phone and e-mail: 4 rows each;<br>• type "renew": 3;<br>• plan 1: 2;<br>• date range: 5. | `E1-plus-requests-pending.png`, `E2-search-customer.png`, `E3-approved-tab.png`, `E4-filter-type-renew.png` | **Pass** |
| 9 | Table and form audit, visible UX issues fixed | See "Fixes in this correction" below; [admin-ux-audit-matrix.md](admin-ux-audit-matrix.md) | All lists | Browser review of each list in Arabic and English, light and dark, and mobile. PHPUnit `FilamentResourceIntegrityTest` and the full suite | `after/*` | **Pass** |
| 10 | Terms & FAQ working | `ContentService`, `ContentPageResource`, `FaqResource`, `CustomerContentController` | App content → Terms and conditions / FAQ | Flow F: a draft returns API **404**; once published it returns **200** with version 1; a published FAQ appears in `GET /api/v1/customer/content/faqs`. PHPUnit `AppContentTest` | `F1-terms-editor.png` … `F5-content-list.png` | **Pass** |
| 11 | Navigation substantially reduced | `AdminPanelProvider`, `CityResource`, `CurrencyResource`, `PaymentMethodResource`, `DeliveryAreaResource`, `DeliveryFeeRuleResource`, `RoleResource`, `NotificationResource`, `OfferResource` | Sidebar | Flow G. The sidebar now holds 23 entries, the 5 catalog entries are gone and the configuration lists sit in collapsed groups. Delivery areas and fee rules sit under Cities; cities, currencies and payment methods are in one **Settings** group; roles under Staff; notifications under the activity log; the separate "Delivery" group is removed | `G1-sidebar-ar.png`, `G2-sidebar-en.png` | **Pass** |
| 12 | Visual quality | `resources/css/filament/admin/theme.css` (rounds 1–3) | Any page | Screenshots in Arabic light, English dark and mobile; no horizontal page scroll except inside wide tables | `after/*` | **Pass** |

## Browser flows

| Flow | Steps (all performed in the browser) | Result |
|---|---|---|
| A | (catalog manager) Stores → Shami Grill → Products → **Add product** → name, picture, an existing category and an **inline new category** → save → lands on **Branch prices** → add a price for a branch → **Product ratings** tab → hide/show available | Product #28 saved with 2 categories, 1 branch price |
| B | Store → Branches → **Add branch** → save → lands on **Products & prices** → price a product → switch availability off and on | Branch #12 saved; availability change saved |
| C | Stores → **New store** → choose "Restaurants" → **+** create "Oriental sweets" inline → save | Store #7 with both categories |
| D | Customers → filter "Active member" → open Karim → PLUS section and history | 2 members, matching the active cycles |
| E | PLUS requests → Pending tab → search by name, by phone, by e-mail → Approved tab → type filter → plan filter → date range | Every count matches the database |
| F | App content → Terms → draft (API 404) → publish (API 200, version 1) → FAQ → publish → in API | As expected |
| G | Super Admin sidebar, Arabic and English | None of the five entries |

## Fixes in this correction

- **Hidden entries:** the five resources use `$shouldRegisterNavigation = false`, replacing the earlier sub-items. Their routes and policies are unchanged.
- **Stores hub:** the store row has **Branches / Products** links and the counts open the right tab. The Stores page has a **Categories & ratings** menu, shown per permission. Breadcrumbs and **Back to stores** appear on the contextual lists. **Stores** stays highlighted in the sidebar on all of these pages.
- **Next step after create:** a new product opens its branch prices; a new branch opens its products and prices.
- **Bug: dashboard and other links ignored their tab or filter.** Filament 5 reads `?tab=` and `?filters=`, but the links used `activeTab` and `tableFilters`. Fixed in the dashboard, the widgets and the store categories page. Regression test: `test_dashboard_links_open_the_right_tab`.
- **Bug: inline category creation could not be saved quickly.** The URL name is now optional and generated (unique) from the English name.
- **Tables:**
  - columns that are always empty in the data are hidden: the store logo, the PLUS discount, and "deleted" on customers;
  - the order list shows the scheduled time under the type badge instead of a mostly empty column;
  - translated titles and empty states on the ratings and PLUS tabs.

## Test results (this correction)

| Check | Result |
|---|---|
| Full suite, SQLite (`php artisan test`) | 642 tests: 620 passed, 22 skipped (MySQL-only), 0 failed (after updating one assertion: the store URL name is no longer required) |
| Admin UX tests (`AdminWorkflowOverhaulTest`, `AdminUxRedesignTest`) | 20 passed, 216 assertions |
| `WaffrliMySqlSchemaTest` + `AdminWorkflowOverhaulTest` on local MySQL 8.0 (`waffrli_testing`) | 19 passed (521 assertions); the schema test alone: 5 passed, 359 assertions |
| API drift checks: `waffrli:api-inventory --check`, `waffrli:openapi --check`, `waffrli:api-contract --check` | All match |
| Pint (`--dirty`, the files changed here) | Clean. A whole-repo `pint --test` still lists older files outside this PR; CI does not run Pint |
| Theme build (`npm run build:admin-theme`) | Built |
| CI on the PR (SQLite, MySQL, contracts) | See the PR checks |
