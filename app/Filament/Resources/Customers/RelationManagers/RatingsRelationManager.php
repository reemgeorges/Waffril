<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Concerns\TranslatedRelationLabels;
use App\Filament\Resources\ProductRatings\ProductRatingResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/**
 * Customer → Ratings (UX overhaul 2026-11-12): the ratings this customer
 * wrote, with the same moderation actions and permissions as everywhere.
 */
class RatingsRelationManager extends RelationManager
{
    use TranslatedRelationLabels;

    protected static string $relationLabelKey = 'rating';

    protected static string $relationship = 'productRatings';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-star';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.resources.product_rating.plural');
    }

    public function table(Table $table): Table
    {
        return ProductRatingResource::table($table)
            ->emptyStateHeading(__('admin.ratings.none_by_customer'));
    }
}
