<?php

namespace App\Http\Controllers\Category;

use App\Facades\Services\Category\CategoryFacade;
use App\Http\Controllers\Controller;
use App\Http\Controllers\FatherCrudController;
use App\Http\Requests\Model\StoreCategoryRequest;
use Illuminate\Http\Request;

class CategoryController extends FatherCrudController
{
    protected function setVariables() : void {
        $this->key = "category";
        $this->service = CategoryFacade::class;
        $this->createRequest = StoreCategoryRequest::class;
        $this->updateRequest = StoreCategoryRequest::class;
    }
}