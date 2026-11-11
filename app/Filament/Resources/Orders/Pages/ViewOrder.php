<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\BusinessAction;
use App\Models\DeliveryOffer;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderCancellation;
use App\Models\OrderPayment;
use App\Services\Delivery\DeliveryOfferService;
use App\Services\Driver\DriverDeliveryService;
use App\Services\Driver\DriverRecommendationService;
use App\Services\Order\OrderAdminService;
use App\Services\Order\RefundService;
use App\Support\Auth\AdminPermissions;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

/**
 * WAFFRLI (2026-10-31). Order workflow actions. Every action calls the
 * shared services, which enforce permissions, transitions and financial
 * caps; the visibility rules here only hide actions that cannot apply.
 */
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    /** UX redesign round 2: "Order DEMO-0028" instead of a generic "View order". */
    public function getTitle(): string
    {
        return __('admin.orders.title_number', ['number' => $this->getRecord()->order_number]);
    }

    public function getSubheading(): ?string
    {
        $record = $this->getRecord();

        return collect([$record->status->label(), $record->branch?->store?->name, $record->branch?->name])->filter()->implode(' · ');
    }

    protected function service(): OrderAdminService
    {
        return app(OrderAdminService::class);
    }

    /** Dispatch B4: the dispatch panel polls this; realtime admin.orders events call it too. */
    public function refreshDispatch(): void
    {
        $this->record->refresh();
    }

    #[On('waffrli-orders-changed')]
    public function refreshFromRealtime(): void
    {
        $this->refreshDispatch();
    }

    protected function offers(): DeliveryOfferService
    {
        return app(DeliveryOfferService::class);
    }

    protected function hasPendingOffer(Order $record): bool
    {
        return DeliveryOffer::query()->where('pending_order_marker', $record->id)->exists();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->acceptAction(),
            $this->assignAction(),
            $this->sendOfferAction(),
            $this->restartDispatchAction(),
            $this->withdrawOfferAction(),
            $this->forceAssignAction(),
            Action::make('unassign')->label(__('admin.orders.unassign'))->icon('heroicon-o-user-minus')->color('gray')
                ->visible(fn (Order $record) => $record->status === OrderStatus::Assigned && AdminPermissions::currentAllows('orders.assign'))
                ->requiresConfirmation()
                ->action(fn (Order $record) => BusinessAction::run(fn ($admin) => $this->service()->unassignDriver($record, $admin))),
            // WAFFRLI driver API (2026-11-02): proof-of-delivery photo from the private disk.
            Action::make('deliveryPhoto')->label(__('admin.orders.delivery_photo'))->icon('heroicon-o-camera')->color('gray')
                ->visible(fn (Order $record) => filled($record->delivery_photo_path) && AdminPermissions::currentAllows('orders.view'))
                ->action(fn (Order $record) => Storage::disk(DriverDeliveryService::PHOTO_DISK)->download($record->delivery_photo_path)),
            ActionGroup::make([$this->cancelAction(), $this->refundAction()])->label(__('admin.orders.more'))->button(),
        ];
    }

    protected function acceptAction(): Action
    {
        return Action::make('accept')->label(__('admin.orders.accept'))->icon('heroicon-o-check')->color('success')
            ->visible(fn (Order $record) => $record->status === OrderStatus::Pending && AdminPermissions::currentAllows('orders.accept'))
            ->modalDescription(fn (Order $record) => ($opens = $this->service()->acceptanceOpensAt($record))
                ? __('admin.orders.scheduled_acceptance_opens', ['at' => $opens->toDateTimeString()]) : null)
            ->requiresConfirmation()
            ->action(fn (Order $record) => BusinessAction::run(fn ($admin) => $this->service()->accept($record, $admin)));
    }

    protected function assignAction(): Action
    {
        return Action::make('assign')->label(fn (Order $record) => $record->driver_id ? __('admin.orders.reassign') : __('admin.orders.assign'))
            ->icon('heroicon-o-truck')->color('primary')
            // Dispatch B4: while delivery offers are enabled, drivers accept offers instead.
            ->visible(fn (Order $record) => ! DeliveryOfferService::enabled()
                && in_array($record->status, [OrderStatus::Accepted, OrderStatus::Assigned], true) && AdminPermissions::currentAllows('orders.assign'))
            ->schema(fn (Order $record) => [
                Select::make('driver_id')->label(__('admin.orders.recommended_drivers'))->required()
                    ->options(app(DriverRecommendationService::class)->recommend($record)->mapWithKeys(fn ($row) => [
                        $row['driver']->id => $row['driver']->name.' — '.($row['distance_km'] !== null ? $row['distance_km'].' km' : __('admin.drivers.no_position')),
                    ]))
                    ->helperText(__('admin.orders.recommendation_help')),
            ])
            ->action(fn (Order $record, array $data) => BusinessAction::run(
                fn ($admin) => $this->service()->assignDriver($record, Driver::findOrFail($data['driver_id']), $admin)
            ));
    }

    /** Eligible drivers for a NEW round, fairest first (only free, available, same-city drivers). */
    protected function offerDriverOptions(Order $record): array
    {
        return app(DriverRecommendationService::class)->dispatchCandidates($record, $record->dispatch_round + 1, 50)
            ->mapWithKeys(fn ($row) => [$row['driver']->id => implode(' — ', array_filter([
                $row['driver']->name,
                $row['meta']['location_used'] ? __('admin.dispatch.meta_distance', ['km' => $row['meta']['distance_km']]) : __('admin.dispatch.meta_no_fresh_gps'),
                __('admin.dispatch.meta_deliveries_today', ['count' => $row['meta']['deliveries_today']]),
                $row['meta']['last_offered_at'] ? __('admin.dispatch.meta_last_offer', ['ago' => Carbon::parse($row['meta']['last_offered_at'])->diffForHumans()]) : __('admin.dispatch.meta_never_offered'),
            ]))])->all();
    }

    protected function offerDriverField(Order $record): Select
    {
        return Select::make('driver_id')->label(__('admin.dispatch.choose_driver'))->required()->searchable()
            ->options(fn () => $this->offerDriverOptions($record))
            ->helperText(__('admin.dispatch.choose_driver_help', ['seconds' => DeliveryOfferService::timeoutSeconds()]));
    }

    /** First offer of an order (no dispatch yet): the admin picks the driver; the order stays accepted. */
    protected function sendOfferAction(): Action
    {
        return Action::make('sendOffer')->label(__('admin.dispatch.send_offer'))->icon('heroicon-o-paper-airplane')->color('primary')
            ->visible(fn (Order $record) => DeliveryOfferService::enabled() && $record->status === OrderStatus::Accepted
                && $record->dispatch_round === 0 && ! $this->hasPendingOffer($record) && AdminPermissions::currentAllows('orders.assign'))
            ->schema(fn (Order $record) => [$this->offerDriverField($record)])
            ->action(function (Order $record, array $data) {
                BusinessAction::run(fn ($admin) => $this->offers()->offerByAdmin($record, Driver::findOrFail($data['driver_id']), $admin), __('admin.dispatch.offer_sent'));
                $this->refreshDispatch();
            });
    }

    /** New admin round (after a no-driver queue, a pause, or to replace the pending offer). */
    protected function restartDispatchAction(): Action
    {
        return Action::make('restartDispatch')->label(__('admin.dispatch.restart'))->icon('heroicon-o-arrow-path')->color('warning')
            ->visible(fn (Order $record) => DeliveryOfferService::enabled() && $record->status === OrderStatus::Accepted
                && $record->dispatch_round > 0 && AdminPermissions::currentAllows('orders.assign'))
            ->schema(fn (Order $record) => [$this->offerDriverField($record)])
            ->requiresConfirmation()
            ->modalDescription(fn (Order $record) => $this->hasPendingOffer($record) ? __('admin.dispatch.restart_withdraws') : __('admin.dispatch.restart_help'))
            ->action(function (Order $record, array $data) {
                BusinessAction::run(fn ($admin) => $this->offers()->restartByAdmin($record, Driver::findOrFail($data['driver_id']), $admin), __('admin.dispatch.offer_sent'));
                $this->refreshDispatch();
            });
    }

    /** Withdraw = PAUSE: no automatic next driver until an admin sends a new offer. */
    protected function withdrawOfferAction(): Action
    {
        return Action::make('withdrawOffer')->label(__('admin.dispatch.withdraw'))->icon('heroicon-o-hand-raised')->color('gray')
            ->visible(fn (Order $record) => DeliveryOfferService::enabled() && $this->hasPendingOffer($record) && AdminPermissions::currentAllows('orders.assign'))
            ->requiresConfirmation()
            ->modalDescription(__('admin.dispatch.withdraw_help'))
            ->action(function (Order $record) {
                BusinessAction::run(fn ($admin) => $this->offers()->withdrawByAdmin($record, $admin));
                $this->refreshDispatch();
            });
    }

    /** Emergency only: assigns without the driver's acceptance; separate permission, reason required, audited. */
    protected function forceAssignAction(): Action
    {
        return Action::make('forceAssign')->label(__('admin.dispatch.force_assign'))->icon('heroicon-o-bolt')->color('danger')
            ->visible(fn (Order $record) => DeliveryOfferService::enabled()
                && in_array($record->status, [OrderStatus::Accepted, OrderStatus::Assigned], true) && AdminPermissions::currentAllows('orders.force_assign'))
            ->schema(fn (Order $record) => [
                Select::make('driver_id')->label(__('admin.orders.recommended_drivers'))->required()->searchable()
                    ->options(app(DriverRecommendationService::class)->recommend($record, 50)->mapWithKeys(fn ($row) => [
                        $row['driver']->id => $row['driver']->name.' — '.($row['distance_km'] !== null ? $row['distance_km'].' km' : __('admin.drivers.no_position')),
                    ])),
                Textarea::make('reason')->label(__('admin.dispatch.force_reason'))->required()->minLength(5)->maxLength(500),
            ])
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-exclamation-triangle')
            ->modalDescription(__('admin.dispatch.force_warning'))
            ->action(function (Order $record, array $data) {
                BusinessAction::run(fn ($admin) => $this->service()->forceAssignDriver($record, Driver::findOrFail($data['driver_id']), $admin, (string) $data['reason']));
                $this->refreshDispatch();
            });
    }

    protected function cancelAction(): Action
    {
        $afterPickup = fn (Order $record) => $record->status === OrderStatus::PickedUp;
        $walletPayment = fn (Order $record) => $record->payments()->whereHas('method', fn ($query) => $query->where('kind', 'wallet'))->first();
        $cashPayment = fn (Order $record) => $record->payments()->whereHas('method', fn ($query) => $query->where('kind', 'cash'))->first();

        return Action::make('cancel')->label(__('admin.orders.cancel'))->icon('heroicon-o-x-circle')->color('danger')
            ->visible(fn (Order $record) => in_array($record->status, [OrderStatus::Pending, OrderStatus::Accepted, OrderStatus::Assigned, OrderStatus::PickedUp], true)
                && AdminPermissions::currentAllows('orders.cancel')
                && (! $afterPickup($record) || AdminPermissions::currentAllows('orders.cancel_after_pickup')))
            ->schema(fn (Order $record) => array_filter([
                Hidden::make('idempotency_key')->default(fn () => (string) Str::uuid()),
                Select::make('reason_code')->label(__('admin.fields.reason'))->required()
                    ->options(collect(OrderCancellation::REASONS)->mapWithKeys(fn ($r) => [$r => __("admin.orders.reason_{$r}")])),
                Textarea::make('note')->label(__('admin.fields.note'))->maxLength(500)->required($afterPickup($record)),
                $afterPickup($record) ? Section::make(__('admin.orders.after_pickup'))->description(__('admin.orders.after_pickup_help'))->schema([
                    Select::make('driver_handling')->label(__('admin.fields.driver_handling'))->required()
                        ->options(collect(OrderCancellation::DRIVER_HANDLING)->reject(fn ($h) => $h === 'not_applicable')
                            ->mapWithKeys(fn ($h) => [$h => __("admin.orders.handling_{$h}")])),
                    TextInput::make('cash_collected_amount')->label(__('admin.fields.cash_collected_amount'))->numeric()->minValue(0)
                        ->maxValue(fn () => (float) ($cashPayment($record)?->amount ?? 0))->default(0)->rule('decimal:0,4')
                        ->visible(fn () => $cashPayment($record) !== null),
                    TextInput::make('wallet_reversal_amount')->label(__('admin.fields.wallet_reversal_amount'))->numeric()->minValue(0)
                        ->maxValue(fn () => (float) (string) ($walletPayment($record)?->returnableAmount() ?? 0))
                        ->default(fn () => (string) ($walletPayment($record)?->returnableAmount() ?? 0))->rule('decimal:0,4')
                        ->visible(fn () => $walletPayment($record) !== null),
                    TextInput::make('refund_collected_cash_amount')->label(__('admin.fields.refund_collected_cash_amount'))->numeric()->minValue(0)->default(0)
                        ->rule('decimal:0,4')->lte('cash_collected_amount')
                        ->helperText(__('admin.orders.refund_cash_help'))
                        ->visible(fn () => $cashPayment($record) !== null && AdminPermissions::currentAllows('refunds.create')),
                    Toggle::make('restore_free_delivery')->label(__('admin.fields.restore_free_delivery'))
                        ->visible(fn () => $record->free_delivery_applied),
                ]) : null,
            ]))
            ->requiresConfirmation()
            ->modalDescription(fn (Order $record) => $afterPickup($record) ? __('admin.orders.cancel_after_pickup_warning') : __('admin.orders.cancel_warning'))
            ->action(fn (Order $record, array $data) => BusinessAction::run(fn ($admin) => $this->service()->cancelByAdmin($record, $admin, $data)));
    }

    protected function refundAction(): Action
    {
        return Action::make('refund')->label(__('admin.orders.refund'))->icon('heroicon-o-arrow-uturn-left')->color('warning')
            ->visible(fn (Order $record) => $record->status === OrderStatus::Delivered && AdminPermissions::currentAllows('refunds.create'))
            ->schema(fn (Order $record) => [
                Hidden::make('idempotency_key')->default(fn () => (string) Str::uuid()),
                Select::make('order_payment_id')->label(__('admin.fields.payment'))->required()
                    ->options($record->payments()->with('method')->get()
                        ->filter(fn (OrderPayment $p) => $p->returnableAmount()->isGreaterThan(0))
                        ->mapWithKeys(fn (OrderPayment $p) => [$p->id => $p->method?->name.' — '.__('admin.orders.refundable_up_to', ['amount' => (string) $p->returnableAmount()])])),
                TextInput::make('amount')->label(__('admin.fields.amount'))->required()->numeric()->gt(0)->rule('decimal:0,4'),
                TextInput::make('reason_code')->label(__('admin.fields.reason'))->required()->maxLength(32)->alphaDash(),
                Textarea::make('note')->label(__('admin.fields.note'))->maxLength(500),
            ])
            ->modalDescription(__('admin.orders.refund_help'))
            ->requiresConfirmation()
            ->action(fn (Order $record, array $data) => BusinessAction::run(fn ($admin) => app(RefundService::class)->refund(
                OrderPayment::where('order_id', $record->id)->findOrFail($data['order_payment_id']),
                (string) $data['amount'], $data['reason_code'], $data['note'] ?? null, $admin, $data['idempotency_key'],
            )));
    }
}
