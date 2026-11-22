# WAFFRLI: order and driver ratings (2026-11-22)

**Status: proposed implementation package; not merged or production-deployed.**

Product ratings are replaced with two independent customer-owned ratings per DELIVERED order. Each has an integer score (1..5) and optional comment (at most 1000 Unicode characters).

## PUT /api/v1/customer/orders/{order}/rating

Authenticated customer only. Body:

```json
{"rating":5,"comment":"Everything was great"}
```

Success (inside the existing WAFFRLI response envelope):

```json
{"data":{"message":"Rating saved successfully.","rating":{"rating":5,"comment":"Everything was great","is_hidden":false,"created_at":"2026-11-22T11:00:00+00:00","updated_at":"2026-11-22T11:00:00+00:00"}},"status":true,"error":null,"statusCode":200}
```

## PUT /api/v1/customer/orders/{order}/driver-rating

Same ownership and validation, plus order.driver_id must be non-null. Body:

```json
{"rating":4,"comment":"Friendly driver"}
```

Success `data.rating` also includes `driver_id` set **by the server**. Do not send a driver ID.

Both are UPSERT operations, serialized with an order-row lock and guarded by UNIQUE(order_id). Updating one never updates the other. A customer's update to a hidden rating does not automatically unhide it.

- `404`: order absent or belongs to a different customer.
- `422`: not delivered, no driver for driver rating, invalid score or overlong comment.
- `401`/`403`: existing auth/customer-account restrictions.

## GET /api/v1/customer/orders/{order} (and GET orders list)

The order in `data.order` (or the list's order item) has a new `ratings` property:

```json
{
  "ratings": {
    "can_rate_order": true,
    "can_rate_driver": true,
    "order": {
      "rating": 5,
      "comment": "Everything was great",
      "is_hidden": false,
      "updated_at": "2026-11-22T11:00:00+00:00"
    },
    "driver": {
      "rating": 4,
      "comment": "Friendly driver",
      "is_hidden": false,
      "driver_id": 12,
      "updated_at": "2026-11-22T11:00:00+00:00"
    }
  }
}
```

`order` or `driver` can independently be `null`; the two booleans indicate eligibility, regardless of whether a rating already exists. `can_rate_driver=false` when delivered without an assigned driver.

## Removed, breaking contracts

- `POST /api/v1/customer/products/{product}/rating` **removed**.
- Product cards, product details, and favorites **no longer return** `rating.average`, `rating.count` or `rating.placeholder`.
- Product ratings in Filament are removed; old reviews survive exclusively in `legacy_product_ratings` (not served by API).
- New ratings **must not** be inferred from historical product ratings, which measure a different subject.

## Admin

New Filament resources: Order Ratings and Driver Ratings. Permission `ratings.moderate` controls moderation; only the owner submits scores/comments; admins can hide/unhide with reason. Hidden driver scores are excluded from the driver's aggregate. Customer has separate order/driver rating tabs, driver has a ratings tab, and order detail shows both independently.

## Contract and integration checks

Run on isolated development DB: Laravel migrations, PHPUnit (SQLite and MySQL 8.4), test all Filament pages in Arabic and English, and regenerate the committed API inventory, OpenAPI and Hoppscotch export:

```bash
php artisan waffrli:api-inventory
php artisan waffrli:openapi
php artisan waffrli:api-contract
python3 api-tests/bruno/generators/hoppscotch.py
php artisan waffrli:api-inventory --check
php artisan waffrli:openapi --check
php artisan waffrli:api-contract --check
```

**No production migration is performed by this package.** Back up the database and validate the archive and rollback in staging before any production release.
