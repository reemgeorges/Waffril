<?php

namespace App\Filament\Resources\Branches\RelationManagers;

use App\Filament\Concerns\TranslatedRelationLabels;
use App\Filament\Support\BusinessAction;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\RowActions;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Services\Audit\AdminAudit;
use App\Services\Catalog\CatalogAdminService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Per-branch product prices (default currency) and availability. Only
 * products of the branch's own store can be listed (also enforced by the
 * database's composite foreign keys).
 */
class BranchProductsRelationManager extends RelationManager
{
    use TranslatedRelationLabels;

    protected static string $relationLabelKey = 'branch_product';

    protected static string $relationship = 'branchProducts';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.branches.products_and_prices');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('product_id')->label(__('admin.fields.product'))
                ->options(fn () => Product::where('store_id', $this->getOwnerRecord()->store_id)->get()->mapWithKeys(fn ($p) => [$p->id => $p->name]))
                ->required()->searchable()
                ->rule(fn (?BranchProduct $record) => Rule::unique('branch_products', 'product_id')
                    ->where('branch_id', $this->getOwnerRecord()->id)->ignore($record?->id))
                ->disabled(fn (?BranchProduct $record) => $record !== null),
            Fields::money('price')->helperText(__('admin.help.default_currency_amount')),
            Toggle::make('is_available')->label(__('admin.fields.is_available'))->default(true),
        ]);
    }

    /**
     * Branch → Products & prices (table UX overhaul 2026-11-12): picture,
     * product and its categories, the branch price and availability. Edit
     * price is the primary action; availability (audited service) and
     * removal sit under "More actions".
     */
    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('product.categories'))
            ->columns([
                Columns::picture('product.image', fn (BranchProduct $record) => $record->product?->name, 36),
                TextColumn::make('product.name')->label(__('admin.fields.product'))->weight(FontWeight::SemiBold)
                    ->description(fn (BranchProduct $record) => $record->product?->categories->map->name->implode(' · ') ?: null)
                    ->searchable(),
                TextColumn::make('price')->label(__('admin.fields.price'))->sortable()->weight(FontWeight::SemiBold),
                Columns::status('is_available', __('admin.table.available'), __('admin.table.unavailable'))->label(__('admin.table.availability')),
            ])
            ->searchPlaceholder(__('admin.table.search_product'))
            ->filters([TernaryFilter::make('is_available')->label(__('admin.table.availability'))
                ->trueLabel(__('admin.table.available'))->falseLabel(__('admin.table.unavailable'))])
            ->headerActions([
                CreateAction::make()->after(fn (BranchProduct $record) => AdminAudit::log('catalog.branch_price_created', $record, ['price' => (string) $record->price])),
            ])
            ->recordActions([
                RowActions::primary(EditAction::make()->label(__('admin.table.edit_price'))
                    ->after(fn (BranchProduct $record) => AdminAudit::log('catalog.branch_price_updated', $record, ['price' => (string) $record->price]))),
                RowActions::more([
                    Action::make('toggleAvailability')
                        ->label(fn (BranchProduct $record) => $record->is_available ? __('admin.table.toggle_unavailable') : __('admin.table.toggle_available'))
                        ->icon(fn (BranchProduct $record) => $record->is_available ? 'heroicon-m-no-symbol' : 'heroicon-m-check-circle')
                        ->color(fn (BranchProduct $record) => $record->is_available ? 'warning' : 'success')
                        ->requiresConfirmation()
                        ->action(fn (BranchProduct $record) => BusinessAction::run(
                            fn ($admin) => app(CatalogAdminService::class)->setBranchProductAvailability($record, ! $record->is_available, $admin)
                        )),
                    DeleteAction::make(),
                ]),
            ]);
    }
}
