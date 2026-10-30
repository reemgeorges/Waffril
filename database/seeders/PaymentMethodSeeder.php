<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

/**
 * WAFFRLI (2026-10-31) — idempotent system seeder for the launch payment
 * methods: wallet and cash on delivery. Existing rows are never changed.
 * Not called from DatabaseSeeder.
 */
class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        PaymentMethod::query()->firstOrCreate(['code' => PaymentMethod::CODE_WALLET], [
            'kind' => 'wallet', 'name' => ['en' => 'Wallet', 'ar' => 'المحفظة'], 'is_active' => true, 'sort_order' => 1,
        ]);
        PaymentMethod::query()->firstOrCreate(['code' => PaymentMethod::CODE_CASH], [
            'kind' => 'cash', 'name' => ['en' => 'Cash on delivery', 'ar' => 'الدفع عند الاستلام'], 'is_active' => true, 'sort_order' => 2,
        ]);
    }
}
