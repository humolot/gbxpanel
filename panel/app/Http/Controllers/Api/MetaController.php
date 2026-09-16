<?php

namespace App\Http\Controllers\Api;

use App\Services\Api\ApiCatalog;
use App\Services\Api\OpenApi;
use Illuminate\Http\Request;

class MetaController extends ApiController
{
    public function index(Request $request)
    {
        return $this->data([
            'panel' => \App\Models\Setting::get('panel_title', 'GBX Panel'),
            'version' => config('gbx.version'),
            'api_version' => ApiCatalog::VERSION,
            'documentation' => url('/api/'.ApiCatalog::VERSION.'/openapi.json'),
            'endpoints' => count(ApiCatalog::endpoints()),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function whoami(Request $request)
    {
        $key = $this->key();

        return $this->data([
            'key' => ['id' => $key?->id, 'name' => $key?->name, 'prefix' => $key?->prefix],
            'scopes' => $key?->scopes ?? [],
            'client_id' => $key?->client_id,
            'user' => $key?->user?->only(['id', 'name', 'username', 'role']),
            'rate_limit' => $key?->rate_limit,
            'expires_at' => $key?->expires_at?->toIso8601String(),
            'allowed_ips' => array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $key?->allowed_ips)))),
            'your_ip' => $request->ip(),
        ]);
    }

    public function openapi()
    {
        return response()->json(OpenApi::document(url('/api/'.ApiCatalog::VERSION)), 200, [], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
