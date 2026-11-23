<?php

namespace App\Filament\Resources\DriverRatings;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\DriverRatings\Pages\ListDriverRatings;
use App\Filament\Resources\Drivers\DriverResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\AdminResource;
use App\Filament\Support\BusinessAction;
use App\Filament\Support\RowActions;
use App\Filament\Support\TableDefaults;
use App\Models\DriverRating;
use App\Services\Ratings\RatingModerationService;
use App\Support\Auth\AdminPermissions;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class DriverRatingResource extends AdminResource
{
    protected static ?string $model = DriverRating::class;

    protected static string|\UnitEnum|null $navigationGroup = 'people';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-star';

    protected static ?int $navigationSort = 75;

    public static function getModelLabel(): string
    {
        return __('order_driver_ratings.driver_singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('order_driver_ratings.driver_plural');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['order', 'user', 'driver']))
            ->columns([
                TextColumn::make('order.order_number')->label(__('order_driver_ratings.order_number'))->searchable()->sortable()
                    ->url(fn ($record) => OrderResource::canViewAny() && $record->order ? OrderResource::getUrl('view', ['record' => $record->order]) : null),
                TextColumn::make('driver.name')->label(__('order_driver_ratings.driver'))->searchable()
                    ->url(fn (DriverRating $record) => DriverResource::canViewAny() && $record->driver ? DriverResource::getUrl('edit', ['record' => $record->driver]) : null),
                TextColumn::make('user.first_name')->label(__('order_driver_ratings.customer'))
                    ->state(fn (DriverRating $record) => $record->user?->fullName())
                    ->url(fn (DriverRating $record) => CustomerResource::canViewAny() && $record->user ? CustomerResource::getUrl('view', ['record' => $record->user]) : null),
                TextColumn::make('rating')->label(__('order_driver_ratings.stars'))->sortable()
                    ->formatStateUsing(fn ($state) => str_repeat('★', (int) $state).str_repeat('☆', 5 - (int) $state))
                    ->color('warning')->width(TableDefaults::COMPACT_WIDTH),
                TextColumn::make('comment')->label(__('order_driver_ratings.comment'))
                    ->wrap()->limit(110)->placeholder('—'),
                TextColumn::make('is_hidden')->label(__('order_driver_ratings.visibility'))->badge()
                    ->formatStateUsing(fn ($state) => $state ? __('order_driver_ratings.hidden') : __('order_driver_ratings.visible'))
                    ->color(fn ($state) => $state ? 'danger' : 'success')
                    ->tooltip(fn (DriverRating $record) => $record->hidden_reason),
                TextColumn::make('created_at')->label(__('order_driver_ratings.created_at'))->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('rating')->label(__('order_driver_ratings.stars'))
                    ->options([1 => '★', 2 => '★★', 3 => '★★★', 4 => '★★★★', 5 => '★★★★★']),
                TernaryFilter::make('is_hidden')->label(__('order_driver_ratings.visibility')),
                Filter::make('with_comment')->label(__('order_driver_ratings.with_comment'))->toggle()
                    ->query(fn ($query) => $query->whereNotNull('comment')->where('comment', '!=', '')),
            ])
            ->recordActions([
                RowActions::primary(Action::make('hide')->label(__('order_driver_ratings.hide'))
                    ->icon('heroicon-m-eye-slash')->color('danger')
                    ->visible(fn (DriverRating $record) => ! $record->is_hidden && AdminPermissions::currentAllows('ratings.moderate'))
                    ->schema([TextInput::make('reason')->label(__('order_driver_ratings.hidden_reason'))->required()->maxLength(255)])
                    ->action(fn (DriverRating $record, array $data) => BusinessAction::run(
                        fn ($admin) => app(RatingModerationService::class)->setHidden($record, $admin, true, $data['reason'])
                    ))),
                RowActions::primary(Action::make('unhide')->label(__('order_driver_ratings.unhide'))
                    ->icon('heroicon-m-eye')->color('success')->requiresConfirmation()
                    ->visible(fn (DriverRating $record) => $record->is_hidden && AdminPermissions::currentAllows('ratings.moderate'))
                    ->action(fn (DriverRating $record) => BusinessAction::run(
                        fn ($admin) => app(RatingModerationService::class)->setHidden($record, $admin, false)
                    ))),
            ])
            ->emptyStateHeading(__('order_driver_ratings.no_driver_rating'))
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListDriverRatings::route('/')];
    }
}
