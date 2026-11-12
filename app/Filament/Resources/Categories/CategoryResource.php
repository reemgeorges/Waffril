<?php

namespace App\Filament\Resources\Categories;

use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Fields;
use App\Filament\Support\SafeCategoryDelete;
use App\Models\Category;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
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

    /**
     * UX overhaul (2026-11-12): not in the sidebar. Managed from its owning
     * workflow (Stores → store → Branches / Products / categories / ratings);
     * the pages and routes stay, protected by the same policy.
     */
    protected static bool $shouldRegisterNavigation = false;

    protected static string $labelKey = 'category';

    protected static string|\UnitEnum|null $navigationGroup = 'catalog';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static ?int $navigationSort = 60;

    public static function form(Schema $schema): Schema
    {
        return $schema->components(self::formFields());
    }

    /**
     * Product categories are platform-wide (shared by every store). Also used
     * to create a category straight from the product form (UX redesign
     * 2026-11-11), so both places validate the same way.
     *
     * @return list<Component>
     */
    public static function formFields(): array
    {
        return [
            Fields::translatable('name', fillsSlug: true)->columnSpanFull(),
            Select::make('parent_id')->label(__('admin.fields.parent'))
                ->options(fn (?Category $record) => Category::query()->when($record, fn ($query) => $query->whereKeyNot($record->id))
                    ->get()->mapWithKeys(fn ($c) => [$c->id => $c->name]))
                ->searchable()->placeholder(__('admin.categories.no_parent'))
                ->helperText(__('admin.help.category_parent'))->columnSpanFull(),
            Fields::translatable('description', required: false, multiline: true)->columnSpanFull(),
            Grid::make(2)->columnSpanFull()->schema([
                FileUpload::make('image')->label(__('admin.fields.image'))->image()->disk('public')->directory('categories')->maxSize(2048)
                    ->helperText(__('admin.help.category_image')),
                Grid::make(1)->schema([
                    Toggle::make('is_active')->label(__('admin.fields.is_active'))->default(true),
                    TextInput::make('sort_order')->label(__('admin.fields.sort_order'))->integer()->minValue(0)->default(0)
                        ->helperText(__('admin.help.sort_order')),
                ]),
            ]),
            Fields::moreSettings([Fields::slug('categories')])->columnSpanFull(),
        ];
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
            ->recordActions([EditAction::make()->mutateRecordDataUsing(Fields::withTranslations()), SafeCategoryDelete::make('product'), RestoreAction::make()])
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
