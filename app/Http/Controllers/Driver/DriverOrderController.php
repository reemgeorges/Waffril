<?php

namespace App\Http\Controllers\Driver;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Model\Driver\DriverDeliverRequest;
use App\Http\Requests\Model\Driver\DriverProblemRequest;
use App\Http\Resources\Model\Driver\DriverOrderResource;
use App\Http\Traits\GeneralTrait;
use App\Models\Order;
use App\Services\Driver\DriverDeliveryService;
use Illuminate\Http\Request;

/**
 * WAFFRLI driver API (2026-11-02) — the driver's own orders and delivery
 * actions. Every lookup is scoped to `driver_id = authenticated driver`;
 * other drivers' orders answer 404.
 */
class DriverOrderController extends Controller
{
    use GeneralTrait;

    protected const WITH = ['branch.store', 'city', 'deliveryArea', 'user', 'currency', 'items.options', 'payments.method'];

    public function __construct(protected DriverDeliveryService $deliveries) {}

    public function active(Request $request)
    {
        try {
            $orders = Order::query()->where('driver_id', $request->user('driver')->id)
                ->whereIn('status', [OrderStatus::Assigned->value, OrderStatus::PickedUp->value])
                ->with(self::WITH)->orderBy('assigned_at')->get();

            return $this->apiResponse(['orders' => DriverOrderResource::collection($orders)]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function history(Request $request)
    {
        try {
            $perPage = min(max((int) $request->query('per_page', 20), 1), 50);
            $page = Order::query()->where('driver_id', $request->user('driver')->id)
                ->whereIn('status', [OrderStatus::Delivered->value, OrderStatus::Cancelled->value])
                ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
                ->with(self::WITH)->orderByDesc('updated_at')->orderByDesc('id')
                ->paginate($perPage);

            return $this->apiResponse([
                'orders' => DriverOrderResource::collection($page->getCollection()),
                'pagination' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function show(Request $request, string $order)
    {
        try {
            $model = $this->deliveries->ownOrder($request->user('driver'), $order)->load(self::WITH);

            return $this->apiResponse(['order' => DriverOrderResource::make($model)]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function pickup(Request $request, string $order)
    {
        try {
            $model = $this->deliveries->pickUp($request->user('driver'), $order)->load(self::WITH);

            return $this->apiResponse(['message' => __('messages.driver.picked_up'), 'order' => DriverOrderResource::make($model)]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function deliver(DriverDeliverRequest $request, string $order)
    {
        try {
            $model = $this->deliveries->deliver(
                $request->user('driver'), $order,
                $request->filled('cash_collected') ? (string) $request->input('cash_collected') : null,
                $request->input('currency'),
                $request->file('photo'),
            )->load(self::WITH);

            return $this->apiResponse(['message' => __('messages.driver.delivered'), 'order' => DriverOrderResource::make($model)]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function reportProblem(DriverProblemRequest $request, string $order)
    {
        try {
            $result = $this->deliveries->reportProblem($request->user('driver'), $order, $request->input('type'), $request->input('note'));

            return $this->apiResponse(['message' => __('messages.driver.problem_reported')] + $result);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }
}
