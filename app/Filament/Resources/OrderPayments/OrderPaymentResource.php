<?php

namespace App\Filament\Resources\OrderPayments;

use App\Filament\Resources\OrderPayments\Pages\ListOrderPayments;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Fields;
use App\Models\OrderPayment;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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

    protected static ?int $navigationSort = 20;

    public static function statusOptions(): array
    {
        return collect(['pending', 'paid', 'failed', 'cancelled', 'reversed'])->mapWithKeys(fn ($s) => [$s => __("admin.payments.status_{$s}")])->all();
    }

    /**
     * Payments (table UX overhaul 2026-11-12; also the tab on an order): the
     * order and customer, method and collector, the amount in the payment's
     * own currency (collected / reversed / refunded under it, only when not
     * zero — nothing is converted), status and payment date.
     */
    public static function table(Table $table): Table
    {
        $money = fn ($state, $record) => Fields::formatMoney($state, $record->currency);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['order.user', 'method', 'currency', 'collectedBy']))
            ->columns([
                TextColumn::make('order.order_number')->label(__('admin.fields.order_number'))->weight(FontWeight::SemiBold)
                    ->description(fn ($record) => $record->order?->user?->fullName())
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('order', fn ($o) => $o->where('order_number', 'like', "%{$search}%"))
                        ->orWhereHas('order.user', fn ($u) => $u->where('phone', 'like', "%{$search}%")->orWhere('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"))),
                TextColumn::make('method.name')->label(__('admin.table.method'))
                    ->description(fn ($record) => $record->collectedBy ? __('admin.table.by', ['name' => $record->collectedBy->name]) : null)
                    ->visibleFrom('md'),
                TextColumn::make('amount')->label(__('admin.fields.amount'))->weight(FontWeight::SemiBold)->formatStateUsing($money)
                    ->description(fn ($record) => collect([
                        (float) $record->collected_amount > 0 && $record->collected_amount != $record->amount ? __('admin.table.collected_note', ['amount' => $money($record->collected_amount, $record)]) : null,
                        (float) $record->reversed_amount > 0 ? __('admin.table.reversed_note', ['amount' => $money($record->reversed_amount, $record)]) : null,
                        (float) $record->refunded_amount > 0 ? __('admin.table.refunded_note', ['amount' => $money($record->refunded_amount, $record)]) : null,
                    ])->filter()->implode(' · ') ?: null),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()->formatStateUsing(fn ($state) => __("admin.payments.status_{$state}"))
                    ->color(fn ($state) => match ($state) {
                        OrderPayment::STATUS_PAID => 'success',
                        OrderPayment::STATUS_PENDING => 'warning',
                        OrderPayment::STATUS_FAILED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('paid_at')->label(__('admin.fields.paid_at'))->dateTime()->placeholder('—')->sortable()->color('gray')->visibleFrom('lg'),
            ])
            ->searchPlaceholder(__('admin.table.search_payments'))
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->options(self::statusOptions())->multiple(),
                SelectFilter::make('payment_method_id')->label(__('admin.fields.payment_method'))->relationship('method', 'code')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name),
                SelectFilter::make('currency_id')->label(__('admin.fields.currency'))->relationship('currency', 'code'),
                Filter::make('paid_between')->label(__('admin.table.paid_between'))
                    ->schema([
                        DatePicker::make('from')->label(__('admin.fields.from')),
                        DatePicker::make('until')->label(__('admin.fields.until')),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('paid_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('paid_at', '<=', $d))),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListOrderPayments::route('/')];
    }
}
