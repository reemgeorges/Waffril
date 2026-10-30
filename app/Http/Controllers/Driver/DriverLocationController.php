<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Http\Requests\Model\Driver\DriverLocationRequest;
use App\Http\Traits\GeneralTrait;
use App\Services\Driver\DriverDeliveryService;

/** WAFFRLI driver API (2026-11-02) — last-position updates during an active delivery. */
class DriverLocationController extends Controller
{
    use GeneralTrait;

    public function __construct(protected DriverDeliveryService $deliveries) {}

    public function update(DriverLocationRequest $request)
    {
        try {
            $driver = $this->deliveries->updateLocation($request->user('driver'), (float) $request->input('lat'), (float) $request->input('lng'));

            return $this->apiResponse([
                'message' => __('messages.driver.location_updated'),
                'location' => ['lat' => (float) $driver->last_lat, 'lng' => (float) $driver->last_lng, 'recorded_at' => $driver->last_location_at?->toIso8601String()],
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }
}
