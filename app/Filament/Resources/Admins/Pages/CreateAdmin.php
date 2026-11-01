<?php

namespace App\Filament\Resources\Admins\Pages;

use App\Filament\Resources\Admins\AdminResource;
use App\Services\Audit\AdminAudit;
use Filament\Resources\Pages\CreateRecord;
use Spatie\Permission\PermissionRegistrar;

class CreateAdmin extends CreateRecord
{
    protected static string $resource = AdminResource::class;

    protected function afterCreate(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        AdminAudit::log('admin.created', $this->getRecord(), ['roles' => $this->getRecord()->roles()->pluck('name')->all()]);
    }
}
