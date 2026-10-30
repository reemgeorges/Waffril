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
 * them act on it. Text is rendered in each admin's own language; links open the
 * record with the viewer's own permissions. Never fails the caller.
 */
class AdminAlerts
{
    public const ALERTS = [
        'order.placed' => ['orders.view', 'heroicon-o-shopping-bag', 'info'],
        'order.cancellation_requested' => ['orders.resolve_cancellation_requests', 'heroicon-o-x-circle', 'warning'],
        'order.problem_reported' => ['orders.view', 'heroicon-o-exclamation-triangle', 'danger'],
        'plus.request_submitted' => ['plus.approve', 'heroicon-o-star', 'info'],
    ];

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

                // Each admin reads the alert in THEIR language (admins.locale).
                foreach ($recipients->groupBy(fn (Admin $admin) => $admin->preferredLocale()) as $locale => $group) {
                    $key = 'admin.alerts.'.str_replace('.', '_', $type);
                    $notification = Notification::make()
                        ->title(__($key.'.title', [], $locale))
                        ->body(__($key.'.body', ['reference' => $reference ?? '—'], $locale))
                        ->icon($icon)->iconColor($color)
                        ->viewData(['waffrli_alert' => $type]);
                    if ($url) {
                        $notification->actions([Action::make('open')->label(__('admin.alerts.open', [], $locale))->url($url)->markAsRead()]);
                    }
                    $notification->sendToDatabase($group, isEventDispatched: true);
                }
            } catch (Throwable $e) {
                Log::warning('admin_alert.failed', ['type' => $type, 'error' => $e->getMessage()]);
            }
        });
    }
}
