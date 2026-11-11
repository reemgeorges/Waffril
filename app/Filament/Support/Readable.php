<?php

namespace App\Filament\Support;

use Illuminate\Support\Arr;
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
        $label = isset(self::MODELS[$base]) ? __('admin.resources.'.self::MODELS[$base].'.singular') : Str::headline($base);

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

    /** Audit properties as "Label: value · Label: value" (field labels from admin.fields). */
    public static function properties(mixed $properties): string
    {
        if (! is_array($properties) || $properties === []) {
            return is_scalar($properties) ? (string) $properties : '—';
        }

        return collect(Arr::dot($properties))
            ->reject(fn ($value) => $value === null || $value === '' || is_array($value))
            ->map(function ($value, $key) {
                $leaf = Str::afterLast((string) $key, '.');
                $label = __('admin.fields.'.$leaf);
                $label = $label === 'admin.fields.'.$leaf ? Str::headline($leaf) : $label;
                $value = is_bool($value) ? ($value ? __('admin.common.yes') : __('admin.common.no')) : (string) $value;

                return $label.': '.(is_numeric($value) ? Fields::formatDecimal($value) : $value);
            })
            ->take(6)->implode(' · ');
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
