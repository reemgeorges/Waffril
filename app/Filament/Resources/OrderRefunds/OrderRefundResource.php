<?php

namespace App\Filament\Resources\OrderRefunds;

use App\Filament\Resources\OrderRefunds\Pages\ListOrderRefunds;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Fields;
use App\Models\OrderRefund;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * WAFFRLI (2026-10-31). Issued refunds (read-only). Refunds are created
 * from the order page through RefundService (capped, idempotent).
 */
class OrderRefundResource extends AdminResource
{
    protected static ?string $model = OrderRefund::class;

    protected static string $labelKey = 'order_refund';

    protected static string|\UnitEnum|null $navigationGroup = 'finance';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?int $navigationSort = 50;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime()->sortable(),
                TextColumn::make('order.order_number')->label(__('admin.fields.order_number'))->searchable(),
                TextColumn::make('payment.method.name')->label(__('admin.fields.payment_method')),
                TextColumn::make('amount')->label(__('admin.fields.amount'))
                    ->formatStateUsing(fn ($state, OrderRefund $record) => Fields::formatMoney($state, $record->currency)),
                TextColumn::make('reason_code')->label(__('admin.fields.reason')),
                TextColumn::make('createdBy.name')->label(__('admin.fields.created_by')),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListOrderRefunds::route('/')];
    }
}
