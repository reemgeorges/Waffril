<?php

namespace App\Filament\Resources\CurrencyRedenominations;

use App\Filament\Resources\CurrencyRedenominations\Pages\ListCurrencyRedenominations;
use App\Filament\Support\AdminResource;
use App\Models\CurrencyRedenomination;
use App\Services\Currency\CurrencyRedenominationService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * WAFFRLI (2026-10-31). Read-only history of default-currency
 * re-denomination runs. Starting a run is NOT available: the controlled
 * job (locking, conversion of every base-currency value, crash recovery)
 * is phase P7 and switching the default stays disabled until it exists.
 */
class CurrencyRedenominationResource extends AdminResource
{
    protected static ?string $model = CurrencyRedenomination::class;

    protected static string $labelKey = 'currency_redenomination';

    protected static string|\UnitEnum|null $navigationGroup = 'platform';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?int $navigationSort = 25;

    public static function table(Table $table): Table
    {
        return $table
            ->description(fn () => CurrencyRedenominationService::enabled()
                ? __('admin.redenomination.notice')
                : __('admin.currencies.redenomination_unavailable'))
            ->columns([
                TextColumn::make('fromCurrency.code')->label(__('admin.fields.from_currency')),
                TextColumn::make('toCurrency.code')->label(__('admin.fields.to_currency')),
                TextColumn::make('conversion_rate')->label(__('admin.fields.exchange_rate')),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge(),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime(),
                TextColumn::make('completed_at')->label(__('admin.fields.completed_at'))->dateTime(),
                TextColumn::make('error')->label(__('admin.redenomination.error'))->limit(60)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListCurrencyRedenominations::route('/')];
    }
}
