<?php

namespace App\Support;

use App\Services\Auth\OtpService;

/**
 * Phase 3A (2026-10-25) — rewritten as a thin, backward-compatible shim.
 *
 * Previously this class read/wrote a plaintext `otp` column on a
 * never-actually-existing `App\Models\Otp`, with no cooldown, no attempt
 * cap, no rate limiting, and no single-use guarantee beyond a plain
 * boolean flag — and every call site fataled anyway since neither
 * `App\Models\Otp` nor `App\Mail\OtpMail` existed. All of that logic has
 * moved into App\Services\Auth\OtpService (hashed storage, cooldown,
 * attempt ceiling, rate limits, atomic single-use consumption).
 *
 * send()/verify() keep their EXACT original signatures and return types on
 * purpose: App\Services\Basic\BaseAuthService, UserAuthService,
 * AdminAuthService and ResetPasswordService all call these two methods
 * (register, resendRegisterOtp, sendResetOtp, requestPhoneChange,
 * verifyPhoneChangeOtp, verifyOtp) and none of those call sites needed to
 * change — they were all already 100% broken (fatal "class not found")
 * before this phase, so fixing the implementation underneath makes them
 * work for the first time rather than changing working behavior.
 */
class OtpChannelHelper
{
    public static function send($model, string $purpose, string $channel): void
    {
        app(OtpService::class)->issue(
            $model,
            $purpose,
            $channel,
            static::destinationFor($model, $channel),
            request()?->ip()
        );
    }

    public static function verify($model, string $purpose, string $otp, ?string $channel = null): bool
    {
        return app(OtpService::class)->verifyForModel($model, $purpose, $otp, request()?->ip());
    }

    protected static function destinationFor($model, string $channel): ?string
    {
        return match ($channel) {
            'email' => $model->email ?? null,
            'sms', 'whatsapp' => (! empty($model->prefix_phone) && ! empty($model->phone_number))
                ? $model->prefix_phone.$model->phone_number
                : null,
            default => null,
        };
    }
}
