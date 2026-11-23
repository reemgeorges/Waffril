<?php

namespace App\Services\Ratings;

use App\Exceptions\BusinessRuleException;
use App\Models\Admin;
use App\Models\DriverRating;
use App\Models\OrderRating;
use App\Services\Audit\AdminAudit;
use App\Support\Auth\AdminPermissions;
use Illuminate\Support\Facades\DB;

/** Moderation keeps customer content immutable and does not change stored scores. */
class RatingModerationService
{
    public function setHidden(OrderRating|DriverRating $rating, Admin $admin, bool $hidden, ?string $reason = null): void
    {
        if (! AdminPermissions::allows($admin, 'ratings.moderate')) {
            throw new BusinessRuleException(__('admin.errors.forbidden'), 403);
        }
        if ($hidden && ! filled($reason)) {
            throw new BusinessRuleException(__('order_driver_ratings.reason_required'), 422);
        }
        DB::transaction(function () use ($rating, $admin, $hidden, $reason) {
            $rating = $rating::query()->lockForUpdate()->findOrFail($rating->getKey());
            if ($rating->is_hidden === $hidden) {
                return;
            }
            $rating->forceFill([
                'is_hidden' => $hidden,
                'hidden_at' => $hidden ? now() : null,
                'hidden_by_admin_id' => $hidden ? $admin->id : null,
                'hidden_reason' => $hidden ? $reason : null,
            ])->save();
            AdminAudit::log($hidden ? 'rating.hidden' : 'rating.unhidden', $rating, [
                'kind' => $rating instanceof DriverRating ? 'driver' : 'order',
                'reason' => $hidden ? $reason : null,
            ], $admin);
        });
    }
}
