<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Filament\Concerns\TranslatedRelationLabels;
use App\Filament\Resources\ProductRatings\ProductRatingResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/**
 * Ratings of this product with the same moderation actions as the
 * Product ratings resource.
 */
class RatingsRelationManager extends RelationManager
{
    use TranslatedRelationLabels;

    protected static string $relationLabelKey = 'rating';

    protected static string $relationship = 'ratings';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.resources.product_rating.plural');
    }

    public function table(Table $table): Table
    {
        return ProductRatingResource::table($table);
    }
}
