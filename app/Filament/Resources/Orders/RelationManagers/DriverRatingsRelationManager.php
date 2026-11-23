<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Filament\Concerns\RefreshesOnOrderEvents;
use App\Filament\Resources\DriverRatings\DriverRatingResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/** The separate delivery-driver rating attached to this order. */
class DriverRatingsRelationManager extends RelationManager
{
    use RefreshesOnOrderEvents;

    protected static string $relationship = 'driverRatings';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-truck';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('order_driver_ratings.driver_singular');
    }

    public function table(Table $table): Table
    {
        return DriverRatingResource::table($table)
            ->emptyStateHeading(__('order_driver_ratings.no_driver_rating'));
    }
}
