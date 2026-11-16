<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

/** WAFFRLI (2026-11-03) — one admin marketing message and its delivery state. */
class MarketingBroadcast extends Model
{
    use HasTranslations;

    /** {ar, en} JSON (spatie/laravel-translatable). */
    public array $translatable = ['title', 'body'];

    public const AUDIENCE_ALL = 'all_customers';

    public const AUDIENCE_CITY = 'city';

    /** Who receives the message (2026-11-16); the city filter applies to each group. */
    public const RECIPIENTS_CUSTOMERS = 'customers';

    public const RECIPIENTS_DRIVERS = 'drivers';

    public const RECIPIENTS_BOTH = 'both';

    public const RECIPIENT_TYPES = [self::RECIPIENTS_CUSTOMERS, self::RECIPIENTS_DRIVERS, self::RECIPIENTS_BOTH];

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = ['admin_id', 'title', 'body', 'audience', 'recipient_type', 'city_id'];

    protected $casts = ['started_at' => 'datetime', 'sent_at' => 'datetime', 'recipients_count' => 'integer', 'total_count' => 'integer', 'processed_count' => 'integer', 'push_enabled' => 'boolean', 'stored_count' => 'integer', 'push_no_device_count' => 'integer', 'push_queued_count' => 'integer', 'push_accepted_count' => 'integer', 'push_invalid_count' => 'integer', 'push_skipped_count' => 'integer', 'push_failed_count' => 'integer'];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** Push jobs queued but without an outcome yet (worker not run / still retrying). */
    public function pushPending(): int
    {
        return max(0, (int) $this->push_queued_count - (int) $this->push_accepted_count - (int) $this->push_invalid_count
            - (int) $this->push_skipped_count - (int) $this->push_failed_count);
    }

    /** True while sending is queued and no worker has picked it up for over 2 minutes. */
    public function waitingForWorker(): bool
    {
        return $this->status === self::STATUS_QUEUED && $this->created_at !== null && $this->created_at->lt(now()->subMinutes(2));
    }

    /**
     * What is known about delivery, stage by stage (2026-11-16). Never claims
     * that a phone displayed anything: Firebase "accepted" is the strongest
     * evidence the server has. Messages sent before the counters existed say
     * "not recorded" instead of guessing.
     *
     * @return list<string>
     */
    public function deliveryLines(): array
    {
        if ($this->status === self::STATUS_QUEUED) {
            return [__($this->waitingForWorker() ? 'admin.marketing.waiting_worker' : 'admin.marketing.waiting')];
        }
        if ($this->stored_count === null) {
            return [__('admin.marketing.stats_not_recorded')];
        }

        $lines = [__('admin.marketing.stored', ['count' => number_format($this->stored_count)])];
        if ($this->push_enabled === false) {
            $lines[] = __('admin.marketing.push_off');

            return $lines;
        }

        $parts = [__('admin.marketing.push_queued', ['count' => number_format($this->push_queued_count)])];
        $parts[] = __('admin.marketing.push_accepted', ['count' => number_format($this->push_accepted_count)]);
        foreach (['pending' => $this->pushPending(), 'failed' => $this->push_failed_count, 'invalid' => $this->push_invalid_count, 'skipped' => $this->push_skipped_count] as $key => $n) {
            if ($n > 0) {
                $parts[] = __('admin.marketing.push_'.$key, ['count' => number_format($n)]);
            }
        }
        $lines[] = implode(' · ', $parts);
        if ($this->push_no_device_count > 0) {
            $lines[] = __('admin.marketing.push_no_device', ['count' => number_format($this->push_no_device_count)]);
        }

        return $lines;
    }
}
