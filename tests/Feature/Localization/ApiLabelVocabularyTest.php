<?php

namespace Tests\Feature\Localization;

use App\Enums\OrderStatus;
use App\Events\Realtime\OrderStatusChanged;
use App\Models\Order;
use App\Models\User;
use App\Support\Localization\Label;
use Illuminate\Support\Facades\Lang;
use Tests\Feature\Customer\CustomerTestCase;

/**
 * API audit (2026-11-13). The admin panel wording review of 2026-11-11
 * rewrote shared translation keys, which silently changed the *_label text
 * the customer and driver apps receive ("Pending" became "Awaiting
 * acceptance"). The APIs now read their own vocabulary (api_labels.php).
 * These tests pin the released app text and prove the panel wording can
 * change without reaching the APIs.
 */
class ApiLabelVocabularyTest extends CustomerTestCase
{
    /** The text the apps received before the panel review (baseline 6498708). */
    public function test_api_labels_keep_the_released_app_text(): void
    {
        $released = [
            'en' => [
                ['order_status', 'pending', 'Pending'],
                ['order_status', 'picked_up', 'Picked up'],
                ['payment_status', 'pending', 'Pending'],
                ['payment_status', 'reversed', 'Reversed'],
                ['request_status', 'pending', 'Pending'],
                ['settlement_status', 'open', 'Open'],
                ['settlement_status', 'disputed', 'Disputed'],
            ],
            'ar' => [
                ['order_status', 'pending', 'معلق'],
                ['order_status', 'assigned', 'تم إسناد سائق'],
                ['order_status', 'picked_up', 'تم الاستلام من الفرع'],
                ['payment_status', 'pending', 'معلق'],
                ['request_status', 'approved', 'موافق عليه'],
                ['plus_membership', 'active', 'نشطة'],
                ['driver_availability', 'offline', 'غير متصل'],
                ['settlement_status', 'settled', 'مسوّاة'],
            ],
        ];

        foreach ($released as $locale => $rows) {
            app()->setLocale($locale);
            foreach ($rows as [$family, $value, $text]) {
                $actual = $family === 'order_status' ? OrderStatus::from($value)->publicLabel() : Label::of($family, $value);
                $this->assertSame($text, $actual, "{$locale} {$family}.{$value}");
            }
        }
    }

    public function test_panel_wording_does_not_reach_the_api(): void
    {
        app()->setLocale('en');
        // The panel keeps its own (reviewed) wording …
        $this->assertSame('Awaiting acceptance', OrderStatus::Pending->label());
        // … and a panel-only edit never changes the API text.
        Lang::addLines(['admin.order_status.picked_up' => 'Changed for staff'], 'en');
        $this->assertSame('Changed for staff', OrderStatus::PickedUp->label());
        $this->assertSame('Picked up', OrderStatus::PickedUp->publicLabel());

        // A value with no API entry still falls back to the shared admin text.
        Lang::addLines(['admin.orders.type_brand_new' => 'Brand new'], 'en');
        $this->assertSame('Brand new', Label::of('order_type', 'brand_new'));
        $this->assertSame('free_form_reason', Label::of('cancellation_reason', 'free_form_reason'));
    }

    public function test_customer_order_and_realtime_payload_use_the_api_text(): void
    {
        $order = Order::where('order_number', 'DEMO-0033')->firstOrFail(); // picked up
        $token = $this->loginCustomer(User::findOrFail($order->user_id));

        $en = $this->api($token)->withHeader('Accept-Language', 'en')
            ->getJson("/api/v1/customer/orders/{$order->id}")->assertOk()->json('data.order');
        $this->assertSame('picked_up', $en['status']);
        $this->assertSame('Picked up', $en['status_label']);

        $payload = OrderStatusChanged::fromTransition($order, OrderStatus::Assigned->value, null)->broadcastWith();
        $this->assertSame(['ar' => 'تم الاستلام من الفرع', 'en' => 'Picked up'], $payload['status_labels']);
    }

    public function test_every_api_label_exists_in_both_languages(): void
    {
        $en = Lang::get('api_labels', [], 'en');
        $ar = Lang::get('api_labels', [], 'ar');
        $this->assertSame(array_keys($en), array_keys($ar));
        foreach ($en as $group => $keys) {
            $this->assertSame(array_keys($keys), array_keys($ar[$group]), "api_labels.{$group}");
        }
    }
}
