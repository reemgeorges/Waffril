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
     * UX overhaul (2026-11-12): secondary lists sit under the item they belong
     * to (e.g. "All branches" under Stores), so the menu shows the everyday
     * entries first. A child stays top-level when its parent is not visible
     * to the signed-in admin, so nothing becomes unreachable.
     *
     * @var class-string|null resource or page class whose menu item is the parent
     */
    protected static ?string $navParent = null;

    public static function getNavigationParentItem(): ?string
    {
        $parent = static::$navParent;
        if ($parent === null) {
            return parent::getNavigationParentItem();
        }
        $visible = method_exists($parent, 'canAccess') ? $parent::canAccess() : true;

        return $visible ? $parent::getNavigationLabel() : null;
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
        // Records that belong to a customer (wallets, PLUS subscriptions) are
        // titled with the customer, plus the wallet currency when there is one.
        if (method_exists($record, 'user') && ! $record->getAttribute('name') && ! $record->getAttribute('order_number') && ($user = $record->user) && method_exists($user, 'fullName')) {
            $currency = method_exists($record, 'currency') ? $record->currency?->code : null;

            return trim($user->fullName().($currency ? ' · '.$currency : ''));
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
