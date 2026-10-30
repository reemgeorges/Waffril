<?php

namespace App\Filament\Resources\PlusCycles;

use App\Filament\Resources\PlusCycles\Pages\ListPlusCycles;
use App\Filament\Resources\PlusCycles\Pages\ViewPlusCycle;
use App\Filament\Resources\PlusCycles\RelationManagers\DiscountsRelationManager;
use App\Filament\Support\AdminResource;
use App\Models\PlusCycle;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * WAFFRLI PLUS (2026-10-31). Confirmed cycles and their IMMUTABLE benefit
 * snapshots — read-only. Shows free-delivery usage (unused allowance
 * expires with the cycle) and the orders that used the cycle.
 */
class PlusCycleResource extends AdminResource
{
    protected static ?string $model = PlusCycle::class;

    protected static string $labelKey = 'plus_cycle';

    protected static string|\UnitEnum|null $navigationGroup = 'plus';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 30;

    protected static function planName($state): string
    {
        return is_array($state) ? (string) ($state[app()->getLocale()] ?? reset($state)) : (string) $state;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.plus.snapshot'))->description(__('admin.plus.snapshot_immutable'))->columns(4)->schema([
                TextEntry::make('user.phone')->label(__('admin.fields.customer'))
                    ->formatStateUsing(fn ($state, PlusCycle $record) => $record->user?->fullName().' '.$state),
                TextEntry::make('cycle_number')->label(__('admin.fields.cycle_number')),
                TextEntry::make('plan_name')->label(__('admin.fields.plan'))->formatStateUsing(fn ($state) => self::planName($state)),
                TextEntry::make('status')->label(__('admin.fields.status'))->badge()->formatStateUsing(fn ($state) => __("admin.plus.cycle_{$state}")),
                TextEntry::make('starts_at')->label(__('admin.fields.starts_at'))->dateTime(),
                TextEntry::make('ends_at')->label(__('admin.fields.ends_at'))->dateTime(),
                TextEntry::make('renewal_policy')->label(__('admin.fields.renewal_policy'))->formatStateUsing(fn ($state) => __("admin.plus.policy_{$state}")),
                TextEntry::make('snapshotCurrency.code')->label(__('admin.fields.currency')),
                TextEntry::make('free_deliveries_total')->label(__('admin.fields.free_deliveries_total')),
                TextEntry::make('free_deliveries_used')->label(__('admin.fields.free_deliveries_used')),
                TextEntry::make('orders_count')->label(__('admin.plus.orders_using_cycle'))->state(fn (PlusCycle $record) => $record->orders()->count()),
                TextEntry::make('confirmedBy.name')->label(__('admin.fields.confirmed_by')),
                TextEntry::make('confirmed_at')->label(__('admin.fields.confirmed_at'))->dateTime(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.phone')->label(__('admin.fields.customer'))
                    ->formatStateUsing(fn ($state, PlusCycle $record) => $record->user?->fullName().' '.$state),
                TextColumn::make('cycle_number')->label(__('admin.fields.cycle_number')),
                TextColumn::make('plan_name')->label(__('admin.fields.plan'))->formatStateUsing(fn ($state) => self::planName($state)),
                TextColumn::make('starts_at')->label(__('admin.fields.starts_at'))->dateTime()->sortable(),
                TextColumn::make('ends_at')->label(__('admin.fields.ends_at'))->dateTime()->sortable(),
                TextColumn::make('free_deliveries_used')->label(__('admin.fields.free_deliveries_used'))
                    ->formatStateUsing(fn ($state, PlusCycle $record) => $state.' / '.$record->free_deliveries_total),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()->formatStateUsing(fn ($state) => __("admin.plus.cycle_{$state}")),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))
                    ->options(collect(['scheduled', 'active', 'expired', 'cancelled'])->mapWithKeys(fn ($s) => [$s => __("admin.plus.cycle_{$s}")])),
            ])
            ->recordActions([ViewAction::make()->url(fn (PlusCycle $record) => self::getUrl('view', ['record' => $record]))])
            ->defaultSort('starts_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [DiscountsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListPlusCycles::route('/'), 'view' => ViewPlusCycle::route('/{record}')];
    }
}
