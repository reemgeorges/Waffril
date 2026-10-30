<?php

namespace App\Filament\Resources\MarketingBroadcasts\Pages;

use App\Filament\Resources\MarketingBroadcasts\MarketingBroadcastResource;
use App\Filament\Support\BusinessAction;
use App\Models\City;
use App\Models\MarketingBroadcast;
use App\Services\Notifications\MarketingBroadcastService;
use App\Support\Auth\AdminPermissions;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;

class ListMarketingBroadcasts extends ListRecords
{
    protected static string $resource = MarketingBroadcastResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('send')->label(__('admin.marketing.singular'))->icon('heroicon-o-paper-airplane')
                ->visible(fn () => AdminPermissions::currentAllows('notifications.marketing'))
                ->requiresConfirmation()
                ->schema([
                    TextInput::make('title_en')->label(__('admin.marketing.title_en'))->required()->maxLength(120),
                    TextInput::make('title_ar')->label(__('admin.marketing.title_ar'))->required()->maxLength(120),
                    Textarea::make('body_en')->label(__('admin.marketing.body_en'))->required()->maxLength(500),
                    Textarea::make('body_ar')->label(__('admin.marketing.body_ar'))->required()->maxLength(500),
                    Select::make('audience')->label(__('admin.marketing.audience'))->required()->live()
                        ->default(MarketingBroadcast::AUDIENCE_ALL)
                        ->options([
                            MarketingBroadcast::AUDIENCE_ALL => __('admin.marketing.all_customers'),
                            MarketingBroadcast::AUDIENCE_CITY => __('admin.marketing.city'),
                        ]),
                    Select::make('city_id')->label(__('admin.fields.city'))
                        ->options(fn () => City::query()->where('is_active', true)->get()->mapWithKeys(fn (City $c) => [$c->id => $c->name]))
                        ->visible(fn ($get) => $get('audience') === MarketingBroadcast::AUDIENCE_CITY)
                        ->required(fn ($get) => $get('audience') === MarketingBroadcast::AUDIENCE_CITY),
                ])
                ->action(fn (array $data) => BusinessAction::run(fn ($admin) => app(MarketingBroadcastService::class)->create($admin, $data))),
        ];
    }
}
