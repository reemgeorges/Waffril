<?php

namespace App\Http\Controllers\Test;

use App\Facades\Services\Test\TestFacade;
use App\Http\Controllers\Controller;
use App\Http\Controllers\FatherCrudController;
use App\Http\Requests\Model\StoreTestRequest;
use Illuminate\Http\Request;

class TestController extends FatherCrudController
{
    protected function setVariables() : void {
        $this->key = "test";
        $this->service = TestFacade::class;
        $this->createRequest = StoreTestRequest::class;
        $this->updateRequest = StoreTestRequest::class;
    }
}