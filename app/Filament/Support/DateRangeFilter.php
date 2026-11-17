<?php

namespace App\Filament\Support;

use App\Support\Time\AdminTimezone;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Components\Grid;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * WAFFRLI (2026-11-16) — one date-range filter for every admin list.
 *
 * "From" and "Until" sit side by side on every screen (the pair spans two
 * columns of the filter grid). The picked days are the admin's LOCAL days
 * (browser timezone, App\Support\Time\AdminTimezone), queried as UTC
 * instants; both are optional. Each filled date shows as its own removable
 * chip, so the active filter count and "remove all" also cover dates.
 */
final class DateRangeFilter
{
    public static function make(string $name, string $column, ?string $label = null): Filter
    {
        return Filter::make($name)
            ->label($label ?? __('admin.table.date_between'))
            ->schema([
                Grid::make(['default' => 2])->schema([
                    DatePicker::make('from')->label(($label ?? __('admin.table.date_between')).' — '.__('admin.fields.from')),
                    DatePicker::make('until')->label(__('admin.fields.until')),
                ]),
            ])
            ->columnSpan(['default' => 2])
            // 2026-11-17: the picked days are the ADMIN's local days (their
            // browser timezone), converted to UTC bounds: [start of "from",
            // start of the day after "until"). Was whereDate() on UTC values,
            // so a local evening fell on the next UTC day.
            ->query(fn (Builder $query, array $data) => $query
                ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->where($column, '>=', AdminTimezone::dayStartUtc((string) $date)))
                ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->where($column, '<', AdminTimezone::dayEndUtc((string) $date))))
            ->indicateUsing(function (array $data) use ($label): array {
                $indicators = [];
                $prefix = $label ?? __('admin.table.date_between');
                if ($data['from'] ?? null) {
                    $indicators[] = Indicator::make($prefix.': '.__('admin.fields.from').' '.Carbon::parse($data['from'])->translatedFormat(TableDefaults::dateFormat()))->removeField('from');
                }
                if ($data['until'] ?? null) {
                    $indicators[] = Indicator::make($prefix.': '.__('admin.fields.until').' '.Carbon::parse($data['until'])->translatedFormat(TableDefaults::dateFormat()))->removeField('until');
                }

                return $indicators;
            });
    }
}
