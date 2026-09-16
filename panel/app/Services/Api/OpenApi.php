<?php

namespace App\Services\Api;

/**
 * OpenAPI 3.1 document built from the endpoint catalog, so the documentation always matches
 * what the panel really answers. Any client generator (Postman, Insomnia, openapi-generator)
 * can read it.
 */
class OpenApi
{
    public static function document(string $baseUrl): array
    {
        $paths = [];
        foreach (ApiCatalog::endpoints() as $endpoint) {
            $path = preg_replace('/\{(\w+)\}/', '{$1}', $endpoint['path']);
            $paths[$path][strtolower($endpoint['method'])] = self::operation($endpoint);
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => \App\Models\Setting::get('panel_title', 'GBX Panel').' API',
                'version' => (string) config('gbx.version'),
                'description' => "Management API of the panel.\n\n".
                    "Send your key as `Authorization: Bearer <token>`. Every answer carries `ok`; a failure adds `error.code` and `error.message`.\n".
                    "Actions that take longer answer with a task (`data.task.id`): follow it on `GET /tasks/{id}`.\n".
                    'Repeat-safe writes: send an `Idempotency-Key` header and a repeated call returns the first answer instead of acting twice.',
            ],
            'servers' => [['url' => rtrim($baseUrl, '/')]],
            'components' => [
                'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'API key created in the panel (API > Keys)']],
                'schemas' => [
                    'Error' => [
                        'type' => 'object',
                        'properties' => [
                            'ok' => ['type' => 'boolean', 'const' => false],
                            'error' => ['type' => 'object', 'properties' => [
                                'code' => ['type' => 'string', 'examples' => ['validation_failed', 'missing_scope', 'not_found', 'rate_limited']],
                                'message' => ['type' => 'string'],
                                'fields' => ['type' => 'object', 'additionalProperties' => true],
                            ]],
                            'request_id' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'security' => [['bearer' => []]],
            'tags' => array_values(array_map(fn ($tag) => ['name' => $tag], array_keys(ApiCatalog::byTag()))),
            'paths' => $paths,
        ];
    }

    protected static function operation(array $endpoint): array
    {
        $parameters = [];
        preg_match_all('/\{(\w+)\}/', $endpoint['path'], $matches);
        foreach ($matches[1] as $name) {
            $parameters[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'Id of the '.$name];
        }
        foreach ($endpoint['query'] as $name => $description) {
            $parameters[] = ['name' => $name, 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'], 'description' => $description];
        }

        $operation = [
            'tags' => [$endpoint['tag']],
            'summary' => $endpoint['summary'],
            'operationId' => strtolower($endpoint['method']).'_'.trim(preg_replace('/[^a-z0-9]+/i', '_', $endpoint['path']), '_'),
            'parameters' => $parameters,
            'responses' => [
                '200' => ['description' => $endpoint['task'] ? 'Task started' : 'Success', 'content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'properties' => ['ok' => ['type' => 'boolean', 'const' => true], 'message' => ['type' => 'string'], 'data' => ['type' => ['object', 'array', 'null']], 'meta' => ['type' => 'object']],
                ]]]],
                '401' => ['description' => 'No or unknown key', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
                '403' => ['description' => 'The key lacks the permission, expired or the address is not allowed', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
                '422' => ['description' => 'Invalid input or the action failed', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
                '429' => ['description' => 'Rate limit of the key reached', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
            ],
        ];

        if ($endpoint['scope']) {
            $operation['description'] = 'Permission: `'.$endpoint['scope'].'`';
            $operation['security'] = [['bearer' => [$endpoint['scope']]]];
        }
        if ($endpoint['body']) {
            $properties = [];
            foreach ($endpoint['body'] as $name => $description) {
                $properties[$name] = ['description' => $description, 'type' => self::guessType($name, $description)];
            }
            $operation['requestBody'] = [
                'required' => (bool) array_filter($endpoint['body'], fn ($d) => str_contains($d, '[required]')),
                'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => $properties]]],
            ];
        }

        return $operation;
    }

    protected static function guessType(string $name, string $description): string
    {
        return match (true) {
            str_contains($description, 'List of') || in_array($name, ['ids', 'names', 'events', 'hosts', 'paths'], true) => 'array',
            (bool) preg_match('/^(id|.*_id|ttl|priority|keep|lines|offset|page|per_page|max_.*|disk_mb|bandwidth_mb|port)$/', $name) => 'integer',
            (bool) preg_match('/^(add_|create_|delete_|include_|allow_|is_|force|wildcard|restore|recycle|attach|proxied|www|ipv6|databases)/', $name) => 'boolean',
            default => 'string',
        };
    }
}
