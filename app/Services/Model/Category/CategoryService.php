<?php

namespace App\Services\Model\Category;

use App\Services\Basic\BasicCrudService;
use App\Services\Basic\ModelColumnsService;
use App\Models\Category;
use App\Http\Resources\Model\CategoryResource;

class CategoryService extends BasicCrudService
{
    /**
     * Override to set up modelColumnsService and resource.
     */
    protected function setVariables(): void
    {
        $this->modelColumnsService = ModelColumnsService::getServiceFor(
            $this->model = Category::class
        );

        $this->resource = CategoryResource::class;
    }
}