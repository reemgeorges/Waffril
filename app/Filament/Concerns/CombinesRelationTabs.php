<?php

namespace App\Filament\Concerns;

/**
 * UX redesign (2026-11-11): on an edit page, the record's own form becomes
 * the first tab beside its related lists (a store with its branches and
 * products, a product with its prices and options), so everything about one
 * record is managed in one place. Pages set $contentTabLabelKey and
 * $contentTabIcon. The related lists keep their own permission checks.
 */
trait CombinesRelationTabs
{
    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return __(static::$contentTabLabelKey);
    }

    public function getContentTabIcon(): ?string
    {
        return static::$contentTabIcon;
    }
}
