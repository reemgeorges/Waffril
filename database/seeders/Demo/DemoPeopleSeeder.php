<?php

namespace Database\Seeders\Demo;

use App\Models\City;
use App\Models\Driver;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\Customer\CustomerAccountService;
use Illuminate\Database\Seeder;

/**
 * WAFFRLI demo — fictional customers (with valid addresses inside the
 * demo delivery areas) and drivers. Nobody can log in: passwords are
 * random and unknown, emails use the reserved demo domain and phones the
 * fictional 555-01xx/02xx range. Driver positions are set later by
 * DemoOrderSeeder (static demo coordinates, not GPS).
 */
class DemoPeopleSeeder extends Seeder
{
    /** n => [first, last, language, city, [address ar, en, lat, lng]...] */
    public const CUSTOMERS = [
        1 => ['Lina', 'Haddad', 'ar', 'Damascus', [['البيت', 'Home', 'شارع المزة فيلات', 'Mazzeh Villas street', 33.5020, 36.2500], ['العمل', 'Work', 'ساحة الأمويين', 'Umayyad Square', 33.5130, 36.2780]]],
        2 => ['Karim', 'Nasser', 'en', 'Damascus', [['البيت', 'Home', 'كفرسوسة', 'Kafr Sousa', 33.4990, 36.2700]]],
        3 => ['Maya', 'Khoury', 'ar', 'Damascus', [['البيت', 'Home', 'الشعلان', 'Shaalan', 33.5160, 36.2900]]],
        4 => ['Tarek', 'Saleh', 'ar', 'Damascus', [['البيت', 'Home', 'أبو رمانة', 'Abu Rummaneh', 33.5180, 36.2860]]],
        5 => ['Rania', 'Aziz', 'en', 'Damascus', [['البيت', 'Home', 'المهاجرين', 'Muhajireen', 33.5420, 36.2780]]],
        6 => ['Omar', 'Farah', 'ar', 'Damascus', [['البيت', 'Home', 'باب توما', 'Bab Touma', 33.5130, 36.3150]]],
        7 => ['Huda', 'Mansour', 'ar', 'Damascus', [['البيت', 'Home', 'ركن الدين', 'Rukn al-Din', 33.5500, 36.3000]]],
        8 => ['Sami', 'Barakat', 'en', 'Damascus', [['البيت', 'Home', 'المالكي', 'Malki', 33.5200, 36.2750]]],
        9 => ['Dana', 'Halabi', 'ar', 'Aleppo', [['البيت', 'Home', 'العزيزية', 'Aziziyeh', 36.2100, 37.1450]]],
        10 => ['Fares', 'Homsi', 'ar', 'Homs', [['البيت', 'Home', 'الإنشاءات', 'Inshaat', 34.7250, 36.7050]]],
    ];

    /** n => [name, city, vehicle, plate] */
    public const DRIVERS = [
        1 => ['Samer Driver', 'Damascus', 'motorcycle', 'DEMO-M-101'],
        2 => ['Rami Driver', 'Damascus', 'motorcycle', 'DEMO-M-102'],
        3 => ['Fadi Driver', 'Damascus', 'car', 'DEMO-C-103'],
        4 => ['Ziad Driver', 'Damascus', 'motorcycle', 'DEMO-M-104'],
        5 => ['Nour Driver', 'Damascus', 'bicycle', 'DEMO-B-105'],
        6 => ['Hadi Driver', 'Damascus', 'car', 'DEMO-C-106'],
        7 => ['Khaled Driver', 'Aleppo', 'motorcycle', 'DEMO-M-107'],
        8 => ['Yousef Driver', 'Aleppo', 'car', 'DEMO-C-108'],
    ];

    public function run(): void
    {
        $context = app(DemoContext::class);

        $context->at($context->anchor()->subDays(60), function () {
            foreach (self::CUSTOMERS as $n => [$first, $last, $language, $cityEn, $addresses]) {
                $email = self::customerEmail($n);
                if (User::withTrashed()->where('email', $email)->exists()) {
                    continue;
                }

                $user = User::create([
                    'name' => "{$first} {$last}", 'first_name' => $first, 'last_name' => $last,
                    'email' => $email, 'email_verified_at' => now(),
                    'password' => DemoContext::unusablePassword(),
                    'phone' => DemoContext::phone($n), 'language' => $language,
                    'is_active' => true, 'otp_channel' => 'sms',
                ]);
                $user->forceFill(['phone_verified_at' => now(), 'profile_completed_at' => now()])->save();

                $city = City::query()->where('name->en', $cityEn)->firstOrFail();
                foreach ($addresses as $i => [$labelAr, $labelEn, $lineAr, $lineEn, $lat, $lng]) {
                    UserAddress::create([
                        'user_id' => $user->id, 'city_id' => $city->id,
                        'label' => "{$labelEn} / {$labelAr}", 'address_line' => "{$lineEn} / {$lineAr}",
                        'details' => 'Demo address', 'lat' => $lat, 'lng' => $lng, 'is_default' => $i === 0,
                    ]);
                }
            }

            foreach (self::DRIVERS as $n => [$name, $cityEn, $vehicle, $plate]) {
                if (Driver::withTrashed()->where('phone', self::driverPhone($n))->exists()) {
                    continue;
                }
                Driver::create([
                    'name' => $name, 'phone' => self::driverPhone($n), 'password' => DemoContext::unusablePassword(),
                    'city_id' => City::query()->where('name->en', $cityEn)->firstOrFail()->id,
                    'vehicle_type' => $vehicle, 'vehicle_plate' => $plate,
                    'is_active' => true, 'availability' => Driver::AVAILABILITY_AVAILABLE, 'language' => 'ar',
                ]);
            }
        });

        // A suspended customer (through the real service, audited).
        $suspended = self::customer(8);
        if (! $suspended->isSuspended()) {
            $context->at($context->anchor()->subDays(2), fn () => app(CustomerAccountService::class)
                ->suspend($suspended, $context->operator(), 'Demo: repeated refused deliveries'));
        }
    }

    public static function customerEmail(int $n): string
    {
        return DemoContext::email(sprintf('customer-%02d', $n));
    }

    public static function driverPhone(int $n): string
    {
        return DemoContext::phone(100 + $n);
    }

    public static function customer(int $n): User
    {
        return User::query()->where('email', self::customerEmail($n))->firstOrFail();
    }

    public static function driver(int $n): Driver
    {
        return Driver::query()->where('phone', self::driverPhone($n))->firstOrFail();
    }
}
