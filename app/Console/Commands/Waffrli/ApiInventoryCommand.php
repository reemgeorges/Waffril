<?php

namespace App\Console\Commands\Waffrli;

use App\Http\Controllers\Auth\UserAuthController;
use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * WAFFRLI (2026-11-05) — maps every registered /api/v1 route to the Bruno
 * request file(s) that call it (api-tests/bruno/*), writes
 * docs/waffrli/api-inventory.md, and with --check fails when a route has no
 * Bruno request (enforced by tests/Feature/ApiInventoryTest).
 *
 * Never required (listed when registered):
 *  - the development-only OTP helper (GET /api/v1/dev/otp: local + fake SMS + peek token);
 *  - the legacy starter email/password customer routes (UserAuthController),
 *    registered only with WAFFRLI_LEGACY_USER_AUTH=true (phpunit.xml enables
 *    them for the starter regression tests; disabled in every shipped env).
 */
class ApiInventoryCommand extends Command
{
    public const DOC = 'docs/waffrli/api-inventory.md';

    protected $signature = 'waffrli:api-inventory {--check : Fail when an API route has no Bruno request}';

    protected $description = 'Map every API route to its Bruno request file(s) and write docs/waffrli/api-inventory.md';

    public function handle(): int
    {
        $requests = self::brunoRequests();
        $rows = [];
        $missing = [];

        foreach (self::apiRoutes() as [$method, $uri, $route]) {
            $pattern = '#^'.preg_replace('#\\\\\{[^/]+?\\\\\}#', '[^/]+', preg_quote($uri, '#')).'$#';
            $files = collect($requests)->filter(fn ($r) => $r['method'] === $method && preg_match($pattern, $r['path']))->pluck('file')->unique()->values()->all();
            $auth = collect($route->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, 'auth:'));
            $rows[] = [$method, '/'.$uri, $auth ? Str::after($auth, 'auth:') : 'public', $files];
            if ($files === [] && ! self::optional($uri, $route)) {
                $missing[] = "{$method} /{$uri}";
            }
        }

        if ($this->option('check')) {
            if ($missing !== []) {
                $this->error(count($missing).' API route(s) without a Bruno request:');
                foreach ($missing as $line) {
                    $this->line("  - {$line}");
                }

                return self::FAILURE;
            }
            $this->info(count($rows).' API routes, all covered by Bruno requests.');

            return self::SUCCESS;
        }

        File::put(base_path(self::DOC), self::markdown($rows));
        $this->info('Wrote '.self::DOC.' ('.count($rows).' routes, '.count($missing).' without a Bruno request).');

        return self::SUCCESS;
    }

    public static function optional(string $uri, Route $route): bool
    {
        return str_starts_with($uri, 'api/v1/dev/')
            || str_starts_with((string) $route->getActionName(), UserAuthController::class.'@');
    }

    /** @return list<array{0: string, 1: string, 2: Route}> */
    public static function apiRoutes(): array
    {
        $routes = [];
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes[] = [$method, $route->uri(), $route];
            }
        }
        usort($routes, fn ($a, $b) => [$a[1], $a[0]] <=> [$b[1], $b[0]]);

        return $routes;
    }

    /** @return list<array{method: string, path: string, file: string}> */
    public static function brunoRequests(): array
    {
        $requests = [];
        foreach (File::allFiles(base_path('api-tests/bruno')) as $file) {
            if ($file->getExtension() !== 'bru') {
                continue;
            }
            $content = $file->getContents();
            if (! preg_match('/^(get|post|put|patch|delete) \{\s*\n\s*url: (\S+)/mi', $content, $m)) {
                continue;
            }
            $collection = Str::before(Str::after($file->getPathname(), base_path('api-tests/bruno/')), '/');
            $url = preg_replace('#^\{\{base_url\}\}#', '', $m[2]);
            $url = Str::before($url, '?');
            if (! str_starts_with($url, '/api/')) {
                $url = match ($collection) {
                    'WAFFRLI-Customer' => '/api/v1/customer/'.ltrim($url, '/'),
                    'WAFFRLI-Driver' => '/api/v1/driver/'.ltrim($url, '/'),
                    default => '/'.ltrim($url, '/'),
                };
            }
            $requests[] = [
                'method' => strtoupper($m[1]),
                'path' => ltrim(preg_replace('#\{\{[^}]+\}\}#', 'x', $url), '/'),
                'file' => Str::after($file->getPathname(), base_path().'/'),
            ];
        }

        return $requests;
    }

    protected static function markdown(array $rows): string
    {
        $out = "# WAFFRLI API inventory\n\n"
            ."Generated by `php artisan waffrli:api-inventory` from the registered routes and the Bruno collections in `api-tests/bruno`. Do not edit by hand.\n\n"
            ."`--check` (and `tests/Feature/ApiInventoryTest`) fails when an API route has no Bruno request.\n\n"
            ."| Method | Route | Auth guard | Bruno request(s) |\n|---|---|---|---|\n";
        foreach ($rows as [$method, $uri, $auth, $files]) {
            $links = $files === [] ? '—' : implode('<br>', array_map(fn ($f) => '`'.Str::after($f, 'api-tests/bruno/').'`', $files));
            $out .= "| {$method} | `{$uri}` | {$auth} | {$links} |\n";
        }

        return $out.'
Totals: '.count($rows).' routes; '.collect($rows)->filter(fn ($r) => $r[3] !== [])->count()." covered.\n";
    }
}
