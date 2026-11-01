<?php

namespace App\Support\Localization;

use Illuminate\Support\Facades\Lang;

/**
 * WAFFRLI (2026-11-04) — human label for a machine value, in the current
 * locale. APIs return the stable value AND its label ("status": "picked_up",
 * "status_label": "تم استلام الطلب"); values are never translated.
 *
 * The vocabulary lives once in lang/{ar,en}/admin.php and is shared by the
 * admin panel and the APIs. An unknown value (e.g. a free-form refund
 * reason code) is returned as-is rather than as a raw translation key.
 */
final class Label
{
    public const FAMILIES = [
        'order_type' => 'admin.orders.type_%s',
        'order_actor' => 'admin.orders.actor_%s',
        'cancellation_reason' => 'admin.orders.reason_%s',
        'payment_status' => 'admin.payments.status_%s',
        'payment_kind' => 'admin.payments.kind_%s',
        'wallet_type' => 'admin.wallets.type_%s',
        'wallet_direction' => 'admin.wallets.direction_%s',
        'request_status' => 'admin.requests.status_%s',
        'plus_request_type' => 'admin.plus.request_%s',
        'plus_request_source' => 'admin.plus.source_%s',
        'plus_membership' => 'admin.plus.membership_%s',
        'plus_cycle' => 'admin.plus.cycle_%s',
        'plus_policy' => 'admin.plus.policy_%s',
        'account_status' => 'admin.customers.status_%s',
        'driver_availability' => 'admin.drivers.availability_%s',
        'settlement_status' => 'admin.settlements.status_%s',
        'hours_kind' => 'admin.branches.kind_%s',
        'problem_type' => 'messages.driver.problem_types.%s',
    ];

    public static function of(string $family, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $key = sprintf(self::FAMILIES[$family], $value);

        return Lang::has($key) ? __($key) : $value;
    }
}
