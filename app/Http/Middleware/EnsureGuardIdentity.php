<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

/**
 * Phase 2A (2026-10-25) — guard isolation.
 *
 * Laravel Sanctum's guard resolves a bearer token to whatever model it
 * actually belongs to, regardless of which named guard's driver is checking
 * it (the guard's `provider` config is not used to restrict token model
 * type). That means `auth:admin` and `auth:user`, on their own, both accept
 * ANY valid Sanctum token — a user's token would satisfy `auth:admin`.
 *
 * This middleware closes that gap: given the guard name passed as its
 * parameter, it confirms the resolved actor is actually an instance of that
 * guard's configured provider model (config('auth.providers.{provider}.model')).
 * Anything else — no actor, or an actor of the wrong model class — is
 * treated as unauthenticated, which the existing global AuthenticationException
 * handling (App\Providers\ApiExceptionServiceProvider) already renders in the
 * standard API envelope.
 *
 * Usage: ->middleware(['auth:admin', 'guard.identity:admin'])
 */
class EnsureGuardIdentity
{
    public function handle(Request $request, Closure $next, string $guard)
    {
        $actor = $request->user($guard);

        $providerName = config("auth.guards.{$guard}.provider");
        $expectedModel = $providerName ? config("auth.providers.{$providerName}.model") : null;

        if (! $actor || ($expectedModel && ! $actor instanceof $expectedModel)) {
            throw new AuthenticationException('Unauthenticated.', [$guard]);
        }

        return $next($request);
    }
}
