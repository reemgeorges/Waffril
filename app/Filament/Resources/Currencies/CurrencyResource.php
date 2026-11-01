<?php

namespace App\Filament\Resources\Currencies;

use App\Filament\Resources\Currencies\Pages\ManageCurrencies;
use App\Filament\Support\AdminResource;
use App\Filament\Support\BusinessAction;
use App\Filament\Support\Fields;
use App\Models\Currency;
use App\Services\Currency\CurrencyManagement;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * WAFFRLI (2026-10-31). Currencies, exchange rates and the default
 * currency. Every write goes through CurrencyManagement. Switching an
 * EXISTING default is deliberately disabled: the controlled
 * re-denomination job it requires (P7) is not implemented.
 */
class CurrencyResource extends AdminResource
{
    protected static ?string $model = Currency::class;

    protected static string $labelKey = 'currency';

    protected static string|\UnitEnum|null $navigationGroup = 'platform';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->label(__('admin.fields.code'))
                ->required()->length(3)->regex('/^[A-Za-z]{3}$/')
                ->unique(ignoreRecord: true)
                ->disabled(fn (?Currency $record) => $record !== null),
            Fields::translatable('name'),
            TextInput::make('symbol')->label(__('admin.fields.symbol'))->required()->maxLength(10),
            TextInput::make('decimals')->label(__('admin.fields.decimals'))->required()->integer()->minValue(0)->maxValue(4)->default(2),
            TextInput::make('exchange_rate')->label(__('admin.fields.exchange_rate'))
                ->required()->numeric()->gt(0)->rule('decimal:0,12')
                ->helperText(__('admin.help.exchange_rate'))
                ->disabled(fn (?Currency $record) => (bool) $record?->is_default),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label(__('admin.fields.code'))->searchable()->sortable(),
                TextColumn::make('name')->label(__('admin.fields.name')),
                TextColumn::make('symbol')->label(__('admin.fields.symbol')),
                TextColumn::make('decimals')->label(__('admin.fields.decimals')),
                TextColumn::make('exchange_rate')->label(__('admin.fields.exchange_rate'))->sortable(),
                IconColumn::make('is_default')->label(__('admin.fields.is_default'))->boolean(),
                IconColumn::make('is_active')->label(__('admin.fields.is_active'))->boolean(),
                TextColumn::make('rate_updated_at')->label(__('admin.fields.rate_updated_at'))->dateTime()->sortable(),
            ])
            ->filters([TernaryFilter::make('is_active')->label(__('admin.fields.is_active'))])
            ->recordActions([
                EditAction::make()->mutateRecordDataUsing(Fields::withTranslations())
                    ->using(fn (Currency $record, array $data) => BusinessAction::run(
                        fn ($admin) => app(CurrencyManagement::class)->update($record, $data, $admin)
                    ) ?? $record),
                Action::make('activate')->label(__('admin.actions.activate'))->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (Currency $record) => ! $record->is_active && static::can('update', $record))
                    ->requiresConfirmation()
                    ->action(fn (Currency $record) => BusinessAction::run(fn ($admin) => app(CurrencyManagement::class)->setActive($record, true, $admin))),
                Action::make('deactivate')->label(__('admin.actions.deactivate'))->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (Currency $record) => $record->is_active && ! $record->is_default && static::can('update', $record))
                    ->requiresConfirmation()
                    ->action(fn (Currency $record) => BusinessAction::run(fn ($admin) => app(CurrencyManagement::class)->setActive($record, false, $admin))),
                Action::make('initializeDefault')->label(__('admin.currencies.initialize_default'))->icon('heroicon-o-star')->color('warning')
                    ->visible(fn () => ! CurrencyManagement::hasDefault())
                    ->authorize(fn (Currency $record) => static::can('update', $record))
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.currencies.initialize_default_warning'))
                    ->action(fn (Currency $record) => BusinessAction::run(fn ($admin) => app(CurrencyManagement::class)->initializeDefault($record, $admin))),
                Action::make('switchDefault')->label(__('admin.currencies.switch_default'))->icon('heroicon-o-arrows-right-left')
                    ->visible(fn (Currency $record) => CurrencyManagement::hasDefault() && ! $record->is_default)
                    ->disabled()
                    ->tooltip(__('admin.currencies.switch_default_unavailable')),
            ])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return ['index' => ManageCurrencies::route('/')];
    }
}
