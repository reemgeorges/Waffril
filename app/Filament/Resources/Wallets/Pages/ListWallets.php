<?php

namespace App\Filament\Resources\Wallets\Pages;

use App\Filament\Concerns\RefreshesOnOperationalEvents;
use App\Filament\Resources\Wallets\WalletResource;
use Filament\Resources\Pages\ListRecords;

class ListWallets extends ListRecords
{
    use RefreshesOnOperationalEvents;

    protected static string $resource = WalletResource::class;
}
