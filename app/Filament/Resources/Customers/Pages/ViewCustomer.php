<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\BusinessAction;
use App\Models\User;
use App\Services\Customer\CustomerAccountService;
use App\Support\Auth\AdminPermissions;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;

class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        $service = fn () => app(CustomerAccountService::class);

        return [
            Action::make('suspend')->label(__('admin.customers.suspend'))->icon('heroicon-o-no-symbol')->color('danger')
                ->visible(fn (User $record) => ! $record->isSuspended() && ! $record->anonymized_at && AdminPermissions::currentAllows('customers.suspend'))
                ->schema([TextInput::make('reason')->label(__('admin.fields.suspension_reason'))->required()->maxLength(255)])
                ->requiresConfirmation()
                ->action(fn (User $record, array $data) => BusinessAction::run(fn ($admin) => $service()->suspend($record, $admin, $data['reason']))),
            Action::make('reactivate')->label(__('admin.customers.reactivate'))->icon('heroicon-o-check-circle')->color('success')
                ->visible(fn (User $record) => $record->isSuspended() && ! $record->anonymized_at && AdminPermissions::currentAllows('customers.suspend'))
                ->requiresConfirmation()
                ->action(fn (User $record) => BusinessAction::run(fn ($admin) => $service()->reactivate($record, $admin))),
            Action::make('anonymize')->label(__('admin.customers.anonymize'))->icon('heroicon-o-user-minus')->color('danger')
                ->visible(fn (User $record) => $record->trashed() && ! $record->anonymized_at && AdminPermissions::currentAllows('customers.anonymize'))
                ->disabled(fn (User $record) => $service()->anonymizationBlockers($record) !== [])
                ->tooltip(fn (User $record) => implode(' ', $service()->anonymizationBlockers($record)) ?: null)
                ->requiresConfirmation()
                ->modalDescription(__('admin.customers.anonymize_warning'))
                ->action(fn (User $record) => BusinessAction::run(fn ($admin) => $service()->anonymize($record, $admin))),
        ];
    }
}
