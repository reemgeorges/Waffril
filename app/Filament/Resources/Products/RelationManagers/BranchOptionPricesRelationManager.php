<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Filament\Support\BusinessAction;
use App\Filament\Support\Fields;
use App\Models\BranchProduct;
use App\Models\BranchProductOption;
use App\Models\ProductOption;
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
use Filament\Tables\Table;
use Illuminate\Validation\Rule;

/**
 * Per-branch price delta (>= 0) and availability of each option. An
 * option with no row here is not offered in that branch. The database
 * rejects an option of another product (composite foreign keys).
 */
class BranchOptionPricesRelationManager extends RelationManager
{
    protected static string $relationship = 'branchOptionPrices';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.products.branch_option_prices');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('branch_product_id')->label(__('admin.fields.branch'))->required()
                ->options(fn () => BranchProduct::with('branch')->where('product_id', $this->getOwnerRecord()->id)->get()
                    ->mapWithKeys(fn ($bp) => [$bp->id => $bp->branch?->name]))
                ->disabled(fn (?BranchProductOption $record) => $record !== null),
            Select::make('product_option_id')->label(__('admin.fields.option'))->required()
                ->options(fn () => ProductOption::with('group')->where('product_id', $this->getOwnerRecord()->id)->get()
                    ->mapWithKeys(fn ($o) => [$o->id => ($o->group?->name ? $o->group->name.': ' : '').$o->name]))
                ->rule(fn (?BranchProductOption $record, $get) => Rule::unique('branch_product_options', 'product_option_id')
                    ->where('branch_product_id', $get('branch_product_id') ?? $record?->branch_product_id)->ignore($record?->id))
                ->disabled(fn (?BranchProductOption $record) => $record !== null),
            Fields::money('price_delta')->default(0)->helperText(__('admin.help.price_delta')),
            Toggle::make('is_available')->label(__('admin.fields.is_available'))->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['branchProduct.branch', 'option.group']))
            ->columns([
                TextColumn::make('branchProduct.branch.name')->label(__('admin.fields.branch')),
                TextColumn::make('option.group.name')->label(__('admin.fields.option_group')),
                TextColumn::make('option.name')->label(__('admin.fields.option')),
                TextColumn::make('price_delta')->label(__('admin.fields.price_delta')),
                IconColumn::make('is_available')->label(__('admin.fields.is_available'))->boolean(),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([
                Action::make('toggleAvailability')
                    ->label(fn (BranchProductOption $record) => $record->is_available ? __('admin.actions.mark_unavailable') : __('admin.actions.mark_available'))
                    ->icon('heroicon-o-arrow-path')->requiresConfirmation()
                    ->action(fn (BranchProductOption $record) => BusinessAction::run(
                        fn ($admin) => app(CatalogAdminService::class)->setBranchOptionAvailability($record, ! $record->is_available, $admin)
                    )),
                EditAction::make(), DeleteAction::make(),
            ]);
    }
}
