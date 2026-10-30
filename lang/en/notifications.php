<?php

/*
| WAFFRLI (2026-11-04) — texts of notifications sent to customers, drivers
| and admins (stored, live over Reverb, FCM push). Each notification stores
| BOTH languages; push and live messages use the RECIPIENT's language.
*/

return [
    'customer' => [
        'order_title' => 'Order :number',
        'order' => [
            'accepted' => 'Your order :number was accepted.',
            'assigned' => 'A driver is heading to pick up your order :number.',
            'picked_up' => 'Your order :number is on its way.',
            'delivered' => 'Your order :number was delivered. Enjoy!',
            'cancelled' => 'Your order :number was cancelled.',
            'cancellation_rejected' => 'Your request to cancel :number was declined.',
            'refunded' => 'A refund was recorded for your order :number.',
        ],
        'plus_title' => 'WAFFRLI PLUS',
        'plus' => [
            'approved' => 'Your WAFFRLI PLUS request was approved.',
            'rejected' => 'Your WAFFRLI PLUS request was declined.',
        ],
    ],
    'driver' => [
        'order_title' => 'WAFFRLI delivery',
        'order' => [
            'assigned' => 'New delivery assigned: :number',
            'unassigned' => 'Delivery :number was reassigned.',
            'cancelled' => 'Delivery :number was cancelled.',
        ],
    ],
    'admin' => [
        'problem_reported' => 'Driver :driver reported a problem on :number: :problem',
    ],
];
