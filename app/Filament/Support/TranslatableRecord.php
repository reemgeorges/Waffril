<?php

namespace App\Filament\Support;

/**
 * WAFFRLI (2026-10-31) — fills Spatie-translatable attributes with ALL
 * their translations ({ar, en}) when editing, instead of the
 * current-locale string the model returns by default. Saving passes the
 * array back, which Spatie stores as translations.
 */
trait TranslatableRecord
{
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        if (method_exists($record, 'getTranslatableAttributes')) {
            foreach ($record->getTranslatableAttributes() as $attribute) {
                $data[$attribute] = $record->getTranslations($attribute);
            }
        }

        return $data;
    }
}
