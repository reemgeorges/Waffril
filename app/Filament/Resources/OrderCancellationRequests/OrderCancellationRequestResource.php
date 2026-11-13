<?php

namespace App\Filament\Resources\OrderCancellationRequests;

use App\Filament\Resources\OrderCancellationRequests\Pages\ListOrderCancellationRequests;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\AdminResource;
use App\Filament\Support\BusinessAction;
use App\Filament\Support\RowActions;
use App\Filament\Support\TableDefaults;
use App\Models\OrderCancellationRequest;
use App\Services\Order\OrderAdminService;
use App\Support\Auth\AdminPermissions;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Size;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * WAFFRLI (2026-10-31). Customer cancellation requests (orders already
 * accepted). Approving cancels the order through OrderAdminService with
 * full financial handling; an order already picked up must be cancelled
 * from the order page (driver handling / cash collected are required).
 */
class OrderCancellationRequestResource extends AdminResource
{
    protected static ?string $model = OrderCancellationRequest::class;

    protected static string $labelKey = 'order_cancellation_request';

    protected static string|\UnitEnum|null $navigationGroup = 'operations';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-hand-raised';

    protected static ?int $navigationSort = 20;

    public static function getNavigationBadge(): ?string
    {
        $count = OrderCancellationRequest::query()->where('status', OrderCancellationRequest::STATUS_PENDING)->count();

        return $count > 0 ? (string) $count : null;
    }

    /**
     * Cancellation requests (table UX overhaul 2026-11-12; also the tab on
     * an order): the order with its customer, how long ago, the order's
     * state now, the customer's note and the decision. Approve and Reject are
     * explicit labelled buttons, only on pending requests and only with
     * orders.resolve_cancellation_requests; both keep their confirmation.
     */
    public static function table(Table $table): Table
    {
        $canResolve = fn (OrderCancellationRequest $record) => $record->status === OrderCancellationRequest::STATUS_PENDING
            && AdminPermissions::currentAllows('orders.resolve_cancellation_requests');

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['order.user', 'resolvedBy']))
            ->columns([
                TextColumn::make('order.order_number')->label(__('admin.fields.order_number'))->weight(FontWeight::SemiBold)
                    ->description(fn ($record) => collect([$record->order?->user?->fullName(), $record->order?->user?->phone])->filter()->implode(' · '))
                    ->searchable(query: fn ($query, string $search) => $query->whereHas('order', fn ($o) => $o->where('order_number', 'like', "%{$search}%"))
                        ->orWhereHas('order.user', fn ($u) => $u->where('phone', 'like', "%{$search}%")->orWhere('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")))
                    ->url(fn (OrderCancellationRequest $record) => OrderResource::getUrl('view', ['record' => $record->order_id])),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()
                    ->formatStateUsing(fn ($state) => __("admin.requests.status_{$state}"))
                    ->icon(fn ($state) => match ($state) {
                        'approved' => 'heroicon-m-check-circle',
                        'rejected' => 'heroicon-m-x-circle',
                        default => 'heroicon-m-clock',
                    })
                    ->color(fn ($state) => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    })
                    ->description(fn ($record) => $record->resolvedBy?->name),
                TextColumn::make('order.status')->label(__('admin.fields.order_status'))->badge()
                    ->formatStateUsing(fn ($state) => $state?->label())->color(fn ($state) => $state?->color())->visibleFrom('md'),
                TextColumn::make('customer_note')->label(__('admin.fields.customer_note'))->limit(80)->wrap()->placeholder('—')->visibleFrom('md'),
                TextColumn::make('created_at')->label(__('admin.table.requested'))->since()->sortable()
                    ->tooltip(fn ($record) => $record->created_at?->translatedFormat(TableDefaults::dateTimeFormat())),
            ])
            ->searchPlaceholder(__('admin.table.search_cancellations'))
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->default(OrderCancellationRequest::STATUS_PENDING)->options([
                    'pending' => __('admin.requests.status_pending'),
                    'approved' => __('admin.requests.status_approved'),
                    'rejected' => __('admin.requests.status_rejected'),
                ]),
            ])
            ->recordActions([
                RowActions::primary(Action::make('approve')->label(__('admin.table.approve'))->icon('heroicon-m-check')->color('success')
                    ->visible(fn (OrderCancellationRequest $record) => $canResolve($record) && $record->order->status->value !== 'picked_up')
                    ->schema([
                        Hidden::make('idempotency_key')->default(fn () => (string) Str::uuid()),
                        Textarea::make('note')->label(__('admin.fields.note'))->maxLength(500),
                    ])
                    ->requiresConfirmation()->modalDescription(__('admin.orders.cancel_warning'))
                    ->action(fn (OrderCancellationRequest $record, array $data) => BusinessAction::run(
                        fn ($admin) => app(OrderAdminService::class)->approveCancellationRequest($record, $admin, $data)
                    ))),
                Action::make('reject')->label(__('admin.table.reject'))->icon('heroicon-m-x-mark')->color('danger')
                    ->button()->outlined()->size(Size::Small)
                    ->visible($canResolve)
                    ->schema([Textarea::make('note')->label(__('admin.fields.note'))->required()->maxLength(500)])
                    ->action(fn (OrderCancellationRequest $record, array $data) => BusinessAction::run(
                        fn ($admin) => app(OrderAdminService::class)->rejectCancellationRequest($record, $admin, $data['note'])
                    )),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListOrderCancellationRequests::route('/')];
    }
}
