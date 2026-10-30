<?php

namespace App\Services\Notifications;

use App\Exceptions\BusinessRuleException;
use App\Jobs\Push\SendMarketingBroadcast;
use App\Models\Admin;
use App\Models\MarketingBroadcast;
use App\Models\User;
use App\Notifications\MarketingMessage;
use App\Services\Audit\AdminAudit;
use App\Support\Auth\AdminPermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Notification;

/**
 * WAFFRLI (2026-11-03) — admin marketing messages to customers.
 *
 * - Only admins with notifications.marketing; every send is audited.
 * - Audience: all active customers, or those whose DEFAULT address is in a
 *   city. Suspended, inactive, deleted and anonymized accounts are excluded.
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
        if (($data['audience'] ?? MarketingBroadcast::AUDIENCE_ALL) === MarketingBroadcast::AUDIENCE_CITY && empty($data['city_id'])) {
            throw new BusinessRuleException(__('validation.required', ['attribute' => 'city']), 422);
        }

        $broadcast = MarketingBroadcast::create([
            'admin_id' => $admin->id,
            'title_en' => $data['title_en'], 'title_ar' => $data['title_ar'],
            'body_en' => $data['body_en'], 'body_ar' => $data['body_ar'],
            'audience' => $data['audience'] ?? MarketingBroadcast::AUDIENCE_ALL,
            'city_id' => ($data['audience'] ?? null) === MarketingBroadcast::AUDIENCE_CITY ? $data['city_id'] : null,
        ]);
        AdminAudit::log('notifications.marketing_queued', $broadcast, ['audience' => $broadcast->audience, 'city_id' => $broadcast->city_id], $admin);

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

    /** Called by the job. Returns the number of recipients. */
    public function deliver(MarketingBroadcast $broadcast): int
    {
        $claimed = MarketingBroadcast::query()->whereKey($broadcast->id)->where('status', MarketingBroadcast::STATUS_QUEUED)
            ->update(['status' => MarketingBroadcast::STATUS_SENDING, 'started_at' => now()]);
        if ($claimed !== 1) {
            return 0; // already sent / being sent
        }

        $count = 0;
        try {
            $this->audience($broadcast)->select('users.*')->chunkById((int) config('waffrli.push.marketing_chunk', 500), function ($users) use ($broadcast, &$count) {
                Notification::send($users, new MarketingMessage($broadcast));
                $count += $users->count();
            });
        } catch (\Throwable $e) {
            MarketingBroadcast::query()->whereKey($broadcast->id)->update(['status' => MarketingBroadcast::STATUS_FAILED, 'recipients_count' => $count, 'failure_reason' => mb_substr($e->getMessage(), 0, 250)]);
            throw $e;
        }

        MarketingBroadcast::query()->whereKey($broadcast->id)->update(['status' => MarketingBroadcast::STATUS_SENT, 'recipients_count' => $count, 'sent_at' => now()]);

        return $count;
    }
}
