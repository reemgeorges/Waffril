# WAFFRLI admin — workflow overhaul (round 3, 2026-11-12)

Branch: `feature/filament-admin-ux-complete`.

- Page-by-page audit: [admin-ux-audit-matrix.md](admin-ux-audit-matrix.md).
- Screenshots: `screenshots/ux-complete/`.
- Earlier rounds: [admin-ux-redesign.md](admin-ux-redesign.md).

> The detailed specification package (`WAFFRLI_Filament_UX_Complete_Package.zip`) did not reach the implementation session: it was not attached and is not in the repository. This round implements the owner's 11 written requirements. Sections A–K of the package still need a check against this list once it is available.

## The 11 requirements

| # | Requirement | Implementation | Evidence |
|---|---|---|---|
| 1 | Stores vs branches | **Branches tab** on the store page: create in a dialog (store set automatically), pause or activate inline, open for prices, hours and options. Branch pages read "Stores › store › Branches › branch" with **Back to store**. The global list is kept as "All branches", a sub-item under Stores. Relationships unchanged. | `Stores/RelationManagers/BranchesRelationManager`, `Concerns/InStoreContext`; test `test_store_page_manages_its_branches_and_products_in_context` |
| 2 | Products in store context | **Products tab** on the store page: create in a dialog with the product's own picture, text and categories, owned by the store. Branches keep price, availability and options (Branch → Products and prices, Product → Branch prices and options). No shared or branch-owned catalog. | `Stores/RelationManagers/ProductsRelationManager` |
| 3 | Store categories in the store form | Select existing categories, or create one inline (same fields and rules, `catalog.manage`). **Safe delete:** the stores using the category move to a chosen replacement, pre-filled from the new **fallback store category** setting. A confirmation box is required. Stores are never deleted. Soft delete; the activity log keeps the affected ids. | `Services/Catalog/CategoryDeletionService`, `Support/SafeCategoryDelete`, setting `catalog.fallback_store_category_id`; tests `test_deleting_a_store_category_…`, `test_a_category_in_use_cannot_…`, `test_employees_without_catalog_manage_…` |
| 4 | Product categories in the product form | Inline create from the product form (global, many-to-many, bilingual, unique URL name, permission). Safe delete moves the products to a replacement (setting `catalog.fallback_product_category_id`). It refuses a category that still has sub-categories. | test `test_deleting_a_product_category_moves_its_products` |
| 5 | Contextual ratings | Ratings tabs on Product, **Customer** and **delivered Order**, with the same hide / show moderation and permission. The global list stays as the moderation queue, a sub-item "All ratings" under Stores (no top-level item). Public aggregates unchanged. | `Customers/RelationManagers/RatingsRelationManager`, `Orders/RelationManagers/RatingsRelationManager`, `Order::ratings()` |
| 6 | Customer PLUS visibility | **Customer list:** a PLUS column (member, plan, end date) and a filter for active member, awaiting review, or none. **Customer page:** a PLUS section (status, plan, current cycle dates, free deliveries left, waiting requests, link to the subscription) and a **PLUS history** tab, beside the orders, wallets, addresses and ratings tabs. "Active" means an active cycle; a waiting request is never shown as membership. | `Support/PlusStatus`; test `test_customers_show_real_plus_status_and_can_be_filtered_by_it` |
| 7 | PLUS requests | Search by customer name, phone or e-mail. **Tabs by real status:** pending (counted), approved, rejected, withdrawn, all. Filters for type, plan, source and date range. Coloured, labelled statuses; reviewer and review date; review note. **Details** window with the customer's full request history. Approve and reject are unchanged and still require `plus.approve`. | `PlusRequestResource`, `ListPlusRequests`, `plus/request-details.blade.php`; test `test_plus_requests_can_be_searched_and_filtered` |
| 8 | Audit all tables | Every resource, page and relation manager was reviewed. Matrix: [admin-ux-audit-matrix.md](admin-ux-audit-matrix.md). Fixes include: translated names in all 29 relation managers; new searches (cancellation requests, wallet movements, PLUS cycles, marketing); default sorts; record-based titles (wallets and subscriptions show the customer). | `Concerns/TranslatedRelationLabels`; `FilamentResourceIntegrityTest` |
| 9 | Terms & FAQ | **Did not exist.** Added: `content_pages` and `faqs` tables (additive migration); permission `content.manage`; panel pages with Arabic and English tabs, draft or published, automatic version on edits, and the editor. Every change is written to the activity log. **Public read-only** `GET /api/v1/customer/content/terms` and `/faqs`, throttled. No legal text is invented and no consent is recorded. | `ContentService`, `ContentPageResource`, `FaqResource`, `CustomerContentController`; `AppContentTest` |
| 10 | Navigation | Secondary lists are sub-items under their parent: all branches, products and ratings under Stores; product categories under store categories; refunds under payments; wallet movements under wallets; cycles under subscriptions; the currency switch under currencies; advanced settings under platform settings. A new collapsed **App content & messages** group holds Terms, FAQ and marketing. Store-based breadcrumbs. Measured: top-level menu entries went from 35 to 28 (9 lists are now sub-items), and entries visible on first load (configuration groups collapsed) from 24 to 16. | `AdminResource::$navParent`, `AdminPanelProvider` |
| 11 | Professional UI | Builds on rounds 1–2 (brand sidebar, hero, KPI cards, tables). New in this round: detail and timeline components; dialog designs; consistent status badges; Arabic labels for every relation, setting, activity and content screen. | `theme.css` |

## Safe local update (Laragon, existing database)

The new migration only **adds** two tables. Nothing is reset or deleted.

1. Back up the database first (Laragon → MySQL → export, or `mysqldump`).
2. Get the code:

   ```bash
   git pull origin main
   ```

3. Install PHP dependencies (with PHP 8.3 or newer):

   ```bash
   composer install --no-interaction
   ```

4. Install the new tables:

   ```bash
   php artisan migrate
   ```

5. Add the new `content.manage` permission (idempotent; it changes no existing role):

   ```bash
   php artisan db:seed --class=AdminAccessSeeder
   ```

6. Clear the caches:

   ```bash
   php artisan optimize:clear
   ```

The compiled theme (`public/css/filament/admin/theme.css`) and the fonts are committed, so no `npm` build is needed.

Super Admins see **App content** immediately. Give other staff the permission "Manage Terms & Conditions and FAQ" under Roles.
