<?php

namespace App\Services\Customer;

use App\Contracts\Delivery\DistanceCalculator;
use App\Exceptions\BusinessRuleException;
use App\Models\City;
use App\Models\Currency;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\Auth\OtpService;
use App\Services\Model\City\CityService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * WAFFRLI customer API (2026-11-03) — profile, optional email (unique only
 * once VERIFIED), avatar, addresses and the effective delivery city.
 *
 * The effective delivery city is the city of the customer's default
 * (selected) address. GPS may only SUGGEST a city (cities/detect); an
 * address's own coordinates must lie inside the city it belongs to.
 */
class CustomerProfileService
{
    public const EMAIL_PURPOSE = 'customer_email_verify';

    public function update(User $user, array $data): User
    {
        $changes = array_intersect_key($data, array_flip(['first_name', 'last_name', 'language', 'display_currency_id']));
        if (array_key_exists('display_currency_id', $changes) && $changes['display_currency_id'] !== null
            && ! Currency::query()->whereKey($changes['display_currency_id'])->where('is_active', true)->exists()) {
            throw new BusinessRuleException(__('validation.exists', ['attribute' => 'display_currency_id']), 422);
        }
        if (isset($changes['first_name']) || isset($changes['last_name'])) {
            $changes['name'] = trim(($changes['first_name'] ?? $user->first_name).' '.($changes['last_name'] ?? $user->last_name));
        }

        $user->fill($changes);
        if (array_key_exists('email', $data)) {
            $email = $data['email'] !== null ? mb_strtolower(trim($data['email'])) : null;
            if ($email !== $user->email) {
                $user->email = $email;
                $user->forceFill(['email_verified_at' => null]);
            }
        }
        $user->save();

        return $user->fresh();
    }

    public function sendEmailVerification(User $user, ?string $ip): void
    {
        if (! $user->email) {
            throw new BusinessRuleException(__('messages.customer.no_email'), 422);
        }
        if (User::query()->where('verified_email', $user->email)->whereKeyNot($user->id)->exists()) {
            throw new BusinessRuleException(__('messages.customer.email_in_use'), 409);
        }
        app(OtpService::class)->issue($user, self::EMAIL_PURPOSE, 'email', $user->email, $ip);
    }

    public function verifyEmail(User $user, string $code, ?string $ip): User
    {
        if (! $user->email || ! app(OtpService::class)->verifyForModel($user, self::EMAIL_PURPOSE, $code, $ip)) {
            throw new BusinessRuleException(__('messages.customer.otp_invalid'), 422);
        }

        return DB::transaction(function () use ($user) {
            if (User::query()->where('verified_email', $user->email)->whereKeyNot($user->id)->lockForUpdate()->exists()) {
                throw new BusinessRuleException(__('messages.customer.email_in_use'), 409);
            }
            $user->forceFill(['email_verified_at' => now()])->save();

            return $user->fresh();
        });
    }

    public function updateAvatar(User $user, UploadedFile $file): User
    {
        $path = $file->storeAs('avatars/'.$user->id, Str::uuid().'.'.($file->extension() ?: 'jpg'), 'public');
        $old = $user->avatar;
        $user->forceFill(['avatar' => $path])->save();
        if ($old && $old !== $path) {
            Storage::disk('public')->delete($old);
        }

        return $user->fresh();
    }

    public function deleteAvatar(User $user): User
    {
        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
            $user->forceFill(['avatar' => null])->save();
        }

        return $user->fresh();
    }

    /* ---------------- addresses ---------------- */

    public function defaultAddress(User $user): ?UserAddress
    {
        return $user->addresses()->where('is_default', true)->with('city')->first();
    }

    public function saveAddress(User $user, array $data, ?UserAddress $address = null): UserAddress
    {
        return DB::transaction(function () use ($user, $data, $address) {
            $lat = (float) ($data['lat'] ?? $address?->lat);
            $lng = (float) ($data['lng'] ?? $address?->lng);
            $cityId = $this->resolveCity($lat, $lng, $data['city_id'] ?? null);

            $address ??= new UserAddress(['user_id' => $user->id]);
            $address->fill(array_intersect_key($data, array_flip(['label', 'address_line', 'details'])) + ['lat' => $lat, 'lng' => $lng, 'city_id' => $cityId]);
            $address->user_id = $user->id;

            $makeDefault = ($data['is_default'] ?? false) || ! $user->addresses()->where('is_default', true)->whereKeyNot($address->id ?? 0)->exists();
            if ($makeDefault) {
                $user->addresses()->where('is_default', true)->whereKeyNot($address->id ?? 0)->update(['is_default' => false]);
            }
            $address->is_default = $makeDefault || ($address->exists && $address->is_default);
            $address->save();

            return $address->fresh('city');
        });
    }

    public function setDefault(User $user, UserAddress $address): UserAddress
    {
        return DB::transaction(function () use ($user, $address) {
            $user->addresses()->where('is_default', true)->whereKeyNot($address->id)->update(['is_default' => false]);
            $address->forceFill(['is_default' => true])->save();

            return $address->fresh('city');
        });
    }

    /** Soft delete (orders keep their own address snapshot). The next address becomes default. */
    public function deleteAddress(User $user, UserAddress $address): void
    {
        DB::transaction(function () use ($user, $address) {
            $wasDefault = $address->is_default;
            $address->forceFill(['is_default' => false])->save();
            $address->delete();
            if ($wasDefault) {
                $next = $user->addresses()->orderByDesc('updated_at')->first();
                $next?->forceFill(['is_default' => true])->save();
            }
        });
    }

    public function ownAddress(User $user, int|string $id): UserAddress
    {
        $address = $user->addresses()->whereKey($id)->first();
        if (! $address) {
            throw new BusinessRuleException(__('messages.customer.not_found'), 404);
        }

        return $address;
    }

    protected function resolveCity(float $lat, float $lng, ?int $requestedCityId): int
    {
        $detected = app(CityService::class)->detect($lat, $lng);
        if ($detected === null) {
            throw new BusinessRuleException(__('messages.customer.address_outside_city'), 422);
        }
        $cityId = $detected['city']->resource->id ?? $detected['city']->id;
        if ($requestedCityId !== null && $requestedCityId !== (int) $cityId) {
            $requested = City::query()->whereKey($requestedCityId)->where('is_active', true)->first();
            $inside = $requested && app(DistanceCalculator::class)
                ->distanceKm((float) $requested->center_lat, (float) $requested->center_lng, $lat, $lng) <= (float) $requested->radius_km;
            if (! $inside) {
                throw new BusinessRuleException(__('messages.customer.address_city_mismatch'), 422);
            }

            return $requested->id;
        }

        return (int) $cityId;
    }
}
