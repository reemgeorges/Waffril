<?php

namespace App\Filament\Resources\CurrencyRedenominations;

use App\Filament\Resources\Currencies\CurrencyResource;
use App\Filament\Resources\CurrencyRedenominations\Pages\ListCurrencyRedenominations;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Fields;
use App\Models\CurrencyRedenomination;
use App\Services\Currency\CurrencyRedenominationService;
use Filament\Support\Enums\FontWeight;
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

    protected static ?string $navParent = CurrencyResource::class;

    protected static string $labelKey = 'currency_redenomination';

    protected static string|\UnitEnum|null $navigationGroup = 'platform';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?int $navigationSort = 30;

    /**
     * Currency redenominations (table UX overhaul 2026-11-12): the
     * conversion "SYP → SYN" with its rate, status with colour, when it ran
     * and finished, the error (if any) under the status. Read only.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->description(fn () => CurrencyRedenominationService::enabled()
                ? __('admin.redenomination.notice')
                : __('admin.currencies.redenomination_unavailable'))
            ->modifyQueryUsing(fn ($query) => $query->with(['fromCurrency', 'toCurrency']))
            ->columns([
                TextColumn::make('fromCurrency.code')->label(__('admin.fields.from_currency'))->weight(FontWeight::SemiBold)
                    ->formatStateUsing(fn ($state, $record) => $state.' → '.$record->toCurrency?->code)
                    ->description(fn ($record) => '× '.Fields::formatDecimal($record->conversion_rate))
                    ->extraAttributes(['class' => 'wf-ltr']),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()
                    ->formatStateUsing(fn ($state) => __("admin.redenomination.status_{$state}"))
                    ->color(fn ($state) => match ((string) $state) {
                        'completed' => 'success', 'failed' => 'danger', 'running' => 'info', default => 'gray'
                    })
                    ->description(fn ($record) => $record->error ? mb_strimwidth((string) $record->error, 0, 70, '…') : null),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime()->color('gray'),
                TextColumn::make('completed_at')->label(__('admin.fields.completed_at'))->dateTime()->placeholder('—')->visibleFrom('md'),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListCurrencyRedenominations::route('/')];
    }
}
