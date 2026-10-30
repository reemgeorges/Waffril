<?php

namespace App\Filament\Resources\CurrencyRedenominations\Pages;

use App\Filament\Resources\CurrencyRedenominations\CurrencyRedenominationResource;
use App\Filament\Support\BusinessAction;
use App\Jobs\Currency\RunCurrencyRedenomination;
use App\Models\Currency;
use App\Services\Currency\CurrencyRedenominationService;
use App\Support\Auth\PlatformPermissions;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Str;

/**
 * WAFFRLI (2026-11-03) — history of default-currency switches and the
 * controlled "switch default currency" action (permission
 * currencies.change_default, feature flag waffrli.currency.redenomination_enabled).
 * The target's CURRENT exchange rate is the conversion rate; the admin must
 * type the target code to confirm. The run executes in a queued job.
 */
class ListCurrencyRedenominations extends ListRecords
{
    protected static string $resource = CurrencyRedenominationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('switchDefault')->label(__('admin.redenomination.action'))->icon('heroicon-o-arrows-right-left')->color('danger')
                ->visible(fn () => CurrencyRedenominationService::enabled()
                    && PlatformPermissions::allows(auth('admin_web')->user(), PlatformPermissions::CURRENCIES_CHANGE_DEFAULT))
                ->modalDescription(__('admin.redenomination.warning'))
                ->schema([
                    Select::make('to_currency_id')->label(__('admin.fields.to_currency'))->required()
                        ->options(fn () => Currency::query()->where('is_active', true)->where('is_default', false)->orderBy('code')->get()
                            ->mapWithKeys(fn (Currency $c) => [$c->id => $c->code.' — 1 '.Currency::query()->default()->value('code').' = '.$c->exchange_rate.' '.$c->code])),
                    TextInput::make('confirm_code')->label(__('admin.redenomination.confirm_code'))->required()
                        ->rule(fn ($get) => function (string $attribute, $value, $fail) use ($get) {
                            if (strtoupper((string) $value) !== strtoupper((string) Currency::query()->whereKey($get('to_currency_id'))->value('code'))) {
                                $fail(__('admin.redenomination.confirm_mismatch'));
                            }
                        }),
                ])
                ->action(fn (array $data) => BusinessAction::run(function ($admin) use ($data) {
                    $run = app(CurrencyRedenominationService::class)->request($admin, (int) $data['to_currency_id'], 'admin:'.$admin->id.':'.Str::uuid());
                    RunCurrencyRedenomination::dispatch($run->id);

                    return $run;
                }, __('admin.redenomination.queued'))),
        ];
    }
}
