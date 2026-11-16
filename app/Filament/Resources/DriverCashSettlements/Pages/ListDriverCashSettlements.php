<?php

namespace App\Filament\Resources\DriverCashSettlements\Pages;

use App\Filament\Concerns\RefreshesOnOperationalEvents;
use App\Filament\Resources\DriverCashSettlements\DriverCashSettlementResource;
use App\Filament\Support\BusinessAction;
use App\Models\Currency;
use App\Models\Driver;
use App\Models\OrderPayment;
use App\Services\Currency\CurrencyPolicy;
use App\Services\Driver\DriverCashSettlementService;
use App\Support\Auth\AdminPermissions;
use App\Support\Settings\PlatformSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;

class ListDriverCashSettlements extends ListRecords
{
    use RefreshesOnOperationalEvents;

    protected static string $resource = DriverCashSettlementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('prepare')->label(__('admin.settlements.prepare'))->icon('heroicon-o-plus')
                ->visible(fn () => AdminPermissions::currentAllows('settlements.manage'))
                ->schema([
                    Select::make('driver_id')->label(__('admin.fields.driver'))->required()->searchable()
                        ->options(fn () => Driver::query()->orderBy('name')->pluck('name', 'id')),
                    // Operational currencies (CurrencyPolicy: SYP at launch), plus any
                    // currency in which collected cash still awaits settlement (e.g.
                    // an order paid before the switch), so nothing is stranded.
                    // SYP only in normal operation: the choice is shown only while
                    // historical cash in another currency still awaits settlement.
                    Select::make('currency_id')->label(__('admin.fields.currency'))->required()
                        ->default(fn () => CurrencyPolicy::displayCurrency(null)?->id)
                        ->options(fn () => self::settlementCurrencies())
                        ->hidden(fn () => count(self::settlementCurrencies()) <= 1)->dehydratedWhenHidden(),
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

    /**
     * Operational currencies, plus any currency in which collected cash still
     * awaits settlement (e.g. cash taken before SYP operation), so nothing is
     * stranded. The operational currency comes first.
     *
     * @return array<int, string>
     */
    public static function settlementCurrencies(): array
    {
        return once(fn () => Currency::query()
            ->whereIn('id', CurrencyPolicy::customerCurrencies()->pluck('id')
                ->merge(OrderPayment::query()->whereNotNull('collected_by_driver_id')->whereNull('driver_cash_settlement_id')->where('collected_amount', '>', 0)->distinct()->pluck('currency_id')))
            ->get()
            ->sortBy(fn (Currency $c) => [$c->id === CurrencyPolicy::displayCurrency(null)?->id ? 0 : 1, $c->code])
            ->pluck('code', 'id')->all());
    }
}
