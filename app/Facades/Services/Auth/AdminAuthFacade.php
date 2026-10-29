<?php

namespace App\Facades\Services\Auth;

use Illuminate\Support\Facades\Facade;

class AdminAuthFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'AdminAuthService';
    }

}
