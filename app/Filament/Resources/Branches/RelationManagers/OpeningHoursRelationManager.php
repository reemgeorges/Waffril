<?php

namespace App\Filament\Resources\Branches\RelationManagers;

use App\Filament\Concerns\TranslatedRelationLabels;
use App\Filament\Support\RowActions;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Weekly opening slots (platform timezone). A closing time earlier than
 * the opening time means the slot ends after midnight.
 */
class OpeningHoursRelationManager extends RelationManager
{
    use TranslatedRelationLabels;

    protected static string $relationLabelKey = 'opening_hour';

    protected static string $relationship = 'openingHours';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.branches.opening_hours');
    }

    public static function days(): array
    {
        return collect(range(0, 6))->mapWithKeys(fn ($d) => [$d => __("admin.days.{$d}")])->all();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('day_of_week')->label(__('admin.fields.day_of_week'))->options(self::days())->required(),
            TimePicker::make('opens_at')->label(__('admin.fields.opens_at'))->seconds(false)->required(),
            TimePicker::make('closes_at')->label(__('admin.fields.closes_at'))->seconds(false)->required()->different('opens_at'),
        ]);
    }

    /**
     * Branch → Opening hours (table UX overhaul 2026-11-12): the day and
     * its hours in one line ("09:00 – 23:00"), in week order.
     */
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('day_of_week')->label(__('admin.fields.day_of_week'))->weight(FontWeight::SemiBold)
                    ->formatStateUsing(fn ($state) => self::days()[$state] ?? $state),
                TextColumn::make('opens_at')->label(__('admin.table.opening_hours'))->icon('heroicon-m-clock')
                    ->formatStateUsing(fn ($state, $record) => __('admin.table.hours_range', ['from' => substr((string) $record->opens_at, 0, 5), 'to' => substr((string) $record->closes_at, 0, 5)]))
                    ->extraAttributes(['class' => 'wf-ltr wf-num']),
            ])
            ->defaultSort('day_of_week')
            ->headerActions([CreateAction::make()])
            ->recordActions([
                RowActions::primary(EditAction::make()->label(__('admin.table.edit'))),
                RowActions::more([DeleteAction::make()]),
            ]);
    }
}
