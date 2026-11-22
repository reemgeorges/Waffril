# Cart item pricing (API, 2026-11-22)

Every cart item now carries the **server's own price** of its line. The amounts come from the same `OrderPricingService::price()` call that builds the cart `preview`, and that checkout charges. The change is **additive**: every older field is unchanged.

## Endpoints

| Endpoint | What's new |
|---|---|
| `GET /api/v1/customer/carts` | `data.carts[].items[]` |
| `GET /api/v1/customer/carts/{cart}` | `data.cart.items[]` |
| `POST /api/v1/customer/cart/items` | `data.cart.items[]` |
| `PATCH /api/v1/customer/cart/items/{item}` | `data.cart.items[]` |
| `DELETE /api/v1/customer/cart/items/{item}` | `data.cart.items[]` (when the cart still exists) |
| `POST /api/v1/customer/carts/{cart}/checkout` and `GET /api/v1/customer/orders/{order}` | `data.order.items[]` gains `base_unit_price`, `options_unit_price` and `options[].unit_price_delta` (it already had the other fields) |

## New fields on a cart item

| Field | Type | Meaning |
|---|---|---|
| `currency` | string \| null | ISO code of the amounts, the same as `preview.currency` (the currency checkout charges in) |
| `base_unit_price` | string \| null | Price of one unit of the product at this branch, without options |
| `options_unit_price` | string \| null | Sum of the options for one unit: Σ `options[].total_price_delta` |
| `unit_price` | string \| null | `base_unit_price + options_unit_price` |
| `line_subtotal` | string \| null | `unit_price × quantity` (before any discount) |
| `offer_discount` | string \| null | Public offer discount on this line, `"0.00"` when none |
| `line_total` | string \| null | `line_subtotal − offer_discount` |
| `options[].unit_price_delta` | string \| null | Extra price of one of this option |
| `options[].total_price_delta` | string \| null | `unit_price_delta × option quantity` (per unit of the product) |

`quantity` (int) was already present.

**Amounts** are **decimal strings**, already in the **displayed denomination** of the currency, with that currency's decimals. For SYP that is the new pound with 2 decimals (qirsh); for USD, 2 decimals. Parse them as decimals and never as floats, and don't convert them again.

**Null.** All the new fields are `null` when the server can't price the cart. In that case `preview` is `null` and `issue` holds the reason, for example "item unavailable". Show the item without prices.

## Totals

The cart totals are in `preview` and are unchanged:

```
preview.subtotal        = Σ items[].line_subtotal
preview.offer_discount  = Σ items[].offer_discount
preview.total           = Σ items[].line_total − preview.plus_discount + preview.delivery_fee
```

## PLUS

- **The PLUS discount is per cart, not per line.** It is computed on the cart subtotal for the store, with the member's cycle rules and the store's PLUS discount, and any cap. It appears only in `preview.plus_discount` and `preview.plus_discount_percent`.
- **PLUS has priority over public offers.** When a PLUS discount applies, no public offer applies, so every `offer_discount` is `"0.00"` and `line_total == line_subtotal`.
- **Free delivery and the PLUS delivery discount** stay in `preview.delivery_fee`, `preview.delivery_fee_original`, `preview.plus_delivery_discount*`, `preview.with_free_delivery` and `preview.without_free_delivery`, unchanged.

## Security

- **Prices are never sent by the app.** The server re-prices every line on every read and again at checkout. It ignores any client total.
- **Checkout keeps the existing guarantees:** locks, idempotency, wallet and payments are unchanged. The placed order's items return the same numbers as the cart.

## Example: GET /api/v1/customer/carts/{cart}

Captured from the test environment, whose default currency is USD. Production returns SYP amounts in the same shape.

```json
{
  "data": {
    "cart": {
      "id": 1,
      "branch": {
        "id": 1,
        "name": "Mazzeh branch",
        "store": {
          "id": 1,
          "name": "Shami Grill"
        }
      },
      "items": [
        {
          "id": 1,
          "product_id": 1,
          "name": "Chicken shawarma wrap",
          "image_url": "https://…/storage/products/….jpg",
          "quantity": 2,
          "notes": null,
          "is_available": true,
          "options": [
            {
              "id": 1,
              "name": "Saj bread",
              "group": "Bread",
              "quantity": 1,
              "unit_price_delta": "0.00",
              "total_price_delta": "0.00"
            },
            {
              "id": 3,
              "name": "Garlic sauce",
              "group": "Extras",
              "quantity": 1,
              "unit_price_delta": "0.25",
              "total_price_delta": "0.25"
            }
          ],
          "currency": "USD",
          "base_unit_price": "2.50",
          "options_unit_price": "0.25",
          "unit_price": "2.75",
          "line_subtotal": "5.50",
          "offer_discount": "0.00",
          "line_total": "5.50"
        },
        {
          "id": 2,
          "product_id": 2,
          "name": "Mixed grill platter",
          "image_url": "https://…/storage/products/….jpg",
          "quantity": 1,
          "notes": null,
          "is_available": true,
          "options": [
            {
              "id": 8,
              "name": "Large",
              "group": "Size",
              "quantity": 1,
              "unit_price_delta": "1.50",
              "total_price_delta": "1.50"
            }
          ],
          "currency": "USD",
          "base_unit_price": "8.00",
          "options_unit_price": "1.50",
          "unit_price": "9.50",
          "line_subtotal": "9.50",
          "offer_discount": "1.43",
          "line_total": "8.07"
        }
      ],
      "preview": {
        "currency": "USD",
        "subtotal": "15.00",
        "offer_discount": "1.43",
        "plus_discount": "0.00",
        "plus_discount_percent": null,
        "free_delivery_available": false,
        "free_delivery_applied": false,
        "free_deliveries_remaining": 0,
        "delivery_covered": true,
        "meets_min_order": true,
        "delivery_fee": "1.16",
        "delivery_fee_original": "1.16",
        "plus_delivery_discount_percent": null,
        "plus_delivery_discount": "0.00",
        "total": "14.73",
        "with_free_delivery": null,
        "without_free_delivery": null
      },
      "issue": null,
      "updated_at": "2026-11-22T17:21:53+00:00"
    }
  }
}
```

## Frontend notes

- Per line, show `unit_price × quantity`, then `line_total`; if `offer_discount` is not `"0.00"`, show it as a strike-through or "−offer".
- Per option, show `unit_price_delta` when it is not `"0.00"`.
- Take every total from `preview` (`subtotal`, `offer_discount`, `plus_discount`, `delivery_fee`, `total`). Don't recompute totals on the device; use the line fields for display only.
- Refresh the cart after each change (add, update, remove) or when the address or currency changes: the server re-prices.
- `items[].currency` equals `preview.currency`; format amounts with the currency's label, for example "ل.س".
