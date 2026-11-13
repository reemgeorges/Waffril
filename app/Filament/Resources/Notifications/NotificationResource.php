<?php

namespace App\Filament\Resources\Notifications;

use App\Filament\Resources\AdminAuditLogs\AdminAuditLogResource;
use App\Filament\Resources\Notifications\Pages\ListNotifications;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Readable;
use App\Filament\Support\TableDefaults;
use App\Models\Admin;
use App\Models\Driver;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Notifications\DatabaseNotification;

/**
 * WAFFRLI (2026-10-31). Persisted database notifications (existing
 * `notifications` table) — read-only. 2026-11-03: the same rows are what
 * customers/drivers see in their apps (also delivered live over Reverb and
 * by FCM push when configured); admins' own alerts appear in the bell.
 * Marketing messages are sent from MarketingBroadcastResource.
 */
class NotificationResource extends AdminResource
{
    protected static ?string $model = DatabaseNotification::class;

    protected static ?string $navParent = AdminAuditLogResource::class;

    protected static string $labelKey = 'notification';

    protected static string|\UnitEnum|null $navigationGroup = 'system';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bell';

    protected static ?int $navigationSort = 31;

    protected static ?string $slug = 'notifications';

    public static function table(Table $table): Table
    {
        return $table
            ->description(__('admin.notifications_page.notice'))
            ->columns([
                // Table UX overhaul (2026-11-12): the type with its recipient,
                // the text, read / unread as words, and when.
                TextColumn::make('type')->label(__('admin.fields.type'))->badge()->color('gray')
                    ->formatStateUsing(fn ($state) => Readable::notificationType($state))
                    ->description(fn ($record) => Readable::model($record->notifiable_type, $record->notifiable_id)),
                TextColumn::make('data')->label(__('admin.fields.details'))
                    ->state(fn ($record) => Readable::notificationText($record->data))->limit(90)->wrap(),
                TextColumn::make('read_at')->label(__('admin.fields.read'))->badge()
                    ->state(fn ($record) => $record->read_at !== null ? 'read' : 'unread')
                    ->formatStateUsing(fn ($state) => __('admin.table.'.$state))
                    ->color(fn ($state) => $state === 'read' ? 'gray' : 'info')
                    ->icon(fn ($state) => $state === 'read' ? 'heroicon-m-envelope-open' : 'heroicon-m-envelope'),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->since()->sortable()->color('gray')
                    ->tooltip(fn ($record) => $record->created_at?->translatedFormat(TableDefaults::dateTimeFormat())),
            ])
            ->filters([
                // e.g. driver.problem_reported isolates delivery problem reports.
                SelectFilter::make('type')->label(__('admin.fields.type'))
                    ->options(fn () => DatabaseNotification::query()->distinct()->orderBy('type')->pluck('type', 'type')->map(fn ($t) => Readable::notificationType($t))->all()),
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
