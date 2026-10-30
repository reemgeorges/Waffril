<?php

namespace App\Console\Commands\Waffrli;

use App\Models\Admin;
use Database\Seeders\AdminAccessSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * WAFFRLI (2026-10-31) — deliberately creates (or promotes) a Super Admin.
 * Credentials are always typed by the operator; nothing is hard-coded,
 * printed or logged.
 */
class CreateSuperAdminCommand extends Command
{
    protected $signature = 'waffrli:create-super-admin {--email= : Email of the admin to create or promote} {--name= : Name for a new admin}';

    protected $description = 'Create a Super Admin account (or promote an existing admin) for the Filament admin panel';

    public function handle(): int
    {
        (new AdminAccessSeeder)->run();

        $email = $this->option('email') ?: $this->ask('Email');
        $admin = Admin::withTrashed()->where('email', $email)->first();

        if (! $admin) {
            $name = $this->option('name') ?: $this->ask('Name');
            $password = $this->secret('Password (min 10 characters)');
            $validator = Validator::make(compact('email', 'name', 'password'), [
                'email' => ['required', 'email', 'max:255'],
                'name' => ['required', 'max:255'],
                'password' => ['required', 'min:10', 'max:100'],
            ]);
            if ($validator->fails()) {
                $this->error($validator->errors()->first());

                return self::FAILURE;
            }

            $admin = Admin::create(['name' => $name, 'email' => $email, 'password' => $password, 'is_active' => true]);
        } elseif (! $this->confirm("Promote existing admin {$email} to Super Admin?")) {
            return self::FAILURE;
        }

        $admin->assignRole(Admin::SUPER_ADMIN_ROLE);
        $this->info("{$email} is now a Super Admin.");

        return self::SUCCESS;
    }
}
