<?php

namespace App\Facades\Services\Currency;

use Illuminate\Support\Facades\Facade;

/**
 * WAFFRLI P0 (2026-10-30).
 */
class CurrencyFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'CurrencyService';
    }
}
