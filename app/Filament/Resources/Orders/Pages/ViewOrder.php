<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\BusinessAction;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderCancellation;
use App\Models\OrderPayment;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * WAFFRLI (2026-10-31). Order workflow actions. Every action calls the
 * shared services, which enforce permissions, transitions and financial
 * caps; the visibility rules here only hide actions that cannot apply.
 */
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function service(): OrderAdminService
    {
        return app(OrderAdminService::class);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->acceptAction(),
            $this->assignAction(),
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
            ->visible(fn (Order $record) => in_array($record->status, [OrderStatus::Accepted, OrderStatus::Assigned], true) && AdminPermissions::currentAllows('orders.assign'))
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
