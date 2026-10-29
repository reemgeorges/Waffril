<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Phase 2B (2026-10-25) security fix: this seeder previously hard-coded
     * a live admin credential (admin@gmail.com / 12345678) directly in
     * source — exactly what CLAUDE.md's quality gate forbids ("No ...
     * hard-coded ... credentials") and what would land in every clone of
     * this starter kit with the same known password. It now reads the
     * credential from the environment and does nothing (loudly) if it
     * isn't configured, instead of ever inventing one. Still idempotent
     * (updateOrCreate) and still never runs on its own — seeders are only
     * ever invoked explicitly via `php artisan db:seed`.
     */
    public function run(): void
    {
        $email = env('SEED_ADMIN_EMAIL');
        $password = env('SEED_ADMIN_PASSWORD');

        if (! $email || ! $password) {
            $this->command?->warn(
                'AdminSeeder: SEED_ADMIN_EMAIL and/or SEED_ADMIN_PASSWORD are not set in .env — skipping. '.
                'This seeder intentionally never falls back to a hard-coded credential.'
            );

            return;
        }

        Admin::updateOrCreate(
            ['email' => $email],
            [
                'name' => env('SEED_ADMIN_NAME', 'Admin'),
                'password' => Hash::make($password),
            ]
        );
    }
}
