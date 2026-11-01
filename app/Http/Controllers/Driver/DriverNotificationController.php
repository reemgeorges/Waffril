<?php

namespace App\Http\Controllers\Driver;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Model\Driver\DriverNotificationReadRequest;
use App\Http\Resources\Model\Driver\DriverNotificationResource;
use App\Http\Traits\GeneralTrait;
use Illuminate\Http\Request;

/**
 * WAFFRLI driver API (2026-11-02) — the authenticated driver's stored
 * notifications (Laravel `notifications` table). Every query goes through
 * $driver->notifications(), so another account's notification is 404.
 */
class DriverNotificationController extends Controller
{
    use GeneralTrait;

    public function index(Request $request)
    {
        try {
            $driver = $request->user('driver');
            $perPage = min(max((int) $request->query('per_page', 20), 1), 50);
            $query = $request->boolean('unread') ? $driver->unreadNotifications() : $driver->notifications();
            $page = $query->paginate($perPage);

            return $this->apiResponse([
                'notifications' => DriverNotificationResource::collection($page->getCollection()),
                'unread_count' => $driver->unreadNotifications()->count(),
                'pagination' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function markRead(Request $request, string $notification)
    {
        try {
            $model = $request->user('driver')->notifications()->whereKey($notification)->first();
            if (! $model) {
                throw new BusinessRuleException(__('messages.driver.order_not_found'), 404);
            }
            $model->markAsRead();

            return $this->apiResponse(['message' => __('messages.driver.notification_marked_read'), 'notification' => DriverNotificationResource::make($model->fresh())]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function markManyRead(DriverNotificationReadRequest $request)
    {
        try {
            $count = $request->user('driver')->unreadNotifications()
                ->when($request->filled('ids'), fn ($query) => $query->whereIn('id', $request->input('ids')))
                ->update(['read_at' => now()]);

            return $this->apiResponse(['message' => __('messages.driver.notifications_marked_read', ['count' => $count]), 'marked' => $count]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }
}
