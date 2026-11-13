<?php

namespace App\Filament\Resources\Faqs;

use App\Filament\Resources\Faqs\Pages\ManageFaqs;
use App\Filament\Support\AdminResource;
use App\Filament\Support\BusinessAction;
use App\Filament\Support\Fields;
use App\Filament\Support\RowActions;
use App\Models\Faq;
use App\Services\Content\ContentService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;

/**
 * WAFFRLI (2026-11-12) — Frequently asked questions shown in the customer
 * app when published, in display order. Bilingual; saved, published and
 * deleted through App\Services\Content\ContentService (activity log).
 */
class FaqResource extends AdminResource
{
    protected static ?string $model = Faq::class;

    protected static string $labelKey = 'faq';

    protected static string|\UnitEnum|null $navigationGroup = 'content';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Fields::translatable('question', max: 500)->columnSpanFull(),
            Fields::translatable('answer', multiline: true)->columnSpanFull(),
            Grid::make(2)->columnSpanFull()->schema([
                TextInput::make('sort_order')->label(__('admin.fields.sort_order'))->integer()->minValue(0)->default(0)
                    ->helperText(__('admin.help.sort_order')),
                Toggle::make('is_published')->label(__('admin.content.published'))->default(false)
                    ->helperText(__('admin.content.faq_published_help')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $service = fn () => app(ContentService::class);

        return $table
            ->columns([
                TextColumn::make('sort_order')->label('#')->sortable()->color('gray')->visibleFrom('md'),
                TextColumn::make('question')->label(__('admin.fields.question'))->weight(FontWeight::SemiBold)->wrap()->searchable()
                    ->description(fn (Faq $record) => Str::limit((string) $record->answer, 110)),
                TextColumn::make('is_published')->label(__('admin.fields.status'))->badge()
                    ->formatStateUsing(fn ($state) => $state ? __('admin.content.published') : __('admin.content.draft'))
                    ->color(fn ($state) => $state ? 'success' : 'gray')
                    ->icon(fn ($state) => $state ? 'heroicon-m-globe-alt' : 'heroicon-m-pencil'),
                TextColumn::make('updatedBy.name')->label(__('admin.content.updated_by'))->placeholder('—')->color('gray')
                    ->description(fn (Faq $record) => $record->updated_at?->diffForHumans())->visibleFrom('lg'),
            ])
            ->filters([
                TernaryFilter::make('is_published')->label(__('admin.fields.status'))
                    ->trueLabel(__('admin.content.published'))->falseLabel(__('admin.content.draft')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                RowActions::primary(EditAction::make()->label(__('admin.table.edit'))->modalWidth(Width::FourExtraLarge)
                    ->mutateRecordDataUsing(Fields::withTranslations())
                    ->using(fn (Faq $record, array $data) => BusinessAction::run(fn ($admin) => $service()->saveFaq($data, $admin, $record)) ?? throw new Halt)),
                RowActions::more([
                    Action::make('publish')->label(__('admin.content.publish'))->icon('heroicon-m-globe-alt')->color('success')
                        ->visible(fn (Faq $record) => ! $record->is_published && ! $record->trashed() && static::can('update', $record))
                        ->action(fn (Faq $record) => BusinessAction::run(fn ($admin) => $service()->setFaqPublished($record, true, $admin))),
                    Action::make('unpublish')->label(__('admin.content.unpublish'))->icon('heroicon-m-eye-slash')->color('gray')
                        ->visible(fn (Faq $record) => $record->is_published && ! $record->trashed() && static::can('update', $record))
                        ->action(fn (Faq $record) => BusinessAction::run(fn ($admin) => $service()->setFaqPublished($record, false, $admin))),
                    Action::make('delete')->label(__('filament-actions::delete.single.label'))->icon('heroicon-m-trash')->color('danger')
                        ->visible(fn (Faq $record) => ! $record->trashed() && static::can('delete', $record))
                        ->requiresConfirmation()
                        ->action(fn (Faq $record) => BusinessAction::run(fn ($admin) => $service()->deleteFaq($record, $admin))),
                    RestoreAction::make(),
                ]),
            ])
            ->searchPlaceholder(__('admin.table.search_faqs'))
            ->defaultSort('sort_order')
            ->emptyStateHeading(__('admin.content.no_faqs'))
            ->emptyStateDescription(__('admin.content.no_faqs_help'))
            ->emptyStateIcon('heroicon-o-question-mark-circle');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageFaqs::route('/')];
    }
}
