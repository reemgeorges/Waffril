<?php

namespace App\Http\Resources\Model;

use App\Http\Resources\Basic\BasicResource;
use App\Models\DeliveryFeeRule;
use App\Services\Basic\ModelColumnsService;
use Illuminate\Http\Request;

/**
 * WAFFRLI P0 (2026-10-30).
 */
class DeliveryFeeRuleResource extends BasicResource
{
    public function toArray(Request $request): array
    {
        return $this->initResource(
            ModelColumnsService::getServiceFor(
                DeliveryFeeRule::class
            )
        );
    }

    protected function initResource($modelColumnsService): array
    {
        $this->result = parent::initResource($modelColumnsService);

        return array_merge($this->result, [
            'scope' => $this->resource->scopeType(),
            'scope_key' => $this->resource->scope_key,
        ]);
    }
}
