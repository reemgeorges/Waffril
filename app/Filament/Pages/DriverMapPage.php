<?php

namespace App\Filament\Pages;

use App\Enums\OrderStatus;
use App\Models\City;
use App\Models\Order;
use App\Support\Auth\AdminPermissions;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * WAFFRLI (2026-11-01) — interactive map of drivers WITH AN ACTIVE DELIVERY
 * (order assigned or picked up), drawn with Leaflet (vendored in
 * public/vendor/leaflet) on OpenStreetMap tiles.
 *
 * - Markers use each driver's last STORED position (drivers.last_lat/lng,
 *   last_location_at); nothing here simulates GPS movement.
 * - A driver disappears as soon as no assigned / picked-up order remains.
 * - Positions refresh by polling the database (config
 *   waffrli.driver_map.refresh_seconds). The same payload (markers()) is
 *   what a future listener on the single shared Reverb WebSocket (private
 *   channel `admin.drivers-map`) would push; Reverb is not installed.
 * - Every render and refresh re-checks `drivers.track`, so a revoked
 *   permission stops the data stream immediately.
 */
class DriverMapPage extends Page
{
    public const STATUSES = [OrderStatus::Assigned, OrderStatus::PickedUp];

    protected static string|\UnitEnum|null $navigationGroup = 'drivers';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'driver-map';

    protected string $view = 'filament.pages.driver-map';

    /** Filters (Livewire state). */
    public ?int $cityId = null;

    public ?string $status = null;

    public static function canAccess(): bool
    {
        return AdminPermissions::currentAllows('drivers.track');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.drivers.map');
    }

    public function getTitle(): string
    {
        return __('admin.drivers.map');
    }

    protected function ensureCanTrack(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return list<array<string, mixed>> one entry per driver currently delivering */
    public function markers(): array
    {
        $this->ensureCanTrack();

        $statuses = $this->status && in_array($this->status, array_map(fn ($s) => $s->value, self::STATUSES), true)
            ? [$this->status]
            : array_map(fn ($s) => $s->value, self::STATUSES);
        $staleAfter = (int) config('waffrli.driver_map.stale_after_minutes', 10);

        return Order::query()
            ->whereIn('status', $statuses)
            ->whereNotNull('driver_id')
            ->when($this->cityId, fn ($query) => $query->where('city_id', $this->cityId))
            ->with(['driver.city', 'branch'])
            ->orderBy('assigned_at')
            ->get()
            ->groupBy('driver_id')
            ->map(function ($orders) use ($staleAfter) {
                $driver = $orders->first()->driver;
                $at = $driver->last_location_at ? Carbon::parse($driver->last_location_at) : null;

                return [
                    'id' => $driver->id,
                    'name' => $driver->name,
                    'phone' => $driver->phone,
                    'city' => $driver->city?->name,
                    'lat' => $driver->last_lat !== null ? (float) $driver->last_lat : null,
                    'lng' => $driver->last_lng !== null ? (float) $driver->last_lng : null,
                    'updated_at' => $at?->toIso8601String(),
                    'updated_human' => $at?->diffForHumans(),
                    'stale' => $at === null || $at->lt(now()->subMinutes($staleAfter)),
                    'status' => $orders->contains(fn (Order $o) => $o->status === OrderStatus::PickedUp) ? OrderStatus::PickedUp->value : OrderStatus::Assigned->value,
                    'orders' => $orders->map(fn (Order $o) => [
                        'number' => $o->order_number,
                        'status' => $o->status->value,
                        'status_label' => $o->status->label(),
                        'branch' => $o->branch?->name,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /** Polled from the page; also the hook a future Reverb listener would call. */
    public function refreshMap(bool $fit = false): void
    {
        $this->dispatch('driver-map-updated', markers: $this->markers(), fit: $fit);
    }

    public function updatedCityId(): void
    {
        $this->refreshMap(fit: true);
    }

    public function updatedStatus(): void
    {
        $this->refreshMap(fit: true);
    }

    protected function getViewData(): array
    {
        return [
            'markers' => $this->markers(),
            'cities' => City::query()->where('is_active', true)->orderBy('sort_order')->get()->mapWithKeys(fn (City $c) => [$c->id => $c->name])->all(),
            'statuses' => collect(self::STATUSES)->mapWithKeys(fn (OrderStatus $s) => [$s->value => $s->label()])->all(),
            'refreshSeconds' => max(5, (int) config('waffrli.driver_map.refresh_seconds', 30)),
            'staleMinutes' => (int) config('waffrli.driver_map.stale_after_minutes', 10),
            'tileUrl' => config('waffrli.driver_map.tile_url'),
            'tileAttribution' => config('waffrli.driver_map.tile_attribution'),
            'labels' => [
                'order' => __('admin.driver_map.order'),
                'status' => __('admin.driver_map.status'),
                'updated' => __('admin.driver_map.updated'),
                'stale' => __('admin.driver_map.stale'),
                'no_position' => __('admin.driver_map.no_position'),
            ],
        ];
    }
}
