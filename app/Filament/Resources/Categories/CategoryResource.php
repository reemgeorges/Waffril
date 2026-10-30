<?php

namespace App\Filament\Resources\Categories;

use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Fields;
use App\Models\Category;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * WAFFRLI (2026-10-31). Global product-category tree (a product may
 * belong to several categories).
 */
class CategoryResource extends AdminResource
{
    protected static ?string $model = Category::class;

    protected static string $labelKey = 'category';

    protected static string|\UnitEnum|null $navigationGroup = 'catalog';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Fields::translatable('name'),
            TextInput::make('slug')->label(__('admin.fields.slug'))->required()->maxLength(120)->alphaDash()->unique(ignoreRecord: true),
            Select::make('parent_id')->label(__('admin.fields.parent'))
                ->options(fn (?Category $record) => Category::query()->when($record, fn ($query) => $query->whereKeyNot($record->id))
                    ->get()->mapWithKeys(fn ($c) => [$c->id => $c->name]))
                ->searchable(),
            Fields::translatable('description', required: false, multiline: true),
            FileUpload::make('image')->label(__('admin.fields.image'))->image()->disk('public')->directory('categories')->maxSize(2048),
            TextInput::make('sort_order')->label(__('admin.fields.sort_order'))->integer()->minValue(0)->default(0),
            Toggle::make('is_active')->label(__('admin.fields.is_active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('admin.fields.name'))->searchable(),
                TextColumn::make('parent.name')->label(__('admin.fields.parent'))->placeholder('—'),
                TextColumn::make('products_count')->counts('products')->label(__('admin.resources.product.plural')),
                ToggleColumn::make('is_active')->label(__('admin.fields.is_active'))
                    ->disabled(fn ($record) => ! static::can('update', $record)),
                TextColumn::make('sort_order')->label(__('admin.fields.sort_order'))->sortable(),
            ])
            ->filters([
                SelectFilter::make('parent_id')->label(__('admin.fields.parent'))
                    ->options(fn () => Category::all()->mapWithKeys(fn ($c) => [$c->id => $c->name])),
                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make()->mutateRecordDataUsing(Fields::withTranslations()), DeleteAction::make(), RestoreAction::make()])
            ->defaultSort('sort_order');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCategories::route('/')];
    }
}
