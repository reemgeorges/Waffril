<?php

namespace App\Filament\Support;

use Filament\Resources\Resource;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

/**
 * WAFFRLI (2026-10-31) — base for every admin resource: translated
 * labels from lang/{ar,en}/admin.php (`admin.resources.<key>`).
 * Authorization comes from the model policy (App\Policies).
 */
abstract class AdminResource extends Resource
{
    protected static string $labelKey = '';

    public static function getModelLabel(): string
    {
        return __('admin.resources.'.static::$labelKey.'.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.'.static::$labelKey.'.plural');
    }

    public static function getNavigationLabel(): string
    {
        return static::getPluralModelLabel();
    }

    /**
     * UX redesign round 2 (2026-11-11): view and edit pages are titled with
     * the record itself ("تعديل مشاوي الشامي", "عرض Lina Haddad") instead of
     * the generic model name. Display only; global search stays off.
     */
    public static function hasRecordTitle(): bool
    {
        return true;
    }

    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        if ($record === null) {
            return static::getModelLabel();
        }
        if (method_exists($record, 'fullName') && filled($name = $record->fullName())) {
            return $name;
        }
        foreach (['order_number', 'name', 'title', 'code'] as $attribute) {
            $value = $record->getAttribute($attribute);
            if (is_string($value) && filled($value)) {
                return $value;
            }
        }

        return static::getModelLabel();
    }
}
