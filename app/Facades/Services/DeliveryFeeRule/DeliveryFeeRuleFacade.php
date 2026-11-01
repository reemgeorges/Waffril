<?php

namespace App\Facades\Services\DeliveryFeeRule;

use Illuminate\Support\Facades\Facade;

/**
 * WAFFRLI P0 (2026-10-30).
 */
class DeliveryFeeRuleFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'DeliveryFeeRuleService';
    }
}
