<?php

namespace App\Filament\Resources\DriverCashSettlements;

use App\Filament\Resources\DriverCashSettlements\Pages\ListDriverCashSettlements;
use App\Filament\Support\AdminResource;
use App\Filament\Support\BusinessAction;
use App\Models\DriverCashSettlement;
use App\Services\Driver\DriverCashSettlementService;
use App\Support\Auth\AdminPermissions;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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

    protected static string|\UnitEnum|null $navigationGroup = 'drivers';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static ?int $navigationSort = 20;

    public static function table(Table $table): Table
    {
        $can = fn () => AdminPermissions::currentAllows('settlements.manage');

        return $table
            ->columns([
                TextColumn::make('business_date')->label(__('admin.fields.business_date'))->date()->sortable(),
                TextColumn::make('driver.name')->label(__('admin.fields.driver'))->searchable(),
                TextColumn::make('currency.code')->label(__('admin.fields.currency')),
                TextColumn::make('expected_amount')->label(__('admin.fields.expected_amount')),
                TextColumn::make('received_amount')->label(__('admin.fields.received_amount'))->placeholder('—'),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()
                    ->color(fn ($state) => match ($state) {
                        'settled' => 'success', 'disputed' => 'danger', default => 'warning'
                    })
                    ->formatStateUsing(fn ($state) => __("admin.settlements.status_{$state}")),
                TextColumn::make('note')->label(__('admin.fields.note'))->limit(40)->placeholder('—'),
                TextColumn::make('settledBy.name')->label(__('admin.fields.settled_by'))->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->options([
                    'open' => __('admin.settlements.status_open'),
                    'settled' => __('admin.settlements.status_settled'),
                    'disputed' => __('admin.settlements.status_disputed'),
                ]),
            ])
            ->recordActions([
                Action::make('refresh')->label(__('admin.settlements.refresh'))->icon('heroicon-o-arrow-path')
                    ->visible(fn (DriverCashSettlement $record) => $record->status === DriverCashSettlement::STATUS_OPEN && $can())
                    ->action(fn (DriverCashSettlement $record) => BusinessAction::run(fn ($admin) => app(DriverCashSettlementService::class)
                        ->prepare($record->driver, $record->currency, $record->business_date->toDateString(), $admin))),
                Action::make('settle')->label(__('admin.settlements.record_received'))->icon('heroicon-o-banknotes')->color('success')
                    ->visible(fn (DriverCashSettlement $record) => $record->status === DriverCashSettlement::STATUS_OPEN && $can())
                    ->schema([
                        TextInput::make('received_amount')->label(__('admin.fields.received_amount'))->required()->numeric()->minValue(0)->rule('decimal:0,4'),
                        Textarea::make('note')->label(__('admin.fields.note'))->helperText(__('admin.settlements.note_required_on_difference'))->maxLength(500),
                    ])
                    ->requiresConfirmation()
                    ->action(fn (DriverCashSettlement $record, array $data) => BusinessAction::run(fn ($admin) => app(DriverCashSettlementService::class)
                        ->settle($record, (string) $data['received_amount'], $data['note'] ?? null, $admin))),
                Action::make('resolve')->label(__('admin.settlements.resolve_dispute'))->icon('heroicon-o-check-badge')->color('warning')
                    ->visible(fn (DriverCashSettlement $record) => $record->status === DriverCashSettlement::STATUS_DISPUTED && $can())
                    ->schema([Textarea::make('note')->label(__('admin.fields.note'))->required()->maxLength(500)])
                    ->requiresConfirmation()
                    ->action(fn (DriverCashSettlement $record, array $data) => BusinessAction::run(fn ($admin) => app(DriverCashSettlementService::class)
                        ->resolveDispute($record, $data['note'], $admin))),
            ])
            ->defaultSort('business_date', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListDriverCashSettlements::route('/')];
    }
}
