<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Filament\Concerns\TranslatedRelationLabels;
use App\Filament\Support\BusinessAction;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\RowActions;
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
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;

/**
 * Per-branch price delta (>= 0) and availability of each option. An
 * option with no row here is not offered in that branch. The database
 * rejects an option of another product (composite foreign keys).
 */
class BranchOptionPricesRelationManager extends RelationManager
{
    use TranslatedRelationLabels;

    protected static string $relationLabelKey = 'branch_option_price';

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

    /**
     * Product → Option prices per branch (table UX overhaul 2026-11-12):
     * the option with its group, the branch, the extra price and
     * availability. Edit is the primary action.
     */
    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['branchProduct.branch', 'option.group']))
            ->columns([
                TextColumn::make('option.name')->label(__('admin.fields.option'))->weight(FontWeight::SemiBold)
                    ->description(fn (BranchProductOption $record) => $record->option?->group?->name),
                TextColumn::make('branchProduct.branch.name')->label(__('admin.fields.branch')),
                TextColumn::make('price_delta')->label(__('admin.fields.price_delta'))
                    ->formatStateUsing(fn ($state) => ((float) $state > 0 ? '+' : '').Fields::formatDecimal($state)),
                Columns::status('is_available', __('admin.table.available'), __('admin.table.unavailable'))->label(__('admin.table.availability')),
            ])
            ->filters([
                SelectFilter::make('branch')->label(__('admin.fields.branch'))
                    ->options(fn () => $this->getOwnerRecord()->branchProducts()->with('branch')->get()->mapWithKeys(fn ($bp) => [$bp->branch_id => $bp->branch?->name])->all())
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null) ? $query->whereHas('branchProduct', fn ($q) => $q->where('branch_id', $data['value'])) : $query),
                TernaryFilter::make('is_available')->label(__('admin.table.availability'))
                    ->trueLabel(__('admin.table.available'))->falseLabel(__('admin.table.unavailable')),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([
                RowActions::primary(EditAction::make()->label(__('admin.table.edit_price'))),
                RowActions::more([
                    Action::make('toggleAvailability')
                        ->label(fn (BranchProductOption $record) => $record->is_available ? __('admin.table.toggle_unavailable') : __('admin.table.toggle_available'))
                        ->icon(fn (BranchProductOption $record) => $record->is_available ? 'heroicon-m-no-symbol' : 'heroicon-m-check-circle')
                        ->color(fn (BranchProductOption $record) => $record->is_available ? 'warning' : 'success')
                        ->requiresConfirmation()
                        ->action(fn (BranchProductOption $record) => BusinessAction::run(
                            fn ($admin) => app(CatalogAdminService::class)->setBranchOptionAvailability($record, ! $record->is_available, $admin)
                        )),
                    DeleteAction::make(),
                ]),
            ]);
    }
}
