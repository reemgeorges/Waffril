<?php

namespace App\Facades\Services\Auth;

use Illuminate\Support\Facades\Facade;

class UserAuthFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'UserAuthService';
    }

}
