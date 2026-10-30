<?php

namespace App\Notifications;

use App\Models\Driver;
use App\Models\Order;
use Illuminate\Notifications\Notification;

/**
 * WAFFRLI driver API (2026-11-02) — a driver reported a problem with an
 * active delivery. Sent (database channel) to admins who can view orders;
 * they decide what happens next (reassign, cancel through the admin
 * services, contact the customer). Drivers never cancel orders themselves.
 */
class DriverDeliveryProblemReported extends Notification
{
    public const TYPES = ['customer_unreachable', 'wrong_address', 'customer_refused', 'branch_delay', 'order_issue', 'vehicle_issue', 'safety_issue', 'other'];

    public function __construct(public Driver $driver, public Order $order, public string $type, public ?string $note) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'driver.problem_reported';
    }

    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'driver_problem_reported',
            'type' => $this->type,
            'note' => $this->note,
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'order_status' => $this->order->status->value,
            'driver_id' => $this->driver->id,
            'driver_name' => $this->driver->name,
            'message' => [
                'en' => "Driver {$this->driver->name} reported a problem on {$this->order->order_number}: ".__('messages.driver.problem_types.'.$this->type, [], 'en'),
                'ar' => "أبلغ السائق {$this->driver->name} عن مشكلة في {$this->order->order_number}: ".__('messages.driver.problem_types.'.$this->type, [], 'ar'),
            ],
        ];
    }
}
