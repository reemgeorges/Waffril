<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderRating extends BasePlatformModel
{
    protected $fillable = ['order_id', 'user_id', 'rating', 'comment'];

    protected $casts = [
        'rating' => 'integer',
        'is_hidden' => 'boolean',
        'hidden_at' => 'datetime',
    ];

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class)->withTrashed(); }
    public function hiddenBy(): BelongsTo { return $this->belongsTo(Admin::class, 'hidden_by_admin_id'); }
}
