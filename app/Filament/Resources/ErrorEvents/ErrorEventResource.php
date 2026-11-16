<?php

namespace App\Filament\Resources\ErrorEvents;

use App\Filament\Resources\ErrorEvents\Pages\ListErrorEvents;
use App\Filament\Support\AdminResource;
use App\Filament\Support\BusinessAction;
use App\Filament\Support\TableDefaults;
use App\Models\ErrorEvent;
use App\Services\Audit\AdminAudit;
use App\Support\Auth\AdminPermissions;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * WAFFRLI (2026-11-16, Phase 9) — Monitoring → Errors. Grouped, scrubbed
 * application errors written by App\Services\Monitoring\ErrorMonitor.
 * errors.view reads; errors.manage resolves, reopens or ignores (audited).
 * Search by request id finds the group behind a user's report: the
 * X-Request-Id response header.
 */
class ErrorEventResource extends AdminResource
{
    protected static ?string $model = ErrorEvent::class;

    protected static string $labelKey = 'error_event';

    protected static string|\UnitEnum|null $navigationGroup = 'system';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bug-ant';

    protected static ?int $navigationSort = 35;

    public static function getNavigationBadge(): ?string
    {
        $open = ErrorEvent::query()->where('status', ErrorEvent::STATUS_OPEN)->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                TextEntry::make('exception_class')->label(__('admin.errors_monitor.exception'))->columnSpanFull()->copyable(),
                TextEntry::make('message')->label(__('admin.errors_monitor.message'))->columnSpanFull(),
                TextEntry::make('location')->label(__('admin.errors_monitor.location'))->state(fn (ErrorEvent $record) => $record->file ? $record->file.':'.$record->line : null)->copyable(),
                TextEntry::make('status')->label(__('admin.fields.status'))->badge()->formatStateUsing(fn ($state) => __('admin.errors_monitor.status_'.$state)),
                TextEntry::make('occurrences')->label(__('admin.errors_monitor.occurrences'))->numeric(),
                TextEntry::make('environment')->label(__('admin.errors_monitor.environment')),
                TextEntry::make('first_seen_at')->label(__('admin.errors_monitor.first_seen'))->dateTime(TableDefaults::dateTimeFormat()),
                TextEntry::make('last_seen_at')->label(__('admin.errors_monitor.last_seen'))->dateTime(TableDefaults::dateTimeFormat()),
                TextEntry::make('regressed_at')->label(__('admin.errors_monitor.regressed'))->dateTime(TableDefaults::dateTimeFormat())->placeholder('—'),
                TextEntry::make('resolvedBy.name')->label(__('admin.errors_monitor.resolved_by'))->placeholder('—'),
                TextEntry::make('last_request_id')->label(__('admin.errors_monitor.request_id'))->copyable()->placeholder('—'),
                TextEntry::make('last_request')->label(__('admin.errors_monitor.request'))->state(fn (ErrorEvent $record) => trim(($record->last_method ?? '').' '.($record->last_path ?? '')) ?: null)->placeholder('—'),
                TextEntry::make('last_route')->label(__('admin.errors_monitor.route'))->placeholder('—'),
                TextEntry::make('last_actor')->label(__('admin.errors_monitor.actor'))->placeholder('—'),
                TextEntry::make('trace')->label(__('admin.errors_monitor.trace'))->columnSpanFull()
                    ->formatStateUsing(fn ($state) => new HtmlString('<pre dir="ltr" style="white-space:pre-wrap;font-size:12px;max-height:24rem;overflow:auto">'.e((string) $state).'</pre>')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $manage = fn () => AdminPermissions::currentAllows('errors.manage');
        $setStatus = fn (ErrorEvent $record, string $status, string $audit) => BusinessAction::run(function ($admin) use ($record, $status, $audit) {
            $record->forceFill([
                'status' => $status,
                'resolved_at' => $status === ErrorEvent::STATUS_OPEN ? null : now(),
                'resolved_by_admin_id' => $status === ErrorEvent::STATUS_OPEN ? null : $admin->id,
            ])->save();
            AdminAudit::log($audit, $record, ['exception' => $record->exception_class], $admin);
        });

        return $table
            ->description(__('admin.errors_monitor.notice'))
            ->modifyQueryUsing(fn ($query) => $query->with('resolvedBy'))
            ->columns([
                TextColumn::make('exception_class')->label(__('admin.errors_monitor.exception'))
                    ->formatStateUsing(fn ($state) => class_basename($state))->tooltip(fn (ErrorEvent $record) => $record->exception_class)
                    ->description(fn (ErrorEvent $record) => Str::limit($record->message, 110))
                    ->wrap()->searchable(['exception_class', 'message', 'last_request_id', 'file']),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()
                    ->formatStateUsing(fn ($state, ErrorEvent $record) => __('admin.errors_monitor.status_'.$state).($record->regressed_at && $state === ErrorEvent::STATUS_OPEN ? ' · '.__('admin.errors_monitor.regressed') : ''))
                    ->color(fn ($state) => match ($state) {
                        'open' => 'danger', 'resolved' => 'success', default => 'gray'
                    }),
                TextColumn::make('occurrences')->label(__('admin.errors_monitor.occurrences'))->numeric()->sortable()->alignEnd(),
                TextColumn::make('last_seen_at')->label(__('admin.errors_monitor.last_seen'))->since()->sortable()
                    ->tooltip(fn (ErrorEvent $record) => $record->last_seen_at?->translatedFormat(TableDefaults::dateTimeFormat())),
                TextColumn::make('last_path')->label(__('admin.errors_monitor.request'))->state(fn (ErrorEvent $record) => trim(($record->last_method ?? '').' '.($record->last_path ?? '')))
                    ->limit(50)->color('gray')->visibleFrom('lg'),
                TextColumn::make('first_seen_at')->label(__('admin.errors_monitor.first_seen'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->default(ErrorEvent::STATUS_OPEN)
                    ->options(collect(ErrorEvent::STATUSES)->mapWithKeys(fn ($s) => [$s => __('admin.errors_monitor.status_'.$s)])),
                SelectFilter::make('exception_class')->label(__('admin.errors_monitor.exception'))
                    ->options(fn () => ErrorEvent::query()->distinct()->orderBy('exception_class')->pluck('exception_class', 'exception_class')->map(fn ($c) => class_basename($c))->all())
                    ->searchable(),
            ])
            ->recordActions([
                ViewAction::make()->slideOver(),
                Action::make('resolve')->label(__('admin.errors_monitor.resolve'))->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (ErrorEvent $record) => $manage() && $record->status !== ErrorEvent::STATUS_RESOLVED)
                    ->action(fn (ErrorEvent $record) => $setStatus($record, ErrorEvent::STATUS_RESOLVED, 'errors.resolved')),
                Action::make('ignore')->label(__('admin.errors_monitor.ignore'))->icon('heroicon-o-eye-slash')->color('gray')
                    ->visible(fn (ErrorEvent $record) => $manage() && $record->status === ErrorEvent::STATUS_OPEN)
                    ->requiresConfirmation()->modalDescription(__('admin.errors_monitor.ignore_help'))
                    ->action(fn (ErrorEvent $record) => $setStatus($record, ErrorEvent::STATUS_IGNORED, 'errors.ignored')),
                Action::make('reopen')->label(__('admin.errors_monitor.reopen'))->icon('heroicon-o-arrow-path')->color('warning')
                    ->visible(fn (ErrorEvent $record) => $manage() && $record->status !== ErrorEvent::STATUS_OPEN)
                    ->action(fn (ErrorEvent $record) => $setStatus($record, ErrorEvent::STATUS_OPEN, 'errors.reopened')),
            ])
            ->searchPlaceholder(__('admin.errors_monitor.search'))
            ->defaultSort('last_seen_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListErrorEvents::route('/')];
    }
}
