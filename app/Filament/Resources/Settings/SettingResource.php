<?php

namespace App\Filament\Resources\Settings;

use App\Filament\Pages\PlatformSettingsPage;
use App\Filament\Resources\Settings\Pages\ListSettings;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Readable;
use App\Models\Setting;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * WAFFRLI (2026-10-31). Read-only view of every stored setting row
 * (known settings are edited on the Platform Settings page with typed
 * validation).
 */
class SettingResource extends AdminResource
{
    protected static ?string $model = Setting::class;

    protected static ?string $navParent = PlatformSettingsPage::class;

    protected static string $labelKey = 'setting';

    protected static string|\UnitEnum|null $navigationGroup = 'platform';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?int $navigationSort = 40;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')->label(__('admin.fields.key'))->searchable()->sortable()
                    ->formatStateUsing(fn ($state) => Readable::settingKey($state))
                    ->description(fn ($record) => Readable::settingKey($record->key) !== $record->key ? $record->key : null)->wrap(),
                TextColumn::make('value')->label(__('admin.fields.value'))
                    ->formatStateUsing(fn ($state) => is_scalar($state) ? (string) $state : json_encode($state, JSON_UNESCAPED_UNICODE))
                    ->limit(60),
                IconColumn::make('is_monetary')->label(__('admin.fields.is_monetary'))->boolean(),
                TextColumn::make('updated_at')->label(__('admin.fields.updated_at'))->dateTime()->sortable(),
            ])
            ->defaultSort('key');
    }

    public static function getPages(): array
    {
        return ['index' => ListSettings::route('/')];
    }
}
