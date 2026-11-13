<?php

namespace App\Filament\Resources\Stores\RelationManagers;

use App\Filament\Concerns\TranslatedRelationLabels;
use App\Filament\Resources\Branches\BranchResource;
use App\Filament\Support\BusinessAction;
use App\Filament\Support\Fields;
use App\Filament\Support\RowActions;
use App\Models\Branch;
use App\Services\Catalog\CatalogAdminService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Store → Branches (UX overhaul 2026-11-12). The store's physical branches
 * are managed from the store itself: add a branch in a dialog (the store is
 * set by the relationship), pause or resume orders inline, and open a branch
 * for its prices, availability, options and hours. Uses the branch
 * resource's own form, policy and validation; the global branch list stays.
 */
class BranchesRelationManager extends RelationManager
{
    use TranslatedRelationLabels;

    protected static string $relationLabelKey = 'branch';

    protected static string $relationship = 'branches';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-building-office-2';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.resources.branch.plural');
    }

    public static function getBadge($ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->branches()->count();
    }

    public function form(Schema $schema): Schema
    {
        return BranchResource::form($schema);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    /**
     * Store → Branches (table UX overhaul, 2026-11-12): a store has a few
     * branches, so each one is a card: name and city, whether it takes
     * orders, its priced products (a link to them) and minimum order.
     * "Prices & availability" is the primary action; details, opening hours,
     * pause / resume orders and activation sit under "More actions".
     */
    public function table(Table $table): Table
    {
        $edit = fn (Branch $record, ?string $tab = null) => BranchResource::getUrl('edit', ['record' => $record]).($tab ? '?relation='.$tab : '');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('city')->withCount('branchProducts'))
            ->columns([
                Stack::make([
                    Split::make([
                        Stack::make([
                            TextColumn::make('name')->label(__('admin.fields.name'))->searchable()
                                ->weight(FontWeight::SemiBold)->size(TextSize::Large)
                                ->url(fn (Branch $record) => $edit($record))->extraAttributes(['class' => 'wf-card-title']),
                            TextColumn::make('city.name')->label(__('admin.fields.city'))->icon('heroicon-m-map-pin')
                                ->color('gray')->size(TextSize::Small)->placeholder(__('admin.table.no_city')),
                        ])->space(1),
                        TextColumn::make('orders_state')->label(__('admin.fields.is_accepting_orders'))->badge()->grow(false)
                            ->state(fn (Branch $record) => ! $record->is_active ? 'inactive' : ($record->is_accepting_orders ? 'accepting' : 'paused'))
                            ->formatStateUsing(fn (string $state) => __('admin.table.'.$state))
                            ->icon(fn (string $state) => match ($state) {
                                'accepting' => 'heroicon-m-check-circle',
                                'paused' => 'heroicon-m-pause-circle',
                                default => 'heroicon-m-no-symbol',
                            })
                            ->color(fn (string $state) => match ($state) {
                                'accepting' => 'success',
                                'paused' => 'warning',
                                default => 'gray',
                            }),
                    ]),
                    Split::make([
                        TextColumn::make('branch_products_count')->label(__('admin.stores.priced_products'))
                            ->formatStateUsing(fn ($state) => trans_choice('admin.table.priced_products_count', (int) $state, ['count' => (int) $state]))
                            ->icon('heroicon-m-cube')->badge()->color(fn ($state) => (int) $state > 0 ? 'primary' : 'warning')->grow(false)
                            ->url(fn (Branch $record) => $edit($record, 'products'))->tooltip(__('admin.stores.priced_products_help'))
                            ->extraAttributes(['class' => 'wf-stat-link']),
                        TextColumn::make('min_order_badge')->label(__('admin.fields.min_order_amount'))->grow(false)
                            ->state(fn (Branch $record) => (float) $record->min_order_amount > 0 ? __('admin.table.min_order', ['amount' => Fields::formatDecimal($record->min_order_amount)]) : null)
                            ->badge()->color('gray')->icon('heroicon-m-receipt-percent')->placeholder(null),
                    ])->extraAttributes(['class' => 'wf-card-stats']),
                ])->space(3),
            ])
            ->contentGrid(['md' => 2, '2xl' => 3])
            ->recordUrl(null)
            ->defaultSort('name')
            ->searchPlaceholder(__('admin.table.search_branches'))
            ->filters([
                SelectFilter::make('city_id')->label(__('admin.fields.city'))->relationship('city', 'id')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name),
                TernaryFilter::make('is_accepting_orders')->label(__('admin.fields.is_accepting_orders'))
                    ->trueLabel(__('admin.table.accepting'))->falseLabel(__('admin.table.paused')),
            ])
            ->headerActions([
                CreateAction::make()->label(__('admin.stores.add_branch_short'))->modalWidth(Width::FiveExtraLarge)
                    ->visible(fn () => BranchResource::canCreate())
                    // Next step right away: choose its products and prices.
                    ->successRedirectUrl(fn ($record) => BranchResource::getUrl('edit', ['record' => $record]).'?relation=products'),
            ])
            ->recordActions([
                RowActions::primary(Action::make('manage')->label(__('admin.table.prices_and_availability'))->icon('heroicon-m-currency-dollar')
                    ->url(fn (Branch $record) => $edit($record, 'products'))),
                RowActions::more([
                    Action::make('details')->label(__('admin.table.edit_details'))->icon('heroicon-m-pencil-square')
                        ->url(fn (Branch $record) => $edit($record)),
                    Action::make('hours')->label(__('admin.table.opening_hours'))->icon('heroicon-m-clock')
                        ->url(fn (Branch $record) => $edit($record, 'hours')),
                    ...RowActions::switch(BranchResource::class, 'is_accepting_orders',
                        on: ['resume_orders', 'admin.table.resume_orders', 'admin.table.resume_confirm', 'heroicon-m-play-circle'],
                        off: ['pause_orders', 'admin.table.pause_orders', 'admin.table.pause_confirm', 'heroicon-m-pause-circle'],
                        // Same audited service as the branch page.
                        using: fn (Branch $record, bool $accepting) => BusinessAction::run(
                            fn ($admin) => app(CatalogAdminService::class)->setBranchAcceptingOrders($record, $accepting, $admin))),
                    ...RowActions::activation(BranchResource::class),
                ]),
            ])
            ->emptyStateHeading(__('admin.stores.no_branches'))
            ->emptyStateDescription(__('admin.stores.no_branches_help'))
            ->emptyStateIcon('heroicon-o-building-office-2');
    }
}
