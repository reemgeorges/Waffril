<?php

namespace Tests\Support;

use App\Models\Admin;
use App\Models\User;
use Database\Seeders\PlatformPermissionSeeder;

/**
 * WAFFRLI P0 (2026-10-30) — shared helpers for the P0 platform tests.
 */
trait PlatformTestHelpers
{
    protected function adminToken(array $permissions = []): string
    {
        (new PlatformPermissionSeeder)->run();

        $admin = Admin::factory()->create();
        if ($permissions !== []) {
            $admin->givePermissionTo($permissions);
        }

        return $admin->createToken('t')->plainTextToken;
    }

    protected function userToken(): string
    {
        return User::factory()->create(['is_active' => true])->createToken('t')->plainTextToken;
    }

    protected function asBearer(string $token): static
    {
        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    protected function squareBoundary(float $minLat, float $minLng, float $maxLat, float $maxLng): array
    {
        return [
            'type' => 'Polygon',
            'coordinates' => [[
                [$minLng, $minLat],
                [$maxLng, $minLat],
                [$maxLng, $maxLat],
                [$minLng, $maxLat],
                [$minLng, $minLat],
            ]],
        ];
    }
}
