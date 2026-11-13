<?php

namespace App\Filament\Resources\Stores\RelationManagers;

use App\Filament\Concerns\TranslatedRelationLabels;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\Columns;
use App\Filament\Support\RowActions;
use App\Filament\Support\TableDefaults;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Store → Products (UX overhaul 2026-11-12). Products belong to this store
 * (two stores selling "the same" item keep their own picture, text and
 * prices). Add a product in a dialog without leaving the store; open it for
 * branch prices, options and ratings. Uses the product resource's form,
 * policy and validation; the global product list stays available.
 */
class ProductsRelationManager extends RelationManager
{
    use TranslatedRelationLabels;

    protected static string $relationLabelKey = 'product';

    protected static string $relationship = 'products';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-cube';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.resources.product.plural');
    }

    public static function getBadge($ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->products()->count();
    }

    public function form(Schema $schema): Schema
    {
        return ProductResource::form($schema);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    /**
     * Store → Products (table UX overhaul, 2026-11-12): picture (or initial),
     * name with its categories, how many of the store's branches price it,
     * rating and a readable status. "Prices & availability" is the primary
     * action; details, ratings and activation sit under "More actions".
     */
    public function table(Table $table): Table
    {
        $branches = fn () => $this->getOwnerRecord()->branches()->count();
        $edit = fn (Product $record, ?string $tab = null) => ProductResource::getUrl('edit', ['record' => $record]).($tab ? '?relation='.$tab : '');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('categories')->withCount('branchProducts')
                ->withoutGlobalScopes([SoftDeletingScope::class]))
            ->columns([
                Columns::picture('image', fn (Product $record) => $record->name),
                TextColumn::make('name')->label(__('admin.fields.name'))->searchable()->sortable()->weight(FontWeight::SemiBold)
                    ->description(fn (Product $record) => $record->categories->map->name->implode(' · ') ?: null),
                TextColumn::make('branch_products_count')->label(__('admin.stores.sold_in_branches'))->badge()
                    ->formatStateUsing(fn ($state) => (int) $state > 0
                        ? __('admin.table.priced_in', ['count' => (int) $state, 'total' => $branches()])
                        : __('admin.table.not_priced'))
                    ->icon(fn ($state) => (int) $state > 0 ? 'heroicon-m-check-badge' : 'heroicon-m-exclamation-triangle')
                    ->color(fn ($state) => (int) $state > 0 ? 'success' : 'warning')
                    ->url(fn (Product $record) => $edit($record, 'branch-prices'))
                    ->tooltip(__('admin.stores.sold_in_branches_help'))
                    ->extraAttributes(['class' => 'wf-stat-link'])->width(TableDefaults::COMPACT_WIDTH),
                Columns::stars('rating', fn (Product $record) => [$record->averageRating(), (int) $record->rating_count])
                    ->url(fn (Product $record) => $edit($record, 'ratings'))->visibleFrom('md'),
                Columns::status(),
            ])
            ->defaultSort('name')
            ->searchPlaceholder(__('admin.table.search_products'))
            ->filters([
                SelectFilter::make('categories')->label(__('admin.resources.category.plural'))->relationship('categories', 'slug')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name)->multiple()->preload(),
                TernaryFilter::make('priced')->label(__('admin.table.priced_filter'))
                    ->trueLabel(__('admin.table.priced'))->falseLabel(__('admin.table.not_priced'))
                    ->queries(
                        true: fn (Builder $query) => $query->has('branchProducts'),
                        false: fn (Builder $query) => $query->doesntHave('branchProducts'),
                    ),
                TernaryFilter::make('is_active')->label(__('admin.fields.status'))
                    ->trueLabel(__('admin.table.active'))->falseLabel(__('admin.table.inactive')),
                TrashedFilter::make(),
            ])
            ->headerActions([
                CreateAction::make()->label(__('admin.stores.add_product_short'))->modalWidth(Width::FiveExtraLarge)
                    ->visible(fn () => ProductResource::canCreate())
                    // Next step right away: price it in each branch.
                    ->successRedirectUrl(fn ($record) => ProductResource::getUrl('edit', ['record' => $record]).'?relation=branch-prices'),
            ])
            ->recordActions([
                RowActions::primary(Action::make('manage')->label(__('admin.table.prices_and_availability'))->icon('heroicon-m-currency-dollar')
                    ->url(fn (Product $record) => $edit($record, 'branch-prices'))),
                RowActions::more([
                    Action::make('details')->label(__('admin.table.edit_details'))->icon('heroicon-m-pencil-square')
                        ->url(fn (Product $record) => $edit($record)),
                    Action::make('ratings')->label(__('admin.table.ratings'))->icon('heroicon-m-star')
                        ->url(fn (Product $record) => $edit($record, 'ratings')),
                    ...RowActions::activation(ProductResource::class),
                ]),
            ])
            ->recordUrl(fn ($record) => ProductResource::getUrl('edit', ['record' => $record]))
            ->emptyStateHeading(__('admin.stores.no_products'))
            ->emptyStateDescription(__('admin.stores.no_products_help'))
            ->emptyStateIcon('heroicon-o-cube');
    }
}
