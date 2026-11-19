# Flutter Test Checklist (Arabic + English)

Check with a customer whose selected delivery address belongs to the expected city.

| Screen/filter | How to verify | Expected |
|---|---|---|
| Store categories | GET `customer/store-categories` | Restaurants, supermarkets, pharmacies, bakeries, shops + image URLs |
| Branch list | GET `customer/branches` | Branches in the customer's delivery city only; store logo shown |
| Store category filter | GET `customer/branches?store_category_id=ID` | Only branches of stores in selected store category |
| Text search | GET `customer/branches?search=...` | Matches bilingual branch or store names |
| Product categories | GET `customer/product-categories` | Tree of 14 base demo + two regional category types (if seeded) |
| Branch catalog | GET `customer/branches/ID/products` | Photo, options, price, rating and availability |
| Product category filter | GET `customer/branches/ID/products?category_id=ID` | Only matching products in selected branch |
| Unavailable products | Append `include_unavailable=true` | Includes unavailable demo product variants |
| Product details | GET `customer/branches/ID/products/PRODUCT_ID` | Image, size groups and addon groups from real catalog |
| Global search | GET `customer/search?q=shawarma` | Branch/product results limited to city |
| Offers | GET `customer/offers` | Demo offers when currently active by their source date range |
| PLUS | GET `customer/plus/plans` | Existing plans (not newly created by this add-on) |

**Important:** This package does not invent extra offer types (e.g. Buy & Get) or alter existing discount and PLUS business rules. It focuses on making the existing catalog visually realistic for UI testing while preserving the seeded filter relationships.
