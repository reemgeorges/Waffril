<?php

/*
 * WAFFRLI P0 (2026-10-30). Only P0 keys are defined here; any other
 * messages.* key used elsewhere keeps resolving to its key exactly as
 * before this file existed.
 */
return [
    'db_check_violation' => 'The submitted data violates a data integrity rule.',
    'currency_default_required_first' => 'Initialize the default currency before adding other currencies.',
    'currency_default_already_initialized' => 'A default currency already exists. Changing the default currency is not available yet.',
    'currency_default_rate_fixed' => 'The default currency exchange rate is always 1.',
    'currency_default_cannot_deactivate' => 'The default currency cannot be deactivated.',
    'delivery_fee_rule_overlap' => 'This distance band overlaps an active band in the same scope.',
    'delivery_fee_rule_area_city_mismatch' => 'The delivery area must belong to the selected city.',
    'delivery_fee_rule_invalid_band' => 'distance_to_km must be greater than distance_from_km.',
    'setting_value_required' => 'The value is required.',
    'setting_monetary_value_invalid' => 'A monetary setting must be a non-negative number with at most 4 decimals.',
    'city_not_served' => 'This location is outside every service city.',

    // WAFFRLI driver API (2026-11-02).
    'driver' => [
        'invalid_credentials' => 'The phone number or password is incorrect.',
        'account_inactive' => 'This driver account is inactive. Contact the operations team.',
        'logged_out' => 'Logged out.',
        'password_changed' => 'Password changed. Other sessions were signed out.',
        'current_password_wrong' => 'The current password is incorrect.',
        'profile_updated' => 'Profile updated.',
        'availability_updated' => 'Availability updated.',
        'cannot_go_offline_with_active_delivery' => 'You cannot go offline while you have an active delivery. Finish it or contact the operations team.',
        'order_not_found' => 'Order not found.',
        'already_delivered' => 'This order has already been delivered.',
        'cash_amount_mismatch' => 'The cash collected (:collected) must equal the amount due (:due :currency). If the customer cannot pay, report a problem instead.',
        'cash_currency_mismatch' => 'Cash must be collected in :currency, the currency fixed at checkout.',
        'cash_not_due' => 'No cash is due for this order; do not collect cash.',
        'no_active_delivery' => 'Location updates are accepted only while you have an assigned or picked-up delivery.',
        'location_updated' => 'Location updated.',
        'problem_reported' => 'The problem was reported to the operations team.',
        'problem_requires_active_order' => 'Problems can be reported only for your assigned or picked-up orders.',
        'notification_marked_read' => 'Notification marked as read.',
        'notifications_marked_read' => ':count notifications marked as read.',
        'delivered' => 'Delivery completed.',
        'picked_up' => 'Order picked up.',
        'problem_types' => [
            'customer_unreachable' => 'Customer unreachable',
            'wrong_address' => 'Wrong or incomplete address',
            'customer_refused' => 'Customer refused the order',
            'branch_delay' => 'Long wait at the branch',
            'order_issue' => 'Problem with the order items',
            'vehicle_issue' => 'Vehicle problem',
            'safety_issue' => 'Safety concern',
            'other' => 'Other',
        ],
    ],
];
