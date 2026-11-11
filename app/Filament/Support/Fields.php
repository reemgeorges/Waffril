<?php

namespace App\Filament\Support;

use App\Models\Currency;
use App\Support\Money\Money;
use Closure;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * WAFFRLI (2026-10-31) — shared admin form fields.
 */
final class Fields
{
    /**
     * Arabic + English inputs for a Spatie-translatable attribute ({ar, en}).
     * With $fillsSlug, leaving the English name fills an empty "slug" field
     * from it (UX redesign 2026-11-11), so nobody has to invent one.
     */
    public static function translatable(string $name, bool $required = true, bool $multiline = false, int $max = 150, bool $fillsSlug = false): Grid
    {
        $make = fn (string $locale) => ($multiline ? Textarea::make("{$name}.{$locale}")->rows(3) : TextInput::make("{$name}.{$locale}")->maxLength($max))
            ->label(__("admin.fields.{$name}").' ('.__("admin.languages.{$locale}").')')
            // Each language is typed in its own direction, whatever the panel language.
            ->extraInputAttributes(['dir' => $locale === 'ar' ? 'rtl' : 'ltr', 'lang' => $locale])
            ->required($required)
            ->when($fillsSlug && $locale === 'en', fn ($input) => $input->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                    if (blank($get('slug')) && filled($state)) {
                        $set('slug', Str::slug($state));
                    }
                }));

        return Grid::make(2)->schema([$make('ar'), $make('en')]);
    }

    /**
     * The URL name (slug): filled from the English name when left empty,
     * kept in a collapsed "More settings" section because staff rarely need
     * it. Validation is unchanged (required, unique, letters/digits/dashes).
     */
    public static function slug(string $table): TextInput
    {
        return TextInput::make('slug')->label(__('admin.fields.slug'))
            ->required()->maxLength(120)->alphaDash()->unique($table, 'slug', ignoreRecord: true)
            ->extraInputAttributes(['dir' => 'ltr'])
            ->helperText(__('admin.help.slug'));
    }

    /** A collapsed section for rarely changed settings (progressive disclosure). */
    public static function moreSettings(array $schema): Section
    {
        return Section::make(__('admin.forms.more_settings'))
            ->description(__('admin.forms.more_settings_help'))
            ->icon('heroicon-o-adjustments-horizontal')
            ->collapsible()->collapsed(fn (?Model $record) => $record !== null)
            ->schema($schema);
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

    /**
     * Display a stored amount (4-decimal storage scale) in its currency:
     * the currency's own decimals, thousands separator, ISO code
     * ("USD 4.58", "SYP 66,127"). Display only: stored values are untouched.
     */
    public static function formatMoney(mixed $amount, ?Currency $currency): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }
        $decimals = $currency?->decimals ?? 2;
        $value = (string) Money::roundTo((string) $amount, $decimals);
        [$int, $fraction] = array_pad(explode('.', ltrim($value, '-')), 2, '');
        $text = number_format((float) $int, 0, '.', ',').($decimals > 0 ? '.'.str_pad($fraction, $decimals, '0') : '');

        return trim(($currency?->code ?? '').' '.(str_starts_with($value, '-') ? '−' : '').$text);
    }

    /**
     * Display a stored decimal without its storage padding: thousands
     * separator, trailing fraction zeros removed, nothing rounded
     * ("1.000000000000" → "1", "13000.500000000000" → "13,000.5",
     * "7.140" → "7.14"). String-based, so no float precision is lost.
     * Display only: stored values and calculations are untouched.
     */
    public static function formatDecimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $text = trim((string) $value);
        if (! preg_match('/^-?\d+(\.\d+)?$/', $text)) {
            return $text;
        }
        $negative = str_starts_with($text, '-');
        [$int, $fraction] = array_pad(explode('.', ltrim($text, '-')), 2, '');
        $int = ltrim($int, '0') === '' ? '0' : ltrim($int, '0');
        $grouped = strrev(implode(',', str_split(strrev($int), 3)));
        $fraction = rtrim($fraction, '0');

        return ($negative && ($int !== '0' || $fraction !== '') ? '−' : '').$grouped.($fraction !== '' ? '.'.$fraction : '');
    }
}
