<?php

namespace App\Http\Controllers\City;

use App\Facades\Services\City\CityFacade;
use App\Http\Controllers\FatherCrudController;
use App\Http\Requests\Model\Platform\StoreCityRequest;
use App\Http\Requests\Model\Platform\UpdateCityRequest;

/**
 * WAFFRLI P0 (2026-10-30). Admin city management.
 */
class CityController extends FatherCrudController
{
    protected function setVariables(): void
    {
        $this->key = 'city';
        $this->service = CityFacade::class;
        $this->createRequest = StoreCityRequest::class;
        $this->updateRequest = UpdateCityRequest::class;
    }
}
