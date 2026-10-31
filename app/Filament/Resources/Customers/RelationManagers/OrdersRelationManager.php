<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\Fields;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Read-only order history of a customer or driver. */
class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.resources.order.plural');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order_number')->label(__('admin.fields.order_number'))->searchable(),
                TextColumn::make('branch.name')->label(__('admin.fields.branch')),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()
                    ->formatStateUsing(fn ($state) => $state->label())->color(fn ($state) => $state->color()),
                TextColumn::make('total')->label(__('admin.fields.total'))
                    ->formatStateUsing(fn ($state, $record) => Fields::formatMoney($state, $record->currency)),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime()->sortable(),
            ])
            ->recordActions([
                Action::make('open')->label(__('admin.actions.open'))->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn ($record) => OrderResource::getUrl('view', ['record' => $record])),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
