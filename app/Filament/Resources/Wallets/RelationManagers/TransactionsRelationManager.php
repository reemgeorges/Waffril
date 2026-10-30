<?php

namespace App\Filament\Resources\Wallets\RelationManagers;

use App\Filament\Resources\WalletTransactions\WalletTransactionResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/** Append-only ledger of this wallet (read-only). */
class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.wallets.history');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return WalletTransactionResource::table($table);
    }
}
