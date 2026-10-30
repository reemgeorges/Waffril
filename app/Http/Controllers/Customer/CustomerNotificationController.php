<?php

namespace App\Http\Controllers\Customer;

use App\Exceptions\BusinessRuleException;
use App\Http\Resources\Model\Notification\NotificationResource;
use Illuminate\Http\Request;

/**
 * WAFFRLI (2026-11-03) — the customer's stored notifications. Every query
 * goes through $user->notifications(): another account's id is 404.
 */
class CustomerNotificationController extends CustomerController
{
    public function index(Request $request)
    {
        return $this->respond(function () use ($request) {
            $user = $this->customer($request);
            $page = ($request->boolean('unread') ? $user->unreadNotifications() : $user->notifications())->paginate($this->perPage($request));

            return $this->paginated($page, 'notifications', fn ($n) => NotificationResource::make($n)->resolve($request))
                + ['unread_count' => $user->unreadNotifications()->count()];
        });
    }

    public function unreadCount(Request $request)
    {
        return $this->respond(fn () => ['unread_count' => $this->customer($request)->unreadNotifications()->count()]);
    }

    public function markRead(Request $request, string $notification)
    {
        return $this->respond(function () use ($request, $notification) {
            $model = $this->customer($request)->notifications()->whereKey($notification)->first();
            if (! $model) {
                throw new BusinessRuleException(__('messages.customer.not_found'), 404);
            }
            $model->markAsRead();

            return ['notification' => NotificationResource::make($model->fresh())];
        });
    }

    public function markAllRead(Request $request)
    {
        return $this->respond(function () use ($request) {
            $request->validate(['ids' => ['sometimes', 'array', 'max:100'], 'ids.*' => ['string', 'uuid']]);
            $count = $this->customer($request)->unreadNotifications()
                ->when($request->filled('ids'), fn ($q) => $q->whereIn('id', $request->input('ids')))
                ->update(['read_at' => now()]);

            return ['marked' => $count];
        });
    }
}
