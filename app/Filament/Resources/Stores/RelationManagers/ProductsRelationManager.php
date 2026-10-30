<?php

namespace App\Filament\Resources\Stores\RelationManagers;

use App\Filament\Resources\Products\ProductResource;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.resources.product.plural');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image')->label(__('admin.fields.image'))->disk('public'),
                TextColumn::make('name')->label(__('admin.fields.name'))->searchable(),
                IconColumn::make('is_active')->label(__('admin.fields.is_active'))->boolean(),
            ])
            ->recordActions([
                Action::make('open')->label(__('admin.actions.open'))->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn ($record) => ProductResource::getUrl('edit', ['record' => $record])),
            ]);
    }
}
