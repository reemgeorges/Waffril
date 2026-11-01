<?php

namespace App\Filament\Resources\DeliveryFeeRules\Pages;

use App\Filament\Resources\DeliveryFeeRules\DeliveryFeeRuleResource;
use App\Filament\Support\BusinessAction;
use App\Models\City;
use App\Models\DeliveryFeeRule;
use App\Services\Functional\Delivery\DeliveryFeeResolver;
use App\Services\Model\DeliveryFeeRule\DeliveryFeeRuleService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Grid;

class ManageDeliveryFeeRules extends ManageRecords
{
    protected static string $resource = DeliveryFeeRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->using(fn (array $data) => BusinessAction::run(
                fn ($admin) => app(DeliveryFeeRuleService::class)->persist($data, null, $admin->id)
            ) ?? new DeliveryFeeRule),
            Action::make('quote')->label(__('admin.delivery.quote_preview'))->icon('heroicon-o-calculator')->color('gray')
                ->schema([
                    Select::make('city_id')->label(__('admin.fields.city'))
                        ->options(fn () => City::all()->mapWithKeys(fn ($c) => [$c->id => $c->name]))->required(),
                    Grid::make(2)->schema([
                        TextInput::make('origin_lat')->label(__('admin.fields.origin_lat'))->numeric()->required(),
                        TextInput::make('origin_lng')->label(__('admin.fields.origin_lng'))->numeric()->required(),
                        TextInput::make('destination_lat')->label(__('admin.fields.destination_lat'))->numeric()->required(),
                        TextInput::make('destination_lng')->label(__('admin.fields.destination_lng'))->numeric()->required(),
                    ]),
                ])
                ->action(function (array $data) {
                    $quote = app(DeliveryFeeResolver::class)->quote(
                        (int) $data['city_id'], (float) $data['origin_lat'], (float) $data['origin_lng'],
                        (float) $data['destination_lat'], (float) $data['destination_lng'],
                    );
                    $body = __('admin.delivery.quote_result', [
                        'distance' => $quote->distanceKm,
                        'method' => $quote->distanceMethod,
                        'fee' => $quote->fee ?? '—',
                        'scope' => $quote->ruleScope ? __('admin.delivery.scope_'.$quote->ruleScope) : '—',
                    ]);
                    Notification::make()
                        ->title($quote->covered ? __('admin.delivery.covered') : __('admin.delivery.not_covered').' ('.$quote->reason.')')
                        ->body($body)
                        ->{$quote->covered ? 'success' : 'warning'}()
                        ->persistent()->send();
                }),
        ];
    }
}
