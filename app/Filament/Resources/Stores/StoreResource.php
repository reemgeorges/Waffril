<?php

namespace App\Filament\Resources\Stores;

use App\Filament\Resources\Stores\Pages\CreateStore;
use App\Filament\Resources\Stores\Pages\EditStore;
use App\Filament\Resources\Stores\Pages\ListStores;
use App\Filament\Resources\Stores\RelationManagers\BranchesRelationManager;
use App\Filament\Resources\Stores\RelationManagers\ProductsRelationManager;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Fields;
use App\Models\Store;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * WAFFRLI (2026-10-31). Stores (one logo each; no merchant login). A
 * store owns its products; branches share them with their own prices.
 */
class StoreResource extends AdminResource
{
    protected static ?string $model = Store::class;

    protected static string $labelKey = 'store';

    protected static string|\UnitEnum|null $navigationGroup = 'catalog';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                Fields::translatable('name'),
                Grid::make(2)->schema([
                    TextInput::make('slug')->label(__('admin.fields.slug'))->required()->maxLength(120)->alphaDash()->unique(ignoreRecord: true),
                    Select::make('categories')->label(__('admin.resources.store_category.plural'))
                        ->relationship('categories', 'slug')
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->name)
                        ->multiple()->preload()->required(),
                ]),
                Fields::translatable('description', required: false, multiline: true),
                FileUpload::make('logo')->label(__('admin.fields.logo'))->image()->disk('public')->directory('stores')->maxSize(2048),
                Grid::make(2)->schema([Fields::phone('contact_phone'), Fields::phone('contact_whatsapp')]),
                Grid::make(2)->schema([
                    TextInput::make('sort_order')->label(__('admin.fields.sort_order'))->integer()->minValue(0)->default(0),
                    Toggle::make('is_active')->label(__('admin.fields.is_active'))->default(true),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('logo')->label(__('admin.fields.logo'))->disk('public')->circular(),
                TextColumn::make('name')->label(__('admin.fields.name'))->searchable(),
                TextColumn::make('categories.name')->label(__('admin.resources.store_category.plural'))->badge(),
                TextColumn::make('branches_count')->counts('branches')->label(__('admin.resources.branch.plural')),
                TextColumn::make('products_count')->counts('products')->label(__('admin.resources.product.plural')),
                ToggleColumn::make('is_active')->label(__('admin.fields.is_active'))
                    ->disabled(fn ($record) => ! static::can('update', $record)),
            ])
            ->filters([
                SelectFilter::make('categories')->label(__('admin.resources.store_category.plural'))
                    ->relationship('categories', 'slug')->getOptionLabelFromRecordUsing(fn ($record) => $record->name),
                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make(), RestoreAction::make()]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getRelations(): array
    {
        return [BranchesRelationManager::class, ProductsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStores::route('/'),
            'create' => CreateStore::route('/create'),
            'edit' => EditStore::route('/{record}/edit'),
        ];
    }
}
