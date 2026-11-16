<?php

namespace App\Filament\Resources\WalletTransactions\Pages;

use App\Filament\Concerns\RefreshesOnOperationalEvents;
use App\Filament\Resources\WalletTransactions\WalletTransactionResource;
use Filament\Resources\Pages\ListRecords;

class ListWalletTransactions extends ListRecords
{
    use RefreshesOnOperationalEvents;

    protected static string $resource = WalletTransactionResource::class;
}
