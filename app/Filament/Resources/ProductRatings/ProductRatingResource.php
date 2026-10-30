<?php

namespace App\Filament\Resources\ProductRatings;

use App\Filament\Resources\ProductRatings\Pages\ListProductRatings;
use App\Filament\Support\AdminResource;
use App\Filament\Support\BusinessAction;
use App\Models\ProductRating;
use App\Services\Catalog\ProductRatingService;
use App\Support\Auth\AdminPermissions;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * WAFFRLI (2026-10-31). Product rating moderation. Hidden ratings are
 * removed from the product's public average; comments are never shown to
 * customers. Ratings cannot be edited or deleted by admins.
 */
class ProductRatingResource extends AdminResource
{
    protected static ?string $model = ProductRating::class;

    protected static string $labelKey = 'product_rating';

    protected static string|\UnitEnum|null $navigationGroup = 'catalog';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-star';

    protected static ?int $navigationSort = 70;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.name')->label(__('admin.fields.product'))->searchable(),
                TextColumn::make('user.phone')->label(__('admin.fields.customer'))
                    ->formatStateUsing(fn ($state, ProductRating $record) => $record->user?->fullName() ?? $state),
                TextColumn::make('rating')->label(__('admin.fields.rating'))->sortable(),
                TextColumn::make('comment')->label(__('admin.fields.comment'))->limit(60)->placeholder('—'),
                IconColumn::make('is_hidden')->label(__('admin.fields.is_hidden'))->boolean(),
                TextColumn::make('hidden_reason')->label(__('admin.fields.hidden_reason'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_hidden')->label(__('admin.fields.is_hidden')),
                SelectFilter::make('rating')->label(__('admin.fields.rating'))->options(array_combine(range(1, 5), range(1, 5))),
            ])
            ->recordActions([
                Action::make('hide')->label(__('admin.ratings.hide'))->icon('heroicon-o-eye-slash')->color('danger')
                    ->visible(fn (ProductRating $record) => ! $record->is_hidden && AdminPermissions::currentAllows('ratings.moderate'))
                    ->schema([TextInput::make('reason')->label(__('admin.fields.hidden_reason'))->required()->maxLength(255)])
                    ->action(fn (ProductRating $record, array $data) => BusinessAction::run(
                        fn ($admin) => app(ProductRatingService::class)->hide($record, $admin, $data['reason'])
                    )),
                Action::make('unhide')->label(__('admin.ratings.unhide'))->icon('heroicon-o-eye')->color('success')
                    ->visible(fn (ProductRating $record) => $record->is_hidden && AdminPermissions::currentAllows('ratings.moderate'))
                    ->requiresConfirmation()
                    ->action(fn (ProductRating $record) => BusinessAction::run(
                        fn ($admin) => app(ProductRatingService::class)->unhide($record, $admin)
                    )),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListProductRatings::route('/')];
    }
}
