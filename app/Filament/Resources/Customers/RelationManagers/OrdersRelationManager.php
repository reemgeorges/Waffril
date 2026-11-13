<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Enums\OrderStatus;
use App\Filament\Concerns\TranslatedRelationLabels;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\Fields;
use App\Filament\Support\RowActions;
use App\Filament\Support\TableDefaults;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Read-only order history of a customer or driver. */
class OrdersRelationManager extends RelationManager
{
    use TranslatedRelationLabels;

    protected static string $relationLabelKey = 'order';

    protected static string $relationship = 'orders';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.resources.order.plural');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    /**
     * Customer → Orders (table UX overhaul 2026-11-12): order number and
     * age, status, branch and store, total. "View order" opens it.
     */
    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['branch.store', 'currency']))
            ->columns([
                TextColumn::make('order_number')->label(__('admin.fields.order_number'))->searchable()->weight(FontWeight::SemiBold)
                    ->description(fn ($record) => $record->created_at?->translatedFormat(TableDefaults::dateTimeFormat())),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()
                    ->formatStateUsing(fn ($state) => $state->label())->color(fn ($state) => $state->color()),
                TextColumn::make('branch.name')->label(__('admin.fields.branch'))
                    ->description(fn ($record) => $record->branch?->store?->name)->visibleFrom('md'),
                TextColumn::make('total')->label(__('admin.fields.total'))->alignEnd()->weight(FontWeight::SemiBold)
                    ->formatStateUsing(fn ($state, $record) => Fields::formatMoney($state, $record->currency)),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->options(OrderStatus::options())->multiple(),
            ])
            ->recordActions([
                RowActions::primary(Action::make('open')->label(__('admin.table.view_order'))->icon('heroicon-m-eye')
                    ->url(fn ($record) => OrderResource::getUrl('view', ['record' => $record]))),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
