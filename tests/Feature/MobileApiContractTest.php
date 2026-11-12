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

        $this->assertCount(94, $operations, '65 customer + 29 driver operations (4 delivery-offer endpoints 2026-11-10; 2 read-only content endpoints 2026-11-12).');
        $public = $operations->filter(fn ($op) => empty($op['security']))->map(fn ($op) => $op['method'].' '.$op['path'])->sort()->values()->all();
        $this->assertSame([
            // 2026-11-12: published Terms & Conditions and FAQ, read-only (shown during registration).
            'get /api/v1/customer/content/faqs', 'get /api/v1/customer/content/terms',
            'post /api/v1/customer/auth/otp/request', 'post /api/v1/customer/auth/otp/verify', 'post /api/v1/customer/auth/recovery/request',
            'post /api/v1/customer/auth/recovery/verify', 'post /api/v1/customer/auth/register', 'post /api/v1/driver/login',
        ], $public, 'Only sign-in endpoints and the read-only published content are public.');
        $this->assertTrue(collect($public)->every(fn ($e) => str_starts_with($e, 'post ') || str_contains($e, '/content/')), 'Public GETs are content reads only.');
        foreach ($public as $endpoint) {
            $op = $operations->first(fn ($o) => $endpoint === $o['method'].' '.$o['path']);
            $this->assertNotEmpty($op['x-throttle'], "{$endpoint} must be rate limited.");
        }
        $this->assertFalse($operations->contains(fn ($op) => str_contains($op['path'], 'dev/')), 'The dev OTP helper is never documented.');
        $checkout = $operations->first(fn ($op) => str_ends_with($op['path'], '/checkout'));
        $this->assertContains(['$ref' => '#/components/parameters/IdempotencyKey'], $checkout['parameters']);
    }
}
