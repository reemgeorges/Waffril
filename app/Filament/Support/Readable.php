<?php

namespace App\Filament\Support;

use App\Support\Localization\Label;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * UX redesign round 2 (2026-11-11): turns stored technical values (class
 * names, dotted action keys, JSON payloads, setting keys) into words an
 * employee understands, in the panel language. Display only: what is
 * stored, filtered and audited is unchanged; anything unknown falls back to
 * a tidied version of the raw value instead of failing.
 */
final class Readable
{
    /** Model class basename → admin.resources.<key>. */
    private const MODELS = [
        'Order' => 'order', 'Store' => 'store', 'Branch' => 'branch', 'Product' => 'product',
        // Archived audit events retain their original class label.
        'ProductRating' => 'product_rating', 'DriverCashSettlement' => 'driver_cash_settlement',
        'Driver' => 'driver', 'User' => 'customer', 'Admin' => 'admin', 'PlusRequest' => 'plus_request',
        'PlusMembership' => 'plus_membership', 'PlusCycle' => 'plus_cycle', 'PlusPlan' => 'plus_plan',
        'Currency' => 'currency', 'Setting' => 'setting', 'OrderRefund' => 'order_refund',
        'OrderCancellationRequest' => 'order_cancellation_request', 'City' => 'city',
        'DeliveryArea' => 'delivery_area', 'DeliveryFeeRule' => 'delivery_fee_rule', 'Category' => 'category',
        'StoreCategory' => 'store_category', 'Offer' => 'offer', 'PaymentMethod' => 'payment_method',
        'Wallet' => 'wallet', 'Role' => 'role', 'OrderPayment' => 'order_payment',
        'CurrencyRedenomination' => 'currency_redenomination',
    ];

    public static function model(?string $class, mixed $id = null): string
    {
        if (blank($class)) {
            return '—';
        }
        $base = class_basename($class);
        $label = match ($base) {
            'OrderRating' => __('order_driver_ratings.order_singular'),
            'DriverRating' => __('order_driver_ratings.driver_singular'),
            default => isset(self::MODELS[$base]) ? __('admin.resources.'.self::MODELS[$base].'.singular') : Str::headline($base),
        };

        return $id !== null && $id !== '' ? $label.' #'.$id : $label;
    }

    /** "order.driver_assigned" → "تم إسناد سائق للطلب" (admin.audit_actions), else "Order · Driver assigned". */
    public static function action(?string $action): string
    {
        if (blank($action)) {
            return '—';
        }
        $key = 'admin.audit_actions.'.str_replace('.', '__', $action);
        $text = __($key);

        return $text !== $key ? $text : collect(explode('.', $action))->map(fn ($part) => Str::headline($part))->implode(' · ');
    }

    /**
     * Coded audit values that have a translated label: property key → Label
     * family (App\Support\Localization\Label). Anything else is shown as stored.
     */
    private const VALUE_FAMILIES = [
        'policy' => 'plus_policy',
        'reason' => 'cancellation_reason',
        'reason_code' => 'cancellation_reason',
    ];

    /**
     * Audit properties as "Label: value · Label: value". Labels come from
     * admin.fields, then admin.audit_keys (2026-11-16: keys that are not form
     * fields used to fall back to an English headline in the Arabic panel).
     */
    public static function properties(mixed $properties): string
    {
        if (! is_array($properties) || $properties === []) {
            return is_scalar($properties) ? (string) $properties : '—';
        }

        return collect(Arr::dot($properties))
            ->reject(fn ($value) => $value === null || $value === '' || is_array($value))
            ->map(function ($value, $key) {
                $leaf = Str::afterLast((string) $key, '.');

                return self::propertyLabel($leaf).': '.self::propertyValue($leaf, $value);
            })
            ->take(6)->implode(' · ');
    }

    public static function propertyLabel(string $key): string
    {
        foreach (['admin.fields.', 'admin.audit_keys.'] as $group) {
            if (Lang::has($group.$key)) {
                return __($group.$key);
            }
        }

        return Str::headline($key);
    }

    protected static function propertyValue(string $key, mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? __('admin.common.yes') : __('admin.common.no');
        }
        $value = (string) $value;
        if (is_numeric($value)) {
            return Fields::formatDecimal($value);
        }
        if (isset(self::VALUE_FAMILIES[$key]) && preg_match('/^[a-z_]+$/', $value)) {
            // Panel wording (admin.*), not the app vocabulary (api_labels).
            $translationKey = sprintf(Label::FAMILIES[self::VALUE_FAMILIES[$key]], $value);

            return Lang::has($translationKey) ? __($translationKey) : $value;
        }

        return $value;
    }

    /** Notification class or alias → label (admin.notification_types), else headline. */
    public static function notificationType(?string $type): string
    {
        // "order.picked_up", "driver.order_assigned" and demo class names
        // ("OrderPicked_up") all normalise to snake case: order_picked_up.
        $key = Str::snake(str_replace(['.', '-'], '_', class_basename((string) $type)));
        $key = preg_replace('/_+/', '_', $key);
        $lang = 'admin.notification_types.'.$key;
        $text = __($lang);

        return $text !== $lang ? $text : Str::headline($key);
    }

    /** The message a notification showed, in the panel language. */
    public static function notificationText(mixed $data): string
    {
        if (! is_array($data)) {
            return (string) $data;
        }
        $pick = fn ($value) => is_array($value) ? ($value[app()->getLocale()] ?? $value['en'] ?? reset($value)) : $value;
        $message = $pick($data['message'] ?? $data['body'] ?? null);
        $title = $pick($data['title'] ?? null);

        return trim(collect([$title, $message])->filter(fn ($v) => is_scalar($v) && $v !== '')->implode(' — ')) ?: self::properties($data);
    }

    /** "dispatch.auto_recovery" → its label in admin.settings.keys, else the key itself. */
    public static function settingKey(string $key): string
    {
        $lang = 'admin.settings.keys.'.$key;
        $text = __($lang);

        return $text !== $lang && is_string($text) ? $text : $key;
    }
}
