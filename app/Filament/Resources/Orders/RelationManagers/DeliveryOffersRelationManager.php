<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Filament\Concerns\TranslatedRelationLabels;
use App\Filament\Resources\Orders\Support\DispatchPanel;
use App\Models\DeliveryOffer;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * WAFFRLI dispatch B4 (2026-11-10) — every delivery offer of the order,
 * oldest first (read-only audit trail): round and attempt, driver,
 * source, outcome, times, reasons and why the system chose the driver.
 */
class DeliveryOffersRelationManager extends RelationManager
{
    use TranslatedRelationLabels;

    protected static string $relationLabelKey = 'delivery_offer';

    protected static string $relationship = 'deliveryOffers';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.dispatch.history');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return parent::canViewForRecord($ownerRecord, $pageClass) && DispatchPanel::visible($ownerRecord);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('round')->label(__('admin.dispatch.round_attempt'))
                    ->formatStateUsing(fn ($state, DeliveryOffer $record) => $record->round.' / #'.$record->attempt),
                TextColumn::make('driver.name')->label(__('admin.fields.driver'))->placeholder('—'),
                TextColumn::make('source')->label(__('admin.dispatch.source'))->badge()
                    ->formatStateUsing(fn ($state) => __('admin.dispatch.source_'.$state))
                    ->color(fn ($state) => $state === DeliveryOffer::SOURCE_ADMIN ? 'info' : 'gray'),
                TextColumn::make('offeredBy.name')->label(__('admin.dispatch.offered_by'))->placeholder('—')->toggleable(),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()
                    ->formatStateUsing(fn ($state) => $state->label())->color(fn ($state) => DispatchPanel::statusColor($state)),
                TextColumn::make('offered_at')->label(__('admin.dispatch.offered_at'))->dateTime('Y-m-d H:i:s'),
                TextColumn::make('responded_at')->label(__('admin.dispatch.responded_at'))->dateTime('Y-m-d H:i:s')->placeholder('—'),
                TextColumn::make('reason')->label(__('admin.fields.reason'))->placeholder('—')
                    ->state(fn (DeliveryOffer $record) => DispatchPanel::reason($record))->wrap(),
                TextColumn::make('selection_meta')->label(__('admin.dispatch.selection'))->placeholder('—')->wrap()->toggleable()
                    ->state(fn (DeliveryOffer $record) => self::selection($record->selection_meta)),
            ])
            ->defaultSort('id');
    }

    /** "rank 2 · 1 delivery today · 1.2 km (fresh GPS) · recovery: same round" */
    protected static function selection(?array $meta): ?string
    {
        if (! $meta) {
            return null;
        }
        $parts = [];
        if (isset($meta['rank'])) {
            $parts[] = __('admin.dispatch.meta_rank', ['rank' => $meta['rank']]);
        }
        if (isset($meta['deliveries_today'])) {
            $parts[] = __('admin.dispatch.meta_deliveries_today', ['count' => $meta['deliveries_today']]);
        }
        $parts[] = ($meta['location_used'] ?? false) && isset($meta['distance_km'])
            ? __('admin.dispatch.meta_distance', ['km' => $meta['distance_km']])
            : __('admin.dispatch.meta_no_fresh_gps');
        if (isset($meta['recovery'])) {
            $parts[] = __('admin.dispatch.meta_recovery_'.$meta['recovery']);
        }

        return implode(' · ', $parts);
    }
}
