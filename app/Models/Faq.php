<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** WAFFRLI (2026-11-12) — a bilingual question and answer shown in the app when published. */
class Faq extends BaseModel
{
    protected $fillable = ['question', 'answer', 'sort_order', 'is_published', 'updated_by_admin_id'];

    protected $translatable = ['question', 'answer'];

    protected $casts = [
        'is_published' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true)->orderBy('sort_order')->orderBy('id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by_admin_id');
    }
}
