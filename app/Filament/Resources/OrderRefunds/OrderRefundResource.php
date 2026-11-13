<?php

namespace App\Filament\Resources\OrderRefunds;

use App\Filament\Resources\OrderPayments\OrderPaymentResource;
use App\Filament\Resources\OrderRefunds\Pages\ListOrderRefunds;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Fields;
use App\Filament\Support\TableDefaults;
use App\Models\OrderRefund;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Lang;

/**
 * WAFFRLI (2026-10-31). Issued refunds (read-only). Refunds are created
 * from the order page through RefundService (capped, idempotent).
 */
class OrderRefundResource extends AdminResource
{
    protected static ?string $model = OrderRefund::class;

    protected static ?string $navParent = OrderPaymentResource::class;

    protected static string $labelKey = 'order_refund';

    protected static string|\UnitEnum|null $navigationGroup = 'finance';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?int $navigationSort = 30;

    /**
     * Refunds (table UX overhaul 2026-11-12; also the tab on an order): the
     * order, the amount in its own currency, the reason in words, the
     * payment method, who recorded it and when. Read only (audit trail).
     */
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['order', 'payment.method', 'currency', 'createdBy']))
            ->columns([
                TextColumn::make('order.order_number')->label(__('admin.fields.order_number'))->weight(FontWeight::SemiBold)
                    ->description(fn (OrderRefund $record) => $record->created_at?->translatedFormat(TableDefaults::dateTimeFormat()))
                    ->searchable(),
                TextColumn::make('amount')->label(__('admin.fields.amount'))->weight(FontWeight::SemiBold)
                    ->formatStateUsing(fn ($state, OrderRefund $record) => Fields::formatMoney($state, $record->currency)),
                TextColumn::make('reason_code')->label(__('admin.table.reason'))->badge()->color('gray')
                    ->formatStateUsing(fn ($state) => Lang::has("admin.orders.reason_{$state}") ? __("admin.orders.reason_{$state}") : $state),
                TextColumn::make('payment.method.name')->label(__('admin.table.method'))->visibleFrom('md'),
                TextColumn::make('createdBy.name')->label(__('admin.fields.created_by'))->color('gray')->visibleFrom('lg'),
            ])
            ->searchPlaceholder(__('admin.table.search_refunds'))
            ->filters([
                SelectFilter::make('currency_id')->label(__('admin.fields.currency'))->relationship('currency', 'code'),
                Filter::make('created_between')->label(__('admin.table.date_between'))
                    ->schema([
                        DatePicker::make('from')->label(__('admin.fields.from')),
                        DatePicker::make('until')->label(__('admin.fields.until')),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListOrderRefunds::route('/')];
    }
}
