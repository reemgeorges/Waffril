<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Http\Requests\Model\Driver\DriverAvailabilityRequest;
use App\Http\Traits\GeneralTrait;
use App\Models\Driver;
use App\Services\Driver\DriverAccountService;
use Illuminate\Http\Request;

/** WAFFRLI driver API (2026-11-02) — available / offline, consistent with active deliveries. */
class DriverAvailabilityController extends Controller
{
    use GeneralTrait;

    public function __construct(protected DriverAccountService $accounts) {}

    protected function payload(Driver $driver): array
    {
        $active = $driver->activeOrders()->orderBy('assigned_at')->get(['id', 'order_number', 'status']);

        return [
            'availability' => $driver->availability,
            'is_active' => (bool) $driver->is_active,
            'has_active_delivery' => $active->isNotEmpty(),
            'can_go_offline' => $active->isEmpty(),
            'active_orders' => $active->map(fn ($o) => ['id' => $o->id, 'order_number' => $o->order_number, 'status' => $o->status->value])->values(),
        ];
    }

    public function show(Request $request)
    {
        try {
            return $this->apiResponse($this->payload($request->user('driver')));
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function update(DriverAvailabilityRequest $request)
    {
        try {
            $driver = $this->accounts->setAvailability($request->user('driver'), $request->input('availability'));

            return $this->apiResponse(['message' => __('messages.driver.availability_updated')] + $this->payload($driver));
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }
}
