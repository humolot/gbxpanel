<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ClientController as PanelClients;
use App\Models\Client;
use App\Models\ClientPackage;
use App\Services\Clients\ClientManager;
use App\Services\SystemStats;
use Illuminate\Http\Request;

class ClientController extends ApiController
{
    public static function resource(Client $client): array
    {
        return [
            'id' => $client->id,
            'username' => $client->username,
            'name' => $client->name,
            'email' => $client->email,
            'status' => $client->status,
            'package' => $client->package ? ['id' => $client->package->id, 'name' => $client->package->name] : null,
            'limits' => [
                'websites' => $client->limit('max_websites'),
                'databases' => $client->limit('max_databases'),
                'ftp' => $client->limit('max_ftp'),
                'disk_mb' => $client->limit('disk_mb'),
                'bandwidth_mb' => $client->limit('bandwidth_mb'),
            ],
            'usage' => [
                'websites' => $client->websites()->count(),
                'databases' => $client->databases()->count(),
                'ftp' => $client->ftpAccounts()->count(),
                'disk_bytes' => (int) $client->disk_used,
                'disk' => SystemStats::bytes((int) $client->disk_used, 1),
                'bandwidth_bytes' => (int) $client->bandwidth_used,
                'bandwidth' => SystemStats::bytes((int) $client->bandwidth_used, 1),
            ],
            'two_factor' => $client->hasTwoFactor(),
            'expires_at' => $client->expires_at?->toDateString(),
            'notes' => $client->notes,
            'created_at' => $client->created_at?->toIso8601String(),
        ];
    }

    public function index(Request $request)
    {
        $query = Client::query()->with('package')->orderBy('username');
        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($q) => $q->where('username', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return $this->page($query, $request, fn (Client $client) => self::resource($client));
    }

    public function show(Client $client)
    {
        return $this->data(self::resource($client->load('package')));
    }

    public function store(Request $request)
    {
        $response = $this->forward(PanelClients::class, 'store', [
            'username' => $request->input('username'),
            'name' => $request->input('name', $request->input('username')),
            'email' => $request->input('email'),
            'password' => $request->input('password'),
            'package_id' => $request->input('package_id'),
            'expires_at' => $request->input('expires_at'),
            'notes' => $request->input('notes'),
        ]);

        $client = Client::query()->where('username', $request->input('username'))->first();

        return $client && $response->isSuccessful() ? $this->message('Client account created', self::resource($client)) : $response;
    }

    public function update(Request $request, Client $client)
    {
        return $this->forward(PanelClients::class, 'update', [
            'name' => $request->input('name', $client->name),
            'email' => $request->input('email', $client->email),
            'password' => $request->input('password'),
            'package_id' => $request->input('package_id', $client->package_id),
            'expires_at' => $request->input('expires_at', $client->expires_at?->toDateString()),
            'notes' => $request->input('notes', $client->notes),
        ], ['client' => $client]);
    }

    public function destroy(Client $client)
    {
        return $this->forward(PanelClients::class, 'destroy', [], ['client' => $client]);
    }

    public function suspend(Request $request, Client $client)
    {
        return $this->forward(PanelClients::class, 'suspend', ['reason' => $request->input('reason')], ['client' => $client]);
    }

    public function unsuspend(Client $client)
    {
        return $this->forward(PanelClients::class, 'unsuspend', [], ['client' => $client]);
    }

    public function resources(Client $client)
    {
        return $this->forward(PanelClients::class, 'resources', [], ['client' => $client]);
    }

    /** Assign resources to the client, or release them back to the administrator. */
    public function assign(Request $request, Client $client)
    {
        $type = (string) $request->input('type', $request->input('resource'));
        if (! array_key_exists($type, ClientManager::RESOURCES)) {
            return $this->error('unknown_resource', 'Use one of: '.implode(', ', array_keys(ClientManager::RESOURCES)), 422);
        }

        return $this->forward(PanelClients::class, 'assign', [
            'resource' => $type,
            'ids' => (array) $request->input('ids', []),
            'attach' => $this->flag($request, 'attach', true),
        ], ['client' => $client]);
    }

    public function packages()
    {
        return $this->data(ClientPackage::query()->withCount('clients')->orderBy('name')->get()->map(fn (ClientPackage $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'max_websites' => $p->max_websites,
            'max_databases' => $p->max_databases,
            'max_ftp' => $p->max_ftp,
            'disk_mb' => $p->disk_mb,
            'bandwidth_mb' => $p->bandwidth_mb,
            'php_versions' => $p->php_versions,
            'allow_ssl' => (bool) $p->allow_ssl,
            'clients' => $p->clients_count,
        ]));
    }

    public function packageStore(Request $request)
    {
        return $this->forward(PanelClients::class, 'packageStore', $request->json()->all());
    }
}
