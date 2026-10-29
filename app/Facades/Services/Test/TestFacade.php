<?php

namespace App\Facades\Services\Test;

use Illuminate\Support\Facades\Facade;

class TestFacade extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'TestService';
    }
}