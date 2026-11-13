<?php

namespace App\Filament\Support;

use Closure;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared column building blocks (table UX overhaul, 2026-11-12). Display
 * only: they read existing attributes and never change data.
 *
 *  - status():  one readable badge (Active / Inactive / Deleted) with an
 *               icon, instead of a bare switch in every row;
 *  - picture(): a record's picture, or an initial placeholder when it has
 *               none (never a broken image or an empty column);
 *  - countLink(): a count chip ("4 branches ›") that opens the related list;
 *  - stars():   an average rating as "★ 4.5 (12)", or "No ratings yet".
 */
final class Columns
{
    public static function status(string $attribute = 'is_active', ?string $activeLabel = null, ?string $inactiveLabel = null): TextColumn
    {
        return TextColumn::make('status_'.$attribute)->label(__('admin.fields.status'))->badge()
            ->state(fn (Model $record) => match (true) {
                method_exists($record, 'trashed') && $record->trashed() => 'deleted',
                (bool) $record->getAttribute($attribute) => 'active',
                default => 'inactive',
            })
            ->formatStateUsing(fn (string $state) => match ($state) {
                'deleted' => __('admin.table.deleted'),
                'active' => $activeLabel ?? __('admin.table.active'),
                default => $inactiveLabel ?? __('admin.table.inactive'),
            })
            ->icon(fn (string $state) => match ($state) {
                'active' => 'heroicon-m-check-circle',
                'deleted' => 'heroicon-m-trash',
                default => 'heroicon-m-pause-circle',
            })
            ->color(fn (string $state) => match ($state) {
                'active' => 'success',
                'deleted' => 'danger',
                default => 'gray',
            })
            ->width(TableDefaults::COMPACT_WIDTH);
    }

    /** @param  Closure(Model): ?string  $name  the text the placeholder initial comes from */
    public static function picture(string $attribute, Closure $name, int $size = 40): ImageColumn
    {
        return ImageColumn::make($attribute)->label('')->disk('public')
            ->defaultImageUrl(fn (Model $record) => Avatar::placeholder($name($record)))
            ->imageSize($size)->square()
            ->extraImgAttributes(['class' => 'wf-avatar', 'alt' => '', 'loading' => 'lazy']);
    }

    /**
     * @param  string  $choiceKey  translation with pluralisation, e.g. admin.table.branches_count
     * @param  Closure(Model): ?string  $url  null when the viewer may not open the list
     */
    public static function countLink(string $name, string $label, string $choiceKey, string $icon, Closure $url, ?string $tooltip = null): TextColumn
    {
        return TextColumn::make($name)->label($label)
            ->formatStateUsing(fn ($state) => trans_choice($choiceKey, (int) $state, ['count' => (int) $state]))
            ->icon($icon)->badge()->color(fn ($state) => (int) $state > 0 ? 'primary' : 'gray')
            ->url($url)->tooltip($tooltip)
            ->extraAttributes(['class' => 'wf-stat-link'])
            ->width(TableDefaults::COMPACT_WIDTH);
    }

    /** @param  Closure(Model): array{0: ?float, 1: int}  $rating  [average, count] */
    public static function stars(string $name, Closure $rating): TextColumn
    {
        return TextColumn::make($name)->label(__('admin.fields.rating'))
            ->state(function (Model $record) use ($rating) {
                [$average, $count] = $rating($record);

                return $count > 0 ? '★ '.rtrim(rtrim(number_format((float) $average, 1), '0'), '.').' ('.$count.')' : __('admin.table.no_ratings');
            })
            ->color(fn (Model $record) => $rating($record)[1] > 0 ? 'warning' : 'gray')
            ->extraAttributes(['class' => 'wf-num'])
            ->width(TableDefaults::COMPACT_WIDTH);
    }
}
