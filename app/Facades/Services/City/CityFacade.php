<?php

namespace App\Facades\Services\City;

use Illuminate\Support\Facades\Facade;

/**
 * WAFFRLI P0 (2026-10-30).
 */
class CityFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'CityService';
    }
}
