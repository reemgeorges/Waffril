<?php

namespace App\Filament\Resources\PlusPlans;

use App\Filament\Resources\PlusMemberships\PlusMembershipResource;
use App\Filament\Resources\PlusPlans\Pages\CreatePlusPlan;
use App\Filament\Resources\PlusPlans\Pages\EditPlusPlan;
use App\Filament\Resources\PlusPlans\Pages\ListPlusPlans;
use App\Filament\Resources\PlusPlans\RelationManagers\StoreDiscountsRelationManager;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\RowActions;
use App\Models\PlusPlan;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * WAFFRLI PLUS (2026-10-31). Plans and their fixed benefits (free
 * deliveries per cycle + store discounts). Payment and agreement are
 * off-platform; display_price is informational. Changes affect FUTURE
 * cycles only — confirmed cycles keep their snapshots.
 */
class PlusPlanResource extends AdminResource
{
    protected static ?string $model = PlusPlan::class;

    protected static string $labelKey = 'plus_plan';

    protected static string|\UnitEnum|null $navigationGroup = 'plus';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->schema([
                TextInput::make('code')->label(__('admin.fields.code'))->required()->maxLength(50)->alphaDash()->unique(ignoreRecord: true),
                Fields::translatable('name'),
                Fields::translatable('description', required: false, multiline: true),
                Grid::make(3)->schema([
                    TextInput::make('default_duration_days')->label(__('admin.fields.default_duration_days'))->integer()->minValue(1)->maxValue(3650)->default(30)->required(),
                    // 2026-11-12: new cycles use the platform setting (Settings → PLUS); kept read-only.
                    TextInput::make('free_deliveries_per_cycle')->label(__('admin.fields.free_deliveries_per_cycle'))->integer()->default(0)
                        ->disabled()->dehydrated(false)->helperText(__('admin.plus.free_deliveries_global_help')),
                    Fields::money('display_price', required: false)->helperText(__('admin.plus.display_price_help')),
                ]),
                Grid::make(2)->schema([
                    TextInput::make('sort_order')->label(__('admin.fields.sort_order'))->integer()->minValue(0)->default(0),
                    Toggle::make('is_active')->label(__('admin.fields.is_active'))->default(true),
                ]),
            ]),
        ]);
    }

    /**
     * PLUS plans (table UX overhaul 2026-11-12): few records, so each plan
     * is a card — name (code underneath), duration and free deliveries in
     * words, the number of store discounts and members (a link), status.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Stack::make([
                    Split::make([
                        Stack::make([
                            TextColumn::make('name')->label(__('admin.fields.name'))->weight(FontWeight::SemiBold)->size(TextSize::Large),
                            TextColumn::make('code')->label(__('admin.fields.code'))->searchable()->color('gray')->size(TextSize::Small),
                        ])->space(1),
                        Columns::status()->grow(false),
                    ]),
                    Stack::make([
                        TextColumn::make('default_duration_days')->label(__('admin.fields.default_duration_days'))->icon('heroicon-m-calendar-days')
                            ->formatStateUsing(fn ($state) => trans_choice('admin.table.duration_days', (int) $state, ['count' => (int) $state])),
                        TextColumn::make('free_deliveries_per_cycle')->label(__('admin.fields.free_deliveries_per_cycle'))->icon('heroicon-m-truck')
                            ->formatStateUsing(fn ($state) => trans_choice('admin.table.free_per_cycle', (int) $state, ['count' => (int) $state])),
                    ])->space(1),
                    Split::make([
                        TextColumn::make('store_discounts_count')->counts('storeDiscounts')->label(__('admin.plus.store_discounts'))->badge()->color('gold')->grow(false)
                            ->icon('heroicon-m-building-storefront')
                            ->formatStateUsing(fn ($state) => trans_choice('admin.table.store_discounts_count', (int) $state, ['count' => (int) $state])),
                        TextColumn::make('memberships_count')->counts('memberships')->label(__('admin.resources.plus_membership.plural'))->badge()->grow(false)
                            ->icon('heroicon-m-user-group')->color(fn ($state) => (int) $state > 0 ? 'primary' : 'gray')
                            ->formatStateUsing(fn ($state) => trans_choice('admin.table.members_count', (int) $state, ['count' => (int) $state]))
                            ->url(fn ($record) => PlusMembershipResource::canViewAny() ? PlusMembershipResource::getUrl('index', ['filters' => ['plan_id' => ['value' => $record->getKey()]]]) : null)
                            ->tooltip(__('admin.table.open_members'))->extraAttributes(['class' => 'wf-stat-link']),
                    ])->extraAttributes(['class' => 'wf-card-stats']),
                ])->space(3),
            ])
            ->contentGrid(['md' => 2, 'xl' => 3])
            ->recordUrl(null)
            ->filters([TrashedFilter::make()])
            ->recordActions([
                RowActions::primary(EditAction::make()->label(__('admin.table.edit'))),
                RowActions::more([DeleteAction::make(), RestoreAction::make()]),
            ])
            ->defaultSort('sort_order');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getRelations(): array
    {
        return [StoreDiscountsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlusPlans::route('/'),
            'create' => CreatePlusPlan::route('/create'),
            'edit' => EditPlusPlan::route('/{record}/edit'),
        ];
    }
}
