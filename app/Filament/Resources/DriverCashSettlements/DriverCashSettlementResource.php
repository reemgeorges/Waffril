<?php

namespace App\Filament\Resources\DriverCashSettlements;

use App\Filament\Resources\DriverCashSettlements\Pages\ListDriverCashSettlements;
use App\Filament\Support\AdminResource;
use App\Filament\Support\BusinessAction;
use App\Filament\Support\Fields;
use App\Filament\Support\RowActions;
use App\Models\DriverCashSettlement;
use App\Services\Driver\DriverCashSettlementService;
use App\Support\Auth\AdminPermissions;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * WAFFRLI (2026-10-31). Daily driver → platform cash reconciliation per
 * currency (no merchant settlement exists). Prepare a day, record the
 * cash received (a difference needs a note and becomes a dispute), then
 * resolve disputes. Settled days are final.
 */
class DriverCashSettlementResource extends AdminResource
{
    protected static ?string $model = DriverCashSettlement::class;

    protected static string $labelKey = 'driver_cash_settlement';

    protected static string|\UnitEnum|null $navigationGroup = 'finance';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static ?int $navigationSort = 10;

    /**
     * Driver cash settlements (table UX overhaul 2026-11-12; also a driver's
     * tab): business day and driver, expected and received amounts in the
     * settlement's own currency with the difference, status, note and who
     * settled it. "Record cash received" is the primary action on open
     * days; refresh and dispute resolution keep their confirmation and need
     * settlements.manage.
     */
    public static function table(Table $table): Table
    {
        $can = fn () => AdminPermissions::currentAllows('settlements.manage');
        $money = fn ($state, $record) => Fields::formatMoney($state, $record->currency);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['driver', 'currency', 'settledBy']))
            ->columns([
                TextColumn::make('business_date')->label(__('admin.fields.business_date'))->date()->sortable()->weight(FontWeight::SemiBold)
                    ->description(fn ($record) => $record->driver?->name)
                    ->searchable(query: fn ($query, string $search) => $query->whereHas('driver', fn ($d) => $d->where('name', 'like', "%{$search}%"))),
                TextColumn::make('expected_amount')->label(__('admin.fields.expected_amount'))->formatStateUsing($money),
                TextColumn::make('received_amount')->label(__('admin.fields.received_amount'))->placeholder('—')->weight(FontWeight::SemiBold)
                    ->formatStateUsing($money)
                    ->description(fn ($record) => $record->received_amount !== null && bccomp((string) $record->received_amount, (string) $record->expected_amount, 4) !== 0
                        ? __('admin.table.difference', ['amount' => $money(bcsub((string) $record->received_amount, (string) $record->expected_amount, 4), $record)]) : null),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()
                    ->color(fn ($state) => match ($state) {
                        'settled' => 'success', 'disputed' => 'danger', default => 'warning'
                    })
                    ->icon(fn ($state) => match ($state) {
                        'settled' => 'heroicon-m-check-circle', 'disputed' => 'heroicon-m-exclamation-triangle', default => 'heroicon-m-clock'
                    })
                    ->formatStateUsing(fn ($state) => __("admin.settlements.status_{$state}"))
                    ->description(fn ($record) => $record->settledBy ? __('admin.table.by', ['name' => $record->settledBy->name]) : null),
                TextColumn::make('note')->label(__('admin.fields.note'))->limit(60)->wrap()->placeholder('—')->visibleFrom('lg'),
            ])
            ->searchPlaceholder(__('admin.table.search_settlements'))
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->options([
                    'open' => __('admin.settlements.status_open'),
                    'settled' => __('admin.settlements.status_settled'),
                    'disputed' => __('admin.settlements.status_disputed'),
                ]),
                SelectFilter::make('driver_id')->label(__('admin.fields.driver'))->relationship('driver', 'name')->searchable()->preload()
                    ->hidden(fn ($livewire) => $livewire instanceof RelationManager),
                SelectFilter::make('currency_id')->label(__('admin.fields.currency'))->relationship('currency', 'code'),
            ])
            ->recordActions([
                RowActions::primary(Action::make('settle')->label(__('admin.table.settle'))->icon('heroicon-m-banknotes')
                    ->visible(fn (DriverCashSettlement $record) => $record->status === DriverCashSettlement::STATUS_OPEN && $can())
                    ->schema([
                        TextInput::make('received_amount')->label(__('admin.fields.received_amount'))->required()->numeric()->minValue(0)->rule('decimal:0,4'),
                        Textarea::make('note')->label(__('admin.fields.note'))->helperText(__('admin.settlements.note_required_on_difference'))->maxLength(500),
                    ])
                    ->requiresConfirmation()
                    ->action(fn (DriverCashSettlement $record, array $data) => BusinessAction::run(fn ($admin) => app(DriverCashSettlementService::class)
                        ->settle($record, (string) $data['received_amount'], $data['note'] ?? null, $admin)))),
                RowActions::primary(Action::make('resolve')->label(__('admin.settlements.resolve_dispute'))->icon('heroicon-m-check-badge')->color('warning')
                    ->visible(fn (DriverCashSettlement $record) => $record->status === DriverCashSettlement::STATUS_DISPUTED && $can())
                    ->schema([Textarea::make('note')->label(__('admin.fields.note'))->required()->maxLength(500)])
                    ->requiresConfirmation()
                    ->action(fn (DriverCashSettlement $record, array $data) => BusinessAction::run(fn ($admin) => app(DriverCashSettlementService::class)
                        ->resolveDispute($record, $data['note'], $admin)))),
                RowActions::more([
                    Action::make('refresh')->label(__('admin.settlements.refresh'))->icon('heroicon-m-arrow-path')
                        ->visible(fn (DriverCashSettlement $record) => $record->status === DriverCashSettlement::STATUS_OPEN && $can())
                        ->action(fn (DriverCashSettlement $record) => BusinessAction::run(fn ($admin) => app(DriverCashSettlementService::class)
                            ->prepare($record->driver, $record->currency, $record->business_date->toDateString(), $admin))),
                ]),
            ])
            ->defaultSort('business_date', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListDriverCashSettlements::route('/')];
    }
}
