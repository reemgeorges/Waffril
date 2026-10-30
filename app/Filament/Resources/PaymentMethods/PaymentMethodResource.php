<?php

namespace App\Filament\Resources\PaymentMethods;

use App\Filament\Resources\PaymentMethods\Pages\ManagePaymentMethods;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Fields;
use App\Models\PaymentMethod;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
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

    protected static string|\UnitEnum|null $navigationGroup = 'finance';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?int $navigationSort = 10;

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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label(__('admin.fields.code')),
                TextColumn::make('kind')->label(__('admin.fields.kind'))->badge()->formatStateUsing(fn ($state) => __("admin.payments.kind_{$state}")),
                TextColumn::make('name')->label(__('admin.fields.name')),
                IconColumn::make('is_active')->label(__('admin.fields.is_active'))->boolean(),
                TextColumn::make('sort_order')->label(__('admin.fields.sort_order'))->sortable(),
            ])
            ->recordActions([
                EditAction::make()->mutateRecordDataUsing(Fields::withTranslations())
                    ->mutateDataUsing(fn (array $data, PaymentMethod $record) => $record->kind === 'online' ? array_merge($data, ['is_active' => false]) : $data),
            ])
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return ['index' => ManagePaymentMethods::route('/')];
    }
}
