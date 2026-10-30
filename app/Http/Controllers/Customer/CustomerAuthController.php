<?php

namespace App\Http\Controllers\Customer;

use App\Http\Requests\Model\Customer\CustomerPhoneRequest;
use App\Http\Requests\Model\Customer\CustomerRegisterRequest;
use App\Http\Requests\Model\Customer\CustomerVerifyOtpRequest;
use App\Http\Resources\Model\Customer\CustomerProfileResource;
use App\Services\Customer\Auth\CustomerAuthService;
use Illuminate\Http\Request;

/** WAFFRLI customer API (2026-11-03) — phone OTP login/registration, logout, recovery. */
class CustomerAuthController extends CustomerController
{
    public function __construct(protected CustomerAuthService $auth) {}

    public function requestOtp(CustomerPhoneRequest $request)
    {
        return $this->respond(fn () => ['message' => __('messages.customer.otp_sent')] + $this->auth->requestOtp($request->input('phone'), $request->ip()));
    }

    public function verifyOtp(CustomerVerifyOtpRequest $request)
    {
        return $this->respond(function () use ($request) {
            $result = $this->auth->verifyOtp($request->input('phone'), $request->input('code'), $request->only(['device_name', 'device_id']), $request->ip());
            if ($result['status'] === 'logged_in') {
                $result['token_type'] = 'Bearer';
                $result['user'] = CustomerProfileResource::make($result['user']);
            }

            return $result;
        });
    }

    public function register(CustomerRegisterRequest $request)
    {
        return $this->respond(function () use ($request) {
            $result = $this->auth->register($request->input('registration_token'), $request->only(['first_name', 'last_name', 'language']), $request->only(['device_name', 'device_id']));

            return ['status' => 'registered', 'token' => $result['token'], 'token_type' => 'Bearer', 'user' => CustomerProfileResource::make($result['user'])];
        });
    }

    public function logout(Request $request)
    {
        return $this->respond(function () use ($request) {
            $this->auth->logout($this->customer($request), $request->input('device_id'));

            return ['message' => __('messages.customer.logged_out')];
        });
    }

    public function requestRecovery(CustomerPhoneRequest $request)
    {
        return $this->respond(fn () => ['message' => __('messages.customer.otp_sent')] + $this->auth->requestRecoveryOtp($request->input('phone'), $request->ip()));
    }

    public function recover(CustomerVerifyOtpRequest $request)
    {
        return $this->respond(function () use ($request) {
            $result = $this->auth->recover($request->input('phone'), $request->input('code'), $request->only(['device_name', 'device_id']), $request->ip());

            return ['message' => __('messages.customer.recovered'), 'token' => $result['token'], 'token_type' => 'Bearer', 'user' => CustomerProfileResource::make($result['user'])];
        });
    }
}
