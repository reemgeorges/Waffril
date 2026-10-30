<?php

namespace App\Facades\Services\Setting;

use Illuminate\Support\Facades\Facade;

/**
 * WAFFRLI P0 (2026-10-30).
 */
class SettingFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'SettingManagementService';
    }
}
