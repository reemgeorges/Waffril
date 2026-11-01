<?php

namespace App\Console\Commands\Waffrli;

use App\Models\Driver;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoPeopleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * WAFFRLI driver API (2026-11-02) — DEVELOPMENT ONLY. Lets a developer log
 * in to the driver API as a DEMO driver by choosing that driver's password
 * interactively.
 *
 * Safety:
 *  - refuses outside local / development / demo / testing;
 *  - only demo drivers (fictional +1 202 555 02xx phone created by the demo
 *    seeder) can be changed — never a real driver;
 *  - the password is typed at a hidden prompt (twice), never printed,
 *    logged or hard-coded; existing tokens of that driver are revoked.
 */
class SetDemoDriverPasswordCommand extends Command
{
    protected $signature = 'waffrli:demo-driver-password
        {driver? : Demo driver id or phone (omit to choose from the list)}';

    protected $description = 'DEV ONLY: set the password of a demo driver so you can log in to the driver API';

    public function handle(): int
    {
        if (! app()->environment(DemoContext::ALLOWED_ENVIRONMENTS)) {
            $this->error('Refused: this command only runs in '.implode(', ', DemoContext::ALLOWED_ENVIRONMENTS).'.');

            return self::FAILURE;
        }

        $demoDrivers = Driver::query()->where('phone', 'like', self::demoPhonePrefix().'%')->orderBy('id')->get();
        if ($demoDrivers->isEmpty()) {
            $this->error('No demo drivers found. Run: php artisan db:seed --class=WaffrliDemoSeeder');

            return self::FAILURE;
        }

        $this->table(['id', 'name', 'phone', 'city', 'active deliveries'], $demoDrivers->map(fn (Driver $d) => [
            $d->id, $d->name, $d->phone, $d->city?->getTranslation('name', 'en'), $d->activeOrders()->pluck('order_number')->implode(', ') ?: '—',
        ])->all());

        $selector = $this->argument('driver') ?? $this->ask('Demo driver id or phone');
        $driver = $demoDrivers->first(fn (Driver $d) => (string) $d->id === (string) $selector || $d->phone === $selector);
        if (! $driver) {
            $this->error('Refused: that is not a demo driver. Real driver accounts are managed only in the admin panel.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('New password (min 10 characters, hidden)');
        $confirmation = (string) $this->secret('Repeat the password');
        $validator = Validator::make(['password' => $password, 'password_confirmation' => $confirmation], [
            'password' => ['required', 'string', 'min:10', 'max:100', 'confirmed'],
        ]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        $driver->forceFill(['password' => $password])->save();
        $driver->tokens()->delete();

        $this->info("Password set for demo driver #{$driver->id} ({$driver->phone}). Existing tokens were revoked. The password was not displayed or stored anywhere else.");

        return self::SUCCESS;
    }

    /** Demo drivers use DemoContext::phone(100 + n) → +1202555 02xx. */
    public static function demoPhonePrefix(): string
    {
        return substr(DemoPeopleSeeder::driverPhone(1), 0, -2);
    }
}
