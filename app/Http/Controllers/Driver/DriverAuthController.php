<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Http\Requests\Model\Driver\DriverChangePasswordRequest;
use App\Http\Requests\Model\Driver\DriverLoginRequest;
use App\Http\Requests\Model\Driver\DriverProfileUpdateRequest;
use App\Http\Resources\Model\Driver\DriverProfileResource;
use App\Http\Traits\GeneralTrait;
use App\Services\Driver\DriverAccountService;
use Illuminate\Http\Request;

/**
 * WAFFRLI driver API (2026-11-02) — login (phone + password), logout,
 * profile and password. Drivers are created by admins; no registration.
 */
class DriverAuthController extends Controller
{
    use GeneralTrait;

    public function __construct(protected DriverAccountService $accounts) {}

    public function login(DriverLoginRequest $request)
    {
        try {
            $result = $this->accounts->login($request->input('phone'), $request->input('password'), $request->input('device_name'));

            return $this->apiResponse([
                'token' => $result['token'],
                'token_type' => 'Bearer',
                'driver' => DriverProfileResource::make($result['driver']),
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function logout(Request $request)
    {
        try {
            $this->accounts->logout($request->user('driver'));

            return $this->apiResponse(['message' => __('messages.driver.logged_out')]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function me(Request $request)
    {
        try {
            return $this->apiResponse(['driver' => DriverProfileResource::make($request->user('driver')->loadMissing('city'))]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function updateProfile(DriverProfileUpdateRequest $request)
    {
        try {
            $driver = $this->accounts->updateProfile($request->user('driver'), $request->validated());

            return $this->apiResponse(['message' => __('messages.driver.profile_updated'), 'driver' => DriverProfileResource::make($driver)]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function changePassword(DriverChangePasswordRequest $request)
    {
        try {
            $this->accounts->changePassword($request->user('driver'), $request->input('current_password'), $request->input('password'));

            return $this->apiResponse(['message' => __('messages.driver.password_changed')]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }
}
