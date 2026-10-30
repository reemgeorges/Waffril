<?php

namespace App\Http\Requests\Model\Platform;

use App\Http\Requests\Basic\BasicRequest;
use App\Models\Setting;
use Closure;

/**
 * WAFFRLI P0 (2026-10-30). Only `value` is editable; `key` and
 * `is_monetary` are fixed at creation.
 */
class UpdateSettingRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            'id' => ['required', 'integer', 'exists:settings,id'],
            'key' => ['prohibited'],
            'is_monetary' => ['prohibited'],
            'value' => ['present', function (string $attribute, mixed $value, Closure $fail) {
                if ($value === null) {
                    $fail(__('messages.setting_value_required'));

                    return;
                }

                $setting = Setting::find($this->input('id'));
                if ($setting?->is_monetary && ! MonetaryValue::isValid($value)) {
                    $fail(__('messages.setting_monetary_value_invalid'));
                }
            }],
        ];
    }
}
