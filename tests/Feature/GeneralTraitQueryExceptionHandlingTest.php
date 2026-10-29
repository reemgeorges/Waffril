<?php

namespace Tests\Feature;

use App\Http\Traits\GeneralTrait;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 0.1 security fix regression test.
 *
 * GeneralTrait::handleQueryException()'s default branch used to return
 * "Database error: " . $e->getMessage() unconditionally, with no environment
 * gate — leaking raw SQL / bound parameters / driver-specific error text to
 * API clients in every environment, including production. This test
 * exercises the real handleQueryException() code path against a genuine
 * QueryException raised by a duplicate-unique-key insert on the real
 * `users` table/migration (no mocking of the exception or of the fix
 * itself), and proves the detail is suppressed in production while
 * remaining visible outside of it, and that the
 * {data,status,error,statusCode} envelope shape is unchanged.
 */
class GeneralTraitQueryExceptionHandlingTest extends TestCase
{
    use GeneralTrait;
    use RefreshDatabase;

    /**
     * Insert two rows with the same `email` into the real, migrated `users`
     * table to provoke a genuine unique-constraint QueryException.
     */
    private function triggerDuplicateEmailQueryException(): QueryException
    {
        DB::table('users')->insert([
            'name' => 'First User',
            'email' => 'duplicate@example.com',
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            DB::table('users')->insert([
                'name' => 'Second User',
                'email' => 'duplicate@example.com',
                'password' => bcrypt('password'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            return $e;
        }

        $this->fail('Expected a QueryException due to a duplicate unique `email` column; none was thrown.');
    }

    public function test_query_exception_hides_db_details_in_production(): void
    {
        $this->app['env'] = 'production';

        $exception = $this->triggerDuplicateEmailQueryException();
        $response = $this->handleQueryException($exception);
        $payload = json_decode($response->getContent(), true);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(500, $payload['statusCode']);
        $this->assertFalse($payload['status']);
        $this->assertNull($payload['data']);
        $this->assertSame('A database error occurred. Please try again later.', $payload['error']);
        $this->assertStringNotContainsString('UNIQUE', (string) $payload['error']);
        $this->assertStringNotContainsString('SQL', (string) $payload['error']);
        $this->assertStringNotContainsString('duplicate@example.com', (string) $payload['error']);
        $this->assertStringNotContainsString($exception->getMessage(), (string) $payload['error']);
    }

    public function test_query_exception_shows_db_details_outside_production(): void
    {
        $this->app['env'] = 'testing';

        $exception = $this->triggerDuplicateEmailQueryException();
        $response = $this->handleQueryException($exception);
        $payload = json_decode($response->getContent(), true);

        $this->assertSame(500, $payload['statusCode']);
        $this->assertFalse($payload['status']);
        $this->assertStringStartsWith('Database error: ', (string) $payload['error']);
        $this->assertStringContainsString($exception->getMessage(), (string) $payload['error']);
    }

    public function test_query_exception_envelope_shape_is_preserved_in_production(): void
    {
        $this->app['env'] = 'production';

        $exception = $this->triggerDuplicateEmailQueryException();
        $response = $this->handleQueryException($exception);
        $payload = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('data', $payload);
        $this->assertArrayHasKey('status', $payload);
        $this->assertArrayHasKey('error', $payload);
        $this->assertArrayHasKey('statusCode', $payload);
        $this->assertCount(4, $payload);
    }
}
