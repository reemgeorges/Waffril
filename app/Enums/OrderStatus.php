<?php

namespace App\Enums;

/**
 * WAFFRLI (2026-10-31) — order lifecycle. Allowed transitions are
 * enforced by App\Services\Order\OrderStateMachine.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Assigned = 'assigned';
    case PickedUp = 'picked_up';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('admin.order_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Accepted => 'info',
            self::Assigned, self::PickedUp => 'primary',
            self::Delivered => 'success',
            self::Cancelled => 'danger',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled], true);
    }

    /** Statuses in which an order is "active" (driver tracking, urgent queue). */
    public static function active(): array
    {
        return [self::Accepted->value, self::Assigned->value, self::PickedUp->value];
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
