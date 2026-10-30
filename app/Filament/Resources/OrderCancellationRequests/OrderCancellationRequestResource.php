<?php

namespace App\Filament\Resources\OrderCancellationRequests;

use App\Filament\Resources\OrderCancellationRequests\Pages\ListOrderCancellationRequests;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\AdminResource;
use App\Filament\Support\BusinessAction;
use App\Models\OrderCancellationRequest;
use App\Services\Order\OrderAdminService;
use App\Support\Auth\AdminPermissions;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
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

    public static function table(Table $table): Table
    {
        $canResolve = fn (OrderCancellationRequest $record) => $record->status === OrderCancellationRequest::STATUS_PENDING
            && AdminPermissions::currentAllows('orders.resolve_cancellation_requests');

        return $table
            ->columns([
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime()->sortable(),
                TextColumn::make('order.order_number')->label(__('admin.fields.order_number'))
                    ->url(fn (OrderCancellationRequest $record) => OrderResource::getUrl('view', ['record' => $record->order_id])),
                TextColumn::make('order.status')->label(__('admin.fields.order_status'))->badge()
                    ->formatStateUsing(fn ($state) => $state?->label())->color(fn ($state) => $state?->color()),
                TextColumn::make('customer_note')->label(__('admin.fields.customer_note'))->limit(50)->placeholder('—'),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()
                    ->formatStateUsing(fn ($state) => __("admin.requests.status_{$state}")),
                TextColumn::make('resolvedBy.name')->label(__('admin.fields.resolved_by'))->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->default(OrderCancellationRequest::STATUS_PENDING)->options([
                    'pending' => __('admin.requests.status_pending'),
                    'approved' => __('admin.requests.status_approved'),
                    'rejected' => __('admin.requests.status_rejected'),
                ]),
            ])
            ->recordActions([
                Action::make('approve')->label(__('admin.requests.approve'))->icon('heroicon-o-check')->color('success')
                    ->visible(fn (OrderCancellationRequest $record) => $canResolve($record) && $record->order->status->value !== 'picked_up')
                    ->schema([
                        Hidden::make('idempotency_key')->default(fn () => (string) Str::uuid()),
                        Textarea::make('note')->label(__('admin.fields.note'))->maxLength(500),
                    ])
                    ->requiresConfirmation()->modalDescription(__('admin.orders.cancel_warning'))
                    ->action(fn (OrderCancellationRequest $record, array $data) => BusinessAction::run(
                        fn ($admin) => app(OrderAdminService::class)->approveCancellationRequest($record, $admin, $data)
                    )),
                Action::make('reject')->label(__('admin.requests.reject'))->icon('heroicon-o-x-mark')->color('danger')
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
