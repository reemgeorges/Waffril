<?php

namespace App\Filament\Resources\OrderPayments;

use App\Filament\Resources\OrderPayments\Pages\ListOrderPayments;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Fields;
use App\Models\OrderPayment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * WAFFRLI (2026-10-31). Payment status monitoring (read-only): amount,
 * collected, reversed and refunded per payment.
 */
class OrderPaymentResource extends AdminResource
{
    protected static ?string $model = OrderPayment::class;

    protected static string $labelKey = 'order_payment';

    protected static string|\UnitEnum|null $navigationGroup = 'finance';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 40;

    public static function statusOptions(): array
    {
        return collect(['pending', 'paid', 'failed', 'cancelled', 'reversed'])->mapWithKeys(fn ($s) => [$s => __("admin.payments.status_{$s}")])->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order.order_number')->label(__('admin.fields.order_number'))->searchable(),
                TextColumn::make('method.name')->label(__('admin.fields.payment_method')),
                TextColumn::make('currency.code')->label(__('admin.fields.currency')),
                TextColumn::make('amount')->label(__('admin.fields.amount'))->formatStateUsing(fn ($state, $record) => Fields::formatMoney($state, $record->currency)),
                TextColumn::make('collected_amount')->label(__('admin.fields.collected_amount'))->formatStateUsing(fn ($state, $record) => Fields::formatMoney($state, $record->currency)),
                TextColumn::make('reversed_amount')->label(__('admin.fields.reversed_amount'))->formatStateUsing(fn ($state, $record) => Fields::formatMoney($state, $record->currency)),
                TextColumn::make('refunded_amount')->label(__('admin.fields.refunded_amount'))->formatStateUsing(fn ($state, $record) => Fields::formatMoney($state, $record->currency)),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()->formatStateUsing(fn ($state) => __("admin.payments.status_{$state}")),
                TextColumn::make('collectedBy.name')->label(__('admin.fields.collected_by'))->placeholder('—'),
                TextColumn::make('paid_at')->label(__('admin.fields.paid_at'))->dateTime()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->options(self::statusOptions()),
                SelectFilter::make('payment_method_id')->label(__('admin.fields.payment_method'))->relationship('method', 'code'),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListOrderPayments::route('/')];
    }
}
