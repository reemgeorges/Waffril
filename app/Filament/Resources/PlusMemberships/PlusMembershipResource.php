<?php

namespace App\Filament\Resources\PlusMemberships;

use App\Filament\Resources\PlusMemberships\Pages\ListPlusMemberships;
use App\Filament\Resources\PlusMemberships\Pages\ViewPlusMembership;
use App\Filament\Resources\PlusMemberships\RelationManagers\CyclesRelationManager;
use App\Filament\Resources\PlusMemberships\RelationManagers\ExceptionsRelationManager;
use App\Filament\Support\AdminResource;
use App\Models\PlusMembership;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * WAFFRLI PLUS (2026-10-31). Memberships (the logical subscription):
 * renewal policy, approved per-member exceptions, cycles. Changes go
 * through PlusService and apply from the next confirmed cycle.
 */
class PlusMembershipResource extends AdminResource
{
    protected static ?string $model = PlusMembership::class;

    protected static string $labelKey = 'plus_membership';

    protected static string|\UnitEnum|null $navigationGroup = 'plus';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static ?int $navigationSort = 20;

    public static function statusLabel(?string $state): string
    {
        return $state ? __("admin.plus.membership_{$state}") : '—';
    }

    public static function policyLabel(?string $state, ?int $days = null): string
    {
        return $state ? __("admin.plus.policy_{$state}").($days ? " ({$days} ".__('admin.plus.days').')' : '') : '—';
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('user.phone')->label(__('admin.fields.customer'))
                    ->formatStateUsing(fn ($state, PlusMembership $record) => $record->user?->fullName().' '.$state),
                TextEntry::make('plan.name')->label(__('admin.fields.plan')),
                TextEntry::make('nextPlan.name')->label(__('admin.fields.next_plan'))->placeholder('—'),
                TextEntry::make('status')->label(__('admin.fields.status'))->badge()->formatStateUsing(fn ($state) => self::statusLabel($state)),
                TextEntry::make('renewal_policy')->label(__('admin.fields.renewal_policy'))
                    ->formatStateUsing(fn ($state, PlusMembership $record) => self::policyLabel($state, $record->custom_duration_days)),
                TextEntry::make('free_deliveries_override')->label(__('admin.fields.free_deliveries_override'))->placeholder(__('admin.plus.plan_default')),
                TextEntry::make('cancelled_at')->label(__('admin.fields.cancelled_at'))->dateTime()->placeholder('—'),
                TextEntry::make('ended_at')->label(__('admin.fields.ended_at'))->dateTime()->placeholder('—'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.phone')->label(__('admin.fields.customer'))
                    ->formatStateUsing(fn ($state, PlusMembership $record) => $record->user?->fullName().' '.$state)->searchable(),
                TextColumn::make('plan.name')->label(__('admin.fields.plan')),
                TextColumn::make('renewal_policy')->label(__('admin.fields.renewal_policy'))
                    ->formatStateUsing(fn ($state, PlusMembership $record) => self::policyLabel($state, $record->custom_duration_days)),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()->formatStateUsing(fn ($state) => self::statusLabel($state)),
                TextColumn::make('activeCycle.ends_at')->label(__('admin.plus.current_cycle_ends'))->dateTime()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->options([
                    'active' => self::statusLabel('active'), 'cancelled' => self::statusLabel('cancelled'), 'ended' => self::statusLabel('ended'),
                ]),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [CyclesRelationManager::class, ExceptionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListPlusMemberships::route('/'), 'view' => ViewPlusMembership::route('/{record}')];
    }
}
