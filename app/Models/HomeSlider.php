<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Homepage hero slide. A null store_id is an informational/brand banner;
 * a linked slide opens a currently available branch in the customer's city.
 * Images live on the public storage disk. Titles are bilingual JSON.
 */
class HomeSlider extends BaseModel
{
    protected $table = 'home_sliders';

    protected $fillable = [
        'code', 'title', 'subtitle', 'image', 'store_id',
        'is_active', 'sort_order', 'starts_at', 'ends_at',
    ];

    protected $translatable = ['title', 'subtitle'];

    protected array $fileAttributes = ['image' => 'single'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }
}
