<?php

namespace App\Filament\Resources\HomeSliders;

use App\Filament\Resources\HomeSliders\Pages\ManageHomeSliders;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Fields;
use App\Models\HomeSlider;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/** User-facing home sliders, including optional links to a store. */
class HomeSliderResource extends AdminResource
{
    protected static ?string $model = HomeSlider::class;

    protected static string $labelKey = 'home_slider';

    protected static string|\UnitEnum|null $navigationGroup = 'content';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.sliders.content'))->columnSpanFull()->schema([
                Fields::translatable('title', max: 150),
                Fields::translatable('subtitle', required: false, max: 255),
                Grid::make(2)->schema([
                    FileUpload::make('image')->label(__('admin.fields.image'))->disk('public')
                        ->directory('sliders')->image()->maxSize(4096)->required()
                        ->helperText(__('admin.sliders.image_help')),
                    Select::make('store_id')->label(__('admin.fields.store'))
                        ->relationship('store', 'slug', fn (Builder $query) => $query->where('is_active', true))
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->name)
                        ->searchable()->preload()
                        ->helperText(__('admin.sliders.store_help')),
                ]),
                Grid::make(3)->schema([
                    TextInput::make('sort_order')->label(__('admin.fields.sort_order'))
                        ->integer()->minValue(0)->default(0)->required(),
                    DateTimePicker::make('starts_at')->label(__('admin.fields.starts_at'))->seconds(false),
                    DateTimePicker::make('ends_at')->label(__('admin.fields.ends_at'))
                        ->seconds(false)->after('starts_at'),
                ]),
                Toggle::make('is_active')->label(__('admin.fields.is_active'))->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            ImageColumn::make('image')->label(__('admin.fields.image'))->disk('public')->height(56)->width(110),
            TextColumn::make('title')->label(__('admin.fields.title'))->searchable()->wrap(),
            TextColumn::make('store.name')->label(__('admin.fields.store'))
                ->placeholder(__('admin.sliders.global_banner')),
            TextColumn::make('sort_order')->label(__('admin.fields.sort_order'))->sortable(),
            TextColumn::make('is_active')->label(__('admin.fields.status'))->badge()
                ->formatStateUsing(fn ($state) => $state ? __('admin.sliders.active') : __('admin.sliders.inactive'))
                ->color(fn ($state) => $state ? 'success' : 'gray'),
            TextColumn::make('starts_at')->label(__('admin.fields.starts_at'))->dateTime()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('ends_at')->label(__('admin.fields.ends_at'))->dateTime()
                ->toggleable(isToggledHiddenByDefault: true),
        ])
            ->filters([TrashedFilter::make()])
            ->recordActions([EditAction::make()->mutateRecordDataUsing(Fields::withTranslations()), DeleteAction::make(), RestoreAction::make()])
            ->defaultSort('sort_order');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageHomeSliders::route('/')];
    }
}
