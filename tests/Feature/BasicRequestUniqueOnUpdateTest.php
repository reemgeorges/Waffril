<?php

namespace Tests\Feature;

use App\Http\Requests\Model\StoreUserRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Phase 1 (2026-10-25): "unique-on-update" validation fix regression test.
 *
 * Request classes are shared between store AND update (e.g.
 * StoreUserRequest is used for both — see
 * App\Http\Controllers\User\UserController::setVariables():
 * `$this->createRequest = $this->updateRequest = StoreUserRequest::class;`).
 * Its `email` rule is `required|string|max:255|unique:users,email` with no
 * `ignore($id)`. Before this fix, updating a user WITHOUT changing their own
 * email always failed validation, because the row collided with itself.
 *
 * The fix lives in App\Http\Requests\Basic\BasicRequest::withValidator(),
 * which rewrites any `unique:` rule into Rule::unique(...)->ignore($id)
 * whenever the request carries an `id` field — the same convention
 * BasicCrudService uses ($request->id) to know which row is being acted on.
 *
 * These tests exercise the REAL resolution path used in production:
 * App\Http\Controllers\FatherCrudController::store()/update() both do
 * `$request = app($this->createRequest);` / `app($this->updateRequest);` —
 * i.e. resolving the FormRequest out of the container, which is exactly
 * what triggers Laravel's FormRequestServiceProvider to populate the
 * request from the current HTTP request and auto-validate it. No mocking
 * of BasicRequest or of the validator is performed.
 */
class BasicRequestUniqueOnUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function bindIncomingRequest(array $payload): void
    {
        $this->app->instance(
            'request',
            Request::create('/api/user/update', 'POST', $payload)
        );
    }

    public function test_update_without_changing_own_unique_email_passes_validation(): void
    {
        $user = User::factory()->create(['email' => 'alice@example.com']);

        $this->bindIncomingRequest([
            'id' => $user->id,
            'name' => 'Alice Updated',
            'email' => 'alice@example.com', // unchanged, same row
            'password' => 'password',
        ]);

        /** @var StoreUserRequest $request */
        $request = $this->app->make(StoreUserRequest::class);

        $validated = $request->validated();

        $this->assertSame('alice@example.com', $validated['email']);
        $this->assertSame('Alice Updated', $validated['name']);
    }

    public function test_update_changing_unique_email_to_another_existing_records_value_still_fails(): void
    {
        $alice = User::factory()->create(['email' => 'alice@example.com']);
        User::factory()->create(['email' => 'bob@example.com']);

        $this->bindIncomingRequest([
            'id' => $alice->id,
            'name' => 'Alice Updated',
            'email' => 'bob@example.com', // collides with a DIFFERENT existing row
            'password' => 'password',
        ]);

        $this->expectException(HttpResponseException::class);

        $this->app->make(StoreUserRequest::class);
    }

    public function test_update_changing_unique_email_to_a_brand_new_value_passes(): void
    {
        $alice = User::factory()->create(['email' => 'alice@example.com']);

        $this->bindIncomingRequest([
            'id' => $alice->id,
            'name' => 'Alice Updated',
            'email' => 'alice-new@example.com', // not used by anyone
            'password' => 'password',
        ]);

        $request = $this->app->make(StoreUserRequest::class);

        $this->assertSame('alice-new@example.com', $request->validated()['email']);
    }

    public function test_create_with_duplicate_unique_email_still_fails_as_before(): void
    {
        // Baseline: the create path (no `id` field present) must keep
        // rejecting duplicates exactly as before this fix.
        User::factory()->create(['email' => 'carol@example.com']);

        $this->bindIncomingRequest([
            'name' => 'New User',
            'email' => 'carol@example.com',
            'password' => 'password',
        ]);

        $this->expectException(HttpResponseException::class);

        $this->app->make(StoreUserRequest::class);
    }
}
