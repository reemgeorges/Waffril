<?php

namespace Database\Seeders\Demo;

use App\Models\Order;
use App\Models\ProductRating;
use App\Services\Catalog\ProductRatingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * WAFFRLI demo — verified-purchase product ratings (two hidden by a
 * moderator for the moderation queue), product favorites and persisted
 * database notifications. Notifications are stored rows only: nothing is
 * sent (no FCM/SMS/e-mail).
 */
class DemoEngagementSeeder extends Seeder
{
    /** [customer, order number, store slug, product, rating, comment, hide reason] */
    public const RATINGS = [
        [1, 'DEMO-0001', 'demo-shami-grill', 'shawarma-wrap', 5, 'Hot, fresh and fast. / ساخنة وطازجة.', null],
        [1, 'DEMO-0008', 'demo-shami-grill', 'mixed-grill', 4, 'Generous portion.', null],
        [2, 'DEMO-0002', 'demo-pizza-roma', 'margherita', 4, 'Good crust, a bit salty.', null],
        [3, 'DEMO-0003', 'demo-fresh-market', 'olive-oil', 2, 'Bottle arrived broken, refund was quick.', null],
        [3, 'DEMO-0003', 'demo-fresh-market', 'milk', 5, null, null],
        [4, 'DEMO-0004', 'demo-shifa-pharmacy', 'paracetamol', 4, 'Delivered in 30 minutes.', null],
        [5, 'DEMO-0006', 'demo-golden-bakery', 'manakish', 5, 'Best zaatar in the area. / أطيب مناقيش.', null],
        [6, 'DEMO-0007', 'demo-tech-corner', 'power-bank', 1, '[demo] Offensive text replaced for the demo.', 'Demo: abusive language'],
        [9, 'DEMO-0005', 'demo-shami-grill', 'kebab-plate', 5, 'ممتاز', null],
        [7, 'DEMO-0010', 'demo-pizza-roma', 'pepperoni', 3, '[demo] Advertising another restaurant.', 'Demo: off-topic advertising'],
    ];

    /** [customer, store slug, product] */
    public const FAVORITES = [
        [1, 'demo-shami-grill', 'shawarma-wrap'], [1, 'demo-shami-grill', 'mixed-grill'], [2, 'demo-pizza-roma', 'margherita'],
        [3, 'demo-fresh-market', 'labneh'], [5, 'demo-golden-bakery', 'manakish'], [6, 'demo-tech-corner', 'power-bank'],
        [9, 'demo-shami-grill', 'kebab-plate'],
    ];

    public function run(): void
    {
        $context = app(DemoContext::class);
        $ratings = app(ProductRatingService::class);

        foreach (self::RATINGS as $i => [$customer, $number, $storeSlug, $productKey, $stars, $comment, $hideReason]) {
            $user = DemoPeopleSeeder::customer($customer);
            $product = DemoCatalogSeeder::findProduct($storeSlug, $productKey);
            $order = Order::query()->where('order_number', $number)->first();
            if (! $order || ProductRating::query()->where('user_id', $user->id)->where('product_id', $product->id)->exists()) {
                continue;
            }

            $at = ($order->delivered_at ?? $context->anchor())->copy()->addHours(2 + $i);
            $rating = $context->at($at, fn () => $ratings->record($user, $product, $order, $stars, $comment));
            if ($hideReason) {
                $context->at($at->copy()->addHours(3), fn () => $ratings->hide($rating, $context->operator(), $hideReason));
            }
        }

        foreach (self::FAVORITES as [$customer, $storeSlug, $productKey]) {
            DB::table('favorites')->insertOrIgnore([
                'user_id' => DemoPeopleSeeder::customer($customer)->id,
                'product_id' => DemoCatalogSeeder::findProduct($storeSlug, $productKey)->id,
                'created_at' => $context->anchor()->subDays(3),
            ]);
        }

        $this->notifications($context);
    }

    protected function notifications(DemoContext $context): void
    {
        $rows = [
            [1, 'DEMO-0032', 'order.assigned', ['en' => 'A driver is on the way to pick up your order.', 'ar' => 'السائق في طريقه لاستلام طلبك.'], 20, false],
            [3, 'DEMO-0033', 'order.picked_up', ['en' => 'Your order is on its way.', 'ar' => 'طلبك في الطريق إليك.'], 10, false],
            [4, 'DEMO-0028', 'order.scheduled_delayed', ['en' => 'Your scheduled order is being prepared.', 'ar' => 'يتم تجهيز طلبك المجدول.'], 15, false],
            [3, 'DEMO-0003', 'order.refunded', ['en' => 'A refund was added to your wallet.', 'ar' => 'تمت إضافة مبلغ مسترد إلى محفظتك.'], 60 * 24 * 11, true],
            [1, 'DEMO-0008', 'order.delivered', ['en' => 'Your order was delivered. Enjoy!', 'ar' => 'تم توصيل طلبك. بالهناء!'], 60 * 24 * 7, true],
            [2, 'DEMO-0019', 'order.cancelled', ['en' => 'Your cancellation request was approved.', 'ar' => 'تمت الموافقة على طلب الإلغاء.'], 60 * 24, true],
            [2, null, 'plus.renewed', ['en' => 'Your PLUS membership was renewed.', 'ar' => 'تم تجديد اشتراكك في بلس.'], 60 * 24 * 6, true],
            [5, null, 'plus.request_rejected', ['en' => 'Your PLUS request could not be approved.', 'ar' => 'تعذرت الموافقة على طلب بلس.'], 60 * 24 * 7, false],
        ];

        $builder = app(DemoOrderBuilder::class);
        foreach ($rows as [$customer, $number, $event, $message, $minutesAgo, $read]) {
            $user = DemoPeopleSeeder::customer($customer);
            $id = $builder->uuid("notification:{$customer}:{$event}:{$number}");
            if (DB::table('notifications')->where('id', $id)->exists()) {
                continue;
            }
            $at = $context->anchor()->subMinutes($minutesAgo);
            DB::table('notifications')->insert([
                'id' => $id,
                'type' => 'App\\Notifications\\Demo\\'.str_replace('.', '', ucwords($event, '.')),
                'notifiable_type' => $user->getMorphClass(),
                'notifiable_id' => $user->id,
                'data' => json_encode(['event' => $event, 'order_number' => $number, 'message' => $message, 'generated_demo' => true], JSON_UNESCAPED_UNICODE),
                'read_at' => $read ? $at->copy()->addMinutes(5) : null,
                'created_at' => $at, 'updated_at' => $at,
            ]);
        }
    }
}
