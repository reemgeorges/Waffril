<?php

namespace App\Services\Model\DeliveryFeeRule;

use App\Exceptions\BusinessRuleException;
use App\Http\Requests\Basic\BasicRequest;
use App\Http\Resources\Model\DeliveryFeeRuleResource;
use App\Models\DeliveryArea;
use App\Models\DeliveryFeeRule;
use App\Services\Basic\BasicCrudService;
use App\Services\Basic\ModelColumnsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * WAFFRLI P0 (2026-10-30). Admin CRUD for platform-wide delivery fee
 * bands. Within one scope (area / city / global), ACTIVE bands may not
 * overlap: [from, to) intervals are checked on create, update and
 * activate, inside a transaction that locks the scope's active rows.
 * An area-scoped rule must name the area's own city.
 */
class DeliveryFeeRuleService extends BasicCrudService
{
    protected function setVariables(): void
    {
        $this->modelColumnsService = ModelColumnsService::getServiceFor(
            $this->model = DeliveryFeeRule::class
        );

        $this->resource = DeliveryFeeRuleResource::class;
    }

    public function create(BasicRequest $request): mixed
    {
        $this->object = $this->persist($request->validated(), null, $request->user()?->getKey());

        return $this->resource::make($this->object->fresh());
    }

    public function update(BasicRequest $request): mixed
    {
        $rule = DeliveryFeeRule::findOrFail($request->input('id'));
        $data = $request->validated();
        unset($data['id']);
        $this->object = $this->persist($data, $rule, $request->user()?->getKey());

        return $this->resource::make($this->object->fresh());
    }

    /**
     * 2026-10-31: single entry point for creating/updating a rule with all
     * scope and band checks — used by the P0 API and the admin panel.
     */
    public function persist(array $data, ?DeliveryFeeRule $rule, ?int $adminId): DeliveryFeeRule
    {
        return DB::transaction(function () use ($data, $rule, $adminId) {
            $rule = $rule ? DeliveryFeeRule::query()->lockForUpdate()->findOrFail($rule->getKey()) : null;
            $merged = array_merge(
                ['city_id' => null, 'delivery_area_id' => null, 'is_active' => true],
                $rule?->only(['city_id', 'delivery_area_id', 'distance_from_km', 'distance_to_km', 'is_active']) ?? [],
                $data
            );

            $this->assertAreaBelongsToCity($merged['city_id'], $merged['delivery_area_id']);
            if ((float) $merged['distance_to_km'] <= (float) $merged['distance_from_km']) {
                $this->fail(__('messages.delivery_fee_rule_invalid_band'));
            }
            if ($merged['is_active']) {
                $this->assertNoOverlap($merged['city_id'], $merged['delivery_area_id'], $merged['distance_from_km'], $merged['distance_to_km'], $rule?->id);
            }

            $data['updated_by_admin_id'] = $adminId;

            if ($rule) {
                $rule->update($data);

                return $rule;
            }

            return DeliveryFeeRule::create($data);
        });
    }

    public function activate(Request $request): bool
    {
        return DB::transaction(function () use ($request) {
            $this->object = DeliveryFeeRule::query()->lockForUpdate()->findOrFail($request->input('id'));
            $this->assertNoOverlap(
                $this->object->city_id,
                $this->object->delivery_area_id,
                $this->object->distance_from_km,
                $this->object->distance_to_km,
                $this->object->id
            );

            return $this->object->update(['is_active' => true, 'updated_by_admin_id' => $request->user()?->getKey()]);
        });
    }

    protected function assertAreaBelongsToCity(?int $cityId, ?int $areaId): void
    {
        if ($areaId === null) {
            return;
        }

        $area = DeliveryArea::find($areaId);
        if (! $area || $cityId === null || (int) $area->city_id !== (int) $cityId) {
            $this->fail(__('messages.delivery_fee_rule_area_city_mismatch'));
        }
    }

    protected function assertNoOverlap(?int $cityId, ?int $areaId, $from, $to, ?int $ignoreId = null): void
    {
        $overlapping = DeliveryFeeRule::query()
            ->where('scope_key', DeliveryFeeRule::scopeKeyFor($cityId, $areaId))
            ->where('is_active', true)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->where('distance_from_km', '<', $to)
            ->where('distance_to_km', '>', $from)
            ->lockForUpdate()
            ->exists();

        if ($overlapping) {
            $this->fail(__('messages.delivery_fee_rule_overlap'));
        }
    }

    protected function fail(string $message, int $status = 422): never
    {
        throw new BusinessRuleException($message, $status);
    }
}
