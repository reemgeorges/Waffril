<?php

namespace App\Http\Controllers;

use App\Http\Requests\Model\Auth\DeviceTokenRequest;
use App\Http\Traits\GeneralTrait;
use Illuminate\Http\Request;

abstract class FatherAuthController
{
    use GeneralTrait;

    protected $key;
    protected $service;

    protected $createRequest;
    protected $updateRequest;

    public function __construct()
    {
        $this->setVariables();
    }

    protected abstract function setVariables(): void;


    public function register(\App\Http\Requests\Model\Auth\RegisterRequest $request)
    {
        try {
            $data = $this->service::register($request);
            return $this->apiResponse($data);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }


    public function resendRegisterOtp(\App\Http\Requests\Model\Auth\ResendRegisterOtpRequest $request)
    {
        try {
            $data = $this->service::resendRegisterOtp($request);
            return $this->apiResponse($data);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    // ✅ التحقق من OTP للتسجيل
    public function verifyOtp(\App\Http\Requests\Model\Auth\RegisterVerifyRequest $request)
    {
        try {
            $data = $this->service::verifyOtp($request);
            return $this->apiResponse($data);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    // ➕ باقي الدوال كما كانت عندك
    public function login(\App\Http\Requests\Model\Auth\LoginRequest $request)
    {
        try {
            $data = $this->service::login($request);
            return $this->apiResponse($data);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function sendResetOtp(\App\Http\Requests\Model\Auth\ResetPassword\SendResetOtpRequest $request)
    {
        try {
            $this->service::sendResetOtp($request);
            return $this->apiResponse(['message' => __('messages.otp_sent')]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function verifyResetOtp(\App\Http\Requests\Model\Auth\ResetPassword\VerifyResetOtpRequest $request)
    {
        try {
            $data = $this->service::verifyResetOtp($request);
            return $this->apiResponse($data);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function resetPassword(\App\Http\Requests\Model\Auth\ResetPassword\ResetPasswordRequest $request)
    {
        try {
            $this->service::resetPassword($request);
            return $this->apiResponse(['message' => __('messages.password_updated')]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function resendFinalOtp()
    {
        try {
            $this->service::resendFinalOtp(request());
            return $this->apiResponse(['message' => __('messages.otp_resent')]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    // Phase 3C (2026-10-25): these previously type-hinted the plain
    // Illuminate\Http\Request — no validation at all on `channel`/`otp`/
    // `prefix_phone`/`phone_number`, so a malformed destination could
    // reach the service layer's uniqueness query unchecked.
    public function requestPhoneChange(\App\Http\Requests\Model\Auth\RequestPhoneChangeRequest $request)
    {
        try {
            $this->service::requestPhoneChange($request);
            return $this->apiResponse(['message' => __('messages.otp_sent')]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function verifyPhoneChangeOtp(\App\Http\Requests\Model\Auth\VerifyPhoneChangeRequest $request)
    {
        try {
            $data = $this->service::verifyPhoneChangeOtp($request);
            return $this->apiResponse($data);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function updateLanguage(Request $request)
    {
        try {
            $data = $this->service::updateLanguage($request);
            return $this->apiResponse($data);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    // Phase 3B (2026-10-25): mirrors requestPhoneChange/verifyPhoneChangeOtp.
    // Phase 3C: now validated, same reasoning as the phone-change pair above.
    public function requestEmailChange(\App\Http\Requests\Model\Auth\RequestEmailChangeRequest $request)
    {
        try {
            $this->service::requestEmailChange($request);
            return $this->apiResponse(['message' => __('messages.otp_sent')]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function verifyEmailChangeOtp(\App\Http\Requests\Model\Auth\VerifyEmailChangeRequest $request)
    {
        try {
            $data = $this->service::verifyEmailChangeOtp($request);
            return $this->apiResponse($data);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    // Phase 3B (2026-10-25): only reachable at all once a module sets
    // require_login_challenge (both Admin and User default to false).
    public function verifyLoginChallenge(\App\Http\Requests\Model\Auth\RegisterVerifyRequest $request)
    {
        try {
            $data = $this->service::verifyLoginChallenge($request);
            return $this->apiResponse($data);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }
    public function syncDeviceToken(DeviceTokenRequest $request)
    {
        try {
            $data = $this->service::syncLoggedInDeviceToken($request);
            return $this->apiResponse($data);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }
}
