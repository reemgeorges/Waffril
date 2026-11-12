<?php

namespace App\Filament\Resources\ContentPages;

use App\Filament\Resources\ContentPages\Pages\CreateContentPage;
use App\Filament\Resources\ContentPages\Pages\EditContentPage;
use App\Filament\Resources\ContentPages\Pages\ListContentPages;
use App\Filament\Support\AdminResource;
use App\Models\ContentPage;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * WAFFRLI (2026-11-12) — Terms & Conditions (and future fixed app texts).
 * Arabic and English side by side in tabs, a draft/published switch and the
 * published version. Saved through App\Services\Content\ContentService
 * (permission, activity log, versioning). The legal wording itself is the
 * owner's: nothing is pre-filled and no customer consent is recorded.
 */
class ContentPageResource extends AdminResource
{
    protected static ?string $model = ContentPage::class;

    protected static string $labelKey = 'content_page';

    protected static string|\UnitEnum|null $navigationGroup = 'content';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        $text = fn (string $locale) => [
            TextInput::make("title.{$locale}")->label(__('admin.content.title'))->required()->maxLength(200)
                ->extraInputAttributes(['dir' => $locale === 'ar' ? 'rtl' : 'ltr', 'lang' => $locale]),
            Textarea::make("body.{$locale}")->label(__('admin.content.body'))->required()->rows(18)->maxLength(60000)
                ->helperText(__('admin.content.body_help'))
                ->extraInputAttributes(['dir' => $locale === 'ar' ? 'rtl' : 'ltr', 'lang' => $locale]),
        ];

        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->columnSpanFull()->schema([
                Tabs::make('languages')->columnSpan(['default' => 1, 'lg' => 2])->tabs([
                    Tab::make(__('admin.languages.ar'))->schema($text('ar')),
                    Tab::make(__('admin.languages.en'))->schema($text('en')),
                ]),
                Section::make(__('admin.content.publication'))->icon('heroicon-o-globe-alt')->columnSpan(['default' => 1, 'lg' => 1])->schema([
                    Select::make('key')->label(__('admin.content.page'))->required()
                        ->options(fn (?ContentPage $record) => collect(ContentPage::KEYS)
                            ->reject(fn ($key) => $record === null && ContentPage::query()->where('key', $key)->exists())
                            ->mapWithKeys(fn ($key) => [$key => __("admin.content.keys.{$key}")]))
                        ->disabled(fn (?ContentPage $record) => $record !== null)->dehydrated(fn (?ContentPage $record) => $record === null),
                    Toggle::make('is_published')->label(__('admin.content.published'))
                        ->helperText(__('admin.content.published_help')),
                    TextInput::make('version')->label(__('admin.content.version'))->disabled()->dehydrated(false)
                        ->visible(fn (?ContentPage $record) => $record !== null),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')->label(__('admin.content.page'))->weight(FontWeight::SemiBold)
                    ->formatStateUsing(fn ($state) => __("admin.content.keys.{$state}")),
                TextColumn::make('title')->label(__('admin.content.title'))->limit(60),
                TextColumn::make('is_published')->label(__('admin.fields.status'))->badge()
                    ->formatStateUsing(fn ($state) => $state ? __('admin.content.published') : __('admin.content.draft'))
                    ->color(fn ($state) => $state ? 'success' : 'gray')
                    ->icon(fn ($state) => $state ? 'heroicon-m-globe-alt' : 'heroicon-m-pencil'),
                TextColumn::make('version')->label(__('admin.content.version')),
                TextColumn::make('published_at')->label(__('admin.content.published_at'))->dateTime()->placeholder('—'),
                TextColumn::make('updatedBy.name')->label(__('admin.content.updated_by'))->placeholder('—')
                    ->description(fn (ContentPage $record) => $record->updated_at?->diffForHumans()),
            ])
            ->recordActions([EditAction::make()])
            ->emptyStateHeading(__('admin.content.no_pages'))
            ->emptyStateDescription(__('admin.content.no_pages_help'))
            ->emptyStateIcon('heroicon-o-document-text');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContentPages::route('/'),
            'create' => CreateContentPage::route('/create'),
            'edit' => EditContentPage::route('/{record}/edit'),
        ];
    }
}
