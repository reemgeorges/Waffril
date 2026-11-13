<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Concerns\TranslatedRelationLabels;
use App\Filament\Resources\Wallets\WalletResource;
use App\Filament\Support\Fields;
use App\Filament\Support\RowActions;
use App\Filament\Support\TableDefaults;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Read-only wallet balances per currency (never editable). */
class WalletsRelationManager extends RelationManager
{
    use TranslatedRelationLabels;

    protected static string $relationLabelKey = 'wallet';

    protected static string $relationship = 'wallets';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.resources.wallet.plural');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    /**
     * Customer → Wallets (table UX overhaul 2026-11-12): one row per
     * currency, the balance in that currency (never converted), last
     * movement. "Movements" opens the wallet history.
     */
    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('currency'))
            ->columns([
                TextColumn::make('currency.code')->label(__('admin.fields.currency'))->badge()->color('gray')
                    ->description(fn ($record) => $record->currency?->name),
                TextColumn::make('balance')->label(__('admin.fields.balance'))->weight(FontWeight::SemiBold)
                    ->formatStateUsing(fn ($state, $record) => Fields::formatMoney($state, $record->currency)),
                TextColumn::make('updated_at')->label(__('admin.fields.updated_at'))->since()->color('gray')
                    ->tooltip(fn ($record) => $record->updated_at?->translatedFormat(TableDefaults::dateTimeFormat())),
            ])
            ->recordActions([
                RowActions::primary(Action::make('history')->label(__('admin.table.history'))->icon('heroicon-m-list-bullet')
                    ->url(fn ($record) => WalletResource::getUrl('view', ['record' => $record]))),
            ]);
    }
}
