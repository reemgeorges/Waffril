<?php

namespace App\Facades\Services\Auth;

use Illuminate\Support\Facades\Facade;

/**
 * Phase 3A (2026-10-25) fix: previously returned the bare string
 * 'OtpService', which nothing bound in AppServiceProvider — any call
 * through this facade would have fatally errored with "Target class
 * [OtpService] does not exist." Now returns the real service's FQCN
 * directly (same pattern as every Phase 2B-generated auth facade):
 * Laravel's container auto-resolves a parameterless-constructor concrete
 * class without needing an explicit binding.
 */
class OtpFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \App\Services\Auth\OtpService::class;
    }
}
