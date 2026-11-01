<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WAFFRLI (2026-10-31). Customer delivery address.
 */
class UserAddress extends BaseModel
{
    protected $fillable = [
        'user_id' => 'user_id',
        'city_id' => 'city_id',
        'label' => 'label',
        'address_line' => 'address_line',
        'details' => 'details',
        'lat' => 'lat',
        'lng' => 'lng',
        'is_default' => 'is_default',
    ];

    protected $hidden = ['default_marker'];

    protected $casts = [
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'is_default' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
