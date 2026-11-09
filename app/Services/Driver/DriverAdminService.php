<?php

namespace App\Services\Driver;

use App\Exceptions\BusinessRuleException;
use App\Models\Admin;
use App\Models\Driver;
use App\Services\Delivery\DeliveryOfferService;
use App\Services\Audit\AdminAudit;
use App\Support\Auth\AdminPermissions;

/**
 * WAFFRLI (2026-10-31) — driver account administration. A driver with an
 * active delivery cannot be deactivated or forced offline mid-delivery.
 */
class DriverAdminService
{
    public function setActive(Driver $driver, bool $active, Admin $admin): Driver
    {
        $this->authorize($admin);
        if (! $active && $driver->activeOrders()->exists()) {
            throw new BusinessRuleException(__('admin.errors.driver_has_active_delivery'));
        }

        $driver->forceFill(['is_active' => $active] + ($active ? [] : ['availability' => Driver::AVAILABILITY_OFFLINE]))->save();
        if (! $active) {
            $driver->tokens()->delete();
            $this->withdrawOffer($driver);
        }
        AdminAudit::log($active ? 'driver.activated' : 'driver.deactivated', $driver, [], $admin);

        return $driver;
    }

    public function forceOffline(Driver $driver, Admin $admin): Driver
    {
        $this->authorize($admin);
        if ($driver->activeOrders()->exists()) {
            throw new BusinessRuleException(__('admin.errors.driver_has_active_delivery'));
        }

        $driver->forceFill(['availability' => Driver::AVAILABILITY_OFFLINE])->save();
        $this->withdrawOffer($driver);
        AdminAudit::log('driver.forced_offline', $driver, [], $admin);

        return $driver;
    }

    protected function authorize(Admin $admin): void
    {
        if (! AdminPermissions::allows($admin, 'drivers.manage')) {
            throw new BusinessRuleException(__('admin.errors.forbidden'), 403);
        }
    }

    /** Dispatch B3 (2026-11-10): an unavailable driver's pending delivery offer moves on to the next driver. */
    protected function withdrawOffer(Driver $driver): void
    {
        if (DeliveryOfferService::enabled()) {
            app(DeliveryOfferService::class)->cancelPendingForDriver($driver);
        }
    }
}
