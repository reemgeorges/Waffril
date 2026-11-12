<?php

namespace App\Filament\Resources\Stores\RelationManagers;

use App\Filament\Concerns\TranslatedRelationLabels;
use App\Filament\Resources\Branches\BranchResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
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

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('city')->withCount('branchProducts'))
            ->columns([
                TextColumn::make('name')->label(__('admin.fields.name'))->searchable()->weight(FontWeight::SemiBold)
                    ->description(fn ($record) => $record->city?->name),
                TextColumn::make('branch_products_count')->label(__('admin.stores.priced_products'))
                    ->tooltip(__('admin.stores.priced_products_help')),
                TextColumn::make('min_order_amount')->label(__('admin.fields.min_order_amount'))->placeholder('—'),
                ToggleColumn::make('is_accepting_orders')->label(__('admin.fields.is_accepting_orders'))
                    ->disabled(fn ($record) => ! BranchResource::can('update', $record)),
                ToggleColumn::make('is_active')->label(__('admin.fields.is_active'))
                    ->disabled(fn ($record) => ! BranchResource::can('update', $record)),
            ])
            ->filters([
                SelectFilter::make('city_id')->label(__('admin.fields.city'))->relationship('city', 'id')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name),
            ])
            ->headerActions([
                CreateAction::make()->label(__('admin.stores.add_branch_short'))->modalWidth(Width::FiveExtraLarge)
                    ->visible(fn () => BranchResource::canCreate())
                    // Next step right away: choose its products and prices.
                    ->successRedirectUrl(fn ($record) => BranchResource::getUrl('edit', ['record' => $record]).'?relation=products'),
            ])
            ->recordActions([
                Action::make('manage')->label(__('admin.stores.manage_branch'))->icon('heroicon-m-adjustments-horizontal')
                    ->url(fn ($record) => BranchResource::getUrl('edit', ['record' => $record]).'?relation=products'),
            ])
            ->recordUrl(fn ($record) => BranchResource::getUrl('edit', ['record' => $record]))
            ->emptyStateHeading(__('admin.stores.no_branches'))
            ->emptyStateDescription(__('admin.stores.no_branches_help'))
            ->emptyStateIcon('heroicon-o-building-office-2');
    }
}
