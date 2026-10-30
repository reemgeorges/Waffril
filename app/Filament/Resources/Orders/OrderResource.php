<?php

namespace App\Filament\Resources\Orders;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\RelationManagers\CancellationRequestsRelationManager;
use App\Filament\Resources\Orders\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\Orders\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Orders\RelationManagers\RefundsRelationManager;
use App\Filament\Resources\Orders\RelationManagers\StatusHistoriesRelationManager;
use App\Filament\Support\AdminResource;
use App\Models\Order;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * WAFFRLI (2026-10-31). Orders: listing with status/branch/city/date
 * filters and urgent/scheduled indicators; the order page runs the
 * workflow (accept, recommended driver assignment, cancellation incl.
 * after pickup, refunds) exclusively through OrderAdminService and
 * RefundService. Orders are never created or edited by admins.
 */
class OrderResource extends AdminResource
{
    protected static ?string $model = Order::class;

    protected static string $labelKey = 'order';

    protected static string|\UnitEnum|null $navigationGroup = 'operations';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 10;

    public static function getNavigationBadge(): ?string
    {
        $count = Order::query()->where('status', OrderStatus::Pending->value)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        $money = fn (string $field) => TextEntry::make($field)->label(__("admin.fields.{$field}"))
            ->formatStateUsing(fn ($state, Order $record) => $state.' '.$record->currency?->code);

        return $schema->components([
            Section::make(__('admin.orders.summary'))->columns(4)->schema([
                TextEntry::make('order_number')->label(__('admin.fields.order_number')),
                TextEntry::make('status')->label(__('admin.fields.status'))->badge()
                    ->formatStateUsing(fn ($state) => $state->label())->color(fn ($state) => $state->color()),
                TextEntry::make('type')->label(__('admin.fields.type'))->formatStateUsing(fn ($state) => __("admin.orders.type_{$state}")),
                TextEntry::make('scheduled_for')->label(__('admin.fields.scheduled_for'))->dateTime()->placeholder('—'),
                TextEntry::make('urgent_at')->label(__('admin.orders.urgent'))->dateTime()->placeholder('—')->color('danger'),
                TextEntry::make('user.phone')->label(__('admin.fields.customer'))
                    ->formatStateUsing(fn ($state, Order $record) => $record->user?->fullName().' '.$state),
                TextEntry::make('branch.name')->label(__('admin.fields.branch')),
                TextEntry::make('city.name')->label(__('admin.fields.city')),
                TextEntry::make('driver.name')->label(__('admin.fields.driver'))->placeholder('—'),
                TextEntry::make('created_at')->label(__('admin.fields.created_at'))->dateTime(),
                TextEntry::make('customer_notes')->label(__('admin.fields.customer_notes'))->placeholder('—')->columnSpan(2),
            ]),
            Section::make(__('admin.orders.amounts'))->columns(4)->schema([
                TextEntry::make('currency.code')->label(__('admin.fields.currency')),
                TextEntry::make('exchange_rate')->label(__('admin.fields.exchange_rate')),
                $money('subtotal'), $money('offer_discount_total'), $money('plus_discount_amount'),
                $money('delivery_fee_original'), $money('delivery_fee'), $money('total'),
                IconEntry::make('free_delivery_applied')->label(__('admin.fields.free_delivery_applied'))->boolean(),
            ]),
            Section::make(__('admin.orders.delivery'))->columns(4)->schema([
                TextEntry::make('delivery_distance_km')->label(__('admin.fields.delivery_distance_km')),
                TextEntry::make('distance_method')->label(__('admin.fields.distance_method')),
                TextEntry::make('deliveryArea.name')->label(__('admin.fields.delivery_area'))->placeholder('—'),
                TextEntry::make('delivery_address')->label(__('admin.fields.address'))
                    ->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_UNESCAPED_UNICODE) : (string) $state),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order_number')->label(__('admin.fields.order_number'))->searchable()->sortable(),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()
                    ->formatStateUsing(fn ($state) => $state->label())->color(fn ($state) => $state->color()),
                IconColumn::make('urgent_at')->label(__('admin.orders.urgent'))->boolean()
                    ->state(fn (Order $record) => $record->urgent_at !== null)->trueColor('danger'),
                TextColumn::make('type')->label(__('admin.fields.type'))->formatStateUsing(fn ($state) => __("admin.orders.type_{$state}")),
                TextColumn::make('scheduled_for')->label(__('admin.fields.scheduled_for'))->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('branch.name')->label(__('admin.fields.branch')),
                TextColumn::make('city.name')->label(__('admin.fields.city'))->toggleable(),
                TextColumn::make('driver.name')->label(__('admin.fields.driver'))->placeholder('—'),
                TextColumn::make('total')->label(__('admin.fields.total'))
                    ->formatStateUsing(fn ($state, Order $record) => $state.' '.$record->currency?->code),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->options(OrderStatus::options())->multiple(),
                SelectFilter::make('branch_id')->label(__('admin.fields.branch'))->relationship('branch', 'id')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name)->searchable()->preload(),
                SelectFilter::make('city_id')->label(__('admin.fields.city'))->relationship('city', 'id')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name),
                SelectFilter::make('type')->label(__('admin.fields.type'))->options([
                    Order::TYPE_IMMEDIATE => __('admin.orders.type_immediate'),
                    Order::TYPE_SCHEDULED => __('admin.orders.type_scheduled'),
                ]),
                TernaryFilter::make('urgent')->label(__('admin.orders.urgent'))
                    ->queries(true: fn (Builder $query) => $query->whereNotNull('urgent_at'), false: fn (Builder $query) => $query->whereNull('urgent_at')),
                Filter::make('created_between')->label(__('admin.fields.created_at'))
                    ->schema([
                        DatePicker::make('from')->label(__('admin.fields.from')),
                        DatePicker::make('until')->label(__('admin.fields.until')),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($query, $d) => $query->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($query, $d) => $query->whereDate('created_at', '<=', $d))),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
            PaymentsRelationManager::class,
            StatusHistoriesRelationManager::class,
            CancellationRequestsRelationManager::class,
            RefundsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListOrders::route('/'), 'view' => ViewOrder::route('/{record}')];
    }
}
