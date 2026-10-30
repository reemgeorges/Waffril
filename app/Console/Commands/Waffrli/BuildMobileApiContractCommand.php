<?php

namespace App\Console\Commands\Waffrli;

use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * WAFFRLI (2026-11-04) — OpenAPI 3 contract of the Customer and Driver APIs,
 * generated from the IMPLEMENTED routes (paths, methods, path parameters,
 * auth, throttles, language and idempotency headers, standard envelope
 * responses). `--check` exits with an error when docs/waffrli/openapi/
 * mobile-api.yaml no longer matches the routes (drift check; also enforced
 * by tests/Feature/MobileApiContractTest).
 *
 * Request/response bodies are not inferred: the executable examples are the
 * Bruno collections in api-tests/bruno (WAFFRLI-Customer, WAFFRLI-Driver).
 */
class BuildMobileApiContractCommand extends Command
{
    public const PATH = 'docs/waffrli/openapi/mobile-api.yaml';

    protected $signature = 'waffrli:api-contract {--check : Fail if the committed contract differs from the routes}';

    protected $description = 'Generate (or check) the OpenAPI contract of the Customer and Driver APIs from the routes';

    public function handle(): int
    {
        $yaml = Yaml::dump(self::document(), 12, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        $path = base_path(self::PATH);

        if ($this->option('check')) {
            if (! File::exists($path) || File::get($path) !== $yaml) {
                $this->error('The mobile API contract is out of date. Run: php artisan waffrli:api-contract');

                return self::FAILURE;
            }
            $this->info('The mobile API contract matches the routes.');

            return self::SUCCESS;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $yaml);
        $this->info('Wrote '.self::PATH.' ('.count(self::document()['paths']).' paths).');

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    public static function document(): array
    {
        $paths = [];
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            $uri = $route->uri();
            if (! preg_match('#^api/v1/(customer|driver)/#', $uri)) {
                continue;
            }
            $app = str_starts_with($uri, 'api/v1/customer/') ? 'customer' : 'driver';
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $paths['/'.$uri][strtolower($method)] = self::operation($route, $app, strtolower($method));
            }
        }
        ksort($paths);
        foreach ($paths as &$operations) {
            ksort($operations);
        }

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'WAFFRLI Customer and Driver API',
                'version' => '1.0.0',
                'description' => "Generated from the implemented routes by `php artisan waffrli:api-contract` (do not edit by hand).\n"
                    ."Every response uses the envelope {data, status, error, statusCode}. Machine values (statuses, types) are stable;\n"
                    ."*_label fields and messages follow Accept-Language (ar | en, default APP_LOCALE).\n"
                    .'Executable request/response examples: api-tests/bruno/WAFFRLI-Customer and WAFFRLI-Driver.',
            ],
            'servers' => [['url' => '/', 'description' => 'Same host as the API']],
            'tags' => [
                ['name' => 'customer', 'description' => 'Customer app (Sanctum `user` token from phone OTP)'],
                ['name' => 'driver', 'description' => 'Driver app (Sanctum `driver` token)'],
            ],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']],
                'parameters' => [
                    'AcceptLanguage' => ['name' => 'Accept-Language', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['ar', 'en']], 'description' => 'Response language (labels, messages, errors).'],
                    'IdempotencyKey' => ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid'], 'description' => 'Required for checkout; a replay returns the same order.'],
                ],
                'schemas' => [
                    'Envelope' => [
                        'type' => 'object',
                        'required' => ['data', 'status', 'error', 'statusCode'],
                        'properties' => [
                            'data' => ['nullable' => true, 'description' => 'Payload (null on errors).'],
                            'status' => ['type' => 'boolean'],
                            'error' => ['type' => 'string', 'nullable' => true, 'description' => 'Localized message on errors.'],
                            'statusCode' => ['type' => 'integer'],
                        ],
                    ],
                ],
                'responses' => collect([
                    'Ok' => 'Success.',
                    'BadRequest' => 'Validation error (first message in `error`).',
                    'Unauthenticated' => 'Missing or revoked token.',
                    'Forbidden' => 'Account suspended/inactive or not allowed.',
                    'NotFound' => 'Not found or not yours.',
                    'Conflict' => 'State conflict (e.g. order already accepted, ordering paused).',
                    'Unprocessable' => 'Business rule violated.',
                    'TooManyRequests' => 'Rate limited.',
                ])->map(fn ($text) => ['description' => $text, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Envelope']]]])->all(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected static function operation(Route $route, string $app, string $method): array
    {
        $middleware = $route->gatherMiddleware();
        $protected = collect($middleware)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'auth:'));
        $throttles = collect($middleware)->filter(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'))
            ->map(fn ($m) => Str::after($m, 'throttle:'))->values()->all();
        $action = Str::afterLast((string) $route->getActionName(), '\\');

        $parameters = [['$ref' => '#/components/parameters/AcceptLanguage']];
        foreach ($route->parameterNames() as $name) {
            $parameters[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => in_array($name, ['notification'], true) ? 'string' : 'integer']];
        }
        if (str_ends_with($route->uri(), '/checkout')) {
            $parameters[] = ['$ref' => '#/components/parameters/IdempotencyKey'];
        }

        $responses = ['200' => ['$ref' => '#/components/responses/Ok'], '400' => ['$ref' => '#/components/responses/BadRequest']];
        if ($protected) {
            $responses += ['401' => ['$ref' => '#/components/responses/Unauthenticated'], '403' => ['$ref' => '#/components/responses/Forbidden']];
        }
        if ($route->parameterNames() !== []) {
            $responses['404'] = ['$ref' => '#/components/responses/NotFound'];
        }
        if ($method !== 'get') {
            $responses += ['409' => ['$ref' => '#/components/responses/Conflict'], '422' => ['$ref' => '#/components/responses/Unprocessable']];
        }
        if ($throttles !== []) {
            $responses['429'] = ['$ref' => '#/components/responses/TooManyRequests'];
        }
        ksort($responses);

        $operation = [
            'tags' => [$app],
            'operationId' => $app.'.'.Str::camel(str_replace(['/', '{', '}', '-'], ['_', '', '', '_'], Str::after($route->uri(), "api/v1/{$app}/"))).'.'.$method,
            'summary' => Str::headline(str_replace('@', ' ', $action)),
            'parameters' => $parameters,
            'responses' => $responses,
            'x-throttle' => $throttles,
        ];
        if ($protected) {
            $operation['security'] = [['bearerAuth' => []]];
        }

        return $operation;
    }
}
