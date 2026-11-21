<?php

namespace Tests\Feature\Performance;

use App\Models\PersonalAccessToken;
use App\Models\Setting;
use App\Support\Settings\PlatformSettings;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Customer\CustomerTestCase;

/**
 * WAFFRLI (2026-11-21) — capacity audit regressions
 * (docs/performance/2000-user-capacity-audit.md):
 *  - PlatformSettings::get() is read once per key per request/job, and a
 *    changed setting is still visible immediately (same request and next job);
 *  - the branch list no longer runs one settings query per branch;
 *  - Sanctum's last_used_at is written at most once a minute per token,
 *    and tokens still authenticate and revoke exactly as before.
 */
class CapacityRegressionTest extends CustomerTestCase
{
    /** @return list<string> SQL of the queries $callback ran */
    private function queries(callable $callback): array
    {
        $sql = [];
        DB::listen(function ($query) use (&$sql) {
            $sql[] = $query->sql;
        });
        $callback();

        return $sql;
    }

    public function test_settings_are_read_once_per_request_and_changes_are_visible_at_once(): void
    {
        PlatformSettings::forgetMemo();
        $first = null;
        $reads = $this->queries(function () use (&$first) {
            $first = PlatformSettings::get('orders.accepting_new');
            PlatformSettings::get('orders.accepting_new');
            PlatformSettings::get('orders.accepting_new');
        });
        $this->assertCount(1, array_filter($reads, fn ($q) => str_contains($q, 'from `settings`') || str_contains($q, 'from "settings"')));

        // An admin changes the setting: the same request sees the new value.
        Setting::query()->updateOrCreate(['key' => 'orders.accepting_new'], ['value' => ! $first]);
        $this->assertSame(! $first, PlatformSettings::get('orders.accepting_new'));

        // Between queue jobs Laravel forgets scoped instances: the next job reads again.
        DB::table('settings')->where('key', 'orders.accepting_new')->update(['value' => json_encode((bool) $first)]);
        app()->forgetScopedInstances();
        $this->assertSame((bool) $first, PlatformSettings::get('orders.accepting_new'));
    }

    public function test_branch_list_does_not_query_settings_once_per_branch(): void
    {
        $token = $this->loginCustomer($this->demoCustomer(1));
        $this->api($token)->getJson('/api/v1/customer/branches?per_page=50')->assertOk();   // warm-up

        $response = null;
        $sql = $this->queries(function () use ($token, &$response) {
            $response = $this->api($token)->getJson('/api/v1/customer/branches?per_page=50')->assertOk();
        });
        $branches = count($response->json('data.data') ?? $response->json('data.branches') ?? []);
        $this->assertGreaterThan(1, $branches, 'The demo city lists several branches.');
        $settingsQueries = array_values(array_filter($sql, fn ($q) => str_contains($q, 'settings')));
        $this->assertSame(count($settingsQueries), count(array_unique($settingsQueries)), 'Each setting at most once per request.');
        $this->assertLessThan($branches, count($settingsQueries), 'No longer one settings query per branch.');
    }

    public function test_token_last_used_is_written_at_most_once_a_minute_and_tokens_behave_as_before(): void
    {
        $this->assertSame(PersonalAccessToken::class, Sanctum::$personalAccessTokenModel);
        $token = $this->loginCustomer($this->demoCustomer(2));

        $writes = fn (array $sql) => count(array_filter($sql, fn ($q) => str_starts_with(strtolower($q), 'update') && str_contains($q, 'personal_access_tokens')));

        $first = $this->queries(fn () => $this->api($token)->getJson('/api/v1/customer/notifications/unread-count')->assertOk());
        $this->assertSame(1, $writes($first), 'The first use is recorded.');
        $stamp = PersonalAccessToken::findToken($token)->last_used_at;

        $again = $this->queries(function () use ($token) {
            $this->api($token)->getJson('/api/v1/customer/notifications/unread-count')->assertOk();
            $this->api($token)->getJson('/api/v1/customer/orders')->assertOk();
        });
        $this->assertSame(0, $writes($again), 'Within a minute: no write.');
        $this->assertEquals($stamp, PersonalAccessToken::findToken($token)->last_used_at);

        $this->travel(61)->seconds();
        $later = $this->queries(fn () => $this->api($token)->getJson('/api/v1/customer/orders')->assertOk());
        $this->assertSame(1, $writes($later), 'After a minute: recorded again.');
        $this->assertTrue(PersonalAccessToken::findToken($token)->last_used_at->gt($stamp));

        // Revocation is unchanged: a deleted token is refused at once.
        PersonalAccessToken::findToken($token)->delete();
        $this->api($token)->getJson('/api/v1/customer/orders')->assertUnauthorized();
    }
}
