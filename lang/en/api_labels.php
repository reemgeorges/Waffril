<?php

/*
 * Mobile API vocabulary (en). The human labels the customer and driver
 * APIs return next to stable machine values ("status": "picked_up",
 * "status_label": "…"), and the account-deletion blockers.
 *
 * Kept apart from lang/en/admin.php on purpose (API audit, 2026-11-13):
 * the admin panel wording review of 2026-11-11 rewrote shared keys and so
 * changed text the Flutter apps already show. These values are the API text
 * as released before that review. Change them only as a deliberate app
 * change; panel wording lives in admin.php. A key missing here falls back to
 * the admin.php key of the same name.
 */

return [
    'branches' => [
        'kind_closed' => 'Closed',
        'kind_open' => 'Special opening',
    ],
    'customers' => [
        'blockers' => [
            'already_anonymized' => 'Already anonymized.',
            'not_deleted' => 'The customer has not requested deletion.',
            'open_cancellation_requests' => 'Open cancellation requests exist.',
            'open_orders' => 'Orders are still in progress.',
            'pending_payments' => 'Payments are still pending.',
            'recovery_window' => 'Still inside the recovery window (until :until).',
            'wallet_balance' => 'A wallet balance is not zero (settlement policy not yet defined).',
        ],
        'status_active' => 'Active',
        'status_suspended' => 'Suspended',
    ],
    'dispatch' => [
        'status_accepted' => 'Accepted',
        'status_cancelled' => 'Withdrawn',
        'status_expired' => 'Expired',
        'status_pending' => 'Waiting for the driver',
        'status_rejected' => 'Rejected',
    ],
    'drivers' => [
        'availability_available' => 'Available',
        'availability_offline' => 'Offline',
    ],
    'order_status' => [
        'accepted' => 'Accepted',
        'assigned' => 'Driver assigned',
        'cancelled' => 'Cancelled',
        'delivered' => 'Delivered',
        'pending' => 'Pending',
        'picked_up' => 'Picked up',
    ],
    'orders' => [
        'actor_admin' => 'Admin',
        'actor_customer' => 'Customer',
        'actor_driver' => 'Driver',
        'actor_system' => 'System',
        'reason_branch_unavailable' => 'Branch unavailable',
        'reason_customer_refused' => 'Customer refused',
        'reason_customer_request' => 'Customer request',
        'reason_customer_unreachable' => 'Customer unreachable',
        'reason_other' => 'Other',
        'reason_product_unavailable' => 'Product unavailable',
        'type_immediate' => 'Immediate',
        'type_scheduled' => 'Scheduled',
    ],
    'payments' => [
        'kind_cash' => 'Cash on delivery',
        'kind_online' => 'Online (future)',
        'kind_wallet' => 'Wallet',
        'status_cancelled' => 'Cancelled',
        'status_failed' => 'Failed',
        'status_paid' => 'Paid',
        'status_pending' => 'Pending',
        'status_reversed' => 'Reversed',
    ],
    'plus' => [
        'cycle_active' => 'Active',
        'cycle_cancelled' => 'Cancelled',
        'cycle_expired' => 'Expired',
        'cycle_scheduled' => 'Scheduled',
        'membership_active' => 'Active',
        'membership_cancelled' => 'Cancelled (no renewal)',
        'membership_ended' => 'Ended',
        'policy_custom' => 'Custom duration',
        'policy_monthly' => 'Monthly',
        'policy_one_time' => 'One-time',
        'request_cancel' => 'Cancellation',
        'request_change' => 'Plan change',
        'request_custom' => 'Custom',
        'request_new' => 'New',
        'request_renew' => 'Renewal',
        'source_customer' => 'Customer',
        'source_system' => 'System (renewal reminder)',
    ],
    'requests' => [
        'status_approved' => 'Approved',
        'status_pending' => 'Pending',
        'status_rejected' => 'Rejected',
        'status_withdrawn' => 'Withdrawn',
    ],
    'settlements' => [
        'status_disputed' => 'Disputed',
        'status_open' => 'Open',
        'status_settled' => 'Settled',
    ],
    'wallets' => [
        'direction_credit' => 'Credit',
        'direction_debit' => 'Debit',
        'type_order_payment' => 'Order payment',
        'type_order_payment_reversal' => 'Payment reversal',
        'type_order_refund' => 'Refund',
    ],
];
