<?php

namespace App\Contracts\Auth;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 2B (2026-10-25) — interface only, no implementation.
 *
 * Defines the contract a Phase 3 channel adapter (email, sms, whatsapp, a
 * fake/testing driver, ...) must satisfy to deliver an already-generated OTP
 * code to a subject. Nothing in this codebase implements this interface yet
 * and nothing resolves it from the container — `config('auth_modules.otp.channels')`
 * documents the intended channel => implementation-class mapping for Phase 3
 * to fill in. Introducing this now lets auth-module code (and the generator)
 * depend on a stable contract instead of being rewritten when OTP delivery
 * actually lands.
 */
interface OtpChannelProvider
{
    /**
     * Deliver a purpose-bound OTP code to the given subject over this
     * channel. Implementations are responsible for their own transport
     * (mail, SMS gateway, push, ...) and must not log the raw code.
     */
    public function deliver(Model $subject, string $purpose, string $code): void;

    /**
     * The channel name this provider handles, e.g. 'email', 'sms', 'whatsapp'.
     */
    public function channel(): string;
}
