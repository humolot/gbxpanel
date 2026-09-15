<?php

namespace App\Services\Ai\Tools;

use App\Models\Task;
use App\Services\ShellResult;
use Illuminate\Support\Str;

/**
 * A group of related AI functions.
 *
 * tools() returns: name => [
 *   'description' => string,
 *   'parameters'  => JSON schema (object),
 *   'write'       => bool   (changes the server, needs approval),
 *   'admin'       => bool   (administrators only),
 *   'label'       => fn (array $args): string   (short text for the chat UI),
 * ]
 */
abstract class ToolGroup
{
    abstract public function tools(): array;

    abstract public function handle(string $name, array $args): mixed;

    /* ------------------------------------------------------------ schema */

    protected static function params(array $properties = [], array $required = []): array
    {
        return ['type' => 'object', 'properties' => (object) $properties, 'required' => $required];
    }

    protected static function str(string $description, ?array $enum = null): array
    {
        return ['type' => 'string', 'description' => $description] + ($enum ? ['enum' => $enum] : []);
    }

    protected static function int(string $description): array
    {
        return ['type' => 'integer', 'description' => $description];
    }

    protected static function bool(string $description): array
    {
        return ['type' => 'boolean', 'description' => $description];
    }

    protected static function list(string $description): array
    {
        return ['type' => 'array', 'items' => ['type' => 'string'], 'description' => $description];
    }

    protected static function map(string $description): array
    {
        return ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'description' => $description];
    }

    protected static function tool(string $description, array $parameters, bool $write, bool $admin, callable $label): array
    {
        return compact('description', 'parameters', 'write', 'admin') + ['label' => $label];
    }

    /* ----------------------------------------------------------- results */

    protected function shell(ShellResult $result, string $success): array
    {
        return $result->ok()
            ? ['ok' => true, 'message' => $success, 'output' => Str::limit(trim($result->output), 8000)]
            : ['ok' => false, 'exit_code' => $result->exitCode, 'error' => Str::limit($result->message() ?: 'Command failed', 8000)];
    }

    protected function queued(Task $task, string $what): array
    {
        return [
            'ok' => true,
            'queued' => true,
            'task_id' => $task->id,
            'message' => "{$what} is running in background task #{$task->id}. Call get_task_status with task_id={$task->id} (wait_seconds up to 120) to follow it before reporting the result.",
        ];
    }

    /**
     * Reuse a panel controller (validation, rollback, activity log) by
     * dispatching an internal request as the current user.
     */
    protected function panelRequest(string $method, string $uri, array $data = []): array
    {
        $request = \Illuminate\Http\Request::create($uri, $method === 'GET' ? 'GET' : 'POST', $method !== 'GET' && $method !== 'POST' ? $data + ['_method' => $method] : $data);
        $request->headers->set('Accept', 'application/json');
        $request->setUserResolver(fn () => auth()->user());

        $router = app('router');
        $route = $router->getRoutes()->match($request);
        $request->setRouteResolver(fn () => $route);
        $router->substituteBindings($route);

        try {
            $response = app()->call([app($route->getControllerClass()), $route->getActionMethod()], $route->parameters() + ['request' => $request]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ['ok' => false, 'errors' => $e->errors()];
        }

        return $response instanceof \Illuminate\Http\JsonResponse ? $response->getData(true) : ['ok' => true];
    }

    protected static function a(array $args, string $key, mixed $default = null): mixed
    {
        $value = $args[$key] ?? $default;

        return is_string($value) ? trim($value) : $value;
    }

    /** Normalize arrays that models sometimes send as newline/comma separated strings. */
    protected static function strings(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\r?\n/', $value);
        }

        return array_values(array_filter(array_map(fn ($v) => trim((string) $v), (array) $value), fn ($v) => $v !== ''));
    }

    protected static function labelArg(array $args, string $key): string
    {
        $value = $args[$key] ?? '';

        return is_scalar($value) ? (string) $value : json_encode($value);
    }
}
