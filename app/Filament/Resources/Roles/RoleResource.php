<?php

namespace App\Filament\Resources\Roles;

use App\Filament\Resources\Roles\Pages\ManageRoles;
use App\Filament\Support\AdminResource;
use App\Models\Admin;
use App\Services\Audit\AdminAudit;
use App\Support\Auth\AdminPermissions;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * WAFFRLI (2026-10-31). Customizable employee roles built from the
 * permission catalogue (admin guard). The super-admin role is fixed: it
 * is unrestricted by design and cannot be edited or deleted here.
 */
class RoleResource extends AdminResource
{
    protected static ?string $model = Role::class;

    protected static string $labelKey = 'role';

    protected static string|\UnitEnum|null $navigationGroup = 'system';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        $groups = [];
        foreach (AdminPermissions::catalogue() as $group => $permissions) {
            $groups[] = Section::make(__("admin.permission_groups.{$group}"))->collapsible()->schema([
                CheckboxList::make("permission_groups.{$group}")->hiddenLabel()->columns(2)
                    ->options(collect($permissions)->mapWithKeys(fn ($p) => [$p => __("admin.permissions.{$p}")])),
            ]);
        }

        return $schema->components([
            TextInput::make('name')->label(__('admin.fields.name'))->required()->maxLength(125)
                ->rule(fn (?Role $record) => Rule::unique('roles', 'name')->where('guard_name', AdminPermissions::GUARD)->ignore($record?->id))
                ->notIn([Admin::SUPER_ADMIN_ROLE]),
            ...$groups,
        ]);
    }

    /** Converts the grouped checkbox state into a flat permission list (and back). */
    public static function fillPermissions(array $data, Role $record): array
    {
        $owned = $record->permissions()->pluck('name')->all();
        foreach (AdminPermissions::catalogue() as $group => $permissions) {
            $data['permission_groups'][$group] = array_values(array_intersect($permissions, $owned));
        }

        return $data;
    }

    public static function syncPermissions(Role $role, array $data): Role
    {
        $selected = collect($data['permission_groups'] ?? [])->flatten()->filter()
            ->intersect(AdminPermissions::all())->values()->all();
        $role->syncPermissions(Permission::query()->where('guard_name', AdminPermissions::GUARD)->whereIn('name', $selected)->get());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $role;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->where('guard_name', AdminPermissions::GUARD))
            ->columns([
                TextColumn::make('name')->label(__('admin.fields.name'))->searchable(),
                TextColumn::make('permissions_count')->counts('permissions')->label(__('admin.resources.permission.plural')),
                TextColumn::make('users_count')->label(__('admin.resources.admin.plural'))
                    ->state(fn (Role $record) => DB::table(config('permission.table_names.model_has_roles'))
                        ->where('role_id', $record->id)->count()),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data, Role $record) => self::fillPermissions($data, $record))
                    ->using(function (Role $record, array $data) {
                        $record->update(['name' => $data['name']]);
                        AdminAudit::log('role.updated', $record, ['name' => $record->name]);

                        return self::syncPermissions($record, $data);
                    }),
                DeleteAction::make()->after(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageRoles::route('/')];
    }
}
