<?php

namespace App\Filament\Support;

use Filament\Resources\Resource;

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
}
