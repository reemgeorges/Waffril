<?php

namespace App\Filament\Resources\PlusMemberships\Pages;

use App\Filament\Resources\PlusMemberships\PlusMembershipResource;
use App\Filament\Support\BusinessAction;
use App\Filament\Support\PlusPolicyFields;
use App\Models\PlusMembership;
use App\Services\Plus\PlusService;
use App\Support\Auth\AdminPermissions;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;

class ViewPlusMembership extends ViewRecord
{
    protected static string $resource = PlusMembershipResource::class;

    protected function getHeaderActions(): array
    {
        $can = fn () => AdminPermissions::currentAllows('plus.approve');

        return [
            Action::make('renewalPolicy')->label(__('admin.plus.set_renewal_policy'))->icon('heroicon-o-arrow-path')
                ->visible(fn (PlusMembership $record) => $can() && $record->status === PlusMembership::STATUS_ACTIVE)
                ->fillForm(fn (PlusMembership $record) => ['renewal_policy' => $record->renewal_policy, 'custom_duration_days' => $record->custom_duration_days])
                ->schema(PlusPolicyFields::make())
                ->action(fn (PlusMembership $record, array $data) => BusinessAction::run(fn ($admin) => app(PlusService::class)
                    ->setRenewalPolicy($record, $data['renewal_policy'], isset($data['custom_duration_days']) ? (int) $data['custom_duration_days'] : null, $admin))),
            Action::make('freeDeliveries')->label(__('admin.plus.set_free_deliveries_override'))->icon('heroicon-o-gift')
                ->visible(fn (PlusMembership $record) => $can() && $record->status === PlusMembership::STATUS_ACTIVE)
                ->fillForm(fn (PlusMembership $record) => ['value' => $record->free_deliveries_override])
                ->schema([TextInput::make('value')->label(__('admin.fields.free_deliveries_override'))->integer()->minValue(0)->maxValue(1000)
                    ->helperText(__('admin.plus.override_help'))])
                ->action(fn (PlusMembership $record, array $data) => BusinessAction::run(fn ($admin) => app(PlusService::class)
                    ->setFreeDeliveriesOverride($record, filled($data['value'] ?? null) ? (int) $data['value'] : null, $admin))),
        ];
    }
}
