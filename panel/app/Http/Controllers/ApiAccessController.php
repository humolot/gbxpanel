<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use App\Models\ApiRequest;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Api\ApiCatalog;
use App\Services\Api\WebhookManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * API page of the panel: keys with their permissions, webhooks, call log and documentation.
 */
class ApiAccessController extends Controller
{
    public function index(Request $request)
    {
        $tab = in_array($request->query('tab'), ['keys', 'webhooks', 'logs', 'docs'], true) ? $request->query('tab') : 'keys';

        return view('api.index', [
            'tab' => $tab,
            'scopes' => ApiCatalog::scopesByGroup(),
            'events' => ApiCatalog::EVENTS,
            'endpoints' => ApiCatalog::byTag(),
            'version' => ApiCatalog::VERSION,
            'baseUrl' => url('/api/'.ApiCatalog::VERSION),
            'clients' => \App\Models\Client::query()->orderBy('username')->get(['id', 'username']),
            'keyCount' => ApiKey::query()->count(),
            'callsToday' => ApiRequest::query()->where('created_at', '>=', now()->startOfDay())->count(),
            'errorsToday' => ApiRequest::query()->where('created_at', '>=', now()->startOfDay())->where('status', '>=', 400)->count(),
        ]);
    }

    /* ================================================================= keys */

    protected function keyRow(ApiKey $key): array
    {
        return [
            'id' => $key->id,
            'name' => $key->name,
            'prefix' => $key->prefix,
            'scopes' => $key->scopes,
            'allowed_ips' => $key->allowed_ips,
            'client_id' => $key->client_id,
            'client' => $key->client?->username,
            'user' => $key->user?->name,
            'rate_limit' => $key->rate_limit,
            'expires_at' => $key->expires_at?->toDateString(),
            'expired' => $key->isExpired(),
            'last_used_at' => $key->last_used_at?->toDateTimeString(),
            'last_ip' => $key->last_ip,
            'requests' => $key->requests,
            'is_active' => $key->is_active,
        ];
    }

    public function keys()
    {
        return $this->ok('ok', ['data' => ApiKey::query()->with(['user:id,name', 'client:id,username'])->orderBy('name')->get()->map(fn (ApiKey $k) => $this->keyRow($k))]);
    }

