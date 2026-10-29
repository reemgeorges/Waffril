<?php

use App\Http\Controllers\Auth\UserAuthController;
use Illuminate\Support\Facades\Route;

/**
 * Phase 3B (2026-10-25). Previously the ENTIRE user auth route block was
 * commented out (wrapped in one big /* ... *\/), including login itself —
 * not because login was insecure, but because the supporting guard/OTP
 * infrastructure didn't exist yet. It now does (Phase 2A guard, Phase 3A/3B
 * OTP engine), and every route below is covered by
 * tests/Feature/AuthFlowsIntegrationTest.php and
 * tests/Feature/AuthGuardFoundationTest.php end-to-end — "enable only
 * fully implemented and tested routes" per this phase's instructions.
 *
 * Deliberately still NOT enabled here: `/user/device-token` and
 * `/user/me/profile` (the commented block below kept them disabled too) —
 * neither is covered by this phase's tests, so they stay out per the same
 * rule.
 *
 * Phase 3C (2026-10-25): every route below now carries an explicit
 * `throttle:` (see App\Providers\AppServiceProvider::registerAuthRateLimiters())
 * — previously none of them had any HTTP-layer rate limit at all.
 * `login-challenge/verify` is now registered unconditionally: it is safe
 * to do so because BaseAuthService::verifyLoginChallenge() itself gates
 * on otpActionAllowed('login_challenge'), which checks
 * config('auth_modules.modules.user.otp.actions') — not in that list (the
 * default) means this route exists but always 422s, never granting
 * anything by default. The route being live or not isn't what makes this
 * safe; the per-action config gate is.
 */
Route::prefix('user')->name('user.')->group(function () {

    Route::post('register', [UserAuthController::class, 'register'])->name('register')->middleware('throttle:auth-otp-endpoint');
    Route::post('register/resend', [UserAuthController::class, 'resendRegisterOtp'])->name('register.resend')->middleware('throttle:auth-otp-endpoint');
    Route::post('register/verify', [UserAuthController::class, 'verifyOtp'])->name('register.verify')->middleware('throttle:auth-otp-endpoint');
    Route::post('login-challenge/verify', [UserAuthController::class, 'verifyLoginChallenge'])->name('login_challenge.verify')->middleware('throttle:auth-otp-endpoint');

    // Login (email+password | phone+password). Bare social_id login is
    // rejected at the service layer (Phase 3B security fix) regardless of
    // what is sent here — see UserAuthService::login().
    Route::post('login', [UserAuthController::class, 'login'])->name('login')->middleware('throttle:auth-login');

    // Reset password (email OR phone) — verify-otp issues a single-use,
    // short-lived reset_token (App\Services\Auth\PasswordResetGrantService);
    // reset requires and consumes it. No Sanctum token is ever issued by
    // this flow.
    Route::post('password/send-otp', [UserAuthController::class, 'sendResetOtp'])->name('password.send_otp')->middleware('throttle:auth-otp-endpoint');
    Route::post('password/verify-otp', [UserAuthController::class, 'verifyResetOtp'])->name('password.verify_otp')->middleware('throttle:auth-otp-endpoint');
    Route::post('password/reset', [UserAuthController::class, 'resetPassword'])->name('password.reset')->middleware('throttle:auth-otp-endpoint');

    // Authenticated actions (Sanctum). 'guard.identity:user' closes the
    // gap where a valid Admin sanctum token would otherwise also satisfy
    // auth:user (see App\Http\Middleware\EnsureGuardIdentity).
    Route::middleware(['auth:user', 'guard.identity:user'])->group(function () {
        Route::post('request-phone-change', [UserAuthController::class, 'requestPhoneChange'])->name('phone.request_change')->middleware('throttle:auth-otp-endpoint');
        Route::post('verify-phone-change-otp', [UserAuthController::class, 'verifyPhoneChangeOtp'])->name('phone.verify_change')->middleware('throttle:auth-otp-endpoint');
        Route::post('request-email-change', [UserAuthController::class, 'requestEmailChange'])->name('email.request_change')->middleware('throttle:auth-otp-endpoint');
        Route::post('verify-email-change-otp', [UserAuthController::class, 'verifyEmailChangeOtp'])->name('email.verify_change')->middleware('throttle:auth-otp-endpoint');
        Route::post('update-language', [UserAuthController::class, 'updateLanguage'])->name('language.update');
        Route::post('logout', [UserAuthController::class, 'logout'])->name('logout');
    });

});
