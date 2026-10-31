<?php

namespace App\Console\Commands\Waffrli;

use App\Http\Controllers\FatherCrudController;
use Illuminate\Console\Command;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * WAFFRLI (2026-11-05) — the complete OpenAPI 3 document of every implemented
 * /api/v1 route (customer, driver, platform admin), served by Scalar at /docs
 * when WAFFRLI_API_DOCS_ENABLED=true outside production.
 *
 * Built from three sources, so it cannot describe an endpoint that does not exist:
 *  - the registered routes (paths, methods, path parameters, guard, throttles);
 *  - each action's FormRequest rules (request body / query parameter schemas);
 *  - docs/waffrli/openapi/examples.json: REAL request/response pairs captured
 *    from the Bruno collections (api-tests/bruno/generators/capture_examples.py),
 *    secrets redacted.
 *
 * `--check` fails when the committed document differs from what the routes,
 * rules and examples produce (drift; enforced by tests/Feature/OpenApiDocumentTest).
 * The development-only OTP helper (/api/v1/dev/*) and the legacy starter
 * email/password routes (disabled by default) are excluded.
 */
class BuildOpenApiCommand extends Command
{
    public const PATH = 'docs/waffrli/openapi/waffrli-api.yaml';

    public const EXAMPLES = 'docs/waffrli/openapi/examples.json';

    protected $signature = 'waffrli:openapi {--check : Fail if the committed document is out of date}';

    protected $description = 'Generate (or check) the full OpenAPI document of the WAFFRLI API (customer, driver, admin)';

    public function handle(): int
    {
        $yaml = self::yaml();
        $path = base_path(self::PATH);

        if ($this->option('check')) {
            if (! File::exists($path) || File::get($path) !== $yaml) {
                $this->error('docs/waffrli/openapi/waffrli-api.yaml is out of date. Run: php artisan waffrli:openapi');

                return self::FAILURE;
            }
            $this->info('The OpenAPI document matches the routes, rules and examples.');

            return self::SUCCESS;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $yaml);
        $this->info('Wrote '.self::PATH.' ('.count(self::document()['paths']).' paths).');

        return self::SUCCESS;
    }

    public static function yaml(): string
    {
        return Yaml::dump(self::document(), 20, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_OBJECT_AS_MAP);
    }

    /** @return array<string, mixed> */
    public static function document(): array
    {
        $examples = File::exists(base_path(self::EXAMPLES)) ? json_decode(File::get(base_path(self::EXAMPLES)), true) : [];
        $paths = [];
        foreach (ApiInventoryCommand::apiRoutes() as [$method, $uri, $route]) {
            if (ApiInventoryCommand::optional($uri, $route)) {
                continue;
            }
            $paths['/'.$uri][strtolower($method)] = self::operation($route, $method, $examples["{$method} /{$uri}"] ?? []);
        }
        ksort($paths);
        foreach ($paths as &$operations) {
            ksort($operations);
        }

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'WAFFRLI API',
                'version' => '1.0.0',
                'license' => ['name' => 'Proprietary — WAFFRLI'],
                'description' => self::description(),
            ],
            'servers' => [['url' => '/', 'description' => 'Same host as these docs (Docker: http://localhost:8080)']],
            'tags' => [
                ['name' => 'Customer', 'description' => 'Customer app — `/api/v1/customer` (Sanctum `user` token from phone OTP). Bruno: WAFFRLI-Customer.'],
                ['name' => 'Driver', 'description' => 'Driver app — `/api/v1/driver` (Sanctum `driver` token from phone + password). Bruno: WAFFRLI-Driver.'],
                ['name' => 'Admin', 'description' => 'Platform administration — `/api/v1/admin` (Sanctum `admin` token + per-resource permission). Bruno: WAFFRLI-Admin-API.'],
                ['name' => 'Customer (legacy aliases)', 'description' => '`/api/v1/user/cities*`, kept for existing app builds (customer token).'],
            ],
            'paths' => $paths,
            'components' => self::components(),
        ];
    }

    protected static function description(): string
    {
        return implode("\n", [
            'Generated from the implemented routes, their validation rules and real Bruno exchanges by `php artisan waffrli:openapi` — do not edit by hand.',
            '',
            '**Envelope.** Every response is `{data, status, error, statusCode}`; `data` is `null` on errors and `error` holds one localized message.',
            'Validation errors answer **400** (first message in `error`); business-rule refusals answer 409/422; throttling answers 429.',
            '',
            '**Language.** Send `Accept-Language: ar` or `en`. Labels (`*_label`), messages, errors and translatable names follow it;',
            'machine values (`status`, `type`, codes) never change. Admin resources also return `name_translations` ({ar, en}).',
            '',
            '**Auth.** Three separate Sanctum guards: a token of one app is refused by the others. Customer login is phone OTP',
            '(`/customer/auth/otp/request` → `/customer/auth/otp/verify`); one active session per customer. Driver login is phone + password.',
            'The Filament admin panel (`/admin`) uses its own session guard and is not part of this API.',
            '',
            '**Pagination.** Customer/driver lists accept `page` and `per_page` and return `pagination {current_page, per_page, total, last_page}`.',
            'Admin `…/all/paginated` lists return `current_page, next_page, previous_page, total_pages` next to the items.',
            '',
            '**Money.** Amounts are decimal strings in the stored currency; display conversion never changes stored amounts.',
            '',
            '**Realtime.** One Reverb server. Private channels (`private-user.{id}`, `private-driver.{id}`, `private-order.{id}`) are',
            'authorized by `POST /api/v1/customer/broadcasting/auth` and `POST /api/v1/driver/broadcasting/auth`.',
            '',
            'Examples are real responses from the demo database (fictional data); tokens, passwords and OTP codes are redacted.',
        ]);
    }

    /** @return array<string, mixed> */
    protected static function components(): array
    {
        $envelope = fn (array $data = ['description' => 'Payload (null on errors).']) => [
            'type' => 'object',
            'required' => ['data', 'status', 'error', 'statusCode'],
            'properties' => [
                'data' => $data,
                'status' => ['type' => 'boolean'],
                'error' => ['type' => 'string', 'nullable' => true],
                'statusCode' => ['type' => 'integer'],
                'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']], 'description' => 'All validation messages per field (422 validation responses only).'],
            ],
        ];
        $error = fn (string $text, int $code, string $message) => [
            'description' => $text,
            'content' => ['application/json' => [
                'schema' => ['$ref' => '#/components/schemas/ErrorEnvelope'],
                'example' => ['data' => null, 'status' => false, 'error' => $message, 'statusCode' => $code],
            ]],
        ];

        return [
            'securitySchemes' => [
                'customerToken' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Customer token from POST /api/v1/customer/auth/otp/verify (or register / recovery).'],
                'driverToken' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Driver token from POST /api/v1/driver/login.'],
                'adminToken' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Admin token from POST /api/v1/admin/login.'],
            ],
            'parameters' => [
                'AcceptLanguage' => ['name' => 'Accept-Language', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['ar', 'en']], 'description' => 'Response language for labels, messages and errors.'],
                'IdempotencyKey' => ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid'], 'description' => 'Required for checkout: a replay with the same key returns the same order.'],
            ],
            'schemas' => [
                'Envelope' => $envelope(),
                'ErrorEnvelope' => $envelope(['type' => 'object', 'nullable' => true, 'description' => 'Always null on errors.']),
                'BroadcastAuth' => ['type' => 'object', 'required' => ['auth'], 'properties' => [
                    'auth' => ['type' => 'string', 'description' => '`<app key>:<HMAC-SHA256 signature>` for the Pusher protocol (Reverb). Not wrapped in the envelope.'],
                    'channel_data' => ['type' => 'string', 'description' => 'Presence channels only.'],
                ]],
                'Ping' => ['type' => 'object', 'properties' => ['status' => ['type' => 'string', 'enum' => ['ok']]]],
            ],
            'responses' => [
                'BadRequest' => $error('Validation error (first message in `error`).', 400, 'The phone field is required.'),
                'Unauthenticated' => $error('Missing, invalid or revoked token.', 401, 'Please sign in to continue.'),
                'Forbidden' => $error('Account suspended/inactive, missing permission, or channel not yours.', 403, 'This action is unauthorized.'),
                'NotFound' => $error('Not found, or not yours.', 404, 'Not found.'),
                'Conflict' => $error('State conflict (e.g. order already accepted).', 409, 'This order can no longer be cancelled.'),
                'Unprocessable' => $error('Business rule violated.', 422, 'The code is invalid or expired.'),
                'TooManyRequests' => $error('Rate limited or OTP cooldown.', 429, 'Too many attempts. Please try again later.'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected static function operation(Route $route, string $method, array $example): array
    {
        $uri = $route->uri();
        $middleware = $route->gatherMiddleware();
        $guard = collect($middleware)->first(fn ($m) => is_string($m) && str_starts_with($m, 'auth:'));
        $guard = $guard ? Str::after($guard, 'auth:') : null;
        $throttles = collect($middleware)->filter(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'))->map(fn ($m) => Str::after($m, 'throttle:'))->values()->all();
        $permission = collect($middleware)->first(fn ($m) => is_string($m) && str_starts_with($m, 'can:'));
        [$tag, $prefix] = match (true) {
            str_starts_with($uri, 'api/v1/customer/') => ['Customer', 'api/v1/customer/'],
            str_starts_with($uri, 'api/v1/driver/') => ['Driver', 'api/v1/driver/'],
            str_starts_with($uri, 'api/v1/user/') => ['Customer (legacy aliases)', 'api/v1/user/'],
            default => ['Admin', 'api/v1/admin/'],
        };
        $action = (string) $route->getActionName();
        $rules = self::rules($route);
        $multipart = collect($rules)->flatten()->contains(fn ($r) => is_string($r) && preg_match('/^(file|image|mimes:|mimetypes:)/', $r));

        $parameters = [['$ref' => '#/components/parameters/AcceptLanguage']];
        foreach ($route->parameterNames() as $name) {
            $parameters[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => $name === 'notification' ? 'string' : 'integer']];
        }
        if (str_ends_with($uri, '/checkout')) {
            $parameters[] = ['$ref' => '#/components/parameters/IdempotencyKey'];
        }
        $query = $method === 'GET' ? self::schema($rules) : null;
        foreach ($query['properties'] ?? [] as $name => $schema) {
            $parameters[] = array_filter(['name' => $name, 'in' => 'query', 'required' => in_array($name, $query['required'] ?? [], true), 'schema' => $schema, 'example' => $example['query'][$name] ?? null], fn ($v) => $v !== null);
        }
        foreach (($example['query'] ?? []) ?: [] as $name => $value) {
            if (! isset($query['properties'][$name])) {
                $parameters[] = ['name' => $name, 'in' => 'query', 'required' => false, 'schema' => ['type' => is_numeric($value) ? 'integer' : 'string'], 'example' => $value];
            }
        }

        $operation = [
            'tags' => [$tag],
            'operationId' => Str::camel(strtolower($tag === 'Customer (legacy aliases)' ? 'user' : $tag)).'.'.Str::camel(str_replace(['/', '{', '}', '-', '.'], ['_', '', '', '_', '_'], Str::after($uri, $prefix))).'.'.strtolower($method),
            'summary' => self::summary($route, $example),
            'description' => self::operationDescription($action, $guard, $permission, $throttles),
            'parameters' => $parameters,
        ];
        if ($guard !== null) {
            $operation['security'] = [[['user' => 'customerToken', 'driver' => 'driverToken', 'admin' => 'adminToken'][$guard] ?? 'customerToken' => []]];
        } else {
            $operation['security'] = [];
        }

        if ($method !== 'GET' && $method !== 'DELETE' || ($method === 'DELETE' && $rules !== [])) {
            $schema = self::schema($rules) ?? (! empty($example['request']) ? self::exampleSchema($example['request']) + ['description' => 'Inferred from a real request (no FormRequest on this action).'] : ['type' => 'object']);
            $content = ['schema' => $schema];
            if (! empty($example['request'])) {
                $content['example'] = $example['request'];
            }
            if ($rules !== [] || ! empty($example['request'])) {
                $operation['requestBody'] = ['required' => ! empty($schema['required']), 'content' => [$multipart ? 'multipart/form-data' : 'application/json' => $content]];
            }
        }

        $operation['responses'] = self::responses($route, $method, $guard !== null, $throttles, $example);
        if ($throttles !== []) {
            $operation['x-throttle'] = $throttles;
        }

        return $operation;
    }

    protected static function summary(Route $route, array $example): string
    {
        $success = collect($example['responses'] ?? [])->first(fn ($r, $status) => str_starts_with((string) $status, '2'));
        if (is_array($success) && ! empty($success['summary'])) {
            $summary = (string) $success['summary'];
            if (str_starts_with($route->uri(), 'api/v1/admin/') && preg_match('#^api/v1/admin/([a-z-]+)/#', $route->uri(), $m) && ! in_array($m[1], ['password', 'login-challenge'], true)) {
                $summary = Str::headline($m[1]).': '.$summary;
            }

            return $summary;
        }

        return Str::headline(Str::afterLast(str_replace('@', ' ', (string) $route->getActionName()), '\\'));
    }

    protected static function operationDescription(string $action, ?string $guard, ?string $permission, array $throttles): string
    {
        $lines = [];
        $lines[] = $guard === null ? 'Public (no token).' : "Requires a `{$guard}` token.";
        if ($permission) {
            $lines[] = 'Admin permission: `'.Str::after($permission, 'can:').'` policy ability.';
        }
        if ($throttles !== []) {
            $lines[] = 'Throttle: `'.implode('`, `', $throttles).'`.';
        }
        $lines[] = 'Handler: `'.Str::after($action, 'App\\Http\\Controllers\\').'`.';

        return implode("\n", $lines);
    }

    /** @return array<string, mixed> */
    protected static function responses(Route $route, string $method, bool $protected, array $throttles, array $example): array
    {
        $examples = $example['responses'] ?? [];
        $success = collect($examples)->filter(fn ($r, $status) => str_starts_with((string) $status, '2'));
        $okExample = $success->first();
        $okSchema = match (true) {
            str_ends_with($route->uri(), '/broadcasting/auth') => 'BroadcastAuth',
            $route->uri() === 'api/v1/admin/ping' => 'Ping',
            default => 'Envelope',
        };
        $responses = ['200' => ['description' => $okSchema === 'Envelope' ? 'Success.' : 'Success (raw body, not the envelope).', 'content' => ['application/json' => array_filter([
            'schema' => ['$ref' => '#/components/schemas/'.$okSchema],
            'example' => $okExample['value'] ?? null,
        ])]]];
        $standard = ['400' => 'BadRequest'];
        if ($protected) {
            $standard += ['401' => 'Unauthenticated', '403' => 'Forbidden'];
        }
        if ($route->parameterNames() !== [] || in_array($method, ['POST', 'PATCH', 'DELETE'], true) && str_contains($route->uri(), 'admin/')) {
            $standard['404'] = 'NotFound';
        }
        if ($method !== 'GET') {
            $standard += ['409' => 'Conflict', '422' => 'Unprocessable'];
        }
        if ($throttles !== []) {
            $standard['429'] = 'TooManyRequests';
        }
        foreach ($standard as $status => $ref) {
            $responses[$status] = ['$ref' => '#/components/responses/'.$ref];
        }
        foreach ($examples as $status => $captured) {
            $status = (string) $status;
            if (str_starts_with($status, '2') || ! is_array($captured['value'] ?? null)) {
                continue;
            }
            $responses[$status] = [
                'description' => ($captured['summary'] ?? 'Error').' (real response)',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorEnvelope'], 'example' => $captured['value']]],
            ];
        }
        ksort($responses);

        return $responses;
    }

    /** @return array<string, mixed> The action's FormRequest rules ([] when it has none). */
    public static function rules(Route $route): array
    {
        $class = self::formRequestClass($route);
        if ($class === null) {
            return [];
        }
        try {
            $request = $class::create('/'.$route->uri(), 'POST');
            $request->setContainer(app())->setRedirector(app('redirect'));
            $request->setRouteResolver(fn () => $route);

            return method_exists($request, 'rules') ? (array) app()->call([$request, 'rules']) : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The FormRequest validating an action: a type-hinted parameter, the
     * starter CRUD controllers' createRequest/updateRequest, or an inline
     * app(SomeRequest::class) call in the action body.
     *
     * @return class-string<FormRequest>|null
     */
    public static function formRequestClass(Route $route): ?string
    {
        $action = $route->getAction('uses');
        if (! is_string($action)) {
            return null;
        }
        [$class, $method] = str_contains($action, '@') ? explode('@', $action) : [$action, '__invoke'];
        try {
            $reflection = new ReflectionMethod($class, $method);
        } catch (Throwable) {
            return null;
        }
        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && is_subclass_of($type->getName(), FormRequest::class)) {
                return $type->getName();
            }
        }
        if (is_subclass_of($class, FatherCrudController::class) && in_array($method, ['store', 'update'], true)) {
            try {
                $controller = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
                (fn () => $this->setVariables())->call($controller);
                $request = (fn () => $method === 'store' ? $this->createRequest : $this->updateRequest)->call($controller);
                if (is_string($request) && is_subclass_of($request, FormRequest::class)) {
                    return $request;
                }
            } catch (Throwable) {
                // fall through to the source scan
            }
        }
        $file = $reflection->getFileName();
        if ($file && is_file($file)) {
            $lines = array_slice(file($file), $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1);
            if (preg_match('/app\(\s*\\?([A-Za-z0-9_\\\\]+Request)::class/', implode('', $lines), $m)) {
                $candidate = $m[1];
                if (! str_contains($candidate, '\\') && preg_match('/^use\s+([A-Za-z0-9_\\\\]+\\\\'.preg_quote($candidate, '/').');/m', (string) file_get_contents($file), $use)) {
                    $candidate = $use[1];
                } elseif (! str_contains($candidate, '\\')) {
                    $candidate = $reflection->getDeclaringClass()->getNamespaceName().'\\'.$candidate;
                }
                if (class_exists($candidate) && is_subclass_of($candidate, FormRequest::class)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /** @return array<string, mixed> Schema inferred from a real example body (actions without a FormRequest). */
    public static function exampleSchema(mixed $value): array
    {
        return match (true) {
            is_array($value) && array_is_list($value) && $value !== [] => ['type' => 'array', 'items' => self::exampleSchema($value[0])],
            is_array($value) => ['type' => 'object', 'properties' => array_map(fn ($v) => self::exampleSchema($v), $value)],
            is_bool($value) => ['type' => 'boolean'],
            is_int($value) => ['type' => 'integer'],
            is_float($value) => ['type' => 'number'],
            default => ['type' => 'string'],
        };
    }

    /** @return array<string, mixed>|null JSON schema of a Laravel rule set (nested fields, arrays, enums). */
    public static function schema(array $rules): ?array
    {
        if ($rules === []) {
            return null;
        }
        $root = ['type' => 'object', 'properties' => []];
        ksort($rules);
        foreach ($rules as $field => $fieldRules) {
            $list = is_string($fieldRules) ? explode('|', $fieldRules) : (array) $fieldRules;
            $segments = explode('.', (string) $field);
            $node = &$root;
            foreach ($segments as $i => $segment) {
                $last = $i === count($segments) - 1;
                if ($segment === '*') {
                    $node['type'] = 'array';
                    $node['items'] ??= ['type' => 'object'];
                    $node = &$node['items'];
                } else {
                    $node['type'] = $node['type'] ?? 'object';
                    if ($node['type'] !== 'object') {
                        $node['type'] = 'object';
                    }
                    $node['properties'][$segment] ??= [];
                    if ($last && self::isRequired($list)) {
                        $node['required'][] = $segment;
                        $node['required'] = array_values(array_unique($node['required']));
                    }
                    $node = &$node['properties'][$segment];
                }
                if ($last) {
                    $node = array_merge($node, self::fieldSchema($list, $node));
                }
            }
            unset($node);
        }

        return $root;
    }

    protected static function isRequired(array $rules): bool
    {
        $rules = collect($rules)->filter(fn ($r) => is_string($r));

        return ! $rules->contains('sometimes') && $rules->contains(fn ($r) => in_array($r, ['required', 'present', 'accepted'], true));
    }

    /** @return array<string, mixed> */
    protected static function fieldSchema(array $rules, array $existing): array
    {
        $schema = [];
        $notes = [];
        foreach ($rules as $rule) {
            if (! is_string($rule)) {
                if (is_object($rule) && method_exists($rule, '__toString')) {
                    $rule = (string) $rule;
                } else {
                    $notes[] = 'custom rule: '.(is_object($rule) ? class_basename($rule) : 'closure');

                    continue;
                }
            }
            [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
            match ($name) {
                'string', 'alpha', 'alpha_num', 'alpha_dash', 'timezone' => $schema['type'] = 'string',
                'integer', 'digits_between' => $schema['type'] = 'integer',
                'numeric', 'decimal' => $schema['type'] = 'number',
                'boolean', 'accepted' => $schema['type'] = 'boolean',
                'array' => $schema['x-array'] = (string) $arg,
                'email' => $schema += ['type' => 'string', 'format' => 'email'],
                'uuid' => $schema += ['type' => 'string', 'format' => 'uuid'],
                'url' => $schema += ['type' => 'string', 'format' => 'uri'],
                'date', 'date_format' => $schema += ['type' => 'string', 'format' => 'date-time'],
                'file', 'image', 'mimes', 'mimetypes' => $schema += ['type' => 'string', 'format' => 'binary'],
                'nullable' => $schema['nullable'] = true,
                'in' => $schema['enum'] = array_map(fn ($v) => trim($v, '"'), str_getcsv((string) $arg)),
                'digits' => $schema += ['type' => 'string', 'pattern' => '^\d{'.$arg.'}$'],
                'regex' => $schema['pattern'] = trim((string) preg_replace('/^\/(.*)\/[a-z]*$/s', '$1', (string) $arg)),
                'min' => $schema['x-min'] = is_numeric($arg) ? $arg + 0 : $arg,
                'max' => $schema['x-max'] = is_numeric($arg) ? $arg + 0 : $arg,
                'between' => $schema['x-between'] = $arg,
                'exists' => $notes[] = 'must exist ('.$arg.')',
                'unique' => $notes[] = 'must be unique ('.$arg.')',
                'prohibited', 'prohibited_if', 'prohibited_unless' => $notes[] = 'prohibited'.($arg ? " ({$arg})" : ''),
                'required_if', 'required_with', 'required_without', 'required_without_all', 'required_unless', 'required_with_all' => $notes[] = "{$name}: {$arg}",
                'confirmed' => $notes[] = 'requires a matching *_confirmation field',
                'gt', 'gte', 'lt', 'lte', 'different', 'same', 'after', 'before', 'after_or_equal', 'before_or_equal' => $notes[] = "{$name}: {$arg}",
                default => null,
            };
        }
        if (array_key_exists('x-array', $schema)) {
            if (isset($existing['items'])) {
                $schema['type'] = 'array';
            } elseif (isset($existing['properties']) || $schema['x-array'] !== '') {
                $schema['type'] = 'object';
            } else {
                $notes[] = 'JSON array or object';
                $schema['x-any'] = true;
            }
            unset($schema['x-array']);
        }
        $type = $schema['type'] ?? null;
        foreach (['x-min' => ['string' => 'minLength', 'array' => 'minItems', 'default' => 'minimum'], 'x-max' => ['string' => 'maxLength', 'array' => 'maxItems', 'default' => 'maximum']] as $key => $map) {
            if (isset($schema[$key])) {
                if (is_numeric($schema[$key])) {
                    $schema[$map[$type] ?? $map['default']] = $schema[$key];
                }
                unset($schema[$key]);
            }
        }
        if (isset($schema['x-between'])) {
            [$min, $max] = array_pad(explode(',', (string) $schema['x-between']), 2, null);
            if (is_numeric($min) && is_numeric($max)) {
                $schema += $type === 'string' ? ['minLength' => (int) $min, 'maxLength' => (int) $max] : ['minimum' => $min + 0, 'maximum' => $max + 0];
            }
            unset($schema['x-between']);
        }
        if (isset($schema['x-any'])) {
            unset($schema['x-any'], $schema['nullable']);
        } elseif (! isset($schema['type']) && ! isset($existing['type'])) {
            $schema['type'] = 'string';
        }
        if (($schema['type'] ?? null) === 'number') {
            $nullable = ['nullable' => true] + [];
            $number = array_intersect_key($schema, array_flip(['type', 'minimum', 'maximum', 'nullable']));
            $string = ['type' => 'string', 'format' => 'decimal', 'pattern' => '^-?\\d+(\\.\\d+)?$'] + (isset($schema['nullable']) ? $nullable : []);
            $schema = array_diff_key($schema, $number) + ['oneOf' => [$number, $string]];
            $notes[] = 'number, or a decimal string (money amounts are sent as strings)';
        }
        if ($notes !== []) {
            $schema['description'] = implode('; ', array_unique($notes));
        }

        return $schema;
    }
}
