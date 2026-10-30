<?php

namespace App\Http\Resources\Model;

use App\Http\Resources\Basic\BasicResource;
use App\Models\Setting;
use App\Services\Basic\ModelColumnsService;
use Illuminate\Http\Request;

/**
 * WAFFRLI P0 (2026-10-30).
 */
class SettingResource extends BasicResource
{
    public function toArray(Request $request): array
    {
        return $this->initResource(
            ModelColumnsService::getServiceFor(
                Setting::class
            )
        );
    }

    protected function initResource($modelColumnsService): array
    {
        $this->result = parent::initResource($modelColumnsService);

        return array_merge($this->result, [

        ]);
    }
}
