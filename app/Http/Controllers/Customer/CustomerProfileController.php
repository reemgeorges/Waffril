<?php

namespace App\Http\Controllers\Customer;

use App\Http\Requests\Model\Customer\CustomerAvatarRequest;
use App\Http\Requests\Model\Customer\CustomerCodeRequest;
use App\Http\Requests\Model\Customer\CustomerProfileRequest;
use App\Http\Resources\Model\Customer\CustomerAddressResource;
use App\Http\Resources\Model\Customer\CustomerProfileResource;
use App\Models\Currency;
use App\Services\Customer\Auth\CustomerAuthService;
use App\Services\Customer\CustomerProfileService;
use Illuminate\Http\Request;

/** WAFFRLI customer API (2026-11-03) — profile, avatar, email, deletion, delivery context. */
class CustomerProfileController extends CustomerController
{
    public function __construct(protected CustomerProfileService $profiles) {}

    public function show(Request $request)
    {
        return $this->respond(fn () => ['user' => CustomerProfileResource::make($this->customer($request))]);
    }

    public function update(CustomerProfileRequest $request)
    {
        return $this->respond(fn () => ['message' => __('messages.customer.profile_updated'), 'user' => CustomerProfileResource::make($this->profiles->update($this->customer($request), $request->validated()))]);
    }

    public function uploadAvatar(CustomerAvatarRequest $request)
    {
        return $this->respond(fn () => ['user' => CustomerProfileResource::make($this->profiles->updateAvatar($this->customer($request), $request->file('avatar')))]);
    }

    public function deleteAvatar(Request $request)
    {
        return $this->respond(fn () => ['user' => CustomerProfileResource::make($this->profiles->deleteAvatar($this->customer($request)))]);
    }

    public function sendEmailVerification(Request $request)
    {
        return $this->respond(function () use ($request) {
            $this->profiles->sendEmailVerification($this->customer($request), $request->ip());

            return ['message' => __('messages.customer.email_verification_sent')];
        });
    }

    public function verifyEmail(CustomerCodeRequest $request)
    {
        return $this->respond(fn () => ['message' => __('messages.customer.email_verified'), 'user' => CustomerProfileResource::make($this->profiles->verifyEmail($this->customer($request), $request->input('code'), $request->ip()))]);
    }

    public function requestDeletion(Request $request)
    {
        return $this->respond(function () use ($request) {
            $user = app(CustomerAuthService::class)->requestDeletion($this->customer($request));
            $days = (int) config('waffrli.customer.recovery_days', 30);

            return ['message' => __('messages.customer.deletion_requested', ['days' => $days]), 'recovery_until' => $user->recovery_until?->toIso8601String()];
        });
    }

    /** Effective browsing/ordering context: selected address → delivery city, and currencies. */
    public function context(Request $request)
    {
        return $this->respond(function () use ($request) {
            $user = $this->customer($request);
            $address = $this->profiles->defaultAddress($user);
            $default = Currency::query()->default()->first();

            return [
                'selected_address' => $address ? CustomerAddressResource::make($address) : null,
                'delivery_city' => $address?->city ? ['id' => $address->city->id, 'name' => $address->city->name] : null,
                'default_currency' => $default ? ['id' => $default->id, 'code' => $default->code, 'symbol' => $default->symbol, 'decimals' => $default->decimals] : null,
                'display_currency' => $user->displayCurrency ? ['id' => $user->displayCurrency->id, 'code' => $user->displayCurrency->code, 'exchange_rate' => (string) $user->displayCurrency->exchange_rate] : null,
            ];
        });
    }
}
