<?php

namespace App\Http\Requests\Model\Platform;

use App\Http\Requests\Basic\BasicRequest;

/**
 * WAFFRLI P0 (2026-10-30). `code` is immutable; activation goes through
 * activate/deactivate; the default flag never changes here.
 */
class UpdateCurrencyRequest extends BasicRequest
{
    use TranslatableNameRules;

    public function rules(): array
    {
        return array_merge([
            'id' => ['required', 'integer', 'exists:currencies,id'],
            'code' => ['prohibited'],
            'symbol' => ['sometimes', 'string', 'max:10'],
            'decimals' => ['sometimes', 'integer', 'between:0,4'],
            'exchange_rate' => ['sometimes', 'numeric', 'gt:0', 'decimal:0,12', 'max:999999999999'],
            'is_active' => ['prohibited'],
            'is_default' => ['prohibited'],
        ], $this->nameRules(false));
    }
}
