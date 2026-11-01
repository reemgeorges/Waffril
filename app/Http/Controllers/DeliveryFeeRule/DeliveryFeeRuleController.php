<?php

namespace App\Http\Controllers\DeliveryFeeRule;

use App\Facades\Services\DeliveryFeeRule\DeliveryFeeRuleFacade;
use App\Http\Controllers\FatherCrudController;
use App\Http\Requests\Model\Platform\DeliveryQuotePreviewRequest;
use App\Http\Requests\Model\Platform\StoreDeliveryFeeRuleRequest;
use App\Http\Requests\Model\Platform\UpdateDeliveryFeeRuleRequest;
use App\Services\Functional\Delivery\DeliveryFeeResolver;

/**
 * WAFFRLI P0 (2026-10-30). Admin management of platform-wide delivery
 * fee rules, plus a read-only quote preview for verifying them.
 */
class DeliveryFeeRuleController extends FatherCrudController
{
    protected function setVariables(): void
    {
        $this->key = 'delivery_fee_rule';
        $this->service = DeliveryFeeRuleFacade::class;
        $this->createRequest = StoreDeliveryFeeRuleRequest::class;
        $this->updateRequest = UpdateDeliveryFeeRuleRequest::class;
    }

    public function quote(DeliveryFeeResolver $resolver)
    {
        try {
            $request = app(DeliveryQuotePreviewRequest::class);
            $quote = $resolver->quote(
                (int) $request->input('city_id'),
                (float) $request->input('origin_lat'),
                (float) $request->input('origin_lng'),
                (float) $request->input('destination_lat'),
                (float) $request->input('destination_lng'),
            );

            return $this->apiResponse(['quote' => $quote->toArray()]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }
}
