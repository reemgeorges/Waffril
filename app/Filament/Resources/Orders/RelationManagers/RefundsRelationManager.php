<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Filament\Resources\OrderRefunds\OrderRefundResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/** Refunds issued for this order (read-only). */
class RefundsRelationManager extends RelationManager
{
    protected static string $relationship = 'refunds';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.resources.order_refund.plural');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return OrderRefundResource::table($table);
    }
}
