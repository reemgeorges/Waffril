<?php

namespace App\Filament\Support;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\CheckboxColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/**
 * WAFFRLI admin table layout policy (2026-11-11). Display only: alignment,
 * compact widths and the empty-value placeholder, applied through Filament's
 * configureUsing() when each column is created. A resource can still
 * override any of it on the column itself (its own ->alignEnd() etc. wins).
 *
 *  - Counts, sort order, quantities, attempts, decimals: centred and compact,
 *    so a short number sits under its (short) heading.
 *  - Money and measures (amounts, totals, balances, prices, fees, rates,
 *    discount values, distances): end-aligned so digits line up.
 *  - Identifiers (slug, code, phone, e-mail, order number), dates and badge
 *    columns: sized to their content; names and descriptions take the
 *    remaining width.
 *  - Toggles, checkboxes and icons: centred and compact.
 *  - Images: compact (an empty image shows the theme's neutral dash).
 *  - Empty text values: a neutral "—" instead of a blank cell.
 *
 * Column names follow the database columns, so the rule reads the name.
 */
final class TableDefaults
{
    /** Short whole numbers: centred, compact. */
    public const COMPACT_NUMBER = '/(^|_)(count|sort_order|quantity|max_quantity|attempts|decimals)$|_count$/';

    /** Money and measures: end-aligned. */
    public const MEASURE = '/(^|_)(amount|total|subtotal|balance|balance_after|price|price_delta|fee|base_fee|per_km_fee|rate|exchange_rate|conversion_rate|discount_value|radius_km|distance_from_km|distance_to_km)$/';

    /**
     * Identifiers and dates: sized to their (single-line) content, so the
     * spare width goes to names and descriptions instead of a short slug.
     */
    public const COMPACT_TEXT = '/^(id|slug|code|symbol|phone|email|order_number|reference|uuid|scheduled_for)$|_(slug|code|phone|email|number)$|_at$/';

    /** Shrinks a column to its content (works with the auto table layout). */
    public const COMPACT_WIDTH = '1%';

    /**
     * Dates without seconds, day first (UX redesign 2026-11-11):
     * "11 نوفمبر 2026، 21:03" / "11 Nov 2026, 21:03". Display only.
     */
    public static function dateTimeFormat(): string
    {
        return app()->getLocale() === 'ar' ? 'j F Y، H:i' : 'j M Y, H:i';
    }

    public static function dateFormat(): string
    {
        return app()->getLocale() === 'ar' ? 'j F Y' : 'j M Y';
    }

    public static function register(): void
    {
        foreach ([Table::class, Schema::class] as $class) {
            $class::configureUsing(fn ($component) => $component
                ->defaultDateTimeDisplayFormat(fn () => self::dateTimeFormat())
                ->defaultDateDisplayFormat(fn () => self::dateFormat()));
        }

        TextColumn::configureUsing(function (TextColumn $column): void {
            $name = (string) $column->getName();
            $leaf = str_contains($name, '.') ? substr($name, strrpos($name, '.') + 1) : $name;

            if (preg_match(self::COMPACT_NUMBER, $leaf)) {
                $column->alignCenter()->width(self::COMPACT_WIDTH);
            } elseif (preg_match(self::MEASURE, $leaf)) {
                // Display only: "0.2000" → "0.2", "13000.500000000000" → "13,000.5"
                // (a resource's own formatter, set later, still wins).
                $column->alignEnd()->formatStateUsing(fn ($state) => is_numeric($state) ? Fields::formatDecimal($state) : $state);
            } elseif (preg_match(self::COMPACT_TEXT, $leaf)) {
                $column->width(self::COMPACT_WIDTH);
            } else {
                // Badge columns (statuses, categories) are sized to their
                // badges; ->badge() is set later, so decide when rendering.
                $column->width(fn (TextColumn $column): ?string => $column->isBadge() ? self::COMPACT_WIDTH : null);
            }

            $column->placeholder('—');
        });

        ToggleColumn::configureUsing(fn (ToggleColumn $column) => $column->alignCenter()->width(self::COMPACT_WIDTH));
        CheckboxColumn::configureUsing(fn (CheckboxColumn $column) => $column->alignCenter()->width(self::COMPACT_WIDTH));
        // "No" is the normal case for most flags: a quiet grey dash, never a
        // red cross (a resource can still set its own false icon/colour).
        IconColumn::configureUsing(fn (IconColumn $column) => $column->alignCenter()->width(self::COMPACT_WIDTH)
            ->falseIcon('heroicon-o-minus')->falseColor('gray'));

        // Every "create" button carries a plus; deleting a row is an icon with
        // its label as tooltip, so lists are not a column of red words.
        CreateAction::configureUsing(fn (CreateAction $action) => $action->icon('heroicon-m-plus'));
        DeleteAction::configureUsing(fn (DeleteAction $action) => $action->iconButton()->tooltip(fn (DeleteAction $action) => $action->getLabel()));
        RestoreAction::configureUsing(fn (RestoreAction $action) => $action->iconButton()->tooltip(fn (RestoreAction $action) => $action->getLabel()));
        ImageColumn::configureUsing(fn (ImageColumn $column) => $column->width(self::COMPACT_WIDTH));
    }
}
