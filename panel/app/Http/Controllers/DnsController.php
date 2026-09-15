<?php

namespace App\Http\Controllers;

use App\Models\DnsProvider;
use App\Models\DnsZone;
use App\Models\Website;
use App\Services\Dns\DnsException;
use App\Services\Dns\DnsManager;
use App\Services\SystemStats;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** DNS: provider accounts (DNS API), zones and records. */
class DnsController extends Controller
{
    public function __construct(protected DnsManager $dns) {}

    public function index(Request $request, SystemStats $stats)
    {
        $tab = $request->query('tab') === 'providers' ? 'providers' : 'domains';

        return view('dns.index', [
            'tab' => $tab,
            'types' => collect(DnsManager::TYPES)->map(fn ($t, $key) => [
                'key' => $key, 'name' => $t['name'], 'icon' => $t['icon'], 'color' => $t['color'], 'proxy' => ! empty($t['proxy']),
                'fields' => $t['fields'], 'docs' => $t['docs'], 'notes' => $t['notes'],
                'record_types' => $t['class']::types(), 'min_ttl' => $t['class']::minTtl(),
            ])->values(),
            'providersCount' => DnsProvider::query()->count(),
            'serverIp' => $stats->publicIp(),
            'ttls' => DnsManager::TTL_OPTIONS,
        ]);
    }

    protected function fail422(\Throwable $e)
    {
        return $this->fail($e->getMessage(), 422);
    }

    /* ============================================================= providers */

    public function providers()
    {
        return $this->ok('ok', ['data' => DnsProvider::query()->withCount('zones')->orderBy('id')->get()->map(fn (DnsProvider $p) => [
            'id' => $p->id,
            'type' => $p->type,
            'type_name' => DnsManager::TYPES[$p->type]['name'] ?? $p->type,
            'alias' => $p->alias,
            'label' => $p->label(),
            'is_active' => $p->is_active,
            'rate_limit' => $p->rate_limit,
            'account' => $p->account,
            'checked_at' => $p->checked_at?->toDateTimeString(),
            'last_error' => $p->last_error,
            'zones' => $p->zones_count,
            'credentials' => $p->maskedCredentials(),
        ])]);
    }

