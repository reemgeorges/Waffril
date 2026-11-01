<?php

namespace App\Http\Resources\Model\Driver;

use App\Http\Resources\Basic\BasicResource;
use App\Services\Driver\DriverAccountService;
use App\Support\Localization\Label;
use Illuminate\Http\Request;

/**
 * WAFFRLI driver API (2026-11-02) — the authenticated driver's own account.
 * Never includes the password hash or tokens.
 */
class DriverProfileResource extends BasicResource
{
    public function toArray(Request $request): array
    {
        $driver = $this->resource;
        $active = $driver->activeOrders()->orderBy('assigned_at')->get(['id', 'order_number', 'status']);

        return [
            'id' => $driver->id,
            'name' => $driver->name,
            'phone' => $driver->phone,
            'language' => $driver->language,
            'city' => $driver->city ? ['id' => $driver->city->id, 'name' => $driver->city->name] : null,
            'vehicle' => ['type' => $driver->vehicle_type, 'plate' => $driver->vehicle_plate],
            'account' => ['is_active' => (bool) $driver->is_active],
            'availability' => [
                'status' => $driver->availability,
                'status_label' => Label::of('driver_availability', $driver->availability),
                'has_active_delivery' => $active->isNotEmpty(),
                'can_go_offline' => $active->isEmpty(),
                'active_orders' => $active->map(fn ($o) => ['id' => $o->id, 'order_number' => $o->order_number, 'status_label' => $o->status->label(), 'status' => $o->status->value])->values(),
            ],
            'last_location' => $driver->last_location_at ? [
                'lat' => (float) $driver->last_lat, 'lng' => (float) $driver->last_lng,
                'recorded_at' => $driver->last_location_at->toIso8601String(),
            ] : null,
            'editable_fields' => DriverAccountService::EDITABLE_PROFILE_FIELDS,
        ];
    }
}
