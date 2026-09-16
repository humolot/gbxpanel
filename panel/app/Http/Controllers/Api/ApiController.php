<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ApiAuthenticate;
use App\Models\ApiKey;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Base of the API controllers.
 *
 * Actions that change the server are forwarded to the controllers of the panel, so the API and
 * the interface always do exactly the same work; only the answer is reshaped.
 */
abstract class ApiController extends Controller
{
    public function key(): ?ApiKey
    {
        return request()->attributes->get(ApiAuthenticate::CURRENT);
    }

    /* ============================================================== answers */

    protected function data(mixed $data, array $meta = [], int $status = 200): JsonResponse
    {
        $body = ['ok' => true, 'data' => $data instanceof Arrayable ? $data->toArray() : $data];
        if ($meta) {
            $body['meta'] = $meta;
        }

        return response()->json($body, $status);
    }

    protected function message(string $message, array $data = [], int $status = 200): JsonResponse
    {
        return response()->json(['ok' => true, 'message' => $message] + ($data ? ['data' => $data] : []), $status);
    }

    protected function error(string $code, string $message, int $status = 422, array $extra = []): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => ['code' => $code, 'message' => $message] + $extra, 'request_id' => request()->attributes->get('request_id')], $status);
    }

    /** Paginated list with the same meta everywhere. */
    protected function page(Builder $query, Request $request, callable $map, array $meta = []): JsonResponse
    {
        $perPage = min(200, max(1, (int) $request->query('per_page', 50)));
        $paginator = $query->paginate($perPage, ['*'], 'page', max(1, (int) $request->query('page', 1)));

        return $this->data(
            array_map($map, $paginator->items()),
            $meta + ['page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'pages' => $paginator->lastPage()],
        );
    }

    /* ============================================================ forwarding */

    /**
     * Run an action of the panel with the given input and turn its answer into an API answer.
     *
     * @param  array<string, mixed>  $input  body and query of the internal request
     * @param  array<string, mixed>  $parameters  route parameters (models) of the action
     */
    protected function forward(string $controller, string $method, array $input = [], array $parameters = []): JsonResponse
    {
        $original = request();
        $input = $this->clean($input);
        // the panel actions read from query() as well as input(), so the internal request carries both
        $internal = Request::create('/'.ltrim($original->path(), '/').'?'.http_build_query($input), 'POST', $input, [], [], ['HTTP_ACCEPT' => 'application/json']);
        $internal->setUserResolver(fn () => $original->user());
        $internal->attributes->set('request_id', $original->attributes->get('request_id'));

        app()->instance('request', $internal);
        try {
            $result = app()->call([app($controller), $method], $parameters + ['request' => $internal]);
        } finally {
            app()->instance('request', $original);
        }

        return $this->translate($result);
    }

    /** Drop the values the caller did not send, so the panel defaults apply. */
    protected function clean(array $input): array
    {
        return array_filter($input, fn ($value) => $value !== null);
    }

    /** Booleans are sent as true/false in JSON; the panel expects the usual 1/0 input. */
    protected function flag(Request $request, string $field, ?bool $default = null): ?int
    {
        if (! $request->has($field)) {
            return $default === null ? null : (int) $default;
        }

        return $request->boolean($field) ? 1 : 0;
    }

    /** Turn a panel answer ({ok, message, ...}) into the API envelope. */
    protected function translate(mixed $result): JsonResponse
    {
        if (! $result instanceof JsonResponse) {
            return $this->data($result);
        }
        $body = (array) $result->getData(true);
        $status = $result->getStatusCode();
        $ok = $body['ok'] ?? ($status < 400);
        $message = $body['message'] ?? null;
        unset($body['ok'], $body['message']);

        if (! $ok) {
            return $this->error($status === 404 ? 'not_found' : 'failed', (string) ($message ?: 'The action failed.'), $status, $body ? ['details' => $body] : []);
        }

        $response = ['ok' => true];
        if ($message !== null && $message !== 'ok') {
            $response['message'] = $message;
        }
        if ($body) {
            $response['data'] = count($body) === 1 && isset($body['data']) ? $body['data'] : $body;
        }

        return response()->json($response, $status);
    }
}
