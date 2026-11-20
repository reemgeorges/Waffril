<?php

namespace App\Filament\Resources\MarketingBroadcasts;

use App\Filament\Resources\MarketingBroadcasts\Pages\ListMarketingBroadcasts;
use App\Filament\Support\AdminResource;
use App\Filament\Support\RowActions;
use App\Filament\Support\TableDefaults;
use App\Models\MarketingBroadcast;
use App\Support\Time\AdminTimezone;
use Filament\Actions\Action;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Marketing history: compact overview with delivery diagnostics in a read-only
 * modal. UI-only: does not change marketing job, counters or delivery semantics.
 */
class MarketingBroadcastResource extends AdminResource
{
    protected static ?string $model = MarketingBroadcast::class;

    protected static string|\UnitEnum|null $navigationGroup = 'content';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-megaphone';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'marketing-messages';

    public static function getModelLabel(): string
    {
        return __('admin.marketing.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.marketing.plural');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description(__('marketing_table.notice_compact'))
            ->modifyQueryUsing(fn ($query) => $query->with(['city', 'admin']))
            // Keep the existing live-refresh behavior unchanged.
            ->poll(fn () => MarketingBroadcast::query()->whereIn('status', [MarketingBroadcast::STATUS_QUEUED, MarketingBroadcast::STATUS_SENDING])->exists()
                || MarketingBroadcast::query()->where('created_at', '>=', now()->subDay())
                    ->whereRaw('push_queued_count > push_accepted_count + push_invalid_count + push_skipped_count + push_failed_count')->exists() ? '5s' : null)
            ->columns([
                TextColumn::make('title')->label(__('admin.fields.title'))->limit(48)->searchable()->weight(FontWeight::SemiBold)
                    ->description(fn (MarketingBroadcast $record) => AdminTimezone::format($record->created_at, TableDefaults::dateTimeFormat()))
                    ->tooltip(fn (MarketingBroadcast $record) => $record->title),
                TextColumn::make('audience')->label(__('admin.marketing.audience'))
                    ->state(fn (MarketingBroadcast $record) => __('admin.marketing.recipients_'.($record->recipient_type ?: MarketingBroadcast::RECIPIENTS_CUSTOMERS)))
                    ->description(fn (MarketingBroadcast $record) => $record->city?->name ?? __('admin.marketing.scope_all')),
                TextColumn::make('status')->label(__('admin.marketing.status'))->badge()
                    ->formatStateUsing(fn ($state) => __('admin.marketing.status_'.$state))
                    ->icon(fn ($state) => match ($state) {
                        MarketingBroadcast::STATUS_SENT => 'heroicon-m-check-circle',
                        MarketingBroadcast::STATUS_FAILED => 'heroicon-m-exclamation-circle',
                        MarketingBroadcast::STATUS_SENDING => 'heroicon-m-arrow-path',
                        default => 'heroicon-m-clock',
                    })
                    ->color(fn ($state) => match ($state) {
                        MarketingBroadcast::STATUS_SENT => 'success',
                        MarketingBroadcast::STATUS_FAILED => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('recipients_count')->label(__('admin.marketing.recipients'))
                    ->formatStateUsing(fn ($state, MarketingBroadcast $record) =>
                        in_array($record->status, [MarketingBroadcast::STATUS_QUEUED, MarketingBroadcast::STATUS_SENDING], true)
                            ? ($record->total_count !== null
                                ? __('admin.marketing.progress', [
                                    'done' => number_format((int) $record->processed_count),
                                    'total' => number_format((int) $record->total_count),
                                ])
                                : '—')
                            : number_format((int) $state))
                    ->alignCenter()->width(TableDefaults::COMPACT_WIDTH),
                TextColumn::make('stored_count')->label(__('marketing_table.in_app_short'))
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format((int) $state))
                    ->alignCenter()->width(TableDefaults::COMPACT_WIDTH)->visibleFrom('md'),
                TextColumn::make('push_accepted_count')->label(__('marketing_table.firebase_short'))
                    ->state(fn (MarketingBroadcast $record) => $record->push_enabled && $record->stored_count !== null
                        ? $record->push_accepted_count : null)
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format((int) $state))
                    ->tooltip(__('admin.marketing.accepted_help'))
                    ->alignCenter()->width(TableDefaults::COMPACT_WIDTH)->visibleFrom('lg'),
                TextColumn::make('admin.name')->label(__('admin.marketing.sent_by'))
                    ->color('gray')->visibleFrom('xl'),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                RowActions::primary(
                    Action::make('details')
                        ->label(__('admin.fields.details'))
                        ->icon('heroicon-m-eye')
                        ->modalHeading(fn (MarketingBroadcast $record) => $record->title)
                        ->modalWidth('2xl')
                        ->modalContent(fn (MarketingBroadcast $record) => view('filament.marketing-broadcast-details', ['broadcast' => $record]))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel(__('admin.actions.close'))
                ),
            ])
            ->searchPlaceholder(__('admin.table.search_broadcasts'))
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListMarketingBroadcasts::route('/')];
    }
}
