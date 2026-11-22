<?php

namespace App\Filament\Resources\Drivers\RelationManagers;

use App\Filament\Resources\DriverRatings\DriverRatingResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class RatingsRelationManager extends RelationManager
{
    protected static string $relationship = 'ratings';
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
