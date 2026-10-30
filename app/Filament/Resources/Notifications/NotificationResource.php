<?php

namespace App\Filament\Resources\Notifications;

use App\Filament\Resources\Notifications\Pages\ListNotifications;
use App\Filament\Support\AdminResource;
use App\Models\Admin;
use App\Models\Driver;
use App\Models\User;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Notifications\DatabaseNotification;

/**
 * WAFFRLI (2026-10-31). Persisted database notifications (existing
 * `notifications` table) — read-only. No FCM push or real-time sending is
 * performed or simulated here: those integrations (FCM, Reverb) are not
 * installed in this phase.
 */
class NotificationResource extends AdminResource
{
    protected static ?string $model = DatabaseNotification::class;

    protected static string $labelKey = 'notification';

    protected static string|\UnitEnum|null $navigationGroup = 'system';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bell';

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'notifications';

    public static function table(Table $table): Table
    {
        return $table
            ->description(__('admin.notifications_page.notice'))
            ->columns([
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime()->sortable(),
                TextColumn::make('type')->label(__('admin.fields.type'))->formatStateUsing(fn ($state) => class_basename($state)),
                TextColumn::make('notifiable_type')->label(__('admin.fields.recipient'))
                    ->formatStateUsing(fn ($state, $record) => class_basename($state).' #'.$record->notifiable_id),
                TextColumn::make('data')->label(__('admin.fields.details'))
                    ->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_UNESCAPED_UNICODE) : (string) $state)->limit(80),
                IconColumn::make('read_at')->label(__('admin.fields.read'))->boolean()->state(fn ($record) => $record->read_at !== null),
            ])
            ->filters([
                SelectFilter::make('notifiable_type')->label(__('admin.fields.recipient'))->options([
                    User::class => __('admin.resources.customer.plural'),
                    Driver::class => __('admin.resources.driver.plural'),
                    Admin::class => __('admin.resources.admin.plural'),
                ]),
                TernaryFilter::make('read_at')->label(__('admin.fields.read'))->nullable(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListNotifications::route('/')];
    }
}
