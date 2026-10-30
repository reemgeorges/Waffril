<?php

namespace App\Filament\Pages;

use App\Enums\OrderStatus;
use App\Models\Driver;
use App\Support\Auth\AdminPermissions;
use Filament\Pages\Page;

/**
 * WAFFRLI (2026-10-31) — map of drivers WITH ACTIVE DELIVERIES only
 * (assigned / picked up), using each driver's last stored position.
 *
 * Data access is real (drivers + orders tables). The page refreshes by
 * polling the database every 30 s. True live updates will come from the
 * single shared Laravel Reverb WebSocket (private channel
 * `admin.drivers-map`), which is NOT installed in this phase — nothing on
 * this page simulates live socket updates.
 */
class DriverMapPage extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'drivers';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'driver-map';

    protected string $view = 'filament.pages.driver-map';

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

    /** @return list<array<string, mixed>> */
    public static function activeDrivers(): array
    {
        return Driver::query()
            ->whereHas('orders', fn ($query) => $query->whereIn('status', [OrderStatus::Assigned->value, OrderStatus::PickedUp->value]))
            ->with(['city', 'orders' => fn ($query) => $query->whereIn('status', [OrderStatus::Assigned->value, OrderStatus::PickedUp->value])->with('branch')])
            ->get()
            ->map(fn (Driver $driver) => [
                'id' => $driver->id,
                'name' => $driver->name,
                'phone' => $driver->phone,
                'city' => $driver->city?->name,
                'lat' => $driver->last_lat !== null ? (float) $driver->last_lat : null,
                'lng' => $driver->last_lng !== null ? (float) $driver->last_lng : null,
                'last_location_at' => $driver->last_location_at?->diffForHumans(),
                'orders' => $driver->orders->map(fn ($o) => [
                    'number' => $o->order_number,
                    'status' => $o->status->label(),
                    'branch' => $o->branch?->name,
                ])->all(),
            ])->all();
    }

    /** Called by the 30 s database poll; redraws the map markers. */
    public function refreshMap(): void
    {
        $this->dispatch('driver-map-refreshed', drivers: self::activeDrivers());
    }

    protected function getViewData(): array
    {
        return ['drivers' => self::activeDrivers()];
    }
}
