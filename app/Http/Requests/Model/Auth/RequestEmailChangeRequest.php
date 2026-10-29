<?php

namespace App\Http\Requests\Model\Auth;

use App\Http\Requests\Basic\BasicRequest;

/**
 * Phase 3C (2026-10-25). FatherAuthController::requestEmailChange() reads
 * no input itself (the OTP always goes to the account's current, already
 * on-file email — there's nothing for a client to supply) but still
 * deserves a real FormRequest, not a bare Illuminate\Http\Request, so a
 * future field added here isn't silently unvalidated by default.
 */
class RequestEmailChangeRequest extends BasicRequest
{
    public function rules(): array
    {
        return [];
    }
}
