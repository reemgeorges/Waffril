<?php

use App\Models\Admin;
use App\Models\User;

/**
 * Phase 2B (2026-10-25) — declarative registry of configurable auth
 * modules (Admin, User, and anything `make:auth-module` generates later:
 * Agent, Customer, ...).
 *
 * This file is the single source of truth `App\Providers\AuthModulesServiceProvider`
 * reads at boot to fill in any guard/provider that isn't already hand-written
 * in config/auth.php. The Phase 2A `admin`/`user` guards are already
 * hand-written there, so the entries below for them are documentation +
 * a target for the generator's idempotency check, not what actually wires
 * those two guards up (config/auth.php still does, and takes precedence —
 * see AuthModulesServiceProvider's "only fill gaps" behavior). A module
 * `make:auth-module` generates from scratch has no config/auth.php entry at
 * all; AuthModulesServiceProvider is what makes its guard/provider real.
 *
 * Keys under each module:
 *  - model: FQCN of the Eloquent model (must extend BaseAuthModel).
 *  - guard / provider: names used in config('auth.guards'/'auth.providers').
 *  - table: backing table name.
 *  - login_identifiers: subset of ['email', 'phone', 'social'] — which
 *    LoginRequest-style identifier combinations this module's login()
 *    accepts. Phase 3B: 'social' is still accepted here as a declaration,
 *    but bare social_id authentication (no OAuth token verification) is
 *    now disabled at the service layer regardless of this list — see
 *    App\Services\Functional\UserAuthService::login().
 *  - registration: whether a live self-registration route may exist. Only
 *    meaningful when otp.enabled is true AND 'register' is in otp.actions
 *    (register() always requires OTP verification to complete).
 *  - verification_required: whether a newly registered account must verify
 *    (via OTP) before it can log in.
 *  - soft_deletes / active_column: lifecycle support, see Phase 1.
 *  - otp.enabled: whether this module's OTP-backed actions are usable at
 *    all (App\Services\Basic\BaseAuthService checks this and throws a
 *    clear, typed error for a disabled module rather than silently no-op'ing).
 *  - otp.actions: PHASE 3B — per-action, not just per-purpose-list. Which of
 *    register|resend_register|reset_password|phone_change|email_change|
 *    login_challenge this module exposes. BaseAuthService checks
 *    membership in this list before performing the corresponding action,
 *    so a module can expose e.g. reset_password without register.
 *  - require_login_challenge: PHASE 3B — if true, a successful password
 *    check in login() does not immediately issue a token; it issues an
 *    OTP (purpose 'login_challenge') and the client must complete
 *    verifyLoginChallenge() to actually receive a token. Requires
 *    'login_challenge' to also be in otp.actions.
 *  - managed_by_generator: false for the two Phase 2A modules (hand-written
 *    before this generator existed); true for anything `make:auth-module`
 *    creates, so the generator's idempotency check can tell the difference.
 */
return [

    /*
     * Phase 2B (2026-10-25). The guard whose accounts hold "manage-{module}"
     * roles over OTHER modules (see AuthModulesPermissionSeeder and
     * app/Policies/{Admin,User}Policy.php). In this starter kit, Admin is
     * the only actor type expected to manage other modules' accounts —
     * including its own. A module intended to self-manage (e.g. an "Agent"
     * module managing other Agents) still gets its own "manage-{module}"
     * role seeded under THIS guard, not the module's own guard, because the
     * role describes who is allowed to act, not which table is acted upon.
     */
    'management_guard' => 'admin',

    'otp' => [
        // config/otp.php is the authoritative map App\Services\Auth\OtpService
        // actually reads; this mirrors the same values here for
        // discoverability from this file. 'sms' and 'whatsapp' implement
        // the contract shape but throw when used — no real gateway
        // credentials exist in this project (see each class's doc comment).
        'channels' => [
            'fake' => \App\Services\Auth\Otp\Channels\FakeOtpChannel::class,
            'email' => \App\Services\Auth\Otp\Channels\EmailOtpChannel::class,
            'sms' => \App\Services\Auth\Otp\Channels\SmsOtpChannel::class,
            'whatsapp' => \App\Services\Auth\Otp\Channels\WhatsAppOtpChannel::class,
        ],
    ],

    'modules' => [
        'admin' => [
            'label' => 'Admin',
            'model' => Admin::class,
            'guard' => 'admin',
            'provider' => 'admins',
            'table' => 'admins',
            'login_identifiers' => ['email'],
            // Admins are provisioned (seeder/console), not self-registered.
            'registration' => false,
            'verification_required' => false,
            'soft_deletes' => true,
            'active_column' => 'is_active',
            'otp' => [
                'enabled' => true,
                // Phase 3B: Admin has no phone/email-change columns wired
                // (see docs/starter-roadmap.md) — only reset_password is
                // real for this module.
                'actions' => ['reset_password'],
            ],
            'require_login_challenge' => false,
            'managed_by_generator' => false,
        ],

        'user' => [
            'label' => 'User',
            'model' => User::class,
            'guard' => 'user',
            'provider' => 'users',
            'table' => 'users',
            'login_identifiers' => ['email', 'phone', 'social'],
            // Phase 3B: registration is now live — see routes/api/v1/user.php
            // and the Phase 3B roadmap section for what was actually tested.
            'registration' => true,
            'verification_required' => true,
            'soft_deletes' => true,
            'active_column' => 'is_active',
            'otp' => [
                'enabled' => true,
                'actions' => ['register', 'resend_register', 'reset_password', 'phone_change', 'email_change'],
            ],
            'require_login_challenge' => false,
            'managed_by_generator' => false,
        ],

    ],

];
