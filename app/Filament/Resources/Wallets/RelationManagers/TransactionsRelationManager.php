<?php

namespace App\Filament\Resources\Wallets\RelationManagers;

use App\Filament\Concerns\TranslatedRelationLabels;
use App\Filament\Resources\WalletTransactions\WalletTransactionResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/** Append-only ledger of this wallet (read-only). */
class TransactionsRelationManager extends RelationManager
{
    use TranslatedRelationLabels;

    protected static string $relationLabelKey = 'wallet_transaction';

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
