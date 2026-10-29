<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

/**
 * Phase 8 (2026-10-26) — verifies docs/openapi/openapi.yaml (built by
 * `openapi:build`) actually matches the real, currently-registered
 * `api/v1/*` routes: every documented path+method must be a real,
 * registered route, and every real `api/v1/*` route must be documented.
 * Exits non-zero on any mismatch — intended for CI, so documentation
 * drift is caught the same way a failing test would be, not discovered
 * later by a confused API consumer.
 *
 * Deliberately only cross-checks path+method EXISTENCE, not security/
 * request-body accuracy beyond that — a cheap, reliable signal
 * (registered vs documented) that catches the most common and most
 * damaging kind of drift (an endpoint added/removed/renamed without its
 * docs following), without trying to re-derive and compare every
 * validation rule from the route table, which `make:module`'s
 * openApiStub() already keeps in sync at GENERATION time instead.
 */
class DocsCheckDriftCommand extends Command
{
    protected $signature = 'docs:check-drift';

    protected $description = 'Verify docs/openapi/openapi.yaml matches the real, currently-registered api/v1/* routes';

    public function handle(Filesystem $files): int
    {
        $openApiPath = base_path('docs/openapi/openapi.yaml');

        if (! $files->exists($openApiPath)) {
            $this->error("No aggregated OpenAPI document found at {$openApiPath} — run `php artisan openapi:build` first.");

            return 1;
        }

        $document = Yaml::parseFile($openApiPath);

        $documented = [];
        foreach ($document['paths'] ?? [] as $path => $operations) {
            // OpenAPI paths are written with a leading slash
            // (/api/v1/...); Route::uri() never has one — stripped here
            // once, rather than carrying the inconsistency into the
            // comparison below.
            $normalizedPath = ltrim($path, '/');
            foreach (array_keys($operations) as $method) {
                $documented["{$method} {$normalizedPath}"] = true;
            }
        }

        $registered = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                // Laravel registers HEAD alongside every GET automatically
                // — not a separately authored route, so not something the
                // docs are expected to list on its own.
                if ($method === 'HEAD') {
                    continue;
                }
                $registered[mb_strtolower($method).' '.$uri] = true;
            }
        }

        $missingDocs = array_diff_key($registered, $documented);
        $staleDocs = array_diff_key($documented, $registered);

        if (empty($missingDocs) && empty($staleDocs)) {
            $this->info('No drift: every documented api/v1/* path+method matches a real registered route, and vice versa ('.count($registered).' routes).');

            return 0;
        }

        if (! empty($missingDocs)) {
            $this->error('Registered but NOT documented:');
            foreach (array_keys($missingDocs) as $key) {
                $this->line("  - {$key}");
            }
        }

        if (! empty($staleDocs)) {
            $this->error('Documented but NOT a real registered route (stale docs):');
            foreach (array_keys($staleDocs) as $key) {
                $this->line("  - {$key}");
            }
        }

        $this->line('Regenerate the drifted module\'s fragment with `make:module --with-openapi --force`, then re-run `php artisan openapi:build`.');

        return 1;
    }
}
