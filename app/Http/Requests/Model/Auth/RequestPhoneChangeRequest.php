<?php

namespace App\Http\Requests\Model\Auth;

use App\Http\Requests\Basic\BasicRequest;
use Illuminate\Validation\Rule;

/**
 * Phase 3C (2026-10-25). FatherAuthController::requestPhoneChange()
 * previously type-hinted the plain Illuminate\Http\Request — zero
 * validation of any kind on the one field it reads (`channel`).
 */
class RequestPhoneChangeRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            // Phase 11D (2026-10-26): "fake" never actually delivers
            // anywhere — excluded from this allowlist in production.
            // OtpService::resolveChannel() is the authoritative
            // enforcement; this is a defense-in-depth, clearer-error
            // layer in front of it.
            'channel' => ['nullable', Rule::in($this->allowedOtpChannels())],
        ];
    }

    protected function allowedOtpChannels(): array
    {
        $channels = array_keys(config('otp.channels', []));

        if (app()->environment('production')) {
            $channels = array_values(array_filter($channels, fn ($c) => $c !== 'fake'));
        }

        return $channels;
    }
}
