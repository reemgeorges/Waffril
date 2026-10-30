<?php

namespace App\Support\Settings;

use App\Models\Setting;

/**
 * WAFFRLI (2026-10-31) — registry of the platform settings the admin
 * panel edits and the services read. Values live in the `settings` table
 * (key/value JSON); `monetary` keys are in the default currency and are
 * flagged is_monetary so the future re-denomination job converts them.
 */
final class PlatformSettings
{
    /** @return array<string, array{group: string, type: string, default: mixed, monetary?: bool}> */
    public static function definitions(): array
    {
        return [
            'general.platform_name' => ['group' => 'general', 'type' => 'string', 'default' => 'WAFFRLI'],
            'support.phone' => ['group' => 'support', 'type' => 'phone', 'default' => null],
            'support.whatsapp' => ['group' => 'support', 'type' => 'phone', 'default' => null],
            'operations.timezone' => ['group' => 'operations', 'type' => 'timezone', 'default' => 'Asia/Damascus'],
            'orders.accepting_new' => ['group' => 'maintenance', 'type' => 'bool', 'default' => true],
            'maintenance.message' => ['group' => 'maintenance', 'type' => 'string', 'default' => null],
            'orders.scheduled_acceptance_window_minutes' => ['group' => 'scheduled_orders', 'type' => 'int', 'default' => 60],
            'orders.scheduled_min_lead_minutes' => ['group' => 'scheduled_orders', 'type' => 'int', 'default' => 60],
            'orders.scheduled_max_days_ahead' => ['group' => 'scheduled_orders', 'type' => 'int', 'default' => 7],
            'delivery.driver_recommendation_limit' => ['group' => 'delivery', 'type' => 'int', 'default' => 5],
            'catalog.placeholder_rating' => ['group' => 'catalog', 'type' => 'rating', 'default' => null],
            'plus.renewal_request_lead_days' => ['group' => 'plus', 'type' => 'int', 'default' => 3],
        ];
    }

    public static function get(string $key): mixed
    {
        $definition = self::definitions()[$key] ?? null;
        $value = Setting::query()->where('key', $key)->value('value');

        if ($value === null) {
            return $definition['default'] ?? null;
        }

        return match ($definition['type'] ?? 'string') {
            'int' => (int) json_decode((string) $value, true),
            'bool' => (bool) json_decode((string) $value, true),
            default => json_decode((string) $value, true),
        };
    }

    public static function timezone(): string
    {
        $tz = self::get('operations.timezone');

        return is_string($tz) && in_array($tz, timezone_identifiers_list(), true) ? $tz : config('app.timezone', 'UTC');
    }
}
