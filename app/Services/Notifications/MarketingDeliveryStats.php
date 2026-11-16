<?php

namespace App\Services\Notifications;

use App\Models\MarketingBroadcast;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WAFFRLI (2026-11-16) — per-stage counters of a marketing message
 * (see the 2026_11_16_140000 migration). The push path recognises a
 * marketing push by `marketing_broadcast_id` in the push data (set by
 * MarketingMessage::references()); other pushes are never counted.
 * Increments are atomic; a counting failure never affects delivery.
 */
final class MarketingDeliveryStats
{
    public const COLUMNS = ['push_no_device_count', 'push_queued_count', 'push_accepted_count', 'push_invalid_count', 'push_skipped_count', 'push_failed_count'];

    /** @param array<string, mixed> $pushData the push message "data" */
    public static function broadcastId(array $pushData): ?int
    {
        $id = $pushData['marketing_broadcast_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    public static function increment(?int $broadcastId, string $column, int $by = 1): void
    {
        if ($broadcastId === null || $by < 1 || ! in_array($column, self::COLUMNS, true)) {
            return;
        }
        try {
            MarketingBroadcast::query()->whereKey($broadcastId)->increment($column, $by);
        } catch (Throwable $e) {
            Log::warning('marketing.stats_failed', ['broadcast_id' => $broadcastId, 'column' => $column, 'error' => $e->getMessage()]);
        }
    }
}
