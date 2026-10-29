<?php

namespace Tests\Feature;

use App\Http\Traits\GeneralTrait;
use Tests\TestCase;

/**
 * Phase 0 security fix regression test.
 *
 * GeneralTrait::handleException() used to return raw $e->getMessage() for any
 * uncaught \Exception with no environment gate, leaking internal details
 * (file paths, query fragments, etc.) to API clients in every environment,
 * including production. This test exercises the real handleException() /
 * apiResponse() code path (no mocking of the fix itself) and proves the
 * message is suppressed in production while remaining visible outside of it,
 * and that the {data,status,error,statusCode} envelope shape is unchanged.
 */
class GeneralTraitExceptionHandlingTest extends TestCase
{
    use GeneralTrait;

    public function test_generic_exception_message_is_hidden_in_production(): void
    {
        $this->app['env'] = 'production';

        $response = $this->handleException(
            new \RuntimeException('Sensitive internal detail: C:/secret/path/query failed')
        );

        $payload = json_decode($response->getContent(), true);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(500, $payload['statusCode']);
        $this->assertFalse($payload['status']);
        $this->assertSame('Internal server error.', $payload['error']);
        $this->assertStringNotContainsString('Sensitive internal detail', (string) $payload['error']);
        $this->assertNull($payload['data']);
    }

    public function test_generic_exception_message_is_shown_outside_production(): void
    {
        $this->app['env'] = 'testing';

        $response = $this->handleException(
            new \RuntimeException('Debug detail visible outside production')
        );

        $payload = json_decode($response->getContent(), true);

        $this->assertSame(500, $payload['statusCode']);
        $this->assertFalse($payload['status']);
        $this->assertSame('Debug detail visible outside production', $payload['error']);
    }

    public function test_envelope_shape_is_preserved_in_production(): void
    {
        $this->app['env'] = 'production';

        $response = $this->handleException(new \RuntimeException('shape check'));
        $payload = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('data', $payload);
        $this->assertArrayHasKey('status', $payload);
        $this->assertArrayHasKey('error', $payload);
        $this->assertArrayHasKey('statusCode', $payload);
        $this->assertCount(4, $payload);
    }
}
