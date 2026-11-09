<?php

namespace App\Enums;

/**
 * WAFFRLI dispatch B1 (2026-11-10) — life cycle of a delivery offer.
 * `pending` is the only open state; the others are final.
 */
enum DeliveryOfferStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('admin.dispatch.status_'.$this->value);
    }

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
