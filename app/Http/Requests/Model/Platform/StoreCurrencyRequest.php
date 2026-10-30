<?php

namespace App\Http\Requests\Model\Platform;

use App\Http\Requests\Basic\BasicRequest;

/**
 * WAFFRLI P0 (2026-10-30). `is_default` can never be sent: the first
 * default is set only via POST admin/currencies/initialize-default.
 */
class StoreCurrencyRequest extends BasicRequest
{
    use TranslatableNameRules;

    public function rules(): array
    {
        return array_merge([
            'code' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/', 'unique:currencies,code'],
            'symbol' => ['required', 'string', 'max:10'],
            'decimals' => ['required', 'integer', 'between:0,4'],
            'exchange_rate' => ['required', 'numeric', 'gt:0', 'decimal:0,12', 'max:999999999999'],
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['prohibited'],
        ], $this->nameRules());
    }
}
