<?php

/*
 * WAFFRLI dispatch (2026-11-10) — delivery offers (driver accept/reject
 * with automatic reassignment). See docs/waffrli/delivery-offers.md.
 *
 * `offers_enabled` is the master switch. It stays OFF until the whole
 * replacement workflow (API, expiry jobs, auto-redispatch, Filament) is
 * implemented and verified: while it is off, no offer can be created and
 * the existing direct admin assignment is the only assignment path.
 * It is deliberately an environment flag, not a platform setting, so it
 * cannot be switched on from the admin panel before it is ready.
 *
 * Timeout, attempts and recovery are platform settings (admin panel,
 * group "dispatch"); the bounds below clamp whatever is stored.
 */
return [
    'offers_enabled' => (bool) env('WAFFRLI_DELIVERY_OFFERS_ENABLED', false),

    'timeout_bounds_seconds' => [30, 300],

    // B3 (2026-11-10): a driver's GPS position is used for ranking only if
    // reported within this many minutes (drivers send GPS only while
    // delivering, so idle positions are usually stale and ignored).
    'location_fresh_minutes' => (int) env('WAFFRLI_DISPATCH_LOCATION_FRESH_MINUTES', 10),

    // Orders handled per sweep run (overdue offers, stalled, recovery).
    'sweep_batch' => 50,
    'max_attempts_bounds' => [1, 50],
];
