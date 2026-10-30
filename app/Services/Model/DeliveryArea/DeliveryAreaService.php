<?php

namespace App\Services\Model\DeliveryArea;

use App\Http\Requests\Basic\BasicRequest;
use App\Http\Resources\Model\DeliveryAreaResource;
use App\Models\DeliveryArea;
use App\Services\Basic\BasicCrudService;
use App\Services\Basic\ModelColumnsService;
use App\Support\Geo\Polygon;

/**
 * WAFFRLI P0 (2026-10-30). Admin CRUD for platform-wide delivery areas.
 * The bounding box is always derived from the validated boundary here —
 * clients never send min/max values.
 */
class DeliveryAreaService extends BasicCrudService
{
    protected function setVariables(): void
    {
        $this->modelColumnsService = ModelColumnsService::getServiceFor(
            $this->model = DeliveryArea::class
        );

        $this->resource = DeliveryAreaResource::class;
    }

    public function create(BasicRequest $request): mixed
    {
        $data = $request->validated();
        $this->object = DeliveryArea::create(array_merge($data, Polygon::boundingBox($data['boundary'])));

        return $this->resource::make($this->object->fresh());
    }

    public function update(BasicRequest $request): mixed
    {
        $this->object = DeliveryArea::findOrFail($request->input('id'));
        $data = $request->validated();
        unset($data['id']);

        if (array_key_exists('boundary', $data)) {
            $data = array_merge($data, Polygon::boundingBox($data['boundary']));
        }

        $this->object->update($data);

        return $this->resource::make($this->object->fresh());
    }
}
