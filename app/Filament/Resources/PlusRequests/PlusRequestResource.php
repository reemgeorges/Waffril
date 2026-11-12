<?php

namespace App\Filament\Resources\PlusRequests;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\PlusRequests\Pages\ListPlusRequests;
use App\Filament\Support\AdminResource;
use App\Filament\Support\BusinessAction;
use App\Filament\Support\PlusPolicyFields;
use App\Filament\Support\TableDefaults;
use App\Models\PlusRequest;
use App\Services\Plus\PlusService;
use App\Support\Auth\AdminPermissions;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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

    protected static ?int $navigationSort = 10;

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
            // UX overhaul (2026-11-12): find a request by customer name or phone,
            // narrow by type / plan / source / date; status lives in the page tabs.
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'requestedPlan', 'reviewedBy', 'membership.plan']))
            ->columns([
                TextColumn::make('created_at')->label(__('admin.plus.requested_at'))->dateTime()->sortable()
                    ->description(fn (PlusRequest $record) => $record->created_at?->diffForHumans()),
                TextColumn::make('user.first_name')->label(__('admin.fields.customer'))->weight(FontWeight::SemiBold)
                    ->state(fn (PlusRequest $record) => $record->user?->fullName())
                    ->description(fn (PlusRequest $record) => $record->user?->phone)
                    ->url(fn (PlusRequest $record) => $record->user && CustomerResource::canViewAny() ? CustomerResource::getUrl('view', ['record' => $record->user]) : null)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('user', fn (Builder $user) => $user
                        ->where('phone', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"))),
                TextColumn::make('type')->label(__('admin.fields.type'))->badge()->color('gray')
                    ->icon(fn ($state) => self::TYPE_ICONS[$state] ?? null)
                    ->formatStateUsing(fn ($state) => __("admin.plus.request_{$state}")),
                TextColumn::make('requestedPlan.name')->label(__('admin.fields.plan'))->placeholder('—')
                    ->description(fn (PlusRequest $record) => $record->membership?->plan && $record->membership->plan_id !== $record->requested_plan_id
                        ? __('admin.plus.current_plan', ['plan' => $record->membership->plan->name]) : null),
                TextColumn::make('customer_note')->label(__('admin.fields.customer_note'))->limit(50)->tooltip(fn (PlusRequest $record) => $record->customer_note)->placeholder('—')->wrap(),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()
                    ->color(fn ($state) => self::STATUS_COLORS[$state] ?? 'gray')
                    ->icon(fn ($state) => self::STATUS_ICONS[$state] ?? null)
                    ->formatStateUsing(fn ($state) => __("admin.requests.status_{$state}")),
                TextColumn::make('reviewedBy.name')->label(__('admin.plus.reviewed_by'))->placeholder('—')
                    ->description(fn (PlusRequest $record) => $record->reviewed_at?->translatedFormat(TableDefaults::dateTimeFormat())),
                TextColumn::make('admin_note')->label(__('admin.plus.review_note'))->limit(50)->tooltip(fn (PlusRequest $record) => $record->admin_note)
                    ->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source')->label(__('admin.fields.source'))->formatStateUsing(fn ($state) => __("admin.plus.source_{$state}"))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')->label(__('admin.fields.type'))->multiple()
                    ->options(collect([PlusRequest::TYPE_NEW, PlusRequest::TYPE_RENEW, PlusRequest::TYPE_CHANGE, PlusRequest::TYPE_CANCEL, PlusRequest::TYPE_CUSTOM])
                        ->mapWithKeys(fn ($t) => [$t => __("admin.plus.request_{$t}")])),
                SelectFilter::make('requested_plan_id')->label(__('admin.fields.plan'))->relationship('requestedPlan', 'code')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name)->preload(),
                SelectFilter::make('source')->label(__('admin.fields.source'))
                    ->options(['customer' => __('admin.plus.source_customer'), 'system' => __('admin.plus.source_system')]),
                Filter::make('requested_between')->label(__('admin.plus.requested_at'))
                    ->schema([
                        DatePicker::make('from')->label(__('admin.fields.from')),
                        DatePicker::make('until')->label(__('admin.fields.until')),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d)))
                    ->indicateUsing(fn (array $data) => array_values(array_filter([
                        ($data['from'] ?? null) ? __('admin.fields.from').' '.$data['from'] : null,
                        ($data['until'] ?? null) ? __('admin.fields.until').' '.$data['until'] : null,
                    ]))),
            ])
            ->filtersFormColumns(2)
            ->recordActions([
                Action::make('details')->label(__('admin.plus.details'))->icon('heroicon-o-eye')->color('gray')
                    ->modalHeading(fn (PlusRequest $record) => __('admin.plus.request_heading', ['customer' => $record->user?->fullName()]))
                    ->modalSubmitAction(false)->modalCancelActionLabel(__('admin.actions.close'))
                    ->modalContent(fn (PlusRequest $record) => view('filament.plus.request-details', [
                        'request' => $record,
                        'history' => PlusRequest::query()->with(['requestedPlan', 'reviewedBy'])->where('user_id', $record->user_id)->latest('id')->limit(20)->get(),
                    ])),
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
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('admin.plus.no_requests'))
            ->emptyStateIcon('heroicon-o-star');
    }

    /** Real request states (PlusRequest::STATUS_*): colour + icon + words. */
    public const STATUS_COLORS = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'withdrawn' => 'gray'];

    public const STATUS_ICONS = ['pending' => 'heroicon-m-clock', 'approved' => 'heroicon-m-check-circle', 'rejected' => 'heroicon-m-x-circle', 'withdrawn' => 'heroicon-m-arrow-uturn-left'];

    public const TYPE_ICONS = ['new' => 'heroicon-m-sparkles', 'renew' => 'heroicon-m-arrow-path', 'change' => 'heroicon-m-arrows-right-left', 'cancel' => 'heroicon-m-no-symbol', 'custom' => 'heroicon-m-chat-bubble-left-ellipsis'];

    public static function getPages(): array
    {
        return ['index' => ListPlusRequests::route('/')];
    }
}
