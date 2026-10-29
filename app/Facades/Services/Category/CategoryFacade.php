<?php

namespace App\Facades\Services\Category;

use Illuminate\Support\Facades\Facade;

class CategoryFacade extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'CategoryService';
    }
}