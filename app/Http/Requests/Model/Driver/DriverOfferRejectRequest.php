<?php

namespace App\Http\Requests\Model\Driver;

use App\Http\Requests\Basic\BasicRequest;
use App\Models\DeliveryOffer;

/** WAFFRLI dispatch B2 (2026-11-10): why the driver declines an offer. */
class DriverOfferRejectRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'in:'.implode(',', DeliveryOffer::REJECT_REASONS)],
            'note' => ['nullable', 'required_if:reason,other', 'string', 'max:500'],
        ];
    }
}
