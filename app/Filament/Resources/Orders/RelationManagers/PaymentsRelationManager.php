<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Filament\Resources\OrderPayments\OrderPaymentResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/** Payments of this order (read-only). */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.resources.order_payment.plural');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return OrderPaymentResource::table($table)->filters([]);
    }
}
