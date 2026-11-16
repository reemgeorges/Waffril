<?php

namespace App\Filament\Resources\MarketingBroadcasts;

use App\Filament\Resources\MarketingBroadcasts\Pages\ListMarketingBroadcasts;
use App\Filament\Support\AdminResource;
use App\Filament\Support\TableDefaults;
use App\Models\MarketingBroadcast;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * WAFFRLI (2026-11-03) — admin marketing messages: history (who sent what
 * to which audience, how many recipients) and the permission-gated "send"
 * header action on the list page. Delivery: MarketingBroadcastService.
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
            ->description(__('admin.marketing.notice_v2'))
            ->modifyQueryUsing(fn ($query) => $query->with(['city', 'admin']))
            // Live progress: Reverb (marketing.progress on admin.activity) refreshes
            // the page; while a message is sending, a 5 s poll covers a missing socket.
            ->poll(fn () => MarketingBroadcast::query()->whereIn('status', [MarketingBroadcast::STATUS_QUEUED, MarketingBroadcast::STATUS_SENDING])->exists() ? '5s' : null)
            ->columns([
                // Table UX overhaul (2026-11-12): the message with when it was
                // created, audience, status, recipients, who sent it.
                TextColumn::make('title')->label(__('admin.fields.name'))->limit(60)->searchable()->weight(FontWeight::SemiBold)
                    ->description(fn (MarketingBroadcast $record) => $record->created_at?->translatedFormat(TableDefaults::dateTimeFormat())),
                TextColumn::make('audience')->label(__('admin.marketing.audience'))
                    ->formatStateUsing(fn ($state, MarketingBroadcast $record) => __('admin.marketing.recipients_'.($record->recipient_type ?: MarketingBroadcast::RECIPIENTS_CUSTOMERS))
                        .' — '.($record->city ? $record->city->name : __('admin.marketing.scope_all'))),
                TextColumn::make('status')->label(__('admin.marketing.status'))->badge()
                    ->formatStateUsing(fn ($state) => __('admin.marketing.'.$state))
                    ->color(fn ($state) => match ($state) {
                        'sent' => 'success', 'failed' => 'danger', default => 'warning'
                    }),
                TextColumn::make('recipients_count')->label(__('admin.marketing.recipients'))
                    ->formatStateUsing(fn ($state, MarketingBroadcast $record) => in_array($record->status, [MarketingBroadcast::STATUS_QUEUED, MarketingBroadcast::STATUS_SENDING], true) && $record->total_count !== null
                        ? __('admin.marketing.progress', ['done' => number_format($record->processed_count), 'total' => number_format($record->total_count)])
                        : trans_choice('admin.table.recipients_n', (int) $state, ['count' => (int) $state])),
                TextColumn::make('admin.name')->label(__('admin.marketing.sent_by'))->color('gray')->visibleFrom('md'),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->searchPlaceholder(__('admin.table.search_broadcasts'))
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListMarketingBroadcasts::route('/')];
    }
}
