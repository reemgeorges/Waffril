<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WAFFRLI (2026-11-12) — bilingual app text identified by a fixed key
 * (currently `terms`). Changed only through App\Services\Content\ContentService.
 */
class ContentPage extends BaseModel
{
    public const TERMS = 'terms';

    /** Keys the app knows about (DB CHECK on MySQL). */
    public const KEYS = [self::TERMS];

    protected $fillable = ['key', 'title', 'body', 'is_published', 'published_at', 'version', 'updated_by_admin_id'];

    protected $translatable = ['title', 'body'];

    protected $casts = [
        'is_published' => 'boolean',
        'published_at' => 'datetime',
        'version' => 'integer',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by_admin_id');
    }
}
