<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\ClientPackage;
use App\Models\Setting;
use App\Services\Api\WebhookManager;
use App\Services\Clients\ClientManager;
use App\Services\SoftwareManager;
use App\Services\SystemStats;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Clients (administrators): accounts, packages, storage, activity and sub-panel settings. */
class ClientController extends Controller
{
    public const TABS = [
        'accounts' => ['Account', 'bi-person-badge'],
        'packages' => ['Package', 'bi-box-seam'],
        'storage' => ['Storage', 'bi-hdd-stack'],
        'logs' => ['Logs', 'bi-journal-text'],
        'settings' => ['Settings', 'bi-gear'],
    ];

    public function __construct(protected ClientManager $clients) {}

    public function index(Request $request, SoftwareManager $software)
    {
        $tab = array_key_exists((string) $request->query('tab'), self::TABS) ? (string) $request->query('tab') : 'accounts';

        return view('clients.index', [
            'tab' => $tab,
            'tabs' => self::TABS,
            'packages' => ClientPackage::query()->withCount('clients')->orderBy('name')->get(),
            'phpVersions' => $software->phpVersions(),
            'settings' => ClientManager::settings(),
            'portalUrl' => url('/client'),
            'resources' => collect(ClientManager::RESOURCES)->map(fn ($r) => $r[1]),
        ]);
    }

    protected function row(Client $c): array
    {
        return [
            'id' => $c->id,
            'username' => $c->username,
            'name' => $c->name,
            'email' => $c->email,
            'package_id' => $c->package_id,
            'package' => $c->package?->name,
            'status' => $c->status,
            'suspended_reason' => $c->suspended_reason,
            'expires_at' => $c->expires_at?->toDateString(),
            'expired' => $c->isExpired(),
            'notes' => $c->notes,
            'disk_used' => $c->disk_used,
            'disk_limit' => $c->diskLimitBytes(),
            'disk_h' => SystemStats::bytes($c->disk_used, 1).' / '.($c->package ? ($c->diskLimitBytes() ? SystemStats::bytes($c->diskLimitBytes(), 0) : 'Unlimited') : '-'),
            'bandwidth_used' => $c->bandwidth_used,
            'bandwidth_limit' => $c->bandwidthLimitBytes(),
            'bandwidth_h' => SystemStats::bytes($c->bandwidth_used, 1).' / '.($c->package ? ($c->bandwidthLimitBytes() ? SystemStats::bytes($c->bandwidthLimitBytes(), 0) : 'Unlimited') : '-'),
            'websites' => $c->websites_count ?? null,
            'databases' => $c->databases_count ?? null,
            'ftp' => $c->ftp_accounts_count ?? null,
            'two_factor' => $c->hasTwoFactor(),
            'last_login_at' => $c->last_login_at?->toDateTimeString(),
            'created_at' => $c->created_at?->toDateTimeString(),
        ];
    }

    public function list()
    {
        return $this->ok('ok', ['data' => Client::query()->with('package')->withCount(['websites', 'databases', 'ftpAccounts'])->orderBy('username')->get()->map(fn ($c) => $this->row($c))]);
    }

    protected function validated(Request $request, ?Client $client = null): array
    {
        $data = $request->validate([
            'username' => [$client ? 'prohibited' : 'required', 'string', 'regex:/^[a-z][a-z0-9_-]{2,31}$/', Rule::unique('clients', 'username')],
            'name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:190'],
            'password' => [$client ? 'nullable' : 'required', 'string', Password::min(10)->letters()->numbers()],
            'package_id' => ['nullable', 'integer', Rule::exists('client_packages', 'id')],
            'expires_at' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], ['username.regex' => 'Username: 3-32 lowercase letters, numbers, - and _, starting with a letter.']);
        if ($client && empty($data['password'])) {
            unset($data['password']);
        }

