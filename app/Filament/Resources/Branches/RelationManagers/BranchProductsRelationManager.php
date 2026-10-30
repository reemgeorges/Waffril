<?php

namespace App\Filament\Resources\Branches\RelationManagers;

use App\Filament\Support\BusinessAction;
use App\Filament\Support\Fields;
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
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;

/**
 * Per-branch product prices (default currency) and availability. Only
 * products of the branch's own store can be listed (also enforced by the
 * database's composite foreign keys).
 */
class BranchProductsRelationManager extends RelationManager
{
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

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.name')->label(__('admin.fields.product'))->searchable(),
                TextColumn::make('price')->label(__('admin.fields.price'))->sortable(),
                IconColumn::make('is_available')->label(__('admin.fields.is_available'))->boolean(),
            ])
            ->filters([TernaryFilter::make('is_available')->label(__('admin.fields.is_available'))])
            ->headerActions([
                CreateAction::make()->after(fn (BranchProduct $record) => AdminAudit::log('catalog.branch_price_created', $record, ['price' => (string) $record->price])),
            ])
            ->recordActions([
                Action::make('toggleAvailability')
                    ->label(fn (BranchProduct $record) => $record->is_available ? __('admin.actions.mark_unavailable') : __('admin.actions.mark_available'))
                    ->icon('heroicon-o-arrow-path')->requiresConfirmation()
                    ->action(fn (BranchProduct $record) => BusinessAction::run(
                        fn ($admin) => app(CatalogAdminService::class)->setBranchProductAvailability($record, ! $record->is_available, $admin)
                    )),
                EditAction::make()->after(fn (BranchProduct $record) => AdminAudit::log('catalog.branch_price_updated', $record, ['price' => (string) $record->price])),
                DeleteAction::make(),
            ]);
    }
}
