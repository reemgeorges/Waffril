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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Option groups: `size` (exactly one choice, min = max = 1), `choice`
 * (required/optional selection with min/max), `addon` (paid or free
 * extras, quantities counted toward max).
 */
class OptionGroupsRelationManager extends RelationManager
{
    protected static string $relationship = 'optionGroups';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.products.option_groups');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Fields::translatable('name'),
            Select::make('kind')->label(__('admin.fields.kind'))->required()->live()
                ->options([
                    ProductOptionGroup::KIND_SIZE => __('admin.products.kind_size'),
                    ProductOptionGroup::KIND_CHOICE => __('admin.products.kind_choice'),
                    ProductOptionGroup::KIND_ADDON => __('admin.products.kind_addon'),
                ])
                ->afterStateUpdated(function ($state, Set $set) {
                    if ($state === ProductOptionGroup::KIND_SIZE) {
                        $set('min_select', 1);
                        $set('max_select', 1);
                    }
                }),
            Grid::make(3)->schema([
                TextInput::make('min_select')->label(__('admin.fields.min_select'))->integer()->minValue(0)->maxValue(50)->required()->default(0)
                    ->disabled(fn (Get $get) => $get('kind') === ProductOptionGroup::KIND_SIZE)->dehydrated(),
                TextInput::make('max_select')->label(__('admin.fields.max_select'))->integer()->minValue(1)->maxValue(50)->required()->default(1)
                    ->gte('min_select')
                    ->disabled(fn (Get $get) => $get('kind') === ProductOptionGroup::KIND_SIZE)->dehydrated(),
                TextInput::make('sort_order')->label(__('admin.fields.sort_order'))->integer()->minValue(0)->default(0),
            ]),
            Toggle::make('is_active')->label(__('admin.fields.is_active'))->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withoutGlobalScopes([SoftDeletingScope::class]))
            ->columns([
                TextColumn::make('name')->label(__('admin.fields.name')),
                TextColumn::make('kind')->label(__('admin.fields.kind'))->badge()->formatStateUsing(fn ($state) => __("admin.products.kind_{$state}")),
                TextColumn::make('min_select')->label(__('admin.fields.min_select')),
                TextColumn::make('max_select')->label(__('admin.fields.max_select')),
                TextColumn::make('options_count')->counts('options')->label(__('admin.products.options')),
                IconColumn::make('is_active')->label(__('admin.fields.is_active'))->boolean(),
            ])
            ->filters([TrashedFilter::make()])
            ->headerActions([CreateAction::make()->mutateDataUsing(fn (array $data) => self::normalize($data))])
            ->recordActions([
                EditAction::make()->mutateRecordDataUsing(Fields::withTranslations())->mutateDataUsing(fn (array $data) => self::normalize($data)),
                DeleteAction::make(), RestoreAction::make(),
            ]);
    }

    public static function normalize(array $data): array
    {
        if (($data['kind'] ?? null) === ProductOptionGroup::KIND_SIZE) {
            $data['min_select'] = 1;
            $data['max_select'] = 1;
        }

        return $data;
    }
}
