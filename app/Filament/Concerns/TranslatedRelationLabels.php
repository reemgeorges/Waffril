<?php

namespace App\Filament\Concerns;

/**
 * UX overhaul (2026-11-12): relation-manager buttons, dialogs and empty
 * states use translated record names ("إضافة ساعة عمل") instead of the
 * English class name ("branch opening hour"). Keys in admin.relation_labels.
 */
trait TranslatedRelationLabels
{
    protected static function getModelLabel(): ?string
    {
        return __('admin.relation_labels.'.static::$relationLabelKey.'.singular');
    }

    protected static function getPluralModelLabel(): ?string
    {
        return __('admin.relation_labels.'.static::$relationLabelKey.'.plural');
    }
}
