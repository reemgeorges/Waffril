<?php

namespace App\Services\Audit;

use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Services\Realtime\Realtime;
use Illuminate\Database\Eloquent\Model;

/**
 * WAFFRLI (2026-10-31) — records important admin operations. Callers pass
 * only non-secret properties (never passwords, tokens or OTP codes).
 */
class AdminAudit
{
    /** Audit action prefix → live refresh event (App\Events\Realtime\AdminOperationalEvent). */
    public const LIVE_TYPES = [
        'plus' => 'plus.membership_changed',
        'customer' => 'customer.status_changed',
        'driver' => 'driver.status_changed',
        'settlement' => 'settlement.updated',
        'marketing' => 'marketing.progress',
    ];

    /** Properties merged into every entry while withContext() runs (e.g. generated demo data). */
    protected static array $context = [];

    /**
     * Runs $callback with extra audit properties, e.g. ['generated_demo' => true]
     * so seeded entries are clearly distinguishable from real operations.
     */
    public static function withContext(array $context, callable $callback): mixed
    {
        $previous = static::$context;
        static::$context = array_merge($previous, $context);

        try {
            return $callback();
        } finally {
            static::$context = $previous;
        }
    }

    public static function log(string $action, ?Model $subject = null, array $properties = [], ?Admin $admin = null): AdminAuditLog
    {
        $admin ??= auth('admin_web')->user() ?? auth('admin')->user();
        $properties = array_merge($properties, static::$context);

        // 2026-11-16: every audited business change refreshes the admin
        // screens that show it (after commit, identifiers only). Order
        // changes are already published by the order state machine.
        $prefix = strstr($action, '.', true) ?: $action;
        if (isset(self::LIVE_TYPES[$prefix]) && $subject !== null) {
            Realtime::admin(self::LIVE_TYPES[$prefix], (int) $subject->getKey());
        }

        return AdminAuditLog::create([
            'admin_id' => $admin instanceof Admin ? $admin->getKey() : null,
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'properties' => $properties === [] ? null : $properties,
            'ip_address' => request()?->ip(),
        ]);
    }
}
