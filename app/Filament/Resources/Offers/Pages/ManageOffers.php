<?php

namespace App\Filament\Resources\Offers\Pages;

use App\Filament\Resources\Offers\OfferResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageOffers extends ManageRecords
{
    protected static string $resource = OfferResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->mutateDataUsing(fn (array $data) => $data + ['created_by_admin_id' => auth('admin_web')->id()])];
    }
}
