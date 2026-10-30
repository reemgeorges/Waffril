<?php

use App\Http\Controllers\Customer\DevOtpController;
use Illuminate\Support\Facades\Route;

/*
 * WAFFRLI (2026-11-03) — DEVELOPMENT-ONLY helpers. Nothing here is ever
 * registered in production: the guard below requires a local/testing
 * environment, the fake text transport and an explicit peek token.
 */
if (! app()->environment(['local', 'testing'])
    || config('waffrli.messaging.driver') !== 'fake'
    || blank(config('waffrli.dev.otp_peek_token'))) {
    return;
}

Route::get('dev/otp', [DevOtpController::class, 'peek'])->middleware('throttle:30,1');
