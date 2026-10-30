<?php

namespace App\Services\Audit;

use App\Models\Admin;
use App\Models\AdminAuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * WAFFRLI (2026-10-31) — records important admin operations. Callers pass
 * only non-secret properties (never passwords, tokens or OTP codes).
 */
class AdminAudit
{
    public static function log(string $action, ?Model $subject = null, array $properties = [], ?Admin $admin = null): AdminAuditLog
    {
        $admin ??= auth('admin_web')->user() ?? auth('admin')->user();

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
