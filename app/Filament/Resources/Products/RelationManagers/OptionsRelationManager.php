<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Filament\Support\Fields;
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
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
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

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withoutGlobalScopes([SoftDeletingScope::class])->with('group'))
            ->columns([
                TextColumn::make('group.name')->label(__('admin.fields.option_group')),
                TextColumn::make('name')->label(__('admin.fields.name')),
                TextColumn::make('max_quantity')->label(__('admin.fields.max_quantity')),
                IconColumn::make('is_active')->label(__('admin.fields.is_active'))->boolean(),
            ])
            ->filters([TrashedFilter::make()])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make()->mutateRecordDataUsing(Fields::withTranslations()), DeleteAction::make(), RestoreAction::make()]);
    }
}
