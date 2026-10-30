<?php

namespace App\Http\Requests\Model\Customer;

use App\Http\Requests\Basic\BasicRequest;

/** WAFFRLI customer API (2026-11-03). */
class CustomerAvatarRequest extends BasicRequest
{
    public function rules(): array
    {
        return ['avatar' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072', 'dimensions:max_width=4000,max_height=4000']];
    }
}