    protected function keyData(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(array_merge(['*'], array_keys(ApiCatalog::SCOPES), array_keys(ApiCatalog::scopesByGroup())))],
            'allowed_ips' => ['nullable', 'string', 'max:500'],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')],
            'rate_limit' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        foreach (array_filter(array_map('trim', preg_split('/[\s,]+/', (string) ($data['allowed_ips'] ?? '')))) as $entry) {
            $ip = explode('/', $entry)[0];
            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                return throw \Illuminate\Validation\ValidationException::withMessages(['allowed_ips' => "Invalid address: {$entry}"]);
            }
        }

        return [
            'name' => $data['name'],
            'scopes' => array_values(array_unique($data['scopes'])),
            'allowed_ips' => ($data['allowed_ips'] ?? '') ?: null,
            'client_id' => $data['client_id'] ?? null,
            'rate_limit' => (int) ($data['rate_limit'] ?? 120),
            'expires_at' => $data['expires_at'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ] + ($creating ? ['user_id' => $request->user()->id] : []);
    }

    public function keyStore(Request $request)
    {
        [$key, $token] = ApiKey::issue($this->keyData($request, true));
        $this->audit('api', 'Created API key '.$key->name, implode(', ', $key->scopes));

        return $this->ok('API key created', ['token' => $token, 'key' => $this->keyRow($key)]);
    }

    public function keyUpdate(Request $request, ApiKey $key)
    {
        $key->update($this->keyData($request, false));
        $this->audit('api', 'Updated API key '.$key->name);

        return $this->ok('API key updated');
    }

    public function keyToggle(ApiKey $key)
    {
        $key->update(['is_active' => ! $key->is_active]);

        return $this->ok($key->name.' is now '.($key->is_active ? 'enabled' : 'disabled'));
    }

    /** Replace the token of a key; the old one stops working immediately. */
    public function keyRotate(ApiKey $key)
    {
        [$new, $token] = ApiKey::issue($key->only(['name', 'scopes', 'allowed_ips', 'client_id', 'rate_limit', 'expires_at', 'user_id', 'is_active']));
        $key->delete();
        $this->audit('api', 'Replaced the token of API key '.$key->name);

        return $this->ok('New token created. The old one no longer works.', ['token' => $token, 'key' => $this->keyRow($new)]);
    }

    public function keyDestroy(ApiKey $key)
    {
        $name = $key->name;
        $key->delete();
        $this->audit('api', 'Deleted API key '.$name);

        return $this->ok('API key deleted');
    }

    /* ============================================================= webhooks */

    public function webhooks()
    {
        return $this->ok('ok', ['data' => Webhook::query()->withCount(['deliveries as failed_count' => fn ($q) => $q->where('status', 'failed')])->orderBy('name')->get()->map(fn (Webhook $w) => [
            'id' => $w->id,
            'name' => $w->name,
            'url' => $w->url,
            'events' => $w->events,
            'is_active' => $w->is_active,
            'last_status' => $w->last_status,
            'last_error' => $w->last_error,
            'last_at' => $w->last_at?->toDateTimeString(),
            'failures' => $w->failures,
            'failed_count' => $w->failed_count,
        ])]);
    }

    public function webhookStore(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'url' => ['required', 'url:http,https', 'max:1000'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(array_merge(['*'], array_keys(ApiCatalog::EVENTS)))],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $secret = Webhook::newSecret();
        $webhook = Webhook::query()->create($data + ['secret' => $secret, 'is_active' => $request->boolean('is_active', true)]);
        $this->audit('api', 'Created webhook '.$webhook->name, $webhook->url);

        return $this->ok('Webhook created', ['secret' => $secret]);
    }

    public function webhookUpdate(Request $request, Webhook $webhook)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'url' => ['required', 'url:http,https', 'max:1000'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(array_merge(['*'], array_keys(ApiCatalog::EVENTS)))],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $webhook->update($data + ['is_active' => $request->boolean('is_active', true)]);

        return $this->ok('Webhook updated');
    }

    public function webhookTest(Webhook $webhook, WebhookManager $webhooks)
    {
        $delivery = $webhooks->test($webhook);

        return $delivery->status === 'sent'
            ? $this->ok('The receiver answered with HTTP '.$delivery->response_code)
            : $this->fail($delivery->error ?: 'The receiver did not answer.');
    }

    public function webhookSecret(Webhook $webhook)
    {
        $secret = Webhook::newSecret();
        $webhook->update(['secret' => $secret]);
        $this->audit('api', 'Replaced the secret of webhook '.$webhook->name);

        return $this->ok('New signing secret created', ['secret' => $secret]);
    }

    public function webhookDeliveries(Request $request, Webhook $webhook)
    {
        return $this->ok('ok', ['data' => $webhook->deliveries()->latest('id')->limit(50)->get()->map(fn (WebhookDelivery $d) => [
            'id' => $d->id,
            'event' => $d->event,
            'status' => $d->status,
            'attempts' => $d->attempts,
            'response_code' => $d->response_code,
            'error' => $d->error,
            'payload' => $d->payload,
            'created_at' => $d->created_at?->toDateTimeString(),
        ])]);
    }

    public function webhookDestroy(Webhook $webhook)
    {
        $name = $webhook->name;
        $webhook->delete();
        $this->audit('api', 'Deleted webhook '.$name);

        return $this->ok('Webhook deleted');
    }

    /* ================================================================= logs */

    public function logs(Request $request)
    {
        $query = ApiRequest::query()->latest('id');
        if ($request->filled('key')) {
            $query->where('api_key_id', (int) $request->query('key'));
        }
        if ($request->query('only') === 'errors') {
            $query->where('status', '>=', 400);
        }
        if ($search = trim((string) $request->query('search'))) {
            $query->where('path', 'like', "%{$search}%");
        }

        return $this->ok('ok', [
            'data' => $query->limit(200)->get()->map(fn (ApiRequest $r) => [
                'id' => $r->id,
                'key' => $r->key_name,
                'key_id' => $r->api_key_id,
                'method' => $r->method,
                'path' => $r->path,
                'status' => $r->status,
                'ip' => $r->ip,
                'duration' => $r->duration_ms,
                'message' => $r->message,
                'at' => $r->created_at?->toDateTimeString(),
            ]),
            'keys' => ApiKey::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function clearLogs()
    {
        ApiRequest::query()->delete();
        $this->audit('api', 'Cleared the API log');

        return $this->ok('API log cleared');
    }
}
