<?php

namespace App\Filament\Resources\AdminAuditLogs;

use App\Filament\Resources\AdminAuditLogs\Pages\ListAdminAuditLogs;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Readable;
use App\Models\AdminAuditLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * WAFFRLI (2026-10-31). Append-only audit trail of admin operations
 * (read-only).
 */
class AdminAuditLogResource extends AdminResource
{
    protected static ?string $model = AdminAuditLog::class;

    protected static string $labelKey = 'audit_log';

    protected static string|\UnitEnum|null $navigationGroup = 'system';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?int $navigationSort = 30;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime()->sortable(),
                TextColumn::make('admin.name')->label(__('admin.resources.admin.singular'))->placeholder(__('admin.audit.system')),
                // UX redesign round 2: plain words instead of keys and JSON;
                // the raw key stays visible as a tooltip for support staff.
                TextColumn::make('action')->label(__('admin.fields.action'))->badge()->color('gray')->searchable()
                    ->formatStateUsing(fn ($state) => Readable::action($state))->tooltip(fn (AdminAuditLog $record) => $record->action),
                TextColumn::make('auditable_type')->label(__('admin.fields.subject'))
                    ->formatStateUsing(fn ($state, AdminAuditLog $record) => Readable::model($state, $record->auditable_id)),
                TextColumn::make('properties')->label(__('admin.fields.details'))
                    ->state(fn (AdminAuditLog $record) => Readable::properties($record->properties))
                    ->limit(90)->tooltip(fn (AdminAuditLog $record) => Readable::properties($record->properties))->wrap()->placeholder('—'),
                TextColumn::make('ip_address')->label(__('admin.fields.ip_address'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('admin_id')->label(__('admin.resources.admin.singular'))->relationship('admin', 'name'),
                SelectFilter::make('action')->label(__('admin.fields.action'))
                    ->options(fn () => AdminAuditLog::query()->distinct()->orderBy('action')->pluck('action', 'action')->map(fn ($a) => Readable::action($a))->all()),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListAdminAuditLogs::route('/')];
    }
}
