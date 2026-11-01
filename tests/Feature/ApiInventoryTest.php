<?php

namespace Tests\Feature;

use App\Console\Commands\Waffrli\ApiInventoryCommand;
use Tests\TestCase;

/**
 * Every registered /api/v1 route must have at least one Bruno request
 * (api-tests/bruno/*), and every Bruno request must call a real route.
 */
class ApiInventoryTest extends TestCase
{
    public function test_every_api_route_has_a_bruno_request(): void
    {
        $this->artisan('waffrli:api-inventory', ['--check' => true])->assertSuccessful();
    }

    public function test_bruno_requests_only_call_registered_routes(): void
    {
        $patterns = collect(ApiInventoryCommand::apiRoutes())->map(fn ($r) => [$r[0], '#^'.preg_replace('#\\\\\{[^/]+?\\\\\}#', '[^/]+', preg_quote($r[1], '#')).'$#']);
        $unknown = collect(ApiInventoryCommand::brunoRequests())
            ->filter(fn ($req) => str_starts_with($req['path'], 'api/v1/') && ! str_starts_with($req['path'], 'api/v1/dev/'))
            ->reject(fn ($req) => $patterns->contains(fn ($p) => $p[0] === $req['method'] && preg_match($p[1], $req['path'])))
            ->map(fn ($req) => "{$req['method']} /{$req['path']} ({$req['file']})")
            ->values()->all();

        $this->assertSame([], $unknown, "Bruno requests calling routes that do not exist:\n".implode("\n", $unknown));
    }

    public function test_shared_portal_export_contains_every_bruno_request(): void
    {
        $bruno = collect(ApiInventoryCommand::brunoRequests())->count();
        $count = function (array $collections) use (&$count): int {
            return array_sum(array_map(fn ($c) => count($c['requests'] ?? []) + $count($c['folders'] ?? []), $collections));
        };
        $portal = json_decode((string) file_get_contents(base_path('api-tests/hoppscotch/waffrli-collections.json')), true);

        $this->assertSame($bruno, $count($portal), 'Regenerate the portal export: python3 api-tests/bruno/generators/hoppscotch.py');
        $this->assertStringNotContainsString('"password":', (string) file_get_contents(base_path('api-tests/hoppscotch/waffrli-environments.json')));
    }
}
