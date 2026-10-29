<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
        public function run(): void
    {
        Currency::updateOrCreate(
            ['code' => 'USD'],
            [
                'name' => 'US Dollar',
                'symbol' => '$',
                'decimals' => 2,
                'is_active' => true,
            ]
        );

        Currency::updateOrCreate(
            ['code' => 'SYP'],
            [
                'name' => 'Syrian Pound',
                'symbol' => 'ل.س',
                'decimals' => 2,
                'is_active' => true,
            ]
        );
    }

}
