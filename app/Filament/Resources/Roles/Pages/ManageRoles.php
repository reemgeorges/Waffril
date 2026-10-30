<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Services\Audit\AdminAudit;
use App\Support\Auth\AdminPermissions;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Spatie\Permission\Models\Role;

class ManageRoles extends ManageRecords
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->using(function (array $data) {
                $role = Role::create(['name' => $data['name'], 'guard_name' => AdminPermissions::GUARD]);
                AdminAudit::log('role.created', $role, ['name' => $role->name]);

                return RoleResource::syncPermissions($role, $data);
            }),
        ];
    }
}
