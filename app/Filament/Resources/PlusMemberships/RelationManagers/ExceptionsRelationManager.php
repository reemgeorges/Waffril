<?php

namespace App\Filament\Resources\PlusMemberships\RelationManagers;

use App\Filament\Concerns\TranslatedRelationLabels;
use App\Filament\Resources\PlusPlans\RelationManagers\StoreDiscountsRelationManager;
use App\Filament\Support\BusinessAction;
use App\Filament\Support\Fields;
use App\Filament\Support\RowActions;
use App\Models\Store;
use App\Services\Plus\PlusService;
use App\Support\Auth\AdminPermissions;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Admin-approved per-member exceptions: a different discount for a store,
 * or exclusion of a store. Saved through PlusService (records the
 * approving admin) and applied from the next confirmed cycle.
 */
class ExceptionsRelationManager extends RelationManager
{
    use TranslatedRelationLabels;

    protected static string $relationLabelKey = 'plus_exception';

    protected static string $relationship = 'exceptions';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.plus.exceptions');
    }

    public function table(Table $table): Table
    {
        $can = fn () => AdminPermissions::currentAllows('plus.approve');

        return $table
            // Table UX overhaul (2026-11-12): the store, then "Excluded" or the
            // custom discount with its limits in words, and who approved it.
            ->modifyQueryUsing(fn ($query) => $query->with(['store', 'approvedBy']))
            ->columns([
                TextColumn::make('store.name')->label(__('admin.fields.store'))->weight(FontWeight::SemiBold)
                    ->description(fn ($record) => $record->note),
                TextColumn::make('discount_value')->label(__('admin.fields.discount_value'))->badge()
                    ->state(fn ($record) => $record->is_excluded ? __('admin.table.excluded')
                        : '−'.Fields::formatDecimal($record->discount_value).($record->discount_type === 'percent' ? '%' : ''))
                    ->color(fn ($record) => $record->is_excluded ? 'danger' : 'gold')
                    ->icon(fn ($record) => $record->is_excluded ? 'heroicon-m-no-symbol' : 'heroicon-m-tag')
                    ->description(fn ($record) => $record->is_excluded ? null : (collect([
                        $record->max_discount_amount !== null ? __('admin.table.max_discount', ['amount' => Fields::formatDecimal($record->max_discount_amount)]) : null,
                        $record->min_order_amount !== null ? __('admin.table.min_order_short', ['amount' => Fields::formatDecimal($record->min_order_amount)]) : null,

                    ])->filter()->implode(' · ') ?: null)),
                TextColumn::make('approvedBy.name')->label(__('admin.fields.approved_by'))->placeholder('—')->color('gray')->visibleFrom('md'),
            ])
            ->headerActions([
                Action::make('saveException')->label(__('admin.plus.add_exception'))->icon('heroicon-o-plus')
                    ->visible($can)
                    ->schema([
                        Select::make('store_id')->label(__('admin.fields.store'))->required()->searchable()
                            ->options(fn () => Store::all()->mapWithKeys(fn ($s) => [$s->id => $s->name])),
                        Toggle::make('is_excluded')->label(__('admin.fields.is_excluded'))->live(),
                        Group::make(StoreDiscountsRelationManager::discountFields())->visible(fn (Get $get) => ! $get('is_excluded')),
                        TextInput::make('note')->label(__('admin.fields.note'))->required()->maxLength(255),
                    ])
                    ->action(fn (array $data) => BusinessAction::run(fn ($admin) => app(PlusService::class)
                        ->saveException($this->getOwnerRecord(), Store::findOrFail($data['store_id']), $data, $admin))),
            ])
            ->recordActions([
                RowActions::more([DeleteAction::make()->visible($can)->authorize($can)]),
            ]);
    }
}
