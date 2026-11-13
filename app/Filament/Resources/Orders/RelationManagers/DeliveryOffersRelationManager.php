<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Filament\Concerns\TranslatedRelationLabels;
use App\Filament\Resources\Orders\Support\DispatchPanel;
use App\Filament\Support\TableDefaults;
use App\Models\DeliveryOffer;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontWeight;
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

    /**
     * Order → Delivery offers (table UX overhaul 2026-11-12): the driver
     * with round and attempt, the outcome with its reason, who offered it
     * (system or admin), and when it was offered / answered. Read only.
     */
    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['driver', 'offeredBy']))
            ->columns([
                TextColumn::make('driver.name')->label(__('admin.fields.driver'))->placeholder('—')->weight(FontWeight::SemiBold)
                    ->description(fn (DeliveryOffer $record) => __('admin.table.round_attempt', ['round' => $record->round, 'attempt' => $record->attempt])),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()
                    ->formatStateUsing(fn ($state) => $state->label())->color(fn ($state) => DispatchPanel::statusColor($state))
                    ->description(fn (DeliveryOffer $record) => DispatchPanel::reason($record)),
                TextColumn::make('source')->label(__('admin.dispatch.source'))->badge()
                    ->formatStateUsing(fn ($state) => __('admin.dispatch.source_'.$state))
                    ->color(fn ($state) => $state === DeliveryOffer::SOURCE_ADMIN ? 'info' : 'gray')
                    ->description(fn (DeliveryOffer $record) => $record->offeredBy?->name)->visibleFrom('md'),
                TextColumn::make('offered_at')->label(__('admin.dispatch.offered_at'))->dateTime('H:i:s')
                    ->description(fn (DeliveryOffer $record) => $record->responded_at ? __('admin.table.responded_at', ['time' => $record->responded_at->format('H:i:s')]) : null)
                    ->tooltip(fn (DeliveryOffer $record) => $record->offered_at?->translatedFormat(TableDefaults::dateTimeFormat()))
                    ->extraAttributes(['class' => 'wf-ltr wf-num']),
                TextColumn::make('selection_meta')->label(__('admin.dispatch.selection'))->placeholder('—')->wrap()->toggleable(isToggledHiddenByDefault: true)
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
