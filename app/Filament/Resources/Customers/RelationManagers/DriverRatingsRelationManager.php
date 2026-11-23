<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\DriverRatings\DriverRatingResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class DriverRatingsRelationManager extends RelationManager
{
    protected static string $relationship = 'driverRatings';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-star';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('order_driver_ratings.driver_plural');
    }

    public function table(Table $table): Table
    {
        return DriverRatingResource::table($table);
    }
}
