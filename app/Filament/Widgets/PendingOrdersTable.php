<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Filament\Concerns\RefreshesOnOrderEvents;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\Fields;
use App\Models\Order;
use App\Support\Auth\AdminPermissions;
use Filament\Actions\Action;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * WAFFRLI dashboard (2026-10-31; UX redesign 2026-11-11): orders waiting
 * for acceptance, urgent scheduled orders first, then the longest waiting.
 * Each row opens the order, where the accept / cancel actions live (with
 * their own permission checks).
 */
class PendingOrdersTable extends TableWidget
{
    use RefreshesOnOrderEvents;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return AdminPermissions::currentAllows('orders.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('admin.dashboard.pending_orders'))
            ->description(__('admin.dashboard.pending_table_help'))
            ->query(Order::query()->with(['branch.store', 'currency', 'user'])->where('status', OrderStatus::Pending->value)
                ->orderByRaw('CASE WHEN urgent_at IS NULL THEN 1 ELSE 0 END')->orderBy('created_at'))
            ->columns([
                TextColumn::make('order_number')->label(__('admin.fields.order_number'))->weight(FontWeight::SemiBold),
                TextColumn::make('urgency')->label(__('admin.fields.type'))->badge()
                    ->state(fn (Order $record) => $record->urgent_at !== null ? 'urgent' : $record->type)
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
                    }),
                TextColumn::make('branch.name')->label(__('admin.fields.branch'))
                    ->description(fn (Order $record) => $record->branch?->store?->name),
                TextColumn::make('user.first_name')->label(__('admin.fields.customer'))
                    ->state(fn (Order $record) => $record->user?->fullName()),
                TextColumn::make('scheduled_for')->label(__('admin.fields.scheduled_for'))->dateTime('j M, H:i'),
                TextColumn::make('total')->label(__('admin.fields.total'))->alignEnd()
                    ->formatStateUsing(fn ($state, Order $record) => Fields::formatMoney($state, $record->currency)),
                TextColumn::make('created_at')->label(__('admin.dashboard.waiting_for'))
                    ->since()->tooltip(fn (Order $record) => $record->created_at?->toDateTimeString()),
            ])
            ->headerActions([
                Action::make('all')->label(__('admin.dashboard.open_list'))->link()->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(OrderResource::getUrl('index', ['tab' => 'pending'])),
            ])
            ->recordUrl(fn (Order $record) => OrderResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading(__('admin.dashboard.pending_empty'))
            ->emptyStateDescription(__('admin.dashboard.pending_empty_help'))
            ->emptyStateIcon('heroicon-o-check-circle')
            ->paginated([5, 10])
            ->defaultPaginationPageOption(5);
    }
}
