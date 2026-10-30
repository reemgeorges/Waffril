<?php

namespace App\Http\Controllers\Currency;

use App\Facades\Services\Currency\CurrencyFacade;
use App\Http\Controllers\FatherCrudController;
use App\Http\Requests\Model\Platform\InitializeDefaultCurrencyRequest;
use App\Http\Requests\Model\Platform\StoreCurrencyRequest;
use App\Http\Requests\Model\Platform\UpdateCurrencyRequest;

/**
 * WAFFRLI P0 (2026-10-30). Admin currency management. There is no
 * endpoint that switches an existing default currency (P7).
 */
class CurrencyController extends FatherCrudController
{
    protected function setVariables(): void
    {
        $this->key = 'currency';
        $this->service = CurrencyFacade::class;
        $this->createRequest = StoreCurrencyRequest::class;
        $this->updateRequest = UpdateCurrencyRequest::class;
    }

    public function initializeDefault()
    {
        try {
            $request = app(InitializeDefaultCurrencyRequest::class);
            $data[$this->key] = $this->service::initializeDefault($request);

            return $this->apiResponse($data);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }
}
