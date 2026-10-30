<?php

namespace App\Listeners;

use App\Http\Middleware\SetAdminPanelLocale;
use App\Models\Admin;
use Illuminate\Auth\Events\Login;

/**
 * WAFFRLI (2026-11-04) — when an admin signs in to the panel, a language
 * picked on the login page (session) is saved as their preference; without
 * one, their saved preference is put back into the session.
 */
class ApplyAdminLocaleOnLogin
{
    public function handle(Login $event): void
    {
        if ($event->guard !== 'admin_web' || ! $event->user instanceof Admin || ! app()->bound('session.store')) {
            return;
        }

        $chosen = session('admin_locale');
        if (in_array($chosen, SetAdminPanelLocale::SUPPORTED, true)) {
            if ($event->user->locale !== $chosen) {
                $event->user->forceFill(['locale' => $chosen])->save();
            }

            return;
        }
        if (in_array($event->user->locale, SetAdminPanelLocale::SUPPORTED, true)) {
            session(['admin_locale' => $event->user->locale]);
        }
    }
}
