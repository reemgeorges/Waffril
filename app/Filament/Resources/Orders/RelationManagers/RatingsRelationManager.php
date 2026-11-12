<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Enums\OrderStatus;
use App\Filament\Concerns\TranslatedRelationLabels;
use App\Filament\Resources\ProductRatings\ProductRatingResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Order → Ratings (UX overhaul 2026-11-12): ratings the customer left for
 * products of this delivered order (only delivered orders can be rated),
 * with the usual moderation actions.
 */
class RatingsRelationManager extends RelationManager
{
    use TranslatedRelationLabels;

    protected static string $relationLabelKey = 'rating';

    protected static string $relationship = 'ratings';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-star';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->status === OrderStatus::Delivered && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.resources.product_rating.plural');
    }

    public function table(Table $table): Table
    {
        return ProductRatingResource::table($table)
            ->emptyStateHeading(__('admin.ratings.none_for_order'));
    }
}
