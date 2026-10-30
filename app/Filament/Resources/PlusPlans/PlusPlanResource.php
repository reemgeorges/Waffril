<?php

namespace App\Filament\Resources\PlusPlans;

use App\Filament\Resources\PlusPlans\Pages\CreatePlusPlan;
use App\Filament\Resources\PlusPlans\Pages\EditPlusPlan;
use App\Filament\Resources\PlusPlans\Pages\ListPlusPlans;
use App\Filament\Resources\PlusPlans\RelationManagers\StoreDiscountsRelationManager;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Fields;
use App\Models\PlusPlan;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
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

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextInput::make('code')->label(__('admin.fields.code'))->required()->maxLength(50)->alphaDash()->unique(ignoreRecord: true),
                Fields::translatable('name'),
                Fields::translatable('description', required: false, multiline: true),
                Grid::make(3)->schema([
                    TextInput::make('default_duration_days')->label(__('admin.fields.default_duration_days'))->integer()->minValue(1)->maxValue(3650)->default(30)->required(),
                    TextInput::make('free_deliveries_per_cycle')->label(__('admin.fields.free_deliveries_per_cycle'))->integer()->minValue(0)->maxValue(1000)->default(0)->required(),
                    Fields::money('display_price', required: false)->helperText(__('admin.plus.display_price_help')),
                ]),
                Grid::make(2)->schema([
                    TextInput::make('sort_order')->label(__('admin.fields.sort_order'))->integer()->minValue(0)->default(0),
                    Toggle::make('is_active')->label(__('admin.fields.is_active'))->default(true),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label(__('admin.fields.code'))->searchable(),
                TextColumn::make('name')->label(__('admin.fields.name')),
                TextColumn::make('default_duration_days')->label(__('admin.fields.default_duration_days')),
                TextColumn::make('free_deliveries_per_cycle')->label(__('admin.fields.free_deliveries_per_cycle')),
                TextColumn::make('store_discounts_count')->counts('storeDiscounts')->label(__('admin.plus.store_discounts')),
                TextColumn::make('memberships_count')->counts('memberships')->label(__('admin.resources.plus_membership.plural')),
                IconColumn::make('is_active')->label(__('admin.fields.is_active'))->boolean(),
            ])
            ->filters([TrashedFilter::make()])
            ->recordActions([EditAction::make(), DeleteAction::make(), RestoreAction::make()]);
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
