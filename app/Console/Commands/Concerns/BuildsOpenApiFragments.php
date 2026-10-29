<?php

namespace App\Console\Commands\Concerns;

use Symfony\Component\Yaml\Yaml;

/**
 * Phase 11E (2026-10-26) — extracted from MakeModuleCommand (Phase 8)
 * so MakeAuthModuleCommand's own new OpenAPI fragment generation (this
 * phase) shares the exact same envelope-building and YAML-dumping logic
 * rather than a second, hand-maintained copy that could silently drift
 * from the first — exactly the kind of duplication risk this same
 * session's own Phase 11C work ran into with a copy-pasted brace bug
 * across several option descriptions.
 */
trait BuildsOpenApiFragments
{
    /**
     * $queryParameters: list of ['name' => ..., 'type' => ..., required?,
     * enum?, description?] — only ever for query parameters that
     * GENUINELY work on the generated endpoint (Phase 11E: deliberately
     * does NOT document ?search=/?sort=/filter query params, since
     * make:module never actually configures $search/$dynamicFilterColumns/
     * $sortableColumns for a generated model — those stay empty,
     * secure-by-default, until a developer opts in by hand; documenting
     * them as available would be inaccurate, the same "never document a
     * disabled thing as active" principle this whole file already
     * follows for routes).
     */
    protected function openApiOperation(string $summary, bool $guarded, ?array $requestBodyProperties = null, ?array $requestBodyRequired = null, ?array $queryParameters = null): array
    {
        $operation = ['summary' => $summary, 'responses' => ['200' => ['description' => 'OK']]];

        if ($guarded) {
            $operation['security'] = [['bearerAuth' => []]];
        }

        if (! empty($queryParameters)) {
            $operation['parameters'] = array_map(function ($p) {
                $schema = array_filter(['type' => $p['type'] ?? 'string', 'enum' => $p['enum'] ?? null]);

                return array_filter([
                    'name' => $p['name'],
                    'in' => 'query',
                    'required' => $p['required'] ?? false,
                    'description' => $p['description'] ?? null,
                    'schema' => $schema,
                ]);
            }, $queryParameters);
        }

        if ($requestBodyProperties !== null) {
            $operation['requestBody'] = [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => array_filter([
                            'type' => 'object',
                            'properties' => $requestBodyProperties,
                            'required' => $requestBodyRequired ?: null,
                        ]),
                    ],
                ],
            ];
        }

        return $operation;
    }

    /**
     * Symfony's Yaml::dump() is otherwise exactly right for this, but
     * PHP arrays auto-cast a numeric string key like '200' to the
     * integer 200 — so the `responses` map's HTTP status codes come out
     * as bare YAML integers (`200:`) instead of the quoted strings
     * (`'200':`) the OpenAPI spec actually requires for that map's keys.
     * Rather than fighting PHP's array key coercion upstream, this
     * quotes any bare 3-digit-code key the dumper produced, after the
     * fact — found by actually reading the generated YAML, not by
     * assuming Yaml::dump() was spec-correct by construction.
     */
    protected function dumpOpenApiYaml(array $document): string
    {
        $yaml = Yaml::dump($document, 6, 2);

        return (string) preg_replace('/^(\s*)(\d{3}):$/m', "$1'$2':", $yaml);
    }
}
