<?php

namespace App\Events\Realtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * WAFFRLI (2026-11-03) — a driver's REAL reported position (POST
 * /api/v1/driver/location), never simulated and never stored as history.
 *
 * Sent to the order channels that were re-checked at send time
 * (ChannelAccess::orderIsTrackable: still this driver's, still assigned or
 * picked up) and to `admin.drivers-map` (admins with drivers.track).
 */
class DriverLocationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /** @param  list<int>  $orderIds */
    public function __construct(
        public int $driverId,
        public array $orderIds,
        public float $lat,
        public float $lng,
        public string $at,
    ) {}

    public function broadcastOn(): array
    {
        $channels = array_map(fn (int $id) => new PrivateChannel('order.'.$id), $this->orderIds);
        $channels[] = new PrivateChannel('admin.drivers-map');

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'driver.location';
    }

    public function broadcastWith(): array
    {
        return ['driver_id' => $this->driverId, 'lat' => $this->lat, 'lng' => $this->lng, 'at' => $this->at];
    }
}
