<?php

namespace App\Filament\Support;

use App\Exceptions\BusinessRuleException;
use App\Models\Admin;
use Closure;
use Filament\Notifications\Notification;
use Livewire\Livewire;
use LogicException;

/**
 * WAFFRLI (2026-10-31) — runs a service call from a Filament action and
 * reports the outcome: success notification, or the business rule's
 * client-safe message as a danger notification (the operation was
 * refused and nothing changed). Unexpected errors are rethrown.
 */
final class BusinessAction
{
    public static function run(Closure $callback, ?string $successTitle = null): mixed
    {
        try {
            $result = $callback(self::admin());
            Notification::make()->title($successTitle ?? __('admin.notifications.done'))->success()->send();
            self::refreshPanel();

            return $result;
        } catch (BusinessRuleException|LogicException $e) {
            Notification::make()->title(__('admin.notifications.refused'))->body($e->getMessage())->danger()->send();

            return null;
        }
    }

    /**
     * Own-action refresh (2026-11-16): after a successful business action,
     * every live component on this page (lists, widgets, relation managers)
     * re-reads the database in the same browser round-trip, without waiting
     * for the Reverb echo. Other sessions are refreshed by the broadcast.
     */
    public static function refreshPanel(): void
    {
        $component = Livewire::current();
        if ($component !== null) {
            $component->dispatch('waffrli-orders-changed');
            $component->dispatch('waffrli-operations-changed');
        }
    }

    public static function admin(): Admin
    {
        $admin = auth('admin_web')->user();
        if (! $admin instanceof Admin) {
            throw new BusinessRuleException(__('admin.errors.forbidden'), 403);
        }

        return $admin;
    }
}
