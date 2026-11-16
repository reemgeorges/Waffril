<?php

namespace App\Services\Notifications;

use App\Exceptions\BusinessRuleException;
use App\Jobs\Push\SendMarketingBroadcast;
use App\Models\Admin;
use App\Models\Driver;
use App\Models\MarketingBroadcast;
use App\Models\User;
use App\Notifications\MarketingMessage;
use App\Services\Audit\AdminAudit;
use App\Services\Realtime\Notify;
use App\Services\Realtime\Realtime;
use App\Support\Auth\AdminPermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Notification;

/**
 * WAFFRLI (2026-11-03) — admin marketing messages to customers.
 *
 * - Only admins with notifications.marketing; every send is audited.
 * - Audience: all active customers, or those whose DEFAULT address is in a
 *   city. Suspended, inactive, deleted and anonymized accounts are excluded.
 * - 2026-11-16: recipient_type customers (default, as before) | drivers |
 *   both. Drivers: active, not deleted; the city filter uses the driver's
 *   own city. Sending counts the total first and reports progress per
 *   chunk (processed_count) live on admin.activity.
 * - Delivered by a queued job in chunks (stored + live + FCM per customer);
 *   the job claims the broadcast atomically, so it is sent at most once.
 */
class MarketingBroadcastService
{
    public function create(Admin $admin, array $data): MarketingBroadcast
    {
        if (! AdminPermissions::allows($admin, 'notifications.marketing')) {
            throw new BusinessRuleException(__('admin.errors.forbidden'), 403);
        }
        $recipients = $data['recipient_type'] ?? MarketingBroadcast::RECIPIENTS_CUSTOMERS;
        if (! in_array($recipients, MarketingBroadcast::RECIPIENT_TYPES, true)) {
            throw new BusinessRuleException(__('validation.in', ['attribute' => 'recipient_type']), 422);
        }
        if (($data['audience'] ?? MarketingBroadcast::AUDIENCE_ALL) === MarketingBroadcast::AUDIENCE_CITY && empty($data['city_id'])) {
            throw new BusinessRuleException(__('validation.required', ['attribute' => 'city']), 422);
        }

        $broadcast = MarketingBroadcast::create([
            'admin_id' => $admin->id,
            'title' => ['ar' => $data['title']['ar'], 'en' => $data['title']['en']],
            'body' => ['ar' => $data['body']['ar'], 'en' => $data['body']['en']],
            'audience' => $data['audience'] ?? MarketingBroadcast::AUDIENCE_ALL,
            'recipient_type' => $recipients,
            'city_id' => ($data['audience'] ?? null) === MarketingBroadcast::AUDIENCE_CITY ? $data['city_id'] : null,
        ]);
        AdminAudit::log('notifications.marketing_queued', $broadcast, ['audience' => $broadcast->audience, 'recipient_type' => $broadcast->recipient_type, 'city_id' => $broadcast->city_id], $admin);

        SendMarketingBroadcast::dispatch($broadcast->id);

        return $broadcast->fresh();
    }

    public function audience(MarketingBroadcast $broadcast): Builder
    {
        return User::query()
            ->whereNull('anonymized_at')
            ->where('is_active', true)
            ->where('account_status', '!=', User::ACCOUNT_SUSPENDED)
            ->when($broadcast->audience === MarketingBroadcast::AUDIENCE_CITY, fn ($q) => $q->whereHas(
                'addresses', fn ($a) => $a->where('is_default', true)->where('city_id', $broadcast->city_id)
            ));
    }

    /** Drivers who may receive marketing: active, not deleted; optional city filter on their own city. */
    public function driverAudience(MarketingBroadcast $broadcast): Builder
    {
        return Driver::query()
            ->where('is_active', true)
            ->when($broadcast->audience === MarketingBroadcast::AUDIENCE_CITY, fn ($q) => $q->where('city_id', $broadcast->city_id));
    }

    /** @return array<string, Builder> recipient group => query */
    public function groups(MarketingBroadcast $broadcast): array
    {
        $type = $broadcast->recipient_type ?: MarketingBroadcast::RECIPIENTS_CUSTOMERS;

        return array_filter([
            'customers' => in_array($type, [MarketingBroadcast::RECIPIENTS_CUSTOMERS, MarketingBroadcast::RECIPIENTS_BOTH], true) ? $this->audience($broadcast) : null,
            'drivers' => in_array($type, [MarketingBroadcast::RECIPIENTS_DRIVERS, MarketingBroadcast::RECIPIENTS_BOTH], true) ? $this->driverAudience($broadcast) : null,
        ]);
    }

    /** Called by the job. Returns the number of recipients. */
    public function deliver(MarketingBroadcast $broadcast): int
    {
        $claimed = MarketingBroadcast::query()->whereKey($broadcast->id)->where('status', MarketingBroadcast::STATUS_QUEUED)
            ->update(['status' => MarketingBroadcast::STATUS_SENDING, 'started_at' => now()]);
        if ($claimed !== 1) {
            return 0; // already sent / being sent
        }
        $broadcast->refresh();

        $groups = $this->groups($broadcast);
        $total = array_sum(array_map(fn (Builder $query) => (clone $query)->count(), $groups));
        MarketingBroadcast::query()->whereKey($broadcast->id)->update(['total_count' => $total, 'processed_count' => 0]);
        Realtime::admin('marketing.progress', $broadcast->id);

        $count = 0;
        try {
            foreach ($groups as $query) {
                $table = $query->getModel()->getTable();
                $query->select($table.'.*')->chunkById((int) config('waffrli.push.marketing_chunk', 500), function ($recipients) use ($broadcast, &$count) {
                    // Per recipient and channel (notification audit, 2026-11-13): one
                    // failing channel no longer stops the rest of the audience.
                    Notify::send($recipients, new MarketingMessage($broadcast));
                    $count += $recipients->count();
                    MarketingBroadcast::query()->whereKey($broadcast->id)->update(['processed_count' => $count]);
                    Realtime::admin('marketing.progress', $broadcast->id);
                }, $table.'.id', 'id');
            }
        } catch (\Throwable $e) {
            MarketingBroadcast::query()->whereKey($broadcast->id)->update(['status' => MarketingBroadcast::STATUS_FAILED, 'recipients_count' => $count, 'processed_count' => $count, 'failure_reason' => mb_substr($e->getMessage(), 0, 250)]);
            Realtime::admin('marketing.progress', $broadcast->id);
            throw $e;
        }

        MarketingBroadcast::query()->whereKey($broadcast->id)->update(['status' => MarketingBroadcast::STATUS_SENT, 'recipients_count' => $count, 'processed_count' => $count, 'sent_at' => now()]);
        Realtime::admin('marketing.progress', $broadcast->id);

        return $count;
    }
}
