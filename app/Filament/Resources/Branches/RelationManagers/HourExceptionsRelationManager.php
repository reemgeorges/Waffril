<?php

namespace App\Filament\Resources\Branches\RelationManagers;

use App\Filament\Support\Fields;
use App\Models\BranchHourException;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Temporary closures / holidays (`closed`, override weekly hours) and
 * special opening hours (`open`).
 */
class HourExceptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'hourExceptions';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.branches.hour_exceptions');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('kind')->label(__('admin.fields.kind'))->options([
                BranchHourException::KIND_CLOSED => __('admin.branches.kind_closed'),
                BranchHourException::KIND_OPEN => __('admin.branches.kind_open'),
            ])->required(),
            DateTimePicker::make('starts_at')->label(__('admin.fields.starts_at'))->required()->seconds(false),
            DateTimePicker::make('ends_at')->label(__('admin.fields.ends_at'))->required()->seconds(false)->after('starts_at'),
            Fields::translatable('reason', required: false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kind')->label(__('admin.fields.kind'))->badge()
                    ->color(fn ($state) => $state === BranchHourException::KIND_CLOSED ? 'danger' : 'success')
                    ->formatStateUsing(fn ($state) => __("admin.branches.kind_{$state}")),
                TextColumn::make('starts_at')->label(__('admin.fields.starts_at'))->dateTime(),
                TextColumn::make('ends_at')->label(__('admin.fields.ends_at'))->dateTime(),
                TextColumn::make('reason')->label(__('admin.fields.reason'))->placeholder('—'),
                TextColumn::make('createdBy.name')->label(__('admin.fields.created_by')),
            ])
            ->headerActions([
                CreateAction::make()->mutateDataUsing(fn (array $data) => $data + ['created_by_admin_id' => auth('admin_web')->id()]),
            ])
            ->recordActions([EditAction::make()->mutateRecordDataUsing(Fields::withTranslations()), DeleteAction::make()]);
    }
}
