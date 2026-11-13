<?php

namespace App\Services\Notifications;

use App\Filament\Resources\OrderCancellationRequests\OrderCancellationRequestResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\PlusRequests\PlusRequestResource;
use App\Models\Admin;
use App\Support\Auth\AdminPermissions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WAFFRLI (2026-11-03) — operational alerts in the Filament bell (database
 * notifications in Filament's format, refreshed live on admin.{id} over the
 * shared Reverb server, polled every 30 s otherwise).
 *
 * Each alert goes only to active admins holding the permission that lets
 * them act on it; links open the record with the viewer's own permissions.
 * Never fails the caller.
 *
 * Language (2026-11-11): an alert is stored once, the same for every
 * recipient, as structured data (viewData.waffrli_alert + waffrli_params)
 * with fallback text in the app's fallback language. The bell renders it in
 * the viewer's CURRENT panel language (App\Filament\Support\LocalizedNotification),
 * so switching AR/EN also re-translates existing alerts.
 */
class AdminAlerts
{
    public const ALERTS = [
        'order.placed' => ['orders.view', 'heroicon-o-shopping-bag', 'info'],
        'order.cancellation_requested' => ['orders.resolve_cancellation_requests', 'heroicon-o-x-circle', 'warning'],
        'order.problem_reported' => ['orders.view', 'heroicon-o-exclamation-triangle', 'danger'],
        // Dispatch B3 (2026-11-10): an order is waiting for a driver (sent once per entry into the queue).
        'order.no_driver_available' => ['orders.assign', 'heroicon-o-user-minus', 'warning'],
        'plus.request_submitted' => ['plus.approve', 'heroicon-o-star', 'info'],
    ];

    /** Translation group of an alert type: admin.alerts.order_placed etc. */
    public static function translationKey(string $type): string
    {
        return 'admin.alerts.'.str_replace('.', '_', $type);
    }

    public static function send(string $type, ?int $orderId, ?string $reference): void
    {
        if (! isset(self::ALERTS[$type]) || config('waffrli.notifications_muted')) {
            return;
        }

        DB::afterCommit(function () use ($type, $orderId, $reference) {
            try {
                [$permission, $icon, $color] = self::ALERTS[$type];
                $recipients = Admin::query()->where('is_active', true)->get()
                    ->filter(fn (Admin $admin) => AdminPermissions::allows($admin, $permission));
                if ($recipients->isEmpty()) {
                    return;
                }

                $url = match (true) {
                    $type === 'plus.request_submitted' => PlusRequestResource::getUrl('index'),
                    $type === 'order.cancellation_requested' => OrderCancellationRequestResource::getUrl('index'),
                    $orderId !== null => OrderResource::getUrl('view', ['record' => $orderId]),
                    default => null,
                };

                // One structured alert for everyone; the text below is only a
                // fallback, the bell re-translates it for the viewer.
                $key = self::translationKey($type);
                $locale = (string) config('app.fallback_locale', 'en');
                $params = ['reference' => $reference ?? '—'];
                $notification = Notification::make()
                    ->title(__($key.'.title', [], $locale))
                    ->body(__($key.'.body', $params, $locale))
                    ->icon($icon)->iconColor($color)
                    ->viewData(['waffrli_alert' => $type, 'waffrli_params' => $params]);
                if ($url) {
                    $notification->actions([Action::make('open')->label(__('admin.alerts.open', [], $locale))->url($url)->markAsRead()]);
                }
                // One admin at a time (notification audit, 2026-11-13): the bell
                // refresh broadcast of one admin failing (e.g. Reverb down on a
                // sync queue) must not cost the other admins their alert.
                foreach ($recipients as $admin) {
                    try {
                        $notification->sendToDatabase($admin, isEventDispatched: true);
                    } catch (Throwable $e) {
                        Log::warning('admin_alert.failed', ['type' => $type, 'admin_id' => $admin->id, 'error' => $e->getMessage()]);
                    }
                }
            } catch (Throwable $e) {
                Log::warning('admin_alert.failed', ['type' => $type, 'error' => $e->getMessage()]);
            }
        });
    }
}
