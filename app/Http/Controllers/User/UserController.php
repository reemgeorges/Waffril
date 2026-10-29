<?php

namespace App\Http\Controllers\User;

use App\Facades\Services\User\UserFacade;
use App\Http\Controllers\Controller;
use App\Http\Controllers\FatherCrudController;
use App\Http\Requests\Model\StoreUserRequest;
use Illuminate\Http\Request;

class UserController extends FatherCrudController
{
    protected function setVariables() : void {
        $this->key = "user";
        $this->service = UserFacade::class;
        $this->createRequest = StoreUserRequest::class;
        $this->updateRequest = StoreUserRequest::class;
    }
}