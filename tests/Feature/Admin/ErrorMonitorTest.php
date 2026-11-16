<?php

namespace Tests\Feature\Admin;

use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\ErrorEvents\Pages\ListErrorEvents;
use App\Models\ErrorEvent;
use App\Services\Monitoring\ErrorMonitor;
use App\Services\Monitoring\Scrubber;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;

/**
 * WAFFRLI (2026-11-16, Phase 9) — correlation ids, grouped and scrubbed
 * error storage, the Monitoring → Errors screen, and retention.
 */
class ErrorMonitorTest extends AdminPanelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('api')->get('api/v1/__test/boom/{id}', function (string $id) {
            throw new RuntimeException("Charge {$id} failed: password=hunter2 token: abcdef otp=482913 for owner@example.com +963 944 123 456");
        });
        Route::middleware('api')->get('api/v1/__test/refused', fn () => throw new BusinessRuleException('Not allowed'));
    }

    public function test_scrubber_removes_secrets_and_personal_data(): void
    {
        $text = Scrubber::text('password=hunter2 "token":"abc123" otp: 482913 Authorization: Bearer 5|AbCdEfGhIjKlMnOpQrStUvWxYz0123 a.b@example.com +963944123456 eyJhbGciOi.eyJzdWIiOi.c2lnbmF0dXJl');

        foreach (['hunter2', 'abc123', '482913', 'AbCdEfGh', 'a.b@example.com', '944123456', 'eyJhbGciOi'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
        $this->assertStringContainsString('[email]', $text);
    }

    public function test_every_response_carries_a_request_id_header_and_json_bodies_are_unchanged(): void
    {
        $generated = $this->getJson('/api/v1/__test/refused');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $generated->headers->get('X-Request-Id'));
        $this->assertSame(['data', 'status', 'error', 'statusCode'], array_keys($generated->json()), 'Envelope unchanged.');
        $this->assertStringNotContainsString($generated->headers->get('X-Request-Id'), $generated->getContent());

        $this->getJson('/api/v1/__test/refused', ['X-Request-Id' => 'cf-ray-1234abcd'])->assertHeader('X-Request-Id', 'cf-ray-1234abcd');
        $this->assertNotSame('<script>', $this->getJson('/api/v1/__test/refused', ['X-Request-Id' => '<script>'])->headers->get('X-Request-Id'));
    }

    public function test_unexpected_errors_are_stored_grouped_scrubbed_and_correlated(): void
    {
        $first = $this->getJson('/api/v1/__test/boom/101?card=4111111111111111', ['X-Request-Id' => 'req-first-0001']);
        $first->assertStatus(500);
        $this->getJson('/api/v1/__test/boom/202', ['X-Request-Id' => 'req-second-0002'])->assertStatus(500);

        $event = ErrorEvent::sole();
        $this->assertSame(RuntimeException::class, $event->exception_class);
        $this->assertSame(2, $event->occurrences, 'Different ids in the message: same group.');
        $this->assertSame('req-second-0002', $event->last_request_id);
        $this->assertSame('GET', $event->last_method);
        $this->assertSame('/api/v1/__test/boom/202', $event->last_path);
        $this->assertStringStartsWith('tests/Feature/Admin/ErrorMonitorTest.php', $event->file);
        foreach (['hunter2', 'abcdef', '482913', 'owner@example.com', '944 123 456', '4111111111111111'] as $secret) {
            $this->assertStringNotContainsString($secret, $event->message.$event->trace.$event->last_path);
        }
        $this->assertStringNotContainsString('req-first-0001', $first->getContent());
    }

    public function test_expected_outcomes_are_not_errors(): void
    {
        $this->getJson('/api/v1/__test/refused')->assertStatus(422);
        $this->getJson('/api/v1/__definitely-missing')->assertNotFound();
        ErrorMonitor::capture(ValidationException::withMessages(['x' => 'y']));

        $this->assertSame(0, ErrorEvent::count());
    }

    public function test_a_resolved_error_that_returns_is_reopened(): void
    {
        $this->getJson('/api/v1/__test/boom/1');
        $event = ErrorEvent::sole();
        $event->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();

        $this->getJson('/api/v1/__test/boom/2');
        $event->refresh();
        $this->assertSame('open', $event->status);
        $this->assertNotNull($event->regressed_at);
        $this->assertNull($event->resolved_at);

        $event->forceFill(['status' => 'ignored'])->save();
        $this->getJson('/api/v1/__test/boom/3');
        $this->assertSame('ignored', $event->fresh()->status, 'Ignored keeps counting silently.');
        $this->assertSame(3, $event->fresh()->occurrences);
    }

    public function test_panel_screen_is_permission_gated_and_actions_are_audited(): void
    {
        $this->getJson('/api/v1/__test/boom/1');
        $event = ErrorEvent::sole();

        $this->actingAsAdmin($this->employee(['orders.view']));
        $this->get('/admin/error-events')->assertForbidden();

        $this->actingAsAdmin($this->employee(['errors.view']));
        Livewire::test(ListErrorEvents::class)->assertCanSeeTableRecords([$event])
            ->assertTableActionHidden('resolve', $event);

        $manager = $this->employee(['errors.view', 'errors.manage']);
        $this->actingAsAdmin($manager);
        Livewire::test(ListErrorEvents::class)->callAction(TestAction::make('resolve')->table($event));
        $this->assertSame('resolved', $event->fresh()->status);
        $this->assertSame($manager->id, $event->fresh()->resolved_by_admin_id);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'errors.resolved', 'admin_id' => $manager->id]);
    }

    public function test_retention_prunes_only_old_resolved_and_long_silent_groups(): void
    {
        $make = fn (string $status, int $daysAgo) => ErrorEvent::create([
            'fingerprint' => hash('sha256', $status.$daysAgo), 'exception_class' => 'E', 'message' => 'm', 'status' => $status,
            'first_seen_at' => now()->subDays($daysAgo), 'last_seen_at' => now()->subDays($daysAgo), 'environment' => 'testing',
        ]);
        $oldResolved = $make('resolved', 40);
        $recentResolved = $make('resolved', 5);
        $openRecent = $make('open', 40);
        $openAncient = $make('open', 120);

        $this->artisan('waffrli:prune-errors', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(4, ErrorEvent::count());

        $this->artisan('waffrli:prune-errors')->assertSuccessful();
        $this->assertEqualsCanonicalizing([$recentResolved->id, $openRecent->id], ErrorEvent::pluck('id')->all());
    }
}
