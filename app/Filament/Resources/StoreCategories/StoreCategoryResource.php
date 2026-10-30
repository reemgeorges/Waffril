<?php

namespace App\Filament\Resources\StoreCategories;

use App\Filament\Resources\StoreCategories\Pages\ManageStoreCategories;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Fields;
use App\Models\StoreCategory;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * WAFFRLI (2026-10-31). Store categories (restaurants, supermarkets, ...).
 * Delete = soft delete (restorable); force delete is never offered.
 */
class StoreCategoryResource extends AdminResource
{
    protected static ?string $model = StoreCategory::class;

    protected static string $labelKey = 'store_category';

    protected static string|\UnitEnum|null $navigationGroup = 'catalog';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Fields::translatable('name'),
            TextInput::make('slug')->label(__('admin.fields.slug'))->required()->maxLength(120)->alphaDash()->unique(ignoreRecord: true),
            FileUpload::make('image')->label(__('admin.fields.image'))->image()->disk('public')->directory('store-categories')->maxSize(2048),
            TextInput::make('sort_order')->label(__('admin.fields.sort_order'))->integer()->minValue(0)->default(0),
            Toggle::make('is_active')->label(__('admin.fields.is_active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image')->label(__('admin.fields.image'))->disk('public'),
                TextColumn::make('name')->label(__('admin.fields.name'))->searchable(),
                TextColumn::make('slug')->label(__('admin.fields.slug'))->searchable(),
                TextColumn::make('stores_count')->counts('stores')->label(__('admin.resources.store.plural')),
                ToggleColumn::make('is_active')->label(__('admin.fields.is_active'))
                    ->disabled(fn ($record) => ! static::can('update', $record)),
                TextColumn::make('sort_order')->label(__('admin.fields.sort_order'))->sortable(),
            ])
            ->filters([TrashedFilter::make()])
            ->recordActions([EditAction::make()->mutateRecordDataUsing(Fields::withTranslations()), DeleteAction::make(), RestoreAction::make()])
            ->defaultSort('sort_order');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageStoreCategories::route('/')];
    }
}
