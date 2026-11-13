<?php

namespace App\Filament\Resources\PlusMemberships;

use App\Filament\Resources\PlusMemberships\Pages\ListPlusMemberships;
use App\Filament\Resources\PlusMemberships\Pages\ViewPlusMembership;
use App\Filament\Resources\PlusMemberships\RelationManagers\CyclesRelationManager;
use App\Filament\Resources\PlusMemberships\RelationManagers\ExceptionsRelationManager;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Fields;
use App\Filament\Support\RowActions;
use App\Models\PlusMembership;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
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

    /**
     * PLUS memberships (table UX overhaul 2026-11-12): the member with phone,
     * plan and renewal policy, membership status, and the current cycle's
     * end date with free deliveries left. "View membership" opens it.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['user', 'plan', 'activeCycle']))
            ->columns([
                TextColumn::make('user.first_name')->label(__('admin.fields.customer'))->weight(FontWeight::SemiBold)
                    ->state(fn (PlusMembership $record) => $record->user?->fullName())
                    ->description(fn (PlusMembership $record) => Fields::ltr($record->user?->phone))
                    ->searchable(query: Fields::searchCustomer('user')),
                TextColumn::make('plan.name')->label(__('admin.fields.plan'))
                    ->description(fn (PlusMembership $record) => self::policyLabel($record->renewal_policy, $record->custom_duration_days)),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()->formatStateUsing(fn ($state) => self::statusLabel($state))
                    ->color(fn ($state) => match ($state) {
                        'active' => 'success', 'cancelled' => 'danger', default => 'gray'
                    })
                    ->icon(fn ($state) => $state === 'active' ? 'heroicon-m-star' : null),
                TextColumn::make('activeCycle.ends_at')->label(__('admin.plus.current_cycle_ends'))->date()->placeholder(__('admin.table.no_active_cycle'))
                    ->description(fn (PlusMembership $record) => $record->activeCycle
                        ? __('admin.table.deliveries_used', ['used' => (int) $record->activeCycle->free_deliveries_used, 'total' => (int) $record->activeCycle->free_deliveries_total]) : null)
                    ->visibleFrom('md'),
            ])
            ->searchPlaceholder(__('admin.table.search_memberships'))
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->options([
                    'active' => self::statusLabel('active'), 'cancelled' => self::statusLabel('cancelled'), 'ended' => self::statusLabel('ended'),
                ]),
                SelectFilter::make('plan_id')->label(__('admin.fields.plan'))->relationship('plan', 'code')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name)->preload(),
            ])
            ->recordActions([RowActions::primary(ViewAction::make()->label(__('admin.table.view_membership')))])
            ->defaultSort('created_at', 'desc');
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
