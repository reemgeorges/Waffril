<?php

namespace App\Http\Requests\Model\Platform;

use App\Http\Requests\Basic\BasicRequest;

/**
 * WAFFRLI P0 (2026-10-30).
 */
class InitializeDefaultCurrencyRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            'id' => ['required', 'integer', 'exists:currencies,id'],
        ];
    }
}
