<?php

namespace App\Filament\Resources\PlusCycles\RelationManagers;

use App\Filament\Concerns\TranslatedRelationLabels;
use App\Filament\Support\Fields;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Snapshotted store discounts of this cycle (immutable, read-only). */
class DiscountsRelationManager extends RelationManager
{
    use TranslatedRelationLabels;

    protected static string $relationLabelKey = 'cycle_discount';

    protected static string $relationship = 'discounts';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.plus.store_discounts');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    /**
     * Cycle → Discounts (table UX overhaul 2026-11-12): the store, where the
     * discount came from, the snapshot discount with its limits in words.
     * Read only (snapshot taken when the cycle started).
     */
    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['store', 'cycle.snapshotCurrency']))
            ->columns([
                TextColumn::make('store.name')->label(__('admin.fields.store'))->weight(FontWeight::SemiBold)
                    ->description(fn ($record) => $record->op_redenomination_id ? __('admin.table.converted_run', ['id' => $record->op_redenomination_id]) : null),
                TextColumn::make('source')->label(__('admin.fields.source'))->badge()->color('gray')->formatStateUsing(fn ($state) => __("admin.plus.discount_source_{$state}")),
                TextColumn::make('snapshot_discount_value')->label(__('admin.fields.discount_value'))->badge()->color('gold')
                    ->formatStateUsing(fn ($state, $record) => '−'.($record->discount_type === 'percent' ? Fields::formatDecimal($state).'%' : Fields::formatDecimal($state).' '.$record->cycle?->snapshotCurrency?->code))
                    ->description(fn ($record) => collect([
                        $record->snapshot_max_discount_amount !== null ? __('admin.table.max_discount', ['amount' => Fields::formatDecimal($record->snapshot_max_discount_amount)]) : null,
                        $record->snapshot_min_order_amount !== null ? __('admin.table.min_order_short', ['amount' => Fields::formatDecimal($record->snapshot_min_order_amount)]) : null,
                        $record->max_uses_per_cycle ? trans_choice('admin.table.uses_per_cycle', (int) $record->max_uses_per_cycle, ['count' => (int) $record->max_uses_per_cycle]) : null,
                    ])->filter()->implode(' · ') ?: null),
            ]);
    }
}
