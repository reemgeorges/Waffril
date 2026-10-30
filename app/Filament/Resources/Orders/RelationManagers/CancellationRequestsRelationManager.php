<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Filament\Resources\OrderCancellationRequests\OrderCancellationRequestResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/** Customer cancellation requests for this order, with approve/reject. */
class CancellationRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'cancellationRequests';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.resources.order_cancellation_request.plural');
    }

    public function table(Table $table): Table
    {
        return OrderCancellationRequestResource::table($table)->filters([]);
    }
}
