<?php

namespace Tests\Feature;

use App\Http\Traits\GeneralTrait;
use Illuminate\Database\QueryException;
use Tests\TestCase;

/**
 * WAFFRLI P0 (2026-10-30) — a MySQL 8 CHECK violation (error 3819) maps
 * to the same 400 envelope as the other integrity errors (1062/1451/1452)
 * instead of falling through to a 500.
 */
class PlatformQueryExceptionMappingTest extends TestCase
{
    public function test_check_constraint_violation_maps_to_a_400_envelope(): void
    {
        $handler = new class
        {
            use GeneralTrait;
        };

        $exception = new QueryException('mysql', 'insert into x', [], new \PDOException('Check constraint violated'));
        $exception->errorInfo = ['HY000', 3819, 'Check constraint \'chk_x\' is violated.'];

        $response = $handler->handleException($exception);
        $payload = json_decode($response->getContent(), true);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($payload['status']);
        $this->assertSame(400, $payload['statusCode']);
        $this->assertStringNotContainsString('chk_x', (string) $payload['error']);
    }
}
