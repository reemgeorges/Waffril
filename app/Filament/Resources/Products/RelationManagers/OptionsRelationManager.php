<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Filament\Concerns\TranslatedRelationLabels;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\RowActions;
use App\Models\ProductOptionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Options (sizes, choices, extras) inside this product's groups. Prices
 * are set per branch (Branch option prices tab).
 */
class OptionsRelationManager extends RelationManager
{
    use TranslatedRelationLabels;

    protected static string $relationLabelKey = 'option';

    protected static string $relationship = 'options';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.products.options');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('option_group_id')->label(__('admin.fields.option_group'))->required()
                ->options(fn () => ProductOptionGroup::where('product_id', $this->getOwnerRecord()->id)->get()->mapWithKeys(fn ($g) => [$g->id => $g->name])),
            Fields::translatable('name'),
            Grid::make(2)->schema([
                TextInput::make('max_quantity')->label(__('admin.fields.max_quantity'))->integer()->minValue(1)->maxValue(50)->default(1)->required(),
                TextInput::make('sort_order')->label(__('admin.fields.sort_order'))->integer()->minValue(0)->default(0),
            ]),
            Toggle::make('is_active')->label(__('admin.fields.is_active'))->default(true),
        ]);
    }

    /**
     * Product → Options (table UX overhaul 2026-11-12): option and its
     * group, quantity limit, status; filter by group.
     */
    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withoutGlobalScopes([SoftDeletingScope::class])->with('group'))
            ->columns([
                TextColumn::make('name')->label(__('admin.fields.name'))->weight(FontWeight::SemiBold)->searchable()
                    ->description(fn ($record) => $record->group?->name),
                TextColumn::make('max_quantity')->label(__('admin.fields.max_quantity'))
                    ->formatStateUsing(fn ($state) => $state ? __('admin.table.max_qty', ['n' => (int) $state]) : null),
                Columns::status(),
            ])
            ->searchPlaceholder(__('admin.table.search_option'))
            ->filters([
                SelectFilter::make('group')->label(__('admin.fields.option_group'))
                    ->relationship('group', 'id', fn (Builder $query) => $query->where('product_id', $this->getOwnerRecord()->getKey()))
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name),
                TrashedFilter::make(),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([
                RowActions::primary(EditAction::make()->label(__('admin.table.edit'))->mutateRecordDataUsing(Fields::withTranslations())),
                RowActions::more([DeleteAction::make(), RestoreAction::make()]),
            ]);
    }
}
