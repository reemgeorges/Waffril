<?php

namespace App\Filament\Resources\PlusCycles\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Snapshotted store discounts of this cycle (immutable, read-only). */
class DiscountsRelationManager extends RelationManager
{
    protected static string $relationship = 'discounts';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.plus.store_discounts');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('store.name')->label(__('admin.fields.store')),
            TextColumn::make('source')->label(__('admin.fields.source'))->badge()->formatStateUsing(fn ($state) => __("admin.plus.discount_source_{$state}")),
            TextColumn::make('snapshot_discount_value')->label(__('admin.fields.discount_value'))
                ->formatStateUsing(fn ($state, $record) => $record->discount_type === 'percent' ? $state.'%' : $state.' '.$record->cycle?->snapshotCurrency?->code),
            TextColumn::make('snapshot_max_discount_amount')->label(__('admin.fields.max_discount_amount'))->placeholder('—'),
            TextColumn::make('snapshot_min_order_amount')->label(__('admin.fields.min_order_amount'))->placeholder('—'),
            TextColumn::make('max_uses_per_cycle')->label(__('admin.fields.max_uses_per_cycle'))->placeholder('—'),
            TextColumn::make('op_redenomination_id')->label(__('admin.plus.converted_by_run'))->placeholder('—'),
        ]);
    }
}
