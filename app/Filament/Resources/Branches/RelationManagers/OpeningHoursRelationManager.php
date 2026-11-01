<?php

namespace App\Filament\Resources\Branches\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Weekly opening slots (platform timezone). A closing time earlier than
 * the opening time means the slot ends after midnight.
 */
class OpeningHoursRelationManager extends RelationManager
{
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

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('day_of_week')->label(__('admin.fields.day_of_week'))->formatStateUsing(fn ($state) => self::days()[$state] ?? $state),
                TextColumn::make('opens_at')->label(__('admin.fields.opens_at')),
                TextColumn::make('closes_at')->label(__('admin.fields.closes_at')),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
