<?php

namespace App\Filament\Resources\MarketingBroadcasts\Pages;

use App\Filament\Concerns\RefreshesOnOperationalEvents;
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
    use RefreshesOnOperationalEvents;

    protected static string $resource = MarketingBroadcastResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('send')->label(__('admin.marketing.singular'))->icon('heroicon-o-paper-airplane')
                ->visible(fn () => AdminPermissions::currentAllows('notifications.marketing'))
                ->requiresConfirmation()
                ->schema([
                    TextInput::make('title.ar')->label(__('admin.marketing.title_ar'))->required()->maxLength(120)->extraInputAttributes(['dir' => 'rtl']),
                    TextInput::make('title.en')->label(__('admin.marketing.title_en'))->required()->maxLength(120)->extraInputAttributes(['dir' => 'ltr']),
                    Textarea::make('body.ar')->label(__('admin.marketing.body_ar'))->required()->maxLength(500)->extraInputAttributes(['dir' => 'rtl']),
                    Textarea::make('body.en')->label(__('admin.marketing.body_en'))->required()->maxLength(500)->extraInputAttributes(['dir' => 'ltr']),
                    Select::make('recipient_type')->label(__('admin.marketing.recipient_type'))->required()
                        ->default(MarketingBroadcast::RECIPIENTS_CUSTOMERS)
                        ->options(collect(MarketingBroadcast::RECIPIENT_TYPES)->mapWithKeys(fn ($type) => [$type => __('admin.marketing.recipients_'.$type)])),
                    Select::make('audience')->label(__('admin.marketing.scope'))->required()->live()
                        ->default(MarketingBroadcast::AUDIENCE_ALL)
                        ->options([
                            MarketingBroadcast::AUDIENCE_ALL => __('admin.marketing.scope_all'),
                            MarketingBroadcast::AUDIENCE_CITY => __('admin.marketing.scope_city'),
                        ]),
                    Select::make('city_id')->label(__('admin.fields.city'))
                        ->helperText(__('admin.marketing.city_help'))
                        ->options(fn () => City::query()->where('is_active', true)->get()->mapWithKeys(fn (City $c) => [$c->id => $c->name]))
                        ->searchable()
                        ->visible(fn ($get) => $get('audience') === MarketingBroadcast::AUDIENCE_CITY)
                        ->required(fn ($get) => $get('audience') === MarketingBroadcast::AUDIENCE_CITY),
                ])
                ->action(fn (array $data) => BusinessAction::run(fn ($admin) => app(MarketingBroadcastService::class)->create($admin, $data))),
        ];
    }
}
