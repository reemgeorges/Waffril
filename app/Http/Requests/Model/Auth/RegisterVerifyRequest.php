<?php

namespace App\Http\Requests\Model\Auth;

use App\Http\Requests\Basic\BasicRequest;

class RegisterVerifyRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            // Phase 3B (2026-10-25): dropped `exists:users,id` — this is a
            // shared, guard-agnostic endpoint (FatherAuthController::verifyOtp()
            // dispatches to whichever module's service is bound), but
            // `exists:users,id` hardcoded the User module's table, so
            // verify-otp for any other module's id (e.g. an Admin id, or a
            // generated module's id) always failed validation before ever
            // reaching the service. The service layer's own
            // ::where($col, ...)->firstOrFail() already 404s correctly
            // for a genuinely missing id.
            'id'           => ['nullable', 'integer'],
            "email"        => "nullable|email",
            'otp'          => ['required','digits:6'],
            // Phase 3B (2026-10-25): removed 'reset' from this enum —
            // this generic verify endpoint's side effects (marking the
            // account verified/activated and issuing a login token) are
            // WRONG for a password-reset confirmation, which must go
            // through the dedicated verifyResetOtp() -> reset_token grant
            // flow instead (see BaseAuthService::verifyResetOtp()). Added
            // 'email_change', mirroring the existing 'phone_change' branch.
            "purpose" => "required|string|in:register,phone_change,email_change",
            'token_device' => ['nullable','string'],
        ];
    }

    public function messages(): array
    {
        return [
            'id.required'  => __('validation.required', ['attribute' => 'user']),
            'id.exists'    => __('validation.exists', ['attribute' => 'user']),
            'otp.required' => __('validation.custom.otp.required'),
            'otp.digits'   => __('validation.custom.otp.digits'),
        ];
    }
}
