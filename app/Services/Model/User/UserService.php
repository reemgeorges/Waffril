<?php

namespace App\Services\Model\User;

use App\Services\Basic\BasicCrudService;
use App\Services\Basic\ModelColumnsService;
use App\Models\User;
use App\Http\Resources\Model\UserResource;

class UserService extends BasicCrudService
{
    /**
     * Override to set up modelColumnsService and resource.
     */
    protected function setVariables(): void
    {
        $this->modelColumnsService = ModelColumnsService::getServiceFor(
            $this->model = User::class
        );

        $this->resource = UserResource::class;
    }
}