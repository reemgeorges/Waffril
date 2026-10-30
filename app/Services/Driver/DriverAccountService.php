<?php

namespace App\Services\Driver;

use App\Exceptions\BusinessRuleException;
use App\Models\Driver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * WAFFRLI driver API (2026-11-02) — driver self-service: phone + password
 * login (accounts are created by admins in Filament; there is no
 * self-registration), logout, password change, the few profile fields a
 * driver may edit, and availability.
 *
 * Availability is consistent with assignments: a driver with an assigned
 * or picked-up delivery cannot go offline (that would strand the order);
 * only `available` and `offline` exist.
 */
class DriverAccountService
{
    public const EDITABLE_PROFILE_FIELDS = ['language'];

    /** @return array{token: string, driver: Driver} */
    public function login(string $phone, string $password, ?string $deviceName): array
    {
        $driver = Driver::query()->where('phone', $phone)->first();

        // Same message for unknown phone and wrong password (no account enumeration);
        // Hash::check still runs against a dummy hash to keep timing similar.
        if (! $driver || ! Hash::check($password, $driver->password ?? Hash::make('dummy-password-for-timing'))) {
            throw new BusinessRuleException(__('messages.driver.invalid_credentials'), 401);
        }
        if (! $driver->is_active) {
            throw new BusinessRuleException(__('messages.driver.account_inactive'), 403);
        }

        $token = $driver->createToken(mb_substr($deviceName ?: 'driver-app', 0, 100), ['driver', '*'])->plainTextToken;

        return ['token' => $token, 'driver' => $driver->fresh(['city'])];
    }

    public function logout(Driver $driver): void
    {
        $driver->currentAccessToken()?->delete();
    }

    /** Changes the password and revokes every OTHER token of this driver. */
    public function changePassword(Driver $driver, string $current, string $new): void
    {
        if (! Hash::check($current, $driver->password)) {
            throw new BusinessRuleException(__('messages.driver.current_password_wrong'), 422);
        }

        DB::transaction(function () use ($driver, $new) {
            $driver->forceFill(['password' => $new])->save();
            $currentId = $driver->currentAccessToken()?->id;
            $driver->tokens()->when($currentId, fn ($query) => $query->whereKeyNot($currentId))->delete();
        });
    }

    public function updateProfile(Driver $driver, array $data): Driver
    {
        $driver->update(array_intersect_key($data, array_flip(self::EDITABLE_PROFILE_FIELDS)));

        return $driver->fresh(['city']);
    }

    public function hasActiveDelivery(Driver $driver): bool
    {
        return $driver->activeOrders()->exists();
    }

    public function setAvailability(Driver $driver, string $availability): Driver
    {
        if (! in_array($availability, [Driver::AVAILABILITY_AVAILABLE, Driver::AVAILABILITY_OFFLINE], true)) {
            throw new BusinessRuleException(__('validation.in', ['attribute' => 'availability']), 422);
        }

        return DB::transaction(function () use ($driver, $availability) {
            $driver = Driver::query()->lockForUpdate()->findOrFail($driver->getKey());

            if ($availability === Driver::AVAILABILITY_OFFLINE && $driver->activeOrders()->exists()) {
                throw new BusinessRuleException(__('messages.driver.cannot_go_offline_with_active_delivery'), 409);
            }

            $driver->forceFill(['availability' => $availability])->save();

            return $driver->fresh(['city']);
        });
    }
}
