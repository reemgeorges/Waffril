<?php

namespace App\Filament\Resources\Currencies\Pages;

use App\Filament\Resources\Currencies\CurrencyResource;
use App\Filament\Support\BusinessAction;
use App\Models\Currency;
use App\Services\Currency\CurrencyManagement;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCurrencies extends ManageRecords
{
    protected static string $resource = CurrencyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->using(fn (array $data) => BusinessAction::run(
                fn ($admin) => app(CurrencyManagement::class)->create($data, $admin)
            ) ?? new Currency),
        ];
    }
}
