<?php

namespace App\Contracts\Auth;

/**
 * Phase 2B (2026-10-25) — interface only, no implementation.
 *
 * Contract for generating and later verifying a purpose-bound OTP code.
 * `App\Support\OtpChannelHelper` (Phase 0/1 code, predates this interface)
 * already has a concrete, ad-hoc generate()/verify() pair but does not
 * implement this contract and is not changed by this phase — it still reads
 * raw, unimplemented `App\Models\Otp`. This interface exists so Phase 3's
 * real implementation (strong random generation, hashed storage, expiry,
 * cooldown, throttling, attempt ceilings, atomic single-use consumption —
 * see CLAUDE.md's target architecture) has a stable shape to implement
 * against, decided up front rather than inferred from OtpChannelHelper's
 * current shortcuts.
 */
interface OtpCodeGenerator
{
    /**
     * Generate a new code for the given purpose. Implementations decide
     * length/charset; callers must treat the return value as opaque.
     */
    public function generate(string $purpose): string;

    /**
     * Verify a previously issued, still-valid code for the given subject
     * type and identifier. Must be a single-use, atomic check — a code that
     * passes verify() once must never pass again.
     */
    public function verify(string $subjectType, int|string $subjectId, string $purpose, string $code): bool;
}
