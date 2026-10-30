<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Models\OrderItem;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Snapshotted order lines with their selected options (read-only). */
class ItemsRelationManager extends RelationManager
{
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

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('options'))
            ->columns([
                TextColumn::make('product_name')->label(__('admin.fields.product'))->formatStateUsing(fn ($state) => self::translated($state)),
                TextColumn::make('options')->label(__('admin.products.options'))
                    ->state(fn (OrderItem $record) => $record->options->map(fn ($o) => self::translated($o->group_name).': '
                        .self::translated($o->option_name).' ×'.$o->quantity.' (+'.$o->total_price_delta.')')->implode(' · ') ?: '—'),
                TextColumn::make('unit_price')->label(__('admin.fields.unit_price')),
                TextColumn::make('quantity')->label(__('admin.fields.quantity')),
                TextColumn::make('offer_discount_amount')->label(__('admin.fields.offer_discount_amount')),
                TextColumn::make('line_total')->label(__('admin.fields.line_total')),
                TextColumn::make('notes')->label(__('admin.fields.note'))->placeholder('—'),
            ]);
    }
}
