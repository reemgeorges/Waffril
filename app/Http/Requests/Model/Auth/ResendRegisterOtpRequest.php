<?php

namespace App\Http\Requests\Model\Auth;

use App\Http\Requests\Basic\BasicRequest;

class ResendRegisterOtpRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            // Phase 3B (2026-10-25): dropped `exists:users,id` — same
            // reasoning as RegisterVerifyRequest: this is a shared,
            // guard-agnostic endpoint, and the service layer's own
            // ::findOrFail($id) already 404s correctly for a genuinely
            // missing id, for whichever module's table that actually is.
            'id' => ['required', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'id.required' => __('validation.required', ['attribute' => 'user']),
            'id.exists'   => __('validation.exists', ['attribute' => 'user']),
        ];
    }
}
