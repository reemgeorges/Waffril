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
            // {ar, en}: shown to customers while new orders are paused.
            'maintenance.message' => ['group' => 'maintenance', 'type' => 'translated', 'default' => null],
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
        // Raw stored JSON (toBase): the model's `json` cast would decode it a second time.
        $value = Setting::query()->where('key', $key)->toBase()->value('value');

        if ($value === null) {
            return $definition['default'] ?? null;
        }

        return match ($definition['type'] ?? 'string') {
            'int' => (int) json_decode((string) $value, true),
            'bool' => (bool) json_decode((string) $value, true),
            'translated' => self::translations(json_decode((string) $value, true)),
            default => json_decode((string) $value, true),
        };
    }

    /**
     * A translated setting in the requested (or current) locale, falling back
     * to the other supported language; null when neither is set.
     */
    public static function text(string $key, ?string $locale = null): ?string
    {
        $values = (array) self::get($key);
        $locale ??= app()->getLocale();
        foreach (array_unique([$locale, config('app.fallback_locale', 'en'), 'ar', 'en']) as $candidate) {
            if (filled($values[$candidate] ?? null)) {
                return $values[$candidate];
            }
        }

        return null;
    }

    /**
     * {ar, en} from a stored value. A legacy plain string is assigned to ONE
     * language by its script (Arabic letters → ar, otherwise en); the other
     * language stays empty rather than being invented.
     *
     * @return array{ar: ?string, en: ?string}
     */
    public static function translations(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_match('/\p{Arabic}/u', $value) ? ['ar' => $value] : ['en' => $value];
        }

        return ['ar' => ($value['ar'] ?? null) ?: null, 'en' => ($value['en'] ?? null) ?: null];
    }

    public static function timezone(): string
    {
        $tz = self::get('operations.timezone');

        return is_string($tz) && in_array($tz, timezone_identifiers_list(), true) ? $tz : config('app.timezone', 'UTC');
    }
}
