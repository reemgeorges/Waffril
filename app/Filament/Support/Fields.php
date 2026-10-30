<?php

namespace App\Filament\Support;

use Closure;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Illuminate\Database\Eloquent\Model;

/**
 * WAFFRLI (2026-10-31) — shared admin form fields.
 */
final class Fields
{
    /** Arabic + English inputs for a Spatie-translatable attribute ({ar, en}). */
    public static function translatable(string $name, bool $required = true, bool $multiline = false, int $max = 150): Grid
    {
        $make = fn (string $locale) => ($multiline ? Textarea::make("{$name}.{$locale}")->rows(3) : TextInput::make("{$name}.{$locale}")->maxLength($max))
            ->label(__("admin.fields.{$name}").' ('.strtoupper($locale).')')
            ->required($required);

        return Grid::make(2)->schema([$make('ar'), $make('en')]);
    }

    public static function phone(string $name): TextInput
    {
        return TextInput::make($name)
            ->label(__("admin.fields.{$name}"))
            ->tel()
            ->regex('/^\+[1-9]\d{6,14}$/')
            ->helperText(__('admin.help.e164'))
            ->maxLength(16);
    }

    public static function money(string $name, bool $required = true): TextInput
    {
        return TextInput::make($name)
            ->label(__("admin.fields.{$name}"))
            ->numeric()
            ->minValue(0)
            ->rule('decimal:0,4')
            ->required($required);
    }

    /**
     * For modal Edit/View actions: replaces each Spatie-translatable
     * attribute's current-locale string with all its translations so the
     * {ar, en} inputs are filled.
     */
    public static function withTranslations(): Closure
    {
        return function (array $data, Model $record): array {
            if (method_exists($record, 'getTranslatableAttributes')) {
                foreach ($record->getTranslatableAttributes() as $attribute) {
                    $data[$attribute] = $record->getTranslations($attribute);
                }
            }

            return $data;
        };
    }
}
