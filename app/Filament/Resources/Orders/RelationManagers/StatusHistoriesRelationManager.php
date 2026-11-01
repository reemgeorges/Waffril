<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Enums\OrderStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Append-only status trail (read-only). */
class StatusHistoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'statusHistories';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.orders.status_history');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $label = fn ($state) => $state ? (OrderStatus::tryFrom($state)?->label() ?? $state) : '—';

        return $table
            ->columns([
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime(),
                TextColumn::make('from_status')->label(__('admin.fields.from_status'))->formatStateUsing($label),
                TextColumn::make('to_status')->label(__('admin.fields.to_status'))->formatStateUsing($label)->badge(),
                TextColumn::make('actor_type')->label(__('admin.fields.actor'))
                    ->formatStateUsing(fn ($state, $record) => __("admin.orders.actor_{$state}").($record->actor_id ? ' #'.$record->actor_id : '')),
                TextColumn::make('driver.name')->label(__('admin.fields.driver'))->placeholder('—'),
                TextColumn::make('note')->label(__('admin.fields.note'))->placeholder('—'),
            ])
            ->defaultSort('id');
    }
}
