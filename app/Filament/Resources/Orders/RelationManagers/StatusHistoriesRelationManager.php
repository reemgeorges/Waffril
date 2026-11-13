<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Enums\OrderStatus;
use App\Filament\Concerns\TranslatedRelationLabels;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Append-only status trail (read-only). */
class StatusHistoriesRelationManager extends RelationManager
{
    use TranslatedRelationLabels;

    protected static string $relationLabelKey = 'status_change';

    protected static string $relationship = 'statusHistories';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.orders.status_history');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    /**
     * Order → Status history (table UX overhaul 2026-11-12): a timeline —
     * the new status with the previous one under it, who did it (and which
     * driver), the note, and when.
     */
    public function table(Table $table): Table
    {
        $label = fn ($state) => $state ? (OrderStatus::tryFrom($state)?->label() ?? $state) : '—';

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('driver'))
            ->columns([
                TextColumn::make('to_status')->label(__('admin.fields.to_status'))->badge()
                    ->formatStateUsing($label)
                    ->color(fn ($state) => OrderStatus::tryFrom((string) $state)?->color() ?? 'gray')
                    ->description(fn ($record) => $record->from_status ? __('admin.table.from_status', ['status' => $label($record->from_status)]) : null),
                TextColumn::make('actor_type')->label(__('admin.fields.actor'))
                    ->formatStateUsing(fn ($state, $record) => __("admin.orders.actor_{$state}").($record->actor_id ? ' #'.$record->actor_id : ''))
                    ->description(fn ($record) => $record->driver?->name),
                TextColumn::make('note')->label(__('admin.fields.note'))->placeholder('—')->wrap()->visibleFrom('md'),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime()->color('gray'),
            ])
            ->defaultSort('id');
    }
}
