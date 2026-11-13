<?php

namespace App\Filament\Resources\Orders;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\RelationManagers\CancellationRequestsRelationManager;
use App\Filament\Resources\Orders\RelationManagers\DeliveryOffersRelationManager;
use App\Filament\Resources\Orders\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\Orders\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Orders\RelationManagers\RatingsRelationManager;
use App\Filament\Resources\Orders\RelationManagers\RefundsRelationManager;
use App\Filament\Resources\Orders\RelationManagers\StatusHistoriesRelationManager;
use App\Filament\Resources\Orders\Support\DispatchPanel;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Fields;
use App\Filament\Support\RowActions;
use App\Filament\Support\TableDefaults;
use App\Models\Order;
use App\Services\Delivery\DeliveryOfferService;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
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
        // Display only (redesign 2026-11-11): stored values are untouched.
        $money = fn (string $field) => TextEntry::make($field)->label(__("admin.fields.{$field}"))->inlineLabel()
            ->alignEnd()->formatStateUsing(fn ($state, Order $record) => Fields::formatMoney($state, $record->currency));

        return $schema->components([
            // Dispatch B4 (2026-11-10): live delivery-offer status.
            Section::make(__('admin.dispatch.panel_title'))->icon('heroicon-o-paper-airplane')->columnSpanFull()
                ->visible(fn (Order $record) => DispatchPanel::visible($record))
                ->schema([
                    View::make('filament.orders.dispatch-panel')->viewData(fn (Order $record) => ['panel' => DispatchPanel::data($record)]),
                ]),
            // UX redesign round 2: where the order stands, at a glance.
            Section::make()->columnSpanFull()->schema([
                View::make('filament.orders.progress')->viewData(fn (Order $record) => ['record' => $record]),
            ])->extraAttributes(['class' => 'wf-steps-section']),
            // Main column: what the order is and where it goes; side column: the money.
            Grid::make(['default' => 1, 'xl' => 3])->columnSpanFull()->schema([
                Group::make()->columnSpan(['default' => 1, 'xl' => 2])->schema([
                    Section::make(__('admin.orders.summary'))->icon('heroicon-o-clipboard-document-list')->columns(['default' => 1, 'sm' => 2, 'lg' => 3])->schema([
                        TextEntry::make('order_number')->label(__('admin.fields.order_number'))->weight(FontWeight::SemiBold),
                        TextEntry::make('status')->label(__('admin.fields.status'))->badge()
                            ->formatStateUsing(fn ($state) => $state->label())->color(fn ($state) => $state->color()),
                        TextEntry::make('type')->label(__('admin.fields.type'))->formatStateUsing(fn ($state) => __("admin.orders.type_{$state}")),
                        TextEntry::make('user.phone')->label(__('admin.fields.customer'))
                            ->formatStateUsing(fn ($state, Order $record) => $record->user?->fullName().' '.$state),
                        TextEntry::make('branch.name')->label(__('admin.fields.branch')),
                        TextEntry::make('city.name')->label(__('admin.fields.city')),
                        TextEntry::make('driver.name')->label(__('admin.fields.driver'))->placeholder('—'),
                        TextEntry::make('created_at')->label(__('admin.fields.created_at'))->dateTime(),
                        TextEntry::make('scheduled_for')->label(__('admin.fields.scheduled_for'))->dateTime()->placeholder('—'),
                        TextEntry::make('urgent_at')->label(__('admin.orders.urgent'))->dateTime()->placeholder('—')->color('danger'),
                        TextEntry::make('customer_notes')->label(__('admin.fields.customer_notes'))->placeholder('—')->columnSpanFull(),
                    ]),
                    Section::make(__('admin.orders.delivery'))->icon('heroicon-o-map-pin')->columns(['default' => 1, 'sm' => 2, 'lg' => 3])->schema([
                        TextEntry::make('delivery_address')->label(__('admin.fields.address'))->columnSpanFull()
                            ->formatStateUsing(fn ($state) => is_array($state)
                                ? (collect([$state['label'] ?? null, $state['line'] ?? null, $state['details'] ?? null])
                                    ->filter(fn ($part) => is_scalar($part) && trim((string) $part) !== '')->implode(' · ')
                                    ?: json_encode($state, JSON_UNESCAPED_UNICODE))
                                : (string) $state),
                        TextEntry::make('deliveryArea.name')->label(__('admin.fields.delivery_area'))->placeholder('—'),
                        TextEntry::make('delivery_distance_km')->label(__('admin.fields.delivery_distance_km'))
                            ->formatStateUsing(fn ($state) => Fields::formatDecimal($state)),
                        TextEntry::make('distance_method')->label(__('admin.fields.distance_method'))
                            ->formatStateUsing(fn ($state) => __('admin.orders.distance_methods.'.$state) !== 'admin.orders.distance_methods.'.$state ? __('admin.orders.distance_methods.'.$state) : $state),
                    ]),
                ]),
                Group::make()->columnSpan(['default' => 1, 'xl' => 1])->schema([
                    Section::make(__('admin.orders.amounts'))->icon('heroicon-o-banknotes')->schema([
                        TextEntry::make('currency.code')->label(__('admin.fields.currency'))->inlineLabel()->alignEnd(),
                        TextEntry::make('exchange_rate')->label(__('admin.fields.exchange_rate'))->inlineLabel()->alignEnd()
                            ->formatStateUsing(fn ($state) => Fields::formatDecimal($state)),
                        $money('subtotal'), $money('offer_discount_total'), $money('plus_discount_amount'),
                        $money('delivery_fee_original'), $money('delivery_fee'),
                        IconEntry::make('free_delivery_applied')->label(__('admin.fields.free_delivery_applied'))->inlineLabel()->boolean()->falseIcon('heroicon-o-minus')->falseColor('gray'),
                        $money('total')->weight(FontWeight::Bold)->size(TextSize::Large),
                    ]),
                ]),
            ]),
        ]);
    }

    /**
     * Orders (table UX overhaul 2026-11-12; work tabs since 2026-11-11). The
     * columns follow the questions an employee asks: which order and how
     * old, what state and how urgent, which customer, which branch, who
     * delivers, how much. "View order" is the one row action (accept,
     * assign and cancel live on the order page with their own checks). On
     * phones: order, status, type, customer, total and the action.
     */
    public static function table(Table $table): Table
    {
        $waitingTab = fn ($livewire) => ($livewire->activeTab ?? null) === 'waiting_for_driver';

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['branch.store', 'city', 'driver', 'currency', 'user']))
            // Dispatch B4: orders without an available driver stand out.
            ->recordClasses(fn (Order $record) => $record->dispatch_state === Order::DISPATCH_NO_DRIVER ? 'waffrli-row-no-driver' : null)
            ->columns([
                TextColumn::make('order_number')->label(__('admin.fields.order_number'))->searchable()->sortable()->weight(FontWeight::SemiBold)
                    ->description(fn (Order $record) => $record->created_at?->diffForHumans())
                    ->tooltip(fn (Order $record) => $record->created_at?->translatedFormat(TableDefaults::dateTimeFormat())),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()
                    ->formatStateUsing(fn ($state) => $state->label())->color(fn ($state) => $state->color()),
                // Type and urgency as one labelled badge (icon + words; colour
                // is never the only signal); a scheduled order shows its time.
                TextColumn::make('type')->label(__('admin.fields.type'))->badge()
                    ->state(fn (Order $record) => $record->urgent_at !== null && $record->status === OrderStatus::Pending ? 'urgent' : $record->type)
                    ->formatStateUsing(fn (string $state) => $state === 'urgent' ? __('admin.orders.urgent') : __("admin.orders.type_{$state}"))
                    ->icon(fn (string $state) => match ($state) {
                        'urgent' => 'heroicon-m-exclamation-triangle',
                        Order::TYPE_SCHEDULED => 'heroicon-m-calendar',
                        default => 'heroicon-m-bolt',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'urgent' => 'danger',
                        Order::TYPE_SCHEDULED => 'info',
                        default => 'gray',
                    })
                    ->description(fn (Order $record) => $record->scheduled_for
                        ? __('admin.table.scheduled_for', ['date' => $record->scheduled_for->translatedFormat('j M، H:i')]) : null),
                TextColumn::make('user.first_name')->label(__('admin.fields.customer'))
                    ->state(fn (Order $record) => $record->user?->fullName())
                    ->description(fn (Order $record) => Fields::ltr($record->user?->phone))
                    ->searchable(query: Fields::searchCustomer('user')),
                TextColumn::make('branch.name')->label(__('admin.fields.branch'))
                    ->description(fn (Order $record) => collect([$record->branch?->store?->name, $record->city?->name])->filter()->implode(' · '))
                    ->visibleFrom('md'),
                TextColumn::make('driver.name')->label(__('admin.table.driver'))->placeholder(__('admin.table.not_assigned'))
                    ->icon(fn (Order $record) => $record->driver ? 'heroicon-m-truck' : null)->visibleFrom('lg'),
                // Dispatch B4: "Waiting for driver" queue columns (that tab only).
                TextColumn::make('order_age')->label(__('admin.dispatch.order_age'))->visible($waitingTab)
                    ->state(fn (Order $record) => $record->created_at?->diffForHumans(short: true)),
                TextColumn::make('dispatch_state')->label(__('admin.dispatch.state'))->badge()->visible($waitingTab)
                    ->formatStateUsing(fn ($state) => __("admin.dispatch.state_{$state}"))
                    ->color(fn ($state) => $state === Order::DISPATCH_NO_DRIVER ? 'danger' : 'warning'),
                TextColumn::make('dispatch_attempts')->label(__('admin.dispatch.attempts'))->visible($waitingTab)
                    ->formatStateUsing(fn ($state, Order $record) => $record->dispatch_round.' / '.$state.'/'.DeliveryOfferService::maxAttemptsPerRound()),
                TextColumn::make('latestDeliveryOffer.status')->label(__('admin.dispatch.latest_offer'))->badge()->visible($waitingTab)
                    ->formatStateUsing(fn ($state) => $state?->label())->color(fn ($state) => $state ? DispatchPanel::statusColor($state) : 'gray')->placeholder('—'),
                IconColumn::make('needs_attention')->label(__('admin.dispatch.needs_attention'))->boolean()->visible($waitingTab)
                    ->state(fn (Order $record) => $record->dispatch_state === Order::DISPATCH_NO_DRIVER)->trueColor('danger')->trueIcon('heroicon-o-exclamation-triangle')->falseIcon('heroicon-o-minus')->falseColor('gray'),
                TextColumn::make('total')->label(__('admin.fields.total'))->alignEnd()->weight(FontWeight::SemiBold)
                    ->formatStateUsing(fn ($state, Order $record) => Fields::formatMoney($state, $record->currency)),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->searchPlaceholder(__('admin.table.search_orders'))
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->options(OrderStatus::options())->multiple(),
                SelectFilter::make('type')->label(__('admin.fields.type'))->options([
                    Order::TYPE_IMMEDIATE => __('admin.orders.type_immediate'),
                    Order::TYPE_SCHEDULED => __('admin.orders.type_scheduled'),
                ]),
                TernaryFilter::make('urgent')->label(__('admin.orders.urgent'))
                    ->queries(true: fn (Builder $query) => $query->whereNotNull('urgent_at'), false: fn (Builder $query) => $query->whereNull('urgent_at')),
                SelectFilter::make('branch_id')->label(__('admin.fields.branch'))->relationship('branch', 'id')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name)->searchable()->preload(),
                SelectFilter::make('city_id')->label(__('admin.fields.city'))->relationship('city', 'id')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name),
                SelectFilter::make('driver_id')->label(__('admin.table.driver'))->relationship('driver', 'name')->searchable()->preload(),
                TernaryFilter::make('assigned')->label(__('admin.table.driver_assigned'))
                    ->trueLabel(__('admin.table.assigned'))->falseLabel(__('admin.table.unassigned'))
                    ->queries(true: fn (Builder $query) => $query->whereNotNull('driver_id'), false: fn (Builder $query) => $query->whereNull('driver_id')),
                Filter::make('created_between')->label(__('admin.fields.created_at'))
                    ->schema([
                        DatePicker::make('from')->label(__('admin.fields.from')),
                        DatePicker::make('until')->label(__('admin.fields.until')),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($query, $d) => $query->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($query, $d) => $query->whereDate('created_at', '<=', $d))),
            ])
            ->recordActions([RowActions::primary(ViewAction::make()->label(__('admin.table.view_order')))])
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
            DeliveryOffersRelationManager::class,
            RatingsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListOrders::route('/'), 'view' => ViewOrder::route('/{record}')];
    }
}
