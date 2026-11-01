<?php

namespace Tests\Feature;

use App\Console\Commands\Waffrli\BuildMobileApiContractCommand;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * WAFFRLI (2026-11-04) — docs/waffrli/openapi/mobile-api.yaml must match the
 * implemented Customer and Driver routes (regenerate with
 * `php artisan waffrli:api-contract`).
 */
class MobileApiContractTest extends TestCase
{
    public function test_committed_contract_matches_the_routes(): void
    {
        $this->artisan('waffrli:api-contract', ['--check' => true])->assertSuccessful();
    }

    public function test_contract_covers_every_customer_and_driver_endpoint_securely(): void
    {
        $document = Yaml::parseFile(base_path(BuildMobileApiContractCommand::PATH));
        $operations = collect($document['paths'])->flatMap(fn ($ops, $path) => collect($ops)->map(fn ($op, $method) => $op + ['path' => $path, 'method' => $method])->values())->values();

        $this->assertCount(88, $operations, '63 customer + 25 driver operations.');
        $public = $operations->filter(fn ($op) => empty($op['security']))->map(fn ($op) => $op['method'].' '.$op['path'])->sort()->values()->all();
        $this->assertSame([
            'post /api/v1/customer/auth/otp/request', 'post /api/v1/customer/auth/otp/verify', 'post /api/v1/customer/auth/recovery/request',
            'post /api/v1/customer/auth/recovery/verify', 'post /api/v1/customer/auth/register', 'post /api/v1/driver/login',
        ], $public, 'Only sign-in endpoints are public.');
        foreach ($public as $endpoint) {
            $op = $operations->first(fn ($o) => $endpoint === $o['method'].' '.$o['path']);
            $this->assertNotEmpty($op['x-throttle'], "{$endpoint} must be rate limited.");
        }
        $this->assertFalse($operations->contains(fn ($op) => str_contains($op['path'], 'dev/')), 'The dev OTP helper is never documented.');
        $checkout = $operations->first(fn ($op) => str_ends_with($op['path'], '/checkout'));
        $this->assertContains(['$ref' => '#/components/parameters/IdempotencyKey'], $checkout['parameters']);
    }
}
