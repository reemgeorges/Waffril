<?php

namespace App\Filament\Resources\PaymentMethods;

use App\Filament\Resources\PaymentMethods\Pages\ManagePaymentMethods;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\RowActions;
use App\Models\PaymentMethod;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * WAFFRLI (2026-10-31). Payment methods: wallet + cash on delivery at
 * launch. An `online` method can be registered for future electronic
 * payments but stays inactive: no provider integration exists, so it
 * cannot be activated.
 */
class PaymentMethodResource extends AdminResource
{
    protected static ?string $model = PaymentMethod::class;

    protected static string $labelKey = 'payment_method';

    protected static string|\UnitEnum|null $navigationGroup = 'platform';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->label(__('admin.fields.code'))->required()->maxLength(30)->alphaDash()
                ->unique(ignoreRecord: true)->disabled(fn (?PaymentMethod $record) => $record !== null),
            Select::make('kind')->label(__('admin.fields.kind'))->required()->live()
                ->options(['wallet' => __('admin.payments.kind_wallet'), 'cash' => __('admin.payments.kind_cash'), 'online' => __('admin.payments.kind_online')])
                ->disabled(fn (?PaymentMethod $record) => $record !== null),
            Fields::translatable('name'),
            TextInput::make('sort_order')->label(__('admin.fields.sort_order'))->integer()->minValue(0)->default(0),
            Toggle::make('is_active')->label(__('admin.fields.is_active'))->default(true)
                ->disabled(fn ($get, ?PaymentMethod $record) => ($record?->kind ?? $get('kind')) === 'online')
                ->helperText(fn ($get, ?PaymentMethod $record) => ($record?->kind ?? $get('kind')) === 'online' ? __('admin.payments.online_unavailable') : null),
        ]);
    }

    /**
     * Payment methods (table UX overhaul 2026-11-12): a few records, so each
     * is a card — name, kind (cash / wallet / online), status and position.
     * Online methods stay inactive (they are not available yet).
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Stack::make([
                    Split::make([
                        Stack::make([
                            TextColumn::make('name')->label(__('admin.fields.name'))->searchable()->weight(FontWeight::SemiBold)->size(TextSize::Large),
                            TextColumn::make('kind')->label(__('admin.fields.kind'))->badge()->color('gray')
                                ->formatStateUsing(fn ($state) => __("admin.payments.kind_{$state}"))
                                ->icon(fn ($state) => match ($state) {
                                    'cash' => 'heroicon-m-banknotes', 'wallet' => 'heroicon-m-wallet', default => 'heroicon-m-credit-card'
                                })
                                ->tooltip(fn (PaymentMethod $record) => $record->kind === 'online' ? __('admin.table.online_unavailable') : null),
                        ])->space(2),
                        Columns::status()->grow(false),
                    ]),
                    TextColumn::make('sort_order')->label(__('admin.fields.sort_order'))->color('gray')->size(TextSize::Small)
                        ->formatStateUsing(fn ($state) => __('admin.table.sort_position', ['n' => $state])),
                ])->space(3),
            ])
            ->contentGrid(['md' => 2, 'xl' => 3])
            ->recordUrl(null)
            ->recordActions([
                RowActions::primary(EditAction::make()->label(__('admin.table.edit'))->mutateRecordDataUsing(Fields::withTranslations())
                    ->mutateDataUsing(fn (array $data, PaymentMethod $record) => $record->kind === 'online' ? array_merge($data, ['is_active' => false]) : $data)),
            ])
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return ['index' => ManagePaymentMethods::route('/')];
    }
}
