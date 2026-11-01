<?php

namespace App\Http\Controllers\Driver;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Model\Driver\DriverCashCollectionResource;
use App\Http\Resources\Model\Driver\DriverSettlementResource;
use App\Http\Traits\GeneralTrait;
use App\Models\DriverCashSettlement;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Services\Driver\DriverDeliveryService;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;

/**
 * WAFFRLI driver API (2026-11-02) — read-only cash views for the
 * authenticated driver. Recording cash happens only in the delivery
 * workflow; reconciling it is an admin action in Filament.
 */
class DriverCashController extends Controller
{
    use GeneralTrait;

    protected function cashCollections(int $driverId)
    {
        return OrderPayment::query()->where('collected_by_driver_id', $driverId)
            ->where('collected_amount', '>', 0)
            ->whereHas('method', fn ($query) => $query->where('kind', 'cash'));
    }

    /** Per currency: cash to collect on active deliveries and collected cash not yet settled. */
    public function summary(Request $request)
    {
        try {
            $driver = $request->user('driver');
            $rows = [];

            foreach (Order::query()->where('driver_id', $driver->id)->whereIn('status', [OrderStatus::Assigned->value, OrderStatus::PickedUp->value])->with('currency')->get() as $order) {
                $code = $order->currency->code;
                $rows[$code] ??= ['currency' => $code, 'to_collect_on_active_deliveries' => BigDecimal::zero(), 'collected_unsettled' => BigDecimal::zero(), 'collected_in_open_or_disputed_settlements' => BigDecimal::zero()];
                $rows[$code]['to_collect_on_active_deliveries'] = $rows[$code]['to_collect_on_active_deliveries']->plus(DriverDeliveryService::cashDue($order));
            }

            foreach ($this->cashCollections($driver->id)->with(['currency', 'settlement'])->get() as $payment) {
                $code = $payment->currency->code;
                $rows[$code] ??= ['currency' => $code, 'to_collect_on_active_deliveries' => BigDecimal::zero(), 'collected_unsettled' => BigDecimal::zero(), 'collected_in_open_or_disputed_settlements' => BigDecimal::zero()];
                $amount = BigDecimal::of((string) $payment->collected_amount);
                if (! $payment->settlement) {
                    $rows[$code]['collected_unsettled'] = $rows[$code]['collected_unsettled']->plus($amount);
                } elseif ($payment->settlement->status !== DriverCashSettlement::STATUS_SETTLED) {
                    $rows[$code]['collected_in_open_or_disputed_settlements'] = $rows[$code]['collected_in_open_or_disputed_settlements']->plus($amount);
                }
            }

            $rows = array_values(array_map(fn ($row) => array_map(fn ($v) => $v instanceof BigDecimal ? Money::toStorage($v) : $v, $row), $rows));

            return $this->apiResponse(['currencies' => $rows]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function collections(Request $request)
    {
        try {
            $perPage = min(max((int) $request->query('per_page', 20), 1), 50);
            $page = $this->cashCollections($request->user('driver')->id)
                ->with(['order', 'currency', 'settlement'])->orderByDesc('paid_at')->orderByDesc('id')->paginate($perPage);

            return $this->apiResponse([
                'collections' => DriverCashCollectionResource::collection($page->getCollection()),
                'pagination' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function settlements(Request $request)
    {
        try {
            $perPage = min(max((int) $request->query('per_page', 20), 1), 50);
            $page = DriverCashSettlement::query()->where('driver_id', $request->user('driver')->id)
                ->with('currency')->orderByDesc('business_date')->orderByDesc('id')->paginate($perPage);

            return $this->apiResponse([
                'settlements' => DriverSettlementResource::collection($page->getCollection()),
                'pagination' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }
}