        return $data;
    }

    public function store(Request $request)
    {
        $client = Client::query()->create($this->validated($request) + ['status' => 'active']);
        ClientManager::forgetPortalCache();
        $this->audit('client', "Created client {$client->username}", $client->package?->name);
        WebhookManager::event('client.created', ['id' => $client->id, 'username' => $client->username, 'email' => $client->email, 'package' => $client->package?->name]);

        return $this->ok('Client account created', ['id' => $client->id]);
    }

    public function update(Request $request, Client $client)
    {
        $data = $this->validated($request, $client);
        $client->update($data);
        if (isset($data['password'])) {
            $client->setRememberToken(Str::random(60));
            $client->save();
        }
        $this->audit('client', "Updated client {$client->username}");

        return $this->ok('Client account updated');
    }

    public function destroy(Client $client)
    {
        $username = $client->username;
        $this->clients->delete($client);
        $this->audit('client', "Deleted client {$username}", 'Resources returned to the administrator');

        return $this->ok('Client deleted. Its websites, databases and FTP accounts were kept and returned to the administrator.');
    }

    public function suspend(Request $request, Client $client)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:190']]);
        $this->clients->suspend($client, ($data['reason'] ?? '') ?: 'Suspended by the administrator');

        WebhookManager::event('client.suspended', ['id' => $client->id, 'username' => $client->username, 'reason' => ($data['reason'] ?? '') ?: 'Suspended by the administrator']);

        return $this->ok("{$client->username} suspended: websites stopped and FTP disabled");
    }

    public function unsuspend(Client $client)
    {
        $this->clients->unsuspend($client);

        WebhookManager::event('client.unsuspended', ['id' => $client->id, 'username' => $client->username]);

        return $this->ok("{$client->username} reactivated");
    }

    public function resetTwoFactor(Client $client)
    {
        $client->disableTwoFactor();
        $this->audit('client', "Reset two-factor authentication of client {$client->username}");

        return $this->ok('Two-factor authentication reset');
    }

    /* ============================================================ resources */

    /** Every resource with its owner, to assign them to the client. */
    public function resources(Client $client)
    {
        $out = [];
        foreach (ClientManager::RESOURCES as $key => [$model, $label]) {
            $out[$key] = $model::query()->with('client:id,username')->orderBy('id')->get()->map(fn ($r) => [
                'id' => $r->id,
                'label' => match ($key) {
                    'websites' => $r->domain,
                    'databases' => $r->name.' ('.$r->engine.')',
                    'ftp' => $r->username,
                    'cron' => $r->name,
                    'dns' => $r->name,
                },
                'owner' => $r->client?->username,
                'mine' => $r->client_id === $client->id,
            ])->values();
        }

        return $this->ok('ok', ['data' => $out]);
    }

    public function assign(Request $request, Client $client)
    {
        $data = $request->validate([
            'resource' => ['required', Rule::in(array_keys(ClientManager::RESOURCES))],
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['integer'],
            'attach' => ['required', 'boolean'],
        ]);
        $count = $this->clients->assign($client, $data['resource'], $data['ids'], $request->boolean('attach'));
        $this->audit('client', ($request->boolean('attach') ? 'Assigned ' : 'Removed ').$data['resource'].' of client '.$client->username, implode(', ', $data['ids']));

        return $this->ok("{$count} item(s) ".($request->boolean('attach') ? 'assigned to ' : 'removed from ').$client->username);
    }

    /* ============================================================= packages */

    protected function packageData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'max_websites' => ['required', 'integer', 'min:0', 'max:100000'],
            'max_databases' => ['required', 'integer', 'min:0', 'max:100000'],
            'max_ftp' => ['required', 'integer', 'min:0', 'max:100000'],
            'disk_mb' => ['required', 'integer', 'min:0', 'max:100000000'],
            'bandwidth_mb' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'php_versions' => ['nullable', 'array'],
            'php_versions.*' => ['string', 'regex:/^\d\.\d$/'],
            'allow_ssl' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $data['allow_ssl'] = $request->boolean('allow_ssl');
        $data['php_versions'] = array_values(array_unique($data['php_versions'] ?? [])) ?: null;

        return $data;
    }

    public function packageStore(Request $request)
    {
        $package = ClientPackage::query()->create($this->packageData($request));
        $this->audit('client', "Created package {$package->name}");

        return $this->ok('Package created');
    }

    public function packageUpdate(Request $request, ClientPackage $package)
    {
        $package->update($this->packageData($request));
        $this->audit('client', "Updated package {$package->name}");

        return $this->ok('Package updated');
    }

    public function packageDestroy(ClientPackage $package)
    {
        if ($package->clients()->exists()) {
            return $this->fail('Move the clients of this package to another package first.');
        }
        $package->delete();

        return $this->ok('Package deleted');
    }

    /* ======================================================== storage, logs */

    public function storage()
    {
        return $this->ok('ok', ['data' => Client::query()->with('package', 'websites:id,client_id,domain,root_path')->orderByDesc('disk_used')->get()->map(fn (Client $c) => $this->row($c) + [
            'sites' => $c->websites->map(fn ($w) => ['domain' => $w->domain, 'root' => $w->root_path])->values(),
            'usage_updated_at' => $c->usage_updated_at?->toDateTimeString(),
        ])]);
    }

    public function refreshUsage()
    {
        $result = $this->clients->refresh(true);

        return $this->ok('Usage updated'.($result['actions'] ? ': '.implode('; ', $result['actions']) : ''));
    }

    public function logs(Request $request)
    {
        $query = ActivityLog::query()->with('user:id,username')->whereNotNull('client_id')->latest('id');
        if ($request->filled('client_id')) {
            $query->where('client_id', (int) $request->query('client_id'));
        }
        $clients = Client::query()->pluck('username', 'id');

        return $this->ok('ok', ['data' => $query->limit(300)->get()->map(fn (ActivityLog $l) => [
            'time' => $l->created_at?->toDateTimeString(),
            'client' => $clients[$l->client_id] ?? '#'.$l->client_id,
            'admin' => $l->user?->username,
            'category' => $l->category,
            'action' => $l->action,
            'details' => $l->details,
            'ip' => $l->ip,
        ])]);
    }

    public function saveSettings(Request $request)
    {
        $data = $request->validate([
            'client_portal_title' => ['required', 'string', 'max:60'],
            'client_on_expire' => ['required', Rule::in(['suspend', 'none'])],
            'client_over_disk' => ['required', Rule::in(['none', 'block', 'suspend'])],
            'client_over_bandwidth' => ['required', Rule::in(['none', 'block', 'suspend'])],
        ]);
        foreach ($data as $key => $value) {
            Setting::put($key, $value);
        }
        Setting::put('client_portal', $request->boolean('client_portal'));
        ClientManager::forgetPortalCache();
        $this->audit('client', 'Updated client panel settings');

        return $this->ok('Settings saved');
    }
}
