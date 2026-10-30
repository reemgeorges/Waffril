<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Support\Auth\AdminPermissions;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * WAFFRLI (2026-10-31) — oldest pending orders first (urgent scheduled
 * orders on top), linking to the order page.
 */
class PendingOrdersTable extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return AdminPermissions::currentAllows('orders.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('admin.dashboard.pending_orders'))
            ->query(Order::query()->where('status', OrderStatus::Pending->value)
                ->orderByRaw('CASE WHEN urgent_at IS NULL THEN 1 ELSE 0 END')->orderBy('created_at'))
            ->columns([
                TextColumn::make('order_number')->label(__('admin.fields.order_number')),
                IconColumn::make('urgent_at')->label(__('admin.orders.urgent'))->boolean()->state(fn (Order $record) => $record->urgent_at !== null)->trueColor('danger'),
                TextColumn::make('type')->label(__('admin.fields.type'))->formatStateUsing(fn ($state) => __("admin.orders.type_{$state}")),
                TextColumn::make('scheduled_for')->label(__('admin.fields.scheduled_for'))->dateTime()->placeholder('—'),
                TextColumn::make('branch.name')->label(__('admin.fields.branch')),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->since(),
            ])
            ->recordUrl(fn (Order $record) => OrderResource::getUrl('view', ['record' => $record]))
            ->paginated([5, 10]);
    }
}
