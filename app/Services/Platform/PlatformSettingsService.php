<?php

namespace App\Services\Platform;

use App\Exceptions\BusinessRuleException;
use App\Models\Admin;
use App\Models\Setting;
use App\Services\Audit\AdminAudit;
use App\Support\Auth\AdminPermissions;
use App\Support\Settings\PlatformSettings;
use Illuminate\Support\Facades\DB;

/**
 * WAFFRLI (2026-10-31) — saves the typed platform settings
 * (PlatformSettings::definitions()) with validation and an audit entry.
 */
class PlatformSettingsService
{
    /** @param array<string, mixed> $values keyed by setting key */
    public function save(array $values, Admin $admin): void
    {
        if (! AdminPermissions::allows($admin, 'settings.manage')) {
            throw new BusinessRuleException(__('admin.errors.forbidden'), 403);
        }

        $definitions = PlatformSettings::definitions();
        $changed = [];

        DB::transaction(function () use ($values, $definitions, $admin, &$changed) {
            foreach ($values as $key => $value) {
                if (! isset($definitions[$key])) {
                    continue;
                }

                $value = $this->normalize($key, $definitions[$key]['type'], $value);
                $setting = Setting::query()->firstOrNew(['key' => $key]);
                if ($setting->exists && $setting->value === $value) {
                    continue;
                }

                if ($value === null) {
                    if ($setting->exists) {
                        $setting->delete();
                        $changed[] = $key;
                    }

                    continue;
                }

                $setting->fill([
                    'value' => $value,
                    'is_monetary' => (bool) ($definitions[$key]['monetary'] ?? false),
                    'updated_by_admin_id' => $admin->id,
                ])->save();
                $changed[] = $key;
            }
        });

        if ($changed !== []) {
            AdminAudit::log('settings.updated', null, ['keys' => $changed], $admin);
        }
    }

    protected function normalize(string $key, string $type, mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }

        return match ($type) {
            'int' => $this->int($key, $value),
            'bool' => (bool) $value,
            'rating' => $this->rating($key, $value),
            'phone' => preg_match('/^\+[1-9]\d{6,14}$/', (string) $value) ? (string) $value : $this->invalid($key),
            'timezone' => in_array($value, timezone_identifiers_list(), true) ? $value : $this->invalid($key),
            default => mb_substr((string) $value, 0, 500),
        };
    }

    protected function int(string $key, mixed $value): int
    {
        if (! is_numeric($value) || (int) $value < 0 || (int) $value > 100000) {
            $this->invalid($key);
        }

        return (int) $value;
    }

    protected function rating(string $key, mixed $value): float
    {
        if (! is_numeric($value) || (float) $value < 1 || (float) $value > 5) {
            $this->invalid($key);
        }

        return round((float) $value, 2);
    }

    protected function invalid(string $key): never
    {
        throw new BusinessRuleException(__('admin.errors.invalid_setting', ['key' => $key]));
    }
}
