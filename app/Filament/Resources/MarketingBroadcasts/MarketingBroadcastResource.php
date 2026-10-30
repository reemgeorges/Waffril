<?php

namespace App\Filament\Resources\MarketingBroadcasts;

use App\Filament\Resources\MarketingBroadcasts\Pages\ListMarketingBroadcasts;
use App\Filament\Support\AdminResource;
use App\Models\MarketingBroadcast;
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

    protected static string|\UnitEnum|null $navigationGroup = 'system';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-megaphone';

    protected static ?int $navigationSort = 45;

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
            ->description(__('admin.marketing.notice'))
            ->columns([
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime()->sortable(),
                TextColumn::make('title')->label(__('admin.fields.name'))->limit(40),
                TextColumn::make('audience')->label(__('admin.marketing.audience'))
                    ->formatStateUsing(fn ($state, MarketingBroadcast $record) => __('admin.marketing.'.$state).($record->city ? ' — '.$record->city->name : '')),
                TextColumn::make('status')->label(__('admin.marketing.status'))->badge()
                    ->formatStateUsing(fn ($state) => __('admin.marketing.'.$state))
                    ->color(fn ($state) => match ($state) {
                        'sent' => 'success', 'failed' => 'danger', default => 'warning'
                    }),
                TextColumn::make('recipients_count')->label(__('admin.marketing.recipients'))->numeric(),
                TextColumn::make('admin.name')->label(__('admin.marketing.sent_by')),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListMarketingBroadcasts::route('/')];
    }
}
