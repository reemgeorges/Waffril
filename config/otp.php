<?php

use App\Services\Auth\Otp\Channels\EmailOtpChannel;
use App\Services\Auth\Otp\Channels\FakeOtpChannel;
use App\Services\Auth\Otp\Channels\SmsOtpChannel;
use App\Services\Auth\Otp\Channels\WhatsAppOtpChannel;

/**
 * Phase 3A (2026-10-25) — Secure Dynamic OTP Core.
 *
 * Tuning knobs for the OTP subsystem itself (App\Services\Auth\OtpService).
 * Separate from config/auth_modules.php's per-module `otp.enabled`/
 * `otp.purposes` (which declare *whether* a module wants OTP and for what)
 * — this file controls *how* the shared engine behaves, regardless of
 * which module or purpose is using it.
 *
 * Canonical purpose names this phase's engine is built to support (purely
 * documentation — OtpService itself is purpose-string-agnostic and does
 * not validate against this list): register, login_challenge,
 * reset_password, email_change, phone_change.
 *
 * Phase 3B (2026-10-25): the 'reset' vs 'reset_password' purpose-string
 * inconsistency flagged in Phase 3A is now fixed — both
 * App\Services\Basic\BaseAuthService and the orphaned (unrouted)
 * App\Services\Functional\ResetPasswordService use 'reset_password'.
 */
return [

    // Digits in a generated code. Cryptographically random (random_int),
    // never str_pad(mt_rand()) or similar.
    'code_length' => 6,

    // How long an issued code remains verifiable.
    'ttl_minutes' => 10,

    // Minimum time between two codes for the same (subject, purpose) pair,
    // regardless of channel.
    'resend_cooldown_seconds' => 60,

    // How many verify() attempts a single issued code tolerates before it
    // is locked out (independent of whether any attempt is ever correct).
    'max_attempts' => 5,

    // Rate limits: at most `max_sends` issuances per `send_window_minutes`,
    // checked independently by (subject, purpose), by destination (the
    // hashed raw email/phone), and by requesting IP when available.
    'max_sends' => 5,
    'send_window_minutes' => 60,

    // Separate throttle on verify() calls themselves (distinct from the
    // per-code `max_attempts` above — this caps how many verify *requests*
    // an actor/IP may make across ALL codes in the window, which stops
    // someone from working around a single code's attempt cap by
    // requesting fresh codes repeatedly).
    'max_verifies' => 10,
    'verify_window_minutes' => 15,

    // Phase 3B (2026-10-25). How long a password-reset grant (see
    // App\Services\Auth\PasswordResetGrantService) remains redeemable
    // after a successful OTP verification. Deliberately short — this is a
    // single-use credential meant to be exchanged for a password change
    // within the same user session, not stored for later.
    'reset_grant_ttl_minutes' => 10,

    // channel name => App\Contracts\Auth\OtpChannelProvider implementation.
    // Mirrors config('auth_modules.otp.channels') — kept here as the
    // authoritative map; auth_modules.php documents the same values for
    // discoverability from that file.
    'channels' => [
        'fake' => FakeOtpChannel::class,
        'email' => EmailOtpChannel::class,
        'sms' => SmsOtpChannel::class,
        'whatsapp' => WhatsAppOtpChannel::class,
    ],

    // Used by OtpChannelHelper's backward-compatible shim when a caller
    // passes a channel this map doesn't recognize.
    'default_channel' => env('OTP_DEFAULT_CHANNEL', 'fake'),

];
