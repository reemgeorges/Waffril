# WAFFRLI Driver API (implemented 2026-11-02, branch `feature/driver-api`)

Base path `/api/v1/driver`. Responses use the standard envelope `{data, status, error, statusCode}`. Messages are localized: send `Accept-Language: ar` or `en`.

## Authentication model

- **Guard:**
  - Drivers log in with phone and password. They get a Sanctum token with abilities `["driver", "*"]` on the dedicated `driver` guard (provider `drivers` → `App\Models\Driver`).
  - Every protected route uses `auth:driver` + `guard.identity:driver` + `driver.active` + `throttle:driver-api`.
  - Customer and admin tokens are rejected (401).
  - A deactivated or soft-deleted driver is refused (403), even with an old token. Deactivation in Filament also revokes tokens.
- **Accounts:** drivers are created and managed by admins in Filament. There is no self-registration and no OTP. Customer and admin authentication is unchanged.
- **Throttling:**
  - `driver-login`: 5 per minute per IP + phone.
  - `driver-api`: 120 per minute per driver.
  - `driver-location`: 12 per minute per driver.
- **Credential safety:**
  - Login failures use one generic message, so you cannot tell whether a phone is registered.
  - Responses never contain the password hash or tokens (other than the token issued at login).

## Endpoints

All routes except `login` require `Authorization: Bearer <driver token>`.

| Method | Path | Purpose |
|---|---|---|
| POST | `/login` | `{phone, password, device_name?}` → `{token, token_type, driver}` |
| POST | `/logout` | revokes the current token |
| GET | `/me` | profile, city, vehicle, account state, availability, active orders, last location |
| PATCH | `/profile` | `{language: ar\|en}`: the only self-editable field |
| POST | `/password` | `{current_password, password, password_confirmation}`; revokes the driver's other tokens |
| GET | `/availability` | `{availability, is_active, has_active_delivery, can_go_offline, active_orders}` |
| PATCH | `/availability` | `{availability: available\|offline}`; offline → 409 while a delivery is active |
| GET | `/orders/active` | own assigned and picked-up orders |
| GET | `/orders/history?status=&per_page=` | own delivered and cancelled orders, no customer personal data |
| GET | `/orders/{id}` | own order only (others → 404) |
| POST | `/orders/{id}/pickup` | assigned → picked_up |
| POST | `/orders/{id}/deliver` | picked_up → delivered; JSON or multipart `{cash_collected?, currency?, photo?}` |
| POST | `/orders/{id}/problems` | `{type, note?}`; notifies operations admins and writes an audit entry |
| POST | `/location` | `{lat, lng}`; last position, only during an active delivery |
| GET | `/cash/summary` | per currency: to collect on active deliveries, collected and unsettled, collected inside open or disputed settlements |
| GET | `/cash/collections` | own collected cash payments with their settlement state |
| GET | `/cash/settlements` | own daily settlements (read-only) |
| GET | `/notifications?unread=1` | own stored notifications plus `unread_count` |
| POST | `/notifications/{uuid}/read` | mark one of your own notifications as read (others → 404) |
| POST | `/notifications/read` | `{ids?: uuid[]}`: mark several, or all unread, as read |

## Rules enforced

- **Ownership:** every order lookup is scoped to `driver_id = me`. Another driver's order is 404, and it is never exposed.
- **Transitions:** these go through `OrderAdminService::markPickedUp` and `markDelivered`, which use the state machine with row locks and append-only status history.
  - Invalid transitions are 422 (deliver before pickup, pick up twice, pick up a delivered order).
  - A duplicate completion is 409.
  - Verified with 6 concurrent requests on MySQL: one success; the rest got 422 or 409; one history row and one collection.
- **Drivers never cancel:** a driver reports a problem (`customer_unreachable`, `wrong_address`, `customer_refused`, `branch_delay`, `order_issue`, `vehicle_issue`, `safety_issue`, `other`). Admins decide in Filament, including cancellation after pickup through the existing admin services.
- **Cash:**
  - `payment.cash_to_collect` is the pending cash payment, in the order's currency frozen at checkout.
  - When completing, `cash_collected` must equal it exactly. `currency`, if sent, must match.
  - Wallet-paid parts (`prepaid_amount`) are never collected again: a wallet-only order with any cash → 422.
  - Amounts, totals and wallets are never changed by the driver. Settlement and reconciliation stay admin-only.
- **Photo:**
  - Optional proof of delivery (jpg, png or webp, at most 5 MB), stored on the private `local` disk under `delivery-proofs/{order}/`.
  - Its path is kept in the new nullable column `orders.delivery_photo_path` (the one additive migration in this phase).
  - Admins with `orders.view` download it from the Filament order page.
- **Delivery timestamp:** `delivered_at` is the server time of completion.
- **Location:**
  - The latitude and longitude ranges are validated, and (0, 0) is rejected.
  - Only the last position is stored (`drivers.last_lat`, `last_lng`, `last_location_at`); there is no history table.
  - Updates are accepted only while the driver has an assigned or picked-up order. After delivery, cancellation or unassignment the response is 409.
  - The Filament driver map reads the same columns, so it shows new positions on its next refresh.
  - Reverb is not installed. A future realtime push would use the single shared Reverb connection.
- **Privacy:**
  - Customer name and phone, the full address, delivery coordinates and instructions appear only while the delivery is active.
  - History keeps only the city or area, distance, items and amounts.
- **Notifications:**
  - Drivers receive stored notifications when an admin assigns, unassigns or cancels one of their deliveries (`driver.order_*`).
  - Admins receive `driver.problem_reported`.
  - Delivery is through the database channel only. FCM is not installed; it would be added in each notification's `via()`.

## Example

Request:

```http
POST /api/v1/driver/orders/31/deliver
Authorization: Bearer <token>
Content-Type: multipart/form-data

cash_collected=8.03
currency=USD
photo=@proof.png
```

Response:

```json
{
  "data": {
    "message": "Delivery completed.",
    "order": {
      "id": 31,
      "order_number": "DEMO-0031",
      "status": "delivered",
      "customer": null,
      "payment": {
        "currency": {"code": "USD", "symbol": "$", "decimals": 2},
        "order_total": "8.03",
        "prepaid_amount": "0.00",
        "cash_to_collect": "0.00",
        "cash_collected": "8.03"
      },
      "timeline": {"picked_up_at": "2026-10-30T14:53:03+00:00", "delivered_at": "2026-10-30T14:53:04+00:00"},
      "has_delivery_photo": true,
      "allowed_actions": []
    }
  },
  "status": true,
  "error": null,
  "statusCode": 200
}
```

Errors use the same envelope:

```json
{
  "data": null,
  "status": false,
  "error": "The cash collected (0.01) must equal the amount due (8.03 USD). If the customer cannot pay, report a problem instead.",
  "statusCode": 422
}
```

Status codes:

| Code | Meaning |
|---|---|
| 400 | validation error (repository convention) |
| 401 | missing, invalid or foreign token, or wrong credentials |
| 403 | inactive driver |
| 404 | not your order or notification |
| 409 | conflict: duplicate completion, going offline while delivering, location without an active delivery |
| 422 | business rule (invalid transition, cash mismatch) |
| 429 | throttled |

## Development login

Use `php artisan waffrli:demo-driver-password`:

- local, development, demo and testing environments only
- **demo** drivers only (`+1 202 555 02xx`)
- the password is entered at a hidden prompt, never printed, and old tokens are revoked

See `api-tests/bruno/WAFFRLI-Driver/README.md` for the full Bruno workflow.
