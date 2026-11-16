<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * WAFFRLI (2026-11-16, Phase 9) — correlation id for every request.
 *
 * Reuses a well-formed incoming X-Request-Id (from Cloudflare / a proxy /
 * the mobile app), otherwise generates a UUID. It is added to Laravel's
 * log Context (so every log line of the request carries `request_id`), to
 * stored error groups, and returned as the X-Request-Id response HEADER.
 * JSON bodies are never changed (mobile API contracts).
 */
class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get(self::HEADER, '');
        $id = preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,63}$/', $incoming) ? $incoming : (string) Str::uuid();
        Context::add('request_id', $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
