<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Filament\Concerns\RefreshesOnOrderEvents;
use App\Filament\Resources\OrderRatings\OrderRatingResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/** The single overall rating for this order, shown as a read-only/moderatable table. */
class OrderRatingsRelationManager extends RelationManager
{
    use RefreshesOnOrderEvents;

    protected static string $relationship = 'orderRatings';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-star';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('order_driver_ratings.order_singular');
    }

    public function table(Table $table): Table
    {
        return OrderRatingResource::table($table)
            ->emptyStateHeading(__('order_driver_ratings.no_order_rating'));
    }
}
