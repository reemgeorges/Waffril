<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * WAFFRLI P0 (2026-10-30) — Arabic/English API locale.
 *
 * Picks the first supported language from Accept-Language (ar or en) and
 * sets the application locale for the request, which drives Spatie
 * translatable attributes and __() messages. Anything else, or no
 * header, leaves the configured app locale untouched — so requests that
 * do not send the header behave exactly as before.
 */
class SetRequestLocale
{
    public const SUPPORTED = ['ar', 'en'];

    public function handle(Request $request, Closure $next)
    {
        self::applyFrom($request);

        $response = $next($request);
        $response->headers->set('Content-Language', app()->getLocale());

        return $response;
    }

    /** Apply the first supported Accept-Language (also used for errors raised before routing, e.g. 404). */
    public static function applyFrom(Request $request): void
    {
        foreach (explode(',', (string) $request->header('Accept-Language', '')) as $part) {
            $language = strtolower(substr(trim(explode(';', $part)[0]), 0, 2));
            if (in_array($language, self::SUPPORTED, true)) {
                app()->setLocale($language);

                return;
            }
        }
    }
}
