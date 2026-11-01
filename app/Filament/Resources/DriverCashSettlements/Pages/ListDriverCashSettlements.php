<?php

namespace App\Filament\Resources\DriverCashSettlements\Pages;

use App\Support\Settings\PlatformSettings;
use App\Filament\Resources\DriverCashSettlements\DriverCashSettlementResource;
use App\Filament\Support\BusinessAction;
use App\Models\Currency;
use App\Models\Driver;
use App\Services\Driver\DriverCashSettlementService;
use App\Support\Auth\AdminPermissions;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;

class ListDriverCashSettlements extends ListRecords
{
    protected static string $resource = DriverCashSettlementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('prepare')->label(__('admin.settlements.prepare'))->icon('heroicon-o-plus')
                ->visible(fn () => AdminPermissions::currentAllows('settlements.manage'))
                ->schema([
                    Select::make('driver_id')->label(__('admin.fields.driver'))->required()->searchable()
                        ->options(fn () => Driver::query()->orderBy('name')->pluck('name', 'id')),
                    Select::make('currency_id')->label(__('admin.fields.currency'))->required()
                        ->options(fn () => Currency::query()->where('is_active', true)->pluck('code', 'id')),
                    // Business days are in the platform timezone (not UTC): just after local
                    // midnight "today" must already be the new day.
                    DatePicker::make('business_date')->label(__('admin.fields.business_date'))->required()
                        ->default(fn () => now(PlatformSettings::timezone())->toDateString())
                        ->maxDate(fn () => now(PlatformSettings::timezone())->toDateString()),
                ])
                ->action(fn (array $data) => BusinessAction::run(fn ($admin) => app(DriverCashSettlementService::class)->prepare(
                    Driver::findOrFail($data['driver_id']), Currency::findOrFail($data['currency_id']),
                    Carbon::parse($data['business_date'])->toDateString(), $admin,
                ))),
        ];
    }
}
