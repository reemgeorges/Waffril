<?php

/**
 * WAFFRLI — per-endpoint query profile (read-only GET requests), run ON a
 * staging/local copy seeded with LoadTestDatasetSeeder:
 *
 *   php tests/load/tools/profile-endpoints.php [repetitions=20]
 *
 * Calls each endpoint through the HTTP kernel (no network) as a load-test
 * customer/driver and reports, per endpoint: SQL queries per request, SQL
 * time and wall time (median of the repetitions), plus the slowest SQL
 * statement. Refuses to run in production. Prints no tokens.
 */

use App\Models\BranchProduct;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

if (app()->environment('production')) {
    fwrite(STDERR, "Refusing to run in production.\n");
    exit(1);
}

$reps = max(1, (int) ($argv[1] ?? 20));
$tokens = json_decode((string) file_get_contents(storage_path('app/loadtest/tokens.json')), true);
$customer = $tokens['customers'][0] ?? null;
$driver = $tokens['drivers'][0] ?? null;
$branch = $tokens['branches'][0] ?? null;
if (! $customer || ! $branch) {
    fwrite(STDERR, "Seed with LoadTestDatasetSeeder first.\n");
    exit(1);
}

$productId = $branch['product_ids'][0] ?? BranchProduct::query()->where('branch_id', $branch['id'])->where('is_available', true)->value('product_id');

$endpoints = [
    ['customer', 'GET', '/api/v1/customer/sliders'],
    ['customer', 'GET', '/api/v1/customer/store-categories'],
    ['customer', 'GET', '/api/v1/customer/branches?per_page=20'],
    ['customer', 'GET', "/api/v1/customer/branches/{$branch['id']}"],
    ['customer', 'GET', "/api/v1/customer/branches/{$branch['id']}/products?per_page=20"],
    ['customer', 'GET', "/api/v1/customer/branches/{$branch['id']}/products/{$productId}"],
    ['customer', 'GET', '/api/v1/customer/search?q=shawarma'],
    ['customer', 'GET', '/api/v1/customer/offers'],
    ['customer', 'GET', '/api/v1/customer/orders?per_page=10'],
    ['customer', 'GET', '/api/v1/customer/notifications?per_page=20'],
    ['customer', 'GET', '/api/v1/customer/notifications/unread-count'],
    ['customer', 'GET', '/api/v1/customer/plus/membership'],
    ['customer', 'GET', '/api/v1/customer/carts'],
    ['driver', 'GET', '/api/v1/driver/orders/active'],
    ['driver', 'GET', '/api/v1/driver/offers/current'],
];

$http = $app->make(HttpKernel::class);
$rows = [];
foreach ($endpoints as $index => [$who, $method, $uri]) {
    // A different customer per endpoint: one customer is limited to 180 requests/minute.
    $token = $who === 'driver' ? ($driver['token'] ?? null) : ($tokens['customers'][$index % count($tokens['customers'])]['token']);
    $samples = [];
    $slowest = ['ms' => 0, 'sql' => ''];
    for ($i = 0; $i < $reps + 1; $i++) {   // first call warms caches, not counted
        $queries = 0;
        $sqlMs = 0.0;
        DB::flushQueryLog();
        $listener = function ($q) use (&$queries, &$sqlMs, &$slowest) {
            $queries++;
            $sqlMs += $q->time;
            if ($q->time > $slowest['ms']) {
                $slowest = ['ms' => $q->time, 'sql' => mb_substr(preg_replace('/\s+/', ' ', $q->sql), 0, 160)];
            }
        };
        DB::listen($listener);
        $request = Request::create($uri, $method, server: ['HTTP_AUTHORIZATION' => "Bearer {$token}", 'HTTP_ACCEPT' => 'application/json', 'HTTP_ACCEPT_LANGUAGE' => 'ar']);
        $t0 = hrtime(true);
        $response = $http->handle($request);
        $wall = (hrtime(true) - $t0) / 1e6;
        $http->terminate($request, $response);
        // Listeners cannot be removed individually: reset the dispatcher's DB listeners.
        app('events')->forget(QueryExecuted::class);
        app('auth')->forgetGuards();
        if ($i > 0) {
            $samples[] = [$queries, $sqlMs, $wall, $response->getStatusCode()];
        }
    }
    $median = function (array $values) {
        sort($values);

        return $values[intdiv(count($values), 2)];
    };
    $rows[] = [
        'endpoint' => "{$method} {$uri}",
        'status' => $samples[0][3],
        'queries' => $median(array_column($samples, 0)),
        'sql_ms' => round($median(array_column($samples, 1)), 1),
        'wall_ms' => round($median(array_column($samples, 2)), 1),
        'slowest_sql_ms' => round($slowest['ms'], 1),
        'slowest_sql' => $slowest['sql'],
    ];
}

printf("%-75s %6s %8s %8s %8s %9s\n", 'endpoint', 'status', 'queries', 'sql_ms', 'wall_ms', 'max_sql');
foreach ($rows as $r) {
    printf("%-75s %6d %8d %8.1f %8.1f %9.1f\n", mb_substr($r['endpoint'], 0, 75), $r['status'], $r['queries'], $r['sql_ms'], $r['wall_ms'], $r['slowest_sql_ms']);
}
echo "\nSlowest statement per endpoint:\n";
foreach ($rows as $r) {
    echo "- {$r['endpoint']}: {$r['slowest_sql_ms']} ms — {$r['slowest_sql']}\n";
}
