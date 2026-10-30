<?php

namespace App\Http\Controllers\DeliveryArea;

use App\Facades\Services\DeliveryArea\DeliveryAreaFacade;
use App\Http\Controllers\FatherCrudController;
use App\Http\Requests\Model\Platform\StoreDeliveryAreaRequest;
use App\Http\Requests\Model\Platform\UpdateDeliveryAreaRequest;

/**
 * WAFFRLI P0 (2026-10-30). Admin management of platform-wide delivery
 * areas.
 */
class DeliveryAreaController extends FatherCrudController
{
    protected function setVariables(): void
    {
        $this->key = 'delivery_area';
        $this->service = DeliveryAreaFacade::class;
        $this->createRequest = StoreDeliveryAreaRequest::class;
        $this->updateRequest = UpdateDeliveryAreaRequest::class;
    }
}
