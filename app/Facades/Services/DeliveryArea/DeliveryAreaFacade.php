<?php

namespace App\Facades\Services\DeliveryArea;

use Illuminate\Support\Facades\Facade;

/**
 * WAFFRLI P0 (2026-10-30).
 */
class DeliveryAreaFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'DeliveryAreaService';
    }
}
