<?php

namespace App\Http\Controllers\Driver;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Model\Driver\DriverOfferRejectRequest;
use App\Http\Resources\Model\Driver\DriverOfferResource;
use App\Http\Resources\Model\Driver\DriverOrderResource;
use App\Http\Traits\GeneralTrait;
use App\Models\DeliveryOffer;
use App\Models\Driver;
use App\Services\Delivery\DeliveryOfferService;
use Illuminate\Http\Request;

/**
 * WAFFRLI dispatch B2 (2026-11-10) — the driver's delivery offers.
 *
 * Every lookup is scoped to the authenticated driver: another driver's
 * offer answers 404 (its existence is never revealed). All state changes
 * go through DeliveryOfferService (row locks, server time, idempotency);
 * this controller never picks drivers or reassigns orders (that is the
 * dispatcher's job, B3).
 */
class DriverOfferController extends Controller
{
    use GeneralTrait;

    protected const ORDER_WITH = ['branch.store', 'city', 'deliveryArea', 'user', 'currency', 'items.options', 'payments.method'];

    public function __construct(protected DeliveryOfferService $offers) {}

    /** The pending offer, or null. An offer past its deadline is expired on read. */
    public function current(Request $request)
    {
        try {
            $driver = $request->user('driver');
            $offer = DeliveryOffer::query()->pending()->where('driver_id', $driver->id)->first();
            if ($offer && $offer->isOverdue()) {
                $this->offers->expire($offer);
                $offer = null;
            }

            return $this->apiResponse(['offer' => $offer ? DriverOfferResource::make($offer->load(DriverOfferResource::WITH)) : null]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function show(Request $request, string $offer)
    {
        try {
            return $this->apiResponse(['offer' => DriverOfferResource::make($this->own($request->user('driver'), $offer)->load(DriverOfferResource::WITH))]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function accept(Request $request, string $offer)
    {
        try {
            $driver = $request->user('driver');
            $model = $this->own($driver, $offer);
            $order = $this->offers->accept($model, $driver)->load(self::ORDER_WITH);

            return $this->apiResponse([
                'message' => __('messages.driver.offer_accepted'),
                'offer' => DriverOfferResource::make($model->fresh()->load(DriverOfferResource::WITH)),
                'order' => DriverOrderResource::make($order),
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function reject(DriverOfferRejectRequest $request, string $offer)
    {
        try {
            $driver = $request->user('driver');
            $model = $this->offers->reject($this->own($driver, $offer), $driver, $request->validated('reason'), $request->validated('note'));

            return $this->apiResponse([
                'message' => __('messages.driver.offer_rejected'),
                'offer' => DriverOfferResource::make($model->load(DriverOfferResource::WITH)),
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    protected function own(Driver $driver, string $offerId): DeliveryOffer
    {
        $offer = DeliveryOffer::query()->whereKey($offerId)->where('driver_id', $driver->id)->first();
        if (! $offer) {
            throw new BusinessRuleException(__('messages.driver.offer_not_found'), 404);
        }

        return $offer;
    }
}
