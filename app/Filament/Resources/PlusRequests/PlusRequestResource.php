<?php

namespace App\Filament\Resources\PlusRequests;

use App\Filament\Resources\PlusRequests\Pages\ListPlusRequests;
use App\Filament\Support\AdminResource;
use App\Filament\Support\BusinessAction;
use App\Filament\Support\PlusPolicyFields;
use App\Models\PlusRequest;
use App\Services\Plus\PlusService;
use App\Support\Auth\AdminPermissions;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/**
 * WAFFRLI PLUS (2026-10-31). Membership requests (new / renew / change /
 * cancel / custom). Every approval goes through PlusService: `new` and
 * `renew` confirm a cycle (the admin confirms EVERY cycle), `change`
 * applies from the next cycle, `cancel` stops renewal while the current
 * cycle keeps its benefits, `custom` is resolved manually with a note.
 */
class PlusRequestResource extends AdminResource
{
    protected static ?string $model = PlusRequest::class;

    protected static string $labelKey = 'plus_request';

    protected static string|\UnitEnum|null $navigationGroup = 'plus';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?int $navigationSort = 15;

    public static function getNavigationBadge(): ?string
    {
        $count = PlusRequest::query()->where('status', PlusRequest::STATUS_PENDING)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        $pending = fn (PlusRequest $record) => $record->status === PlusRequest::STATUS_PENDING && AdminPermissions::currentAllows('plus.approve');
        $service = fn () => app(PlusService::class);

        return $table
            ->columns([
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime()->sortable(),
                TextColumn::make('user.phone')->label(__('admin.fields.customer'))
                    ->formatStateUsing(fn ($state, PlusRequest $record) => $record->user?->fullName().' '.$state)->searchable(),
                TextColumn::make('type')->label(__('admin.fields.type'))->badge()->formatStateUsing(fn ($state) => __("admin.plus.request_{$state}")),
                TextColumn::make('requestedPlan.name')->label(__('admin.fields.plan'))->placeholder('—'),
                TextColumn::make('source')->label(__('admin.fields.source'))->formatStateUsing(fn ($state) => __("admin.plus.source_{$state}")),
                TextColumn::make('customer_note')->label(__('admin.fields.customer_note'))->limit(50)->placeholder('—'),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()->formatStateUsing(fn ($state) => __("admin.requests.status_{$state}")),
                TextColumn::make('reviewedBy.name')->label(__('admin.fields.resolved_by'))->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->default(PlusRequest::STATUS_PENDING)
                    ->options(collect(['pending', 'approved', 'rejected', 'withdrawn'])->mapWithKeys(fn ($s) => [$s => __("admin.requests.status_{$s}")])),
                SelectFilter::make('type')->label(__('admin.fields.type'))
                    ->options(collect(['new', 'renew', 'change', 'cancel', 'custom'])->mapWithKeys(fn ($t) => [$t => __("admin.plus.request_{$t}")])),
            ])
            ->recordActions([
                Action::make('approveNew')->label(__('admin.requests.approve'))->icon('heroicon-o-check')->color('success')
                    ->visible(fn (PlusRequest $record) => $pending($record) && $record->type === PlusRequest::TYPE_NEW)
                    ->schema([
                        ...PlusPolicyFields::make(),
                        DateTimePicker::make('starts_at')->label(__('admin.fields.starts_at'))->default(now())->seconds(false)->required(),
                        Textarea::make('note')->label(__('admin.fields.note'))->maxLength(1000),
                    ])
                    ->modalDescription(__('admin.plus.approve_new_help'))
                    ->action(fn (PlusRequest $record, array $data) => BusinessAction::run(fn ($admin) => $service()->approveNew(
                        $record, $admin, $data['renewal_policy'],
                        isset($data['custom_duration_days']) ? (int) $data['custom_duration_days'] : null,
                        Carbon::parse($data['starts_at']), $data['note'] ?? null,
                    ))),
                Action::make('approveOther')->label(__('admin.requests.approve'))->icon('heroicon-o-check')->color('success')
                    ->visible(fn (PlusRequest $record) => $pending($record) && $record->type !== PlusRequest::TYPE_NEW)
                    ->schema(fn (PlusRequest $record) => [
                        Textarea::make('note')->label(__('admin.fields.note'))->maxLength(1000)->required($record->type === PlusRequest::TYPE_CUSTOM),
                    ])
                    ->modalDescription(fn (PlusRequest $record) => __("admin.plus.approve_{$record->type}_help"))
                    ->requiresConfirmation()
                    ->action(fn (PlusRequest $record, array $data) => BusinessAction::run(fn ($admin) => match ($record->type) {
                        PlusRequest::TYPE_RENEW => $service()->approveRenewal($record, $admin, $data['note'] ?? null),
                        PlusRequest::TYPE_CHANGE => $service()->approveChange($record, $admin, $data['note'] ?? null),
                        PlusRequest::TYPE_CANCEL => $service()->approveCancellation($record, $admin, $data['note'] ?? null),
                        default => $service()->resolveCustom($record, $admin, (string) $data['note']),
                    })),
                Action::make('reject')->label(__('admin.requests.reject'))->icon('heroicon-o-x-mark')->color('danger')
                    ->visible($pending)
                    ->schema([Textarea::make('note')->label(__('admin.fields.note'))->required()->maxLength(1000)])
                    ->action(fn (PlusRequest $record, array $data) => BusinessAction::run(fn ($admin) => $service()->reject($record, $admin, $data['note']))),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListPlusRequests::route('/')];
    }
}
