<?php

namespace App\Services\Model\Setting;

use App\Http\Requests\Basic\BasicRequest;
use App\Http\Resources\Model\SettingResource;
use App\Models\Setting;
use App\Services\Basic\BasicCrudService;
use App\Services\Basic\ModelColumnsService;

/**
 * WAFFRLI P0 (2026-10-30). Admin management of platform settings.
 *
 * Named *ManagementService to stay distinct from the pre-existing static
 * helper App\Services\Functional\Setting\SettingService (left untouched).
 * `key` and `is_monetary` are fixed at creation: the key is what code
 * reads, and is_monetary decides whether the P7 currency switch converts
 * the value, so neither may change silently afterwards.
 */
class SettingManagementService extends BasicCrudService
{
    protected function setVariables(): void
    {
        $this->modelColumnsService = ModelColumnsService::getServiceFor(
            $this->model = Setting::class
        );

        $this->resource = SettingResource::class;
    }

    public function create(BasicRequest $request): mixed
    {
        $this->object = Setting::create(array_merge($request->validated(), [
            'updated_by_admin_id' => $request->user()?->getKey(),
        ]));

        return $this->resource::make($this->object);
    }

    public function update(BasicRequest $request): mixed
    {
        $this->object = Setting::findOrFail($request->input('id'));
        $this->object->update([
            'value' => $request->validated()['value'],
            'updated_by_admin_id' => $request->user()?->getKey(),
        ]);

        return $this->resource::make($this->object->fresh());
    }
}
