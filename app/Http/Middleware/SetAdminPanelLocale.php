<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * WAFFRLI (2026-10-31) — Arabic/English for the Filament admin panel.
 * The choice is stored in the session (GET /admin/locale/{ar|en});
 * Filament switches to RTL automatically for Arabic.
 */
class SetAdminPanelLocale
{
    public const SUPPORTED = ['ar', 'en'];

    public function handle(Request $request, Closure $next)
    {
        $locale = $request->session()->get('admin_locale', config('app.locale', 'en'));
        if (in_array($locale, self::SUPPORTED, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
