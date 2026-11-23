<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\OrderRatings\OrderRatingResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class OrderRatingsRelationManager extends RelationManager
{
    protected static string $relationship = 'orderRatings';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-star';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('order_driver_ratings.order_plural');
    }

    public function table(Table $table): Table
    {
        return OrderRatingResource::table($table);
    }
}
