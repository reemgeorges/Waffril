<?php

namespace App\Facades\Services\User;

use Illuminate\Support\Facades\Facade;

class UserFacade extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'UserService';
    }
}