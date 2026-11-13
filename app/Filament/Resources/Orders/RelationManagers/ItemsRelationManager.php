<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Filament\Concerns\TranslatedRelationLabels;
use App\Filament\Support\Fields;
use App\Models\OrderItem;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Snapshotted order lines with their selected options (read-only). */
class ItemsRelationManager extends RelationManager
{
    use TranslatedRelationLabels;

    protected static string $relationLabelKey = 'order_item';

    protected static string $relationship = 'items';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.orders.items');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    protected static function translated(mixed $value): string
    {
        return is_array($value) ? (string) ($value[app()->getLocale()] ?? reset($value) ?? '') : (string) $value;
    }

    /**
     * Order → Items (table UX overhaul 2026-11-12): the product with its
     * options and the customer's note, "quantity × unit price", the offer
     * discount under it, and the line total — all in the order's currency.
     * Read only (the order's snapshot).
     */
    public function table(Table $table): Table
    {
        $money = fn ($amount) => Fields::formatMoney($amount, $this->getOwnerRecord()->currency);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('options'))
            ->columns([
                TextColumn::make('product_name')->label(__('admin.fields.product'))->weight(FontWeight::SemiBold)->wrap()
                    ->formatStateUsing(fn ($state) => self::translated($state))
                    ->description(fn (OrderItem $record) => collect([
                        $record->options->map(fn ($o) => self::translated($o->group_name).': '
                            .self::translated($o->option_name).' ×'.$o->quantity.' (+'.Fields::formatDecimal($o->total_price_delta).')')->implode(' · '),
                        $record->notes ? '“'.$record->notes.'”' : null,
                    ])->filter()->implode(' — ') ?: null),
                TextColumn::make('quantity')->label(__('admin.fields.quantity'))
                    ->formatStateUsing(fn ($state, OrderItem $record) => __('admin.table.qty_x_price', ['qty' => (int) $state, 'price' => $money($record->unit_price)]))
                    ->description(fn (OrderItem $record) => (float) $record->offer_discount_amount > 0 ? __('admin.table.discount_note', ['amount' => $money($record->offer_discount_amount)]) : null)
                    ->alignEnd()->extraAttributes(['class' => 'wf-ltr wf-num']),
                TextColumn::make('line_total')->label(__('admin.table.line'))->weight(FontWeight::SemiBold)
                    ->formatStateUsing(fn ($state) => $money($state)),
            ]);
    }
}
