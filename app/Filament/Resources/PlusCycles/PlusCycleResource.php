<?php

namespace App\Filament\Resources\PlusCycles;

use App\Filament\Resources\PlusCycles\Pages\ListPlusCycles;
use App\Filament\Resources\PlusCycles\Pages\ViewPlusCycle;
use App\Filament\Resources\PlusCycles\RelationManagers\DiscountsRelationManager;
use App\Filament\Resources\PlusMemberships\PlusMembershipResource;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Fields;
use App\Filament\Support\RowActions;
use App\Filament\Support\TableDefaults;
use App\Models\PlusCycle;
use App\Services\Plus\PlusService;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
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

    protected static ?string $navParent = PlusMembershipResource::class;

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
                TextEntry::make('free_deliveries_remaining')->label(__('admin.plus.free_deliveries_remaining'))
                    ->state(fn (PlusCycle $record) => max(0, $record->free_deliveries_total - $record->free_deliveries_used)),
                TextEntry::make('delivery_discount_percent')->label(__('admin.plus.delivery_discount_after_allowance'))
                    ->state(fn (PlusCycle $record) => PlusService::cycleDeliveryDiscountPercent($record) + 0)->suffix('%'),
                TextEntry::make('orders_count')->label(__('admin.plus.orders_using_cycle'))->state(fn (PlusCycle $record) => $record->orders()->count()),
                TextEntry::make('confirmedBy.name')->label(__('admin.fields.confirmed_by')),
                TextEntry::make('confirmed_at')->label(__('admin.fields.confirmed_at'))->dateTime(),
            ]),
        ]);
    }

    /**
     * PLUS cycles (table UX overhaul 2026-11-12; also a membership's tab):
     * the member, cycle number and plan, the period in one column, free
     * deliveries used, status.
     */
    public static function table(Table $table): Table
    {
        $date = fn ($value) => $value?->translatedFormat(TableDefaults::dateFormat());

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->columns([
                TextColumn::make('user.phone')->label(__('admin.fields.customer'))->weight(FontWeight::SemiBold)
                    ->formatStateUsing(fn ($state, PlusCycle $record) => $record->user?->fullName())
                    ->description(fn (PlusCycle $record) => $record->user?->phone)
                    ->searchable(query: Fields::searchCustomer('user'))
                    ->hidden(fn ($livewire) => $livewire instanceof RelationManager),
                TextColumn::make('cycle_number')->label(__('admin.fields.plan'))
                    ->formatStateUsing(fn ($state, PlusCycle $record) => self::planName($record->plan_name))
                    ->description(fn (PlusCycle $record) => __('admin.table.cycle_n', ['n' => $record->cycle_number])),
                TextColumn::make('starts_at')->label(__('admin.table.offer_period'))->sortable()
                    ->formatStateUsing(fn ($state, PlusCycle $record) => $date($record->starts_at).' – '.$date($record->ends_at)),
                TextColumn::make('free_deliveries_used')->label(__('admin.table.free_deliveries'))
                    ->formatStateUsing(fn ($state, PlusCycle $record) => __('admin.table.deliveries_used', ['used' => (int) $state, 'total' => (int) $record->free_deliveries_total]))
                    ->visibleFrom('md'),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()->formatStateUsing(fn ($state) => __("admin.plus.cycle_{$state}"))
                    ->color(fn ($state) => match ($state) {
                        'active' => 'success', 'scheduled' => 'info', 'cancelled' => 'danger', default => 'gray'
                    }),
            ])
            ->searchPlaceholder(__('admin.table.search_cycles'))
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))
                    ->options(collect(['scheduled', 'active', 'expired', 'cancelled'])->mapWithKeys(fn ($s) => [$s => __("admin.plus.cycle_{$s}")])),
            ])
            ->recordActions([RowActions::primary(ViewAction::make()->label(__('admin.table.view'))->url(fn (PlusCycle $record) => self::getUrl('view', ['record' => $record])))])
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
