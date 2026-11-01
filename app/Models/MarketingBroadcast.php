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

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = ['admin_id', 'title', 'body', 'audience', 'city_id'];

    protected $casts = ['started_at' => 'datetime', 'sent_at' => 'datetime', 'recipients_count' => 'integer'];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
