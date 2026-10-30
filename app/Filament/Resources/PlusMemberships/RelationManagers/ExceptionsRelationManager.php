<?php

namespace App\Filament\Resources\PlusMemberships\RelationManagers;

use App\Filament\Resources\PlusPlans\RelationManagers\StoreDiscountsRelationManager;
use App\Filament\Support\BusinessAction;
use App\Models\Store;
use App\Services\Plus\PlusService;
use App\Support\Auth\AdminPermissions;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Admin-approved per-member exceptions: a different discount for a store,
 * or exclusion of a store. Saved through PlusService (records the
 * approving admin) and applied from the next confirmed cycle.
 */
class ExceptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'exceptions';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.plus.exceptions');
    }

    public function table(Table $table): Table
    {
        $can = fn () => AdminPermissions::currentAllows('plus.approve');

        return $table
            ->columns([
                TextColumn::make('store.name')->label(__('admin.fields.store')),
                IconColumn::make('is_excluded')->label(__('admin.fields.is_excluded'))->boolean(),
                TextColumn::make('discount_value')->label(__('admin.fields.discount_value'))->placeholder('—')
                    ->formatStateUsing(fn ($state, $record) => $record->discount_type === 'percent' ? $state.'%' : $state),
                TextColumn::make('max_discount_amount')->label(__('admin.fields.max_discount_amount'))->placeholder('—'),
                TextColumn::make('min_order_amount')->label(__('admin.fields.min_order_amount'))->placeholder('—'),
                TextColumn::make('approvedBy.name')->label(__('admin.fields.approved_by'))->placeholder('—'),
                TextColumn::make('note')->label(__('admin.fields.note'))->placeholder('—'),
            ])
            ->headerActions([
                Action::make('saveException')->label(__('admin.plus.add_exception'))->icon('heroicon-o-plus')
                    ->visible($can)
                    ->schema([
                        Select::make('store_id')->label(__('admin.fields.store'))->required()->searchable()
                            ->options(fn () => Store::all()->mapWithKeys(fn ($s) => [$s->id => $s->name])),
                        Toggle::make('is_excluded')->label(__('admin.fields.is_excluded'))->live(),
                        Group::make(StoreDiscountsRelationManager::discountFields())->visible(fn (Get $get) => ! $get('is_excluded')),
                        TextInput::make('note')->label(__('admin.fields.note'))->required()->maxLength(255),
                    ])
                    ->action(fn (array $data) => BusinessAction::run(fn ($admin) => app(PlusService::class)
                        ->saveException($this->getOwnerRecord(), Store::findOrFail($data['store_id']), $data, $admin))),
            ])
            ->recordActions([
                DeleteAction::make()->visible($can)->authorize($can),
            ]);
    }
}
