<?php

namespace App\Services\Model\Test;

use App\Services\Basic\BasicCrudService;
use App\Services\Basic\ModelColumnsService;
use App\Models\Test;
use App\Http\Resources\Model\TestResource;

class TestService extends BasicCrudService
{
    /**
     * Override to set up modelColumnsService and resource.
     */
    protected function setVariables(): void
    {
        $this->modelColumnsService = ModelColumnsService::getServiceFor(
            $this->model = Test::class
        );

        $this->resource = TestResource::class;
    }
}