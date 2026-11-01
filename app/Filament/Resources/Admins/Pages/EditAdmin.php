<?php

namespace App\Filament\Resources\Admins\Pages;

use App\Filament\Resources\Admins\AdminResource;
use App\Services\Audit\AdminAudit;
use Filament\Resources\Pages\EditRecord;
use Spatie\Permission\PermissionRegistrar;

class EditAdmin extends EditRecord
{
    protected static string $resource = AdminResource::class;

    protected function afterSave(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        AdminAudit::log('admin.updated', $this->getRecord(), [
            'roles' => $this->getRecord()->roles()->pluck('name')->all(),
            'is_active' => (bool) $this->getRecord()->is_active,
        ]);
    }
}
