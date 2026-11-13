<?php

namespace App\Filament\Resources\ProductRatings;

use App\Filament\Resources\Customers\RelationManagers\RatingsRelationManager as CustomerRatingsRelationManager;
use App\Filament\Resources\ProductRatings\Pages\ListProductRatings;
use App\Filament\Resources\Products\RelationManagers\RatingsRelationManager as ProductRatingsRelationManager;
use App\Filament\Support\AdminResource;
use App\Filament\Support\BusinessAction;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\RowActions;
use App\Filament\Support\TableDefaults;
use App\Models\ProductRating;
use App\Models\Store;
use App\Services\Catalog\ProductRatingService;
use App\Support\Auth\AdminPermissions;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * WAFFRLI (2026-10-31). Product rating moderation. Hidden ratings are
 * removed from the product's public average; comments are never shown to
 * customers. Ratings cannot be edited or deleted by admins.
 */
class ProductRatingResource extends AdminResource
{
    protected static ?string $model = ProductRating::class;

    /**
     * UX overhaul (2026-11-12): not in the sidebar. Managed from its owning
     * workflow (Stores → store → Branches / Products / categories / ratings);
     * the pages and routes stay, protected by the same policy.
     */
    protected static bool $shouldRegisterNavigation = false;

    protected static string $labelKey = 'product_rating';

    protected static string|\UnitEnum|null $navigationGroup = 'catalog';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-star';

    protected static ?int $navigationSort = 70;

    /**
     * Ratings (the moderation queue, and the Ratings tab of a product, a
     * customer and an order; table UX overhaul 2026-11-12): product and
     * customer (each hidden where it is the page's own record), stars,
     * comment, a clear Visible / Hidden state and the date. The moderation
     * action (hide with a reason / show again) is the one row action and
     * still needs ratings.moderate.
     */
    public static function table(Table $table): Table
    {
        $in = fn (string $class) => fn ($livewire) => $livewire instanceof $class;

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product.store', 'user']))
            ->columns([
                Columns::picture('product.image', fn (ProductRating $record) => $record->product?->name, 36)
                    ->hidden($in(ProductRatingsRelationManager::class))->visibleFrom('md'),
                TextColumn::make('product.name')->label(__('admin.fields.product'))->weight(FontWeight::SemiBold)
                    ->description(fn (ProductRating $record) => $record->product?->store?->name)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('product', fn ($q) => $q->where('name', 'like', "%{$search}%")))
                    ->hidden($in(ProductRatingsRelationManager::class)),
                TextColumn::make('user.first_name')->label(__('admin.fields.customer'))
                    ->state(fn (ProductRating $record) => $record->user?->fullName())
                    ->description(fn (ProductRating $record) => $record->user?->phone)
                    ->searchable(query: Fields::searchCustomer('user'))
                    ->hidden($in(CustomerRatingsRelationManager::class)),
                TextColumn::make('rating')->label(__('admin.table.score'))->sortable()
                    ->formatStateUsing(fn ($state) => str_repeat('★', (int) $state).str_repeat('☆', 5 - (int) $state))
                    ->color(fn ($state) => (int) $state >= 4 ? 'warning' : ((int) $state <= 2 ? 'danger' : 'gray'))
                    ->tooltip(fn ($state) => $state.' / 5')
                    ->extraAttributes(['class' => 'wf-stars'])->width(TableDefaults::COMPACT_WIDTH),
                TextColumn::make('comment')->label(__('admin.fields.comment'))->limit(90)->wrap()->placeholder('—')
                    ->tooltip(fn (ProductRating $record) => mb_strlen((string) $record->comment) > 90 ? $record->comment : null),
                TextColumn::make('is_hidden')->label(__('admin.table.moderation'))->badge()
                    ->formatStateUsing(fn ($state) => $state ? __('admin.table.hidden') : __('admin.table.visible'))
                    ->icon(fn ($state) => $state ? 'heroicon-m-eye-slash' : 'heroicon-m-eye')
                    ->color(fn ($state) => $state ? 'danger' : 'success')
                    ->tooltip(fn (ProductRating $record) => $record->is_hidden ? $record->hidden_reason : null)
                    ->width(TableDefaults::COMPACT_WIDTH),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->since()->sortable()
                    ->tooltip(fn (ProductRating $record) => $record->created_at?->translatedFormat(TableDefaults::dateTimeFormat()))
                    ->visibleFrom('md'),
            ])
            ->searchPlaceholder(__('admin.table.search_ratings'))
            ->filters([
                SelectFilter::make('rating')->label(__('admin.table.score'))->multiple()
                    ->options(collect(range(5, 1))->mapWithKeys(fn ($n) => [$n => str_repeat('★', $n).' ('.$n.')'])->all()),
                TernaryFilter::make('is_hidden')->label(__('admin.table.moderation'))
                    ->trueLabel(__('admin.table.hidden'))->falseLabel(__('admin.table.visible')),
                Filter::make('with_comment')->label(__('admin.table.with_comment'))->toggle()
                    ->query(fn (Builder $query) => $query->whereNotNull('comment')->where('comment', '!=', '')),
                SelectFilter::make('store')->label(__('admin.fields.store'))
                    ->options(fn () => Store::query()->orderBy('id')->get()->mapWithKeys(fn (Store $s) => [$s->id => $s->name])->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('product', fn ($q) => $q->where('store_id', $data['value'])) : $query)
                    ->hidden($in(ProductRatingsRelationManager::class)),
            ])
            ->recordActions([
                RowActions::primary(Action::make('hide')->label(__('admin.ratings.hide'))->icon('heroicon-m-eye-slash')->color('danger')
                    ->visible(fn (ProductRating $record) => ! $record->is_hidden && AdminPermissions::currentAllows('ratings.moderate'))
                    ->schema([TextInput::make('reason')->label(__('admin.fields.hidden_reason'))->required()->maxLength(255)])
                    ->action(fn (ProductRating $record, array $data) => BusinessAction::run(
                        fn ($admin) => app(ProductRatingService::class)->hide($record, $admin, $data['reason'])
                    ))),
                RowActions::primary(Action::make('unhide')->label(__('admin.ratings.unhide'))->icon('heroicon-m-eye')->color('success')
                    ->visible(fn (ProductRating $record) => $record->is_hidden && AdminPermissions::currentAllows('ratings.moderate'))
                    ->requiresConfirmation()
                    ->action(fn (ProductRating $record) => BusinessAction::run(
                        fn ($admin) => app(ProductRatingService::class)->unhide($record, $admin)
                    ))),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListProductRatings::route('/')];
    }
}