    /** @return array{0: array, 1: array} model attributes and credentials */
    protected function providerData(Request $request, ?DnsProvider $provider = null): array
    {
        $data = $request->validate([
            'type' => [$provider ? 'nullable' : 'required', Rule::in(array_keys(DnsManager::TYPES))],
            'alias' => ['nullable', 'string', 'max:60'],
            'is_active' => ['nullable', 'boolean'],
            'rate_limit' => ['nullable', 'boolean'],
            'credentials' => ['nullable', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:500'],
        ]);
        $type = $provider?->type ?? $data['type'];
        $fields = DnsManager::TYPES[$type]['fields'];
        $input = (array) ($data['credentials'] ?? []);
        $old = (array) ($provider?->credentials ?? []);

        $credentials = [];
        $errors = [];
        foreach ($fields as $key => $field) {
            $value = trim((string) ($input[$key] ?? ''));
            if (($field['type'] ?? 'text') === 'checkbox') {
                $credentials[$key] = filter_var($value, FILTER_VALIDATE_BOOL) ? '1' : '';

                continue;
            }
            if ($value === '' && ($field['type'] ?? 'text') === 'password' && isset($old[$key])) {
                $value = (string) $old[$key]; // unchanged secret
            }
            if ($value === '' && ! empty($field['required'])) {
                $errors['credentials.'.$key] = $field['label'].' is required.';
            }
            $credentials[$key] = $value;
        }
        if ($type === 'namecheap' && $credentials['client_ip'] !== '' && ! filter_var($credentials['client_ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $errors['credentials.client_ip'] = 'Namecheap only accepts an IPv4 address.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [[
            'alias' => $data['alias'] ?? null,
            'is_active' => $request->boolean('is_active', true),
            'rate_limit' => $request->boolean('rate_limit'),
        ] + ($provider ? [] : ['type' => $type]), array_filter($credentials, fn ($v) => $v !== '')];
    }

    public function providerStore(Request $request)
    {
        [$attributes, $credentials] = $this->providerData($request);
        $provider = new DnsProvider($attributes + ['credentials' => $credentials]);

        // the credentials must work before the account is saved
        try {
            $account = $this->dns->driver($provider)->verify();
        } catch (DnsException $e) {
            return $this->fail422($e);
        }
        $provider->fill(['account' => mb_substr($account, 0, 190), 'checked_at' => now()])->save();
        $this->audit('dns', 'Added DNS API '.$provider->label());

        try {
            $count = $this->dns->syncZones($provider);
        } catch (DnsException $e) {
            return $this->ok('DNS API added, but the domains could not be listed: '.$e->getMessage());
        }

        return $this->ok("DNS API added: {$count} domain(s) found");
    }

    public function providerUpdate(Request $request, DnsProvider $provider)
    {
        [$attributes, $credentials] = $this->providerData($request, $provider);
        $changed = $credentials != $provider->credentials;
        $provider->fill($attributes + ['credentials' => $credentials]);
        if ($changed) {
            try {
                $provider->account = mb_substr($this->dns->driver($provider)->verify(), 0, 190);
                $provider->checked_at = now();
                $provider->last_error = null;
            } catch (DnsException $e) {
                return $this->fail422($e);
            }
        }
        $provider->save();
        $this->audit('dns', 'Updated DNS API '.$provider->label());

        return $this->ok('DNS API updated');
    }

    public function providerToggle(DnsProvider $provider)
    {
        $provider->update(['is_active' => ! $provider->is_active]);

        return $this->ok($provider->label().($provider->is_active ? ' enabled' : ' disabled'));
    }

    public function providerTest(DnsProvider $provider)
    {
        try {
            return $this->ok('Connection OK: '.$this->dns->test($provider));
        } catch (DnsException $e) {
            return $this->fail422($e);
        }
    }

    public function providerSync(DnsProvider $provider)
    {
        try {
            return $this->ok($this->dns->syncZones($provider).' domain(s) synchronized');
        } catch (DnsException $e) {
            return $this->fail422($e);
        }
    }

    public function providerDestroy(DnsProvider $provider)
    {
        $label = $provider->label();
        $provider->delete();
        $this->audit('dns', 'Removed DNS API '.$label);

        return $this->ok('DNS API removed. The records at the provider were not changed.');
    }

    /* ================================================================= zones */

    public function zones()
    {
        $sites = Website::query()->get(['id', 'domain', 'aliases']);

        return $this->ok('ok', ['data' => DnsZone::query()->with('provider')->orderBy('name')->get()->map(fn (DnsZone $z) => [
            'id' => $z->id,
            'name' => $z->name,
            'provider_id' => $z->provider_id,
            'provider' => $z->provider->label(),
            'provider_type' => $z->provider->type,
            'provider_active' => $z->provider->is_active,
            'manageable' => $z->manageable,
            'note' => $z->note,
            'records' => $z->records_count,
            'synced_at' => $z->synced_at?->toDateTimeString(),
            'websites' => $sites->filter(fn ($s) => $s->domain === $z->name || str_ends_with($s->domain, '.'.$z->name))->pluck('domain')->values(),
        ])]);
    }

    public function syncAll()
    {
        $total = 0;
        $errors = [];
        foreach (DnsProvider::query()->where('is_active', true)->get() as $provider) {
            try {
                $total += $this->dns->syncZones($provider);
            } catch (DnsException $e) {
                $errors[] = $e->getMessage();
            }
        }

        return $errors ? $this->fail("{$total} domain(s) synchronized. ".implode(' ', $errors)) : $this->ok("{$total} domain(s) synchronized");
    }

    protected function manageable(DnsZone $zone): ?\Illuminate\Http\JsonResponse
    {
        if (! $zone->provider->is_active) {
            return $this->fail('The DNS API account of this domain is disabled.');
        }

        return $zone->manageable ? null : $this->fail($zone->note ?: 'The records of this domain cannot be managed through the API.');
    }

    public function records(DnsZone $zone)
    {
        if ($error = $this->manageable($zone)) {
            return $error;
        }
        try {
            $records = $this->dns->records($zone);
        } catch (DnsException $e) {
            return $this->fail422($e);
        }
        $class = DnsManager::driverClass($zone->provider->type);

        return $this->ok('ok', [
            'zone' => ['id' => $zone->id, 'name' => $zone->name, 'provider' => $zone->provider->label(), 'type' => $zone->provider->type],
            'record_types' => $class::types(),
            'min_ttl' => $class::minTtl(),
            'proxy' => ! empty(DnsManager::TYPES[$zone->provider->type]['proxy']),
            'data' => $records,
        ]);
    }

    public function recordStore(Request $request, DnsZone $zone)
    {
        return $this->saveRecord($request, $zone, null);
    }

    public function recordUpdate(Request $request, DnsZone $zone, string $record)
    {
        return $this->saveRecord($request, $zone, $record);
    }

    protected function saveRecord(Request $request, DnsZone $zone, ?string $id)
    {
        if ($error = $this->manageable($zone)) {
            return $error;
        }
        $request->validate([
            'type' => ['required', 'string', 'max:10'],
            'name' => ['nullable', 'string', 'max:253'],
            'content' => ['required', 'string', 'max:4000'],
            'ttl' => ['nullable', 'integer', 'min:1'],
            'priority' => ['nullable', 'integer'],
            'proxied' => ['nullable', 'boolean'],
        ]);
        try {
            $record = $this->dns->normalize($zone, $request->all());
            $id === null ? $this->dns->create($zone, $record) : $this->dns->update($zone, $id, $record);
        } catch (\InvalidArgumentException|DnsException $e) {
            return $this->fail422($e);
        }
        $this->audit('dns', ($id === null ? 'Created' : 'Updated')." {$record['type']} record ".$zone->fqdn($record['name']), $record['content']);

        return $this->ok($id === null ? 'Record created' : 'Record updated');
    }

    public function recordDestroy(Request $request, DnsZone $zone, string $record)
    {
        if ($error = $this->manageable($zone)) {
            return $error;
        }
        try {
            $this->dns->delete($zone, $record);
        } catch (DnsException $e) {
            return $this->fail422($e);
        }
        $this->audit('dns', 'Deleted record in '.$zone->name, (string) $request->input('label'));

        return $this->ok('Record deleted');
    }

    /** Create or update A/AAAA records of the apex and www for this server. */
    public function point(Request $request, DnsZone $zone)
    {
        if ($error = $this->manageable($zone)) {
            return $error;
        }
        $data = $request->validate(['hosts' => ['nullable', 'array', 'max:20'], 'hosts.*' => ['string', 'max:253'], 'ipv6' => ['nullable', 'boolean'], 'proxied' => ['nullable', 'boolean']]);
        $hosts = $data['hosts'] ?? [$zone->name, 'www.'.$zone->name];
        foreach ($hosts as $host) {
            if ($zone->relative($host) === null) {
                return $this->fail("{$host} is not part of {$zone->name}.");
            }
        }
        $messages = $this->dns->pointToServer($hosts, $request->boolean('ipv6', true), $request->has('proxied') ? $request->boolean('proxied') : null);
        $this->audit('dns', 'Pointed '.implode(', ', $hosts).' to this server', implode("\n", $messages));

        return $this->ok('DNS updated', ['messages' => $messages]);
    }

    /** Zone that manages a host, for the website and SSL forms. */
    public function match(Request $request)
    {
        $zone = $this->dns->zoneFor((string) $request->query('domain'));

        return $this->ok('ok', ['zone' => $zone ? ['id' => $zone->id, 'name' => $zone->name, 'provider' => $zone->provider->label(), 'proxy' => ! empty(DnsManager::TYPES[$zone->provider->type]['proxy'])] : null]);
    }

    public function lookup(Request $request)
    {
        $host = strtolower(trim((string) $request->query('host')));
        if (! preg_match('/^[a-z0-9._-]{1,253}$/', $host)) {
            return $this->fail('Invalid host');
        }

        return $this->ok('ok', ['ips' => $this->dns->resolve($host), 'server' => app(SystemStats::class)->publicIp()]);
    }
}
