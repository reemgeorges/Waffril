<?php

namespace App\Filament\Resources\Stores\RelationManagers;

use App\Filament\Resources\Branches\BranchResource;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BranchesRelationManager extends RelationManager
{
    protected static string $relationship = 'branches';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.resources.branch.plural');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('admin.fields.name')),
                TextColumn::make('city.name')->label(__('admin.fields.city')),
                IconColumn::make('is_active')->label(__('admin.fields.is_active'))->boolean(),
                IconColumn::make('is_accepting_orders')->label(__('admin.fields.is_accepting_orders'))->boolean(),
            ])
            ->recordActions([
                Action::make('open')->label(__('admin.actions.open'))->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn ($record) => BranchResource::getUrl('edit', ['record' => $record])),
            ]);
    }
}
