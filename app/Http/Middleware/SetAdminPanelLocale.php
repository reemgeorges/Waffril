<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;

/**
 * WAFFRLI admin panel language (ar → RTL, en → LTR; Filament sets the HTML
 * `lang` and `dir` from the locale). Resolution order:
 *   1. the signed-in admin's saved preference (admins.locale), so each admin
 *      keeps their own language on every device and after logging in again;
 *   2. the session (choice made before signing in);
 *   3. a long-lived `admin_locale` cookie (keeps the login page in the last
 *      language used on this browser after logout);
 *   4. config('app.locale').
 * Filament registers panel middleware as Livewire persistent middleware, so
 * Livewire requests (actions, modals, validation) keep the same language.
 */
class SetAdminPanelLocale
{
    public const SUPPORTED = ['ar', 'en'];

    public const COOKIE = 'admin_locale';

    public function handle(Request $request, Closure $next)
    {
        $admin = auth('admin_web')->user();
        $candidates = [
            $admin instanceof Admin ? $admin->locale : null,
            $request->hasSession() ? $request->session()->get('admin_locale') : null,
            $request->cookie(self::COOKIE),
            config('app.locale', 'en'),
        ];

        foreach ($candidates as $locale) {
            if (in_array($locale, self::SUPPORTED, true)) {
                app()->setLocale($locale);
                break;
            }
        }

        return $next($request);
    }
}
