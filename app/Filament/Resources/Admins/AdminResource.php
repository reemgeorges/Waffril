<?php

namespace App\Filament\Resources\Admins;

use App\Filament\Resources\Admins\Pages\CreateAdmin;
use App\Filament\Resources\Admins\Pages\EditAdmin;
use App\Filament\Resources\Admins\Pages\ListAdmins;
use App\Filament\Support\AdminResource as BaseAdminResource;
use App\Models\Admin;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Spatie\Permission\Models\Role;

/**
 * WAFFRLI (2026-10-31). Admin and employee accounts with their roles.
 * Only a Super Admin can grant the super-admin role; nobody can
 * deactivate or delete their own account.
 */
class AdminResource extends BaseAdminResource
{
    protected static ?string $model = Admin::class;

    protected static string $labelKey = 'admin';

    protected static string|\UnitEnum|null $navigationGroup = 'system';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'admins';

    public static function form(Schema $schema): Schema
    {
        $isSelf = fn (?Admin $record) => $record !== null && $record->id === auth('admin_web')->id();

        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->label(__('admin.fields.name'))->required()->maxLength(255),
                TextInput::make('email')->label(__('admin.fields.email'))->email()->required()->maxLength(255)->unique(ignoreRecord: true),
                TextInput::make('password')->label(__('admin.fields.password'))->password()->revealable()
                    ->minLength(10)->maxLength(100)
                    ->required(fn (?Admin $record) => $record === null)
                    ->dehydrated(fn ($state) => filled($state))
                    ->helperText(fn (?Admin $record) => $record ? __('admin.help.password_keep') : null),
                Toggle::make('is_active')->label(__('admin.fields.is_active'))->default(true)->disabled($isSelf),
                Select::make('roles')->label(__('admin.resources.role.plural'))->multiple()->preload()
                    ->relationship('roles', 'name', fn (Builder $query) => $query->where('guard_name', 'admin')
                        ->when(! auth('admin_web')->user()?->isSuperAdmin(), fn ($query) => $query->where('name', '!=', Admin::SUPER_ADMIN_ROLE)))
                    ->disabled($isSelf)
                    ->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('admin.fields.name'))->searchable(),
                TextColumn::make('email')->label(__('admin.fields.email'))->searchable(),
                TextColumn::make('roles.name')->label(__('admin.resources.role.plural'))->badge(),
                IconColumn::make('is_active')->label(__('admin.fields.is_active'))->boolean(),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime()->sortable(),
            ])
            ->filters([TrashedFilter::make()])
            ->recordActions([EditAction::make(), DeleteAction::make(), RestoreAction::make()]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdmins::route('/'),
            'create' => CreateAdmin::route('/create'),
            'edit' => EditAdmin::route('/{record}/edit'),
        ];
    }
}
