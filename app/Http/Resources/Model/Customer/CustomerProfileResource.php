<?php

namespace App\Http\Resources\Model\Customer;

use App\Http\Resources\Basic\BasicResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** WAFFRLI customer API (2026-11-03) — the authenticated customer. Never exposes credentials. */
class CustomerProfileResource extends BasicResource
{
    public function toArray(Request $request): array
    {
        $u = $this->resource;
        $default = $u->addresses()->where('is_default', true)->with('city')->first();

        return [
            'id' => $u->id,
            'first_name' => $u->first_name,
            'last_name' => $u->last_name,
            'phone' => $u->phone,
            'email' => $u->email,
            'email_verified' => $u->email_verified_at !== null,
            'avatar_url' => $u->avatar ? Storage::disk('public')->url($u->avatar) : null,
            'language' => $u->language,
            'account_status' => $u->account_status,
            'profile_completed' => filled($u->first_name) && filled($u->last_name),
            'display_currency' => $u->displayCurrency ? ['id' => $u->displayCurrency->id, 'code' => $u->displayCurrency->code] : null,
            'selected_address' => $default ? CustomerAddressResource::make($default) : null,
            'delivery_city' => $default?->city ? ['id' => $default->city->id, 'name' => $default->city->name] : null,
        ];
    }
}
