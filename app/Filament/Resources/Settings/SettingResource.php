<?php

namespace App\Filament\Resources\Settings;

use App\Filament\Pages\PlatformSettingsPage;
use App\Filament\Resources\Settings\Pages\ListSettings;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Readable;
use App\Filament\Support\TableDefaults;
use App\Models\Setting;
use Filament\Support\Enums\FontWeight;
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

    /**
     * Advanced settings (table UX overhaul 2026-11-12): the readable setting
     * name with its technical key underneath, the value (money settings
     * marked), last change.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')->label(__('admin.fields.key'))->searchable()->sortable()->weight(FontWeight::SemiBold)
                    ->formatStateUsing(fn ($state) => Readable::settingKey($state))
                    ->description(fn ($record) => Readable::settingKey($record->key) !== $record->key ? $record->key : null)->wrap(),
                TextColumn::make('value')->label(__('admin.fields.value'))
                    ->formatStateUsing(fn ($state) => is_scalar($state) ? (string) $state : json_encode($state, JSON_UNESCAPED_UNICODE))
                    ->description(fn ($record) => $record->is_monetary ? __('admin.table.money_setting') : null)
                    ->limit(60)->extraAttributes(['class' => 'wf-ltr']),
                TextColumn::make('updated_at')->label(__('admin.fields.updated_at'))->since()->sortable()->color('gray')
                    ->tooltip(fn ($record) => $record->updated_at?->translatedFormat(TableDefaults::dateTimeFormat()))->visibleFrom('md'),
            ])
            ->searchPlaceholder(__('admin.table.search_settings'))
            ->defaultSort('key');
    }

    public static function getPages(): array
    {
        return ['index' => ListSettings::route('/')];
    }
}
