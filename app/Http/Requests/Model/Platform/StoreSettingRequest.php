<?php

namespace App\Http\Requests\Model\Platform;

use App\Http\Requests\Basic\BasicRequest;
use Closure;

/**
 * WAFFRLI P0 (2026-10-30).
 */
class StoreSettingRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_.]+$/', 'unique:settings,key'],
            'value' => ['present', $this->valueRule()],
            'is_monetary' => ['sometimes', 'boolean'],
        ];
    }

    protected function valueRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if ($value === null) {
                $fail(__('messages.setting_value_required'));

                return;
            }

            if ($this->boolean('is_monetary') && ! MonetaryValue::isValid($value)) {
                $fail(__('messages.setting_monetary_value_invalid'));
            }
        };
    }
}
