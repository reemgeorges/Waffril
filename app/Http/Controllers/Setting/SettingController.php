<?php

namespace App\Http\Controllers\Setting;

use App\Facades\Services\Setting\SettingFacade;
use App\Http\Controllers\FatherCrudController;
use App\Http\Requests\Model\Platform\StoreSettingRequest;
use App\Http\Requests\Model\Platform\UpdateSettingRequest;

/**
 * WAFFRLI P0 (2026-10-30). Admin settings; routes only expose
 * list/show/create/update (settings are never activated or deleted).
 */
class SettingController extends FatherCrudController
{
    protected function setVariables(): void
    {
        $this->key = 'setting';
        $this->service = SettingFacade::class;
        $this->createRequest = StoreSettingRequest::class;
        $this->updateRequest = UpdateSettingRequest::class;
    }
}
