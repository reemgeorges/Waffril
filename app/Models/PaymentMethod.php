<?php

namespace App\Models;

/**
 * WAFFRLI (2026-10-31). `wallet` and `cash` at launch; `online` kind is
 * structural support for future electronic methods (none implemented).
 */
class PaymentMethod extends BasePlatformModel
{
    public const CODE_WALLET = 'wallet';

    public const CODE_CASH = 'cash';

    protected $fillable = [
        'code' => 'code',
        'kind' => 'kind',
        'name' => 'name',
        'is_active' => 'is_active',
        'sort_order' => 'sort_order',
    ];

    protected $translatable = ['name'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];
}
