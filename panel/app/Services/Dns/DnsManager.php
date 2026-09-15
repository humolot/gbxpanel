<?php

namespace App\Services\Dns;

use App\Models\DnsProvider;
use App\Models\DnsZone;
use App\Services\Dns\Providers;
use App\Services\Dns\Providers\Provider;
use App\Services\Shell;
use App\Services\SystemStats;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** DNS module: provider accounts, zones and records through the provider APIs. */
class DnsManager
{
    public const TYPES = [
        'cloudflare' => [
            'name' => 'Cloudflare',
            'class' => Providers\CloudflareProvider::class,
            'icon' => 'bi-cloud-fill', 'color' => '#f38020',
            'proxy' => true,
            'fields' => [
                'api_token' => ['label' => 'API Token', 'type' => 'password', 'placeholder' => 'Recommended: token with Zone.DNS edit permission'],
                'email' => ['label' => 'API User (e-mail)', 'type' => 'text', 'placeholder' => 'Only with the Global API Key'],
                'api_key' => ['label' => 'Global API Key', 'type' => 'password', 'placeholder' => 'Only when no API token is used'],
            ],
            'docs' => 'https://developers.cloudflare.com/fundamentals/api/get-started/create-token/',
            'notes' => ['Create an API token with the permissions Zone > DNS > Edit and Zone > Zone > Read (all zones or the zones to manage). The Global API Key with the account e-mail also works but gives access to the whole account.'],
        ],
        'namecheap' => [
            'name' => 'Namecheap',
            'class' => Providers\NamecheapProvider::class,
            'icon' => 'bi-tag-fill', 'color' => '#de3723',
            'fields' => [
                'api_user' => ['label' => 'API User', 'type' => 'text', 'required' => true, 'placeholder' => 'Namecheap username'],
                'api_key' => ['label' => 'API Key', 'type' => 'password', 'required' => true],
                'client_ip' => ['label' => 'Whitelisted IPv4', 'type' => 'text', 'placeholder' => 'Empty: public IPv4 of this server'],
                'sandbox' => ['label' => 'Sandbox account', 'type' => 'checkbox'],
            ],
            'docs' => 'https://www.namecheap.com/support/api/intro/',
            'notes' => [
                'Namecheap API needs the IP of this server added in Whitelisted IPs (only IPv4): Profile > Tools > Namecheap API Access > Whitelisted IPs.',
                'Namecheap only enables the API for accounts that meet its requirements (account balance, number of domains or purchases).',
                'The API replaces the whole host list on every change; the panel reads the current records first so nothing else is lost.',
            ],
        ],
        'godaddy' => [
            'name' => 'GoDaddy',
            'class' => Providers\GoDaddyProvider::class,
            'icon' => 'bi-globe', 'color' => '#1bdbdb',
            'fields' => [
                'api_key' => ['label' => 'API Key', 'type' => 'text', 'required' => true],
                'api_secret' => ['label' => 'API Secret', 'type' => 'password', 'required' => true],
                'ote' => ['label' => 'OTE (test) environment', 'type' => 'checkbox'],
            ],
            'docs' => 'https://developer.godaddy.com/keys',
            'notes' => ['Create a Production key. GoDaddy only grants DNS API access to accounts with 10 or more domains or a Discount Domain Club plan.'],
        ],
        'digitalocean' => [
            'name' => 'DigitalOcean',
            'class' => Providers\DigitalOceanProvider::class,
            'icon' => 'bi-droplet-fill', 'color' => '#0080ff',
            'fields' => ['api_token' => ['label' => 'API Token', 'type' => 'password', 'required' => true]],
            'docs' => 'https://docs.digitalocean.com/reference/api/create-personal-access-token/',
            'notes' => ['Create a personal access token with the domain read, create, update and delete scopes (or full access).'],
        ],
        'hetzner' => [
            'name' => 'Hetzner DNS',
            'class' => Providers\HetznerProvider::class,
            'icon' => 'bi-hdd-network-fill', 'color' => '#d50c2d',
            'fields' => ['api_token' => ['label' => 'API Token', 'type' => 'password', 'required' => true]],
            'docs' => 'https://docs.hetzner.com/dns-console/dns/general/api-access-token/',
            'notes' => ['Uses the DNS Console API (dns.hetzner.com). Create the token in DNS Console > API tokens.'],
        ],
        'linode' => [
            'name' => 'Linode (Akamai)',
            'class' => Providers\LinodeProvider::class,
            'icon' => 'bi-diagram-3-fill', 'color' => '#00a95c',
            'fields' => ['api_token' => ['label' => 'Personal Access Token', 'type' => 'password', 'required' => true]],
            'docs' => 'https://techdocs.akamai.com/linode-api/reference/get-started',
            'notes' => ['Create a personal access token with Domains read/write access.'],
        ],
        'vultr' => [
            'name' => 'Vultr',
            'class' => Providers\VultrProvider::class,
            'icon' => 'bi-lightning-fill', 'color' => '#007bfc',
            'fields' => ['api_key' => ['label' => 'API Key', 'type' => 'password', 'required' => true]],
            'docs' => 'https://my.vultr.com/settings/#settingsapi',
            'notes' => ['Enable the API in Account > API and allow the IP of this server in Access Control.'],
        ],
        'porkbun' => [
            'name' => 'Porkbun',
            'class' => Providers\PorkbunProvider::class,
            'icon' => 'bi-piggy-bank-fill', 'color' => '#ef7878',
            'fields' => [
                'api_key' => ['label' => 'API Key', 'type' => 'text', 'required' => true, 'placeholder' => 'pk1_...'],
                'secret_key' => ['label' => 'Secret Key', 'type' => 'password', 'required' => true, 'placeholder' => 'sk1_...'],
            ],
            'docs' => 'https://porkbun.com/account/api',
            'notes' => ['Turn on API ACCESS for each domain in the Porkbun domain management page, otherwise its records cannot be read.'],
        ],
    ];

    public const TTL_OPTIONS = [1 => 'Auto', 60 => '1 min', 300 => '5 min', 600 => '10 min', 1800 => '30 min', 3600 => '1 hour', 14400 => '4 hours', 43200 => '12 hours', 86400 => '1 day'];

    /* =============================================================== drivers */

    public function driver(DnsProvider $provider): Provider
    {
        $class = self::TYPES[$provider->type]['class'] ?? throw new DnsException('Unknown DNS provider '.$provider->type);
        if (Shell::simulating() && ! app()->runningUnitTests()) {
            return new Providers\SimulatedProvider((array) $provider->credentials, false, 'provider'.$provider->id, $provider->type);
        }

        return new $class((array) $provider->credentials, (bool) $provider->rate_limit, 'provider'.$provider->id);
    }

    public static function driverClass(string $type): string
    {
        return self::TYPES[$type]['class'] ?? throw new DnsException('Unknown DNS provider '.$type);
    }

    /** Check the credentials and remember the result. */
    public function test(DnsProvider $provider): string
    {
        try {
            $account = $this->driver($provider)->verify();
            $provider->forceFill(['account' => mb_substr($account, 0, 190), 'checked_at' => now(), 'last_error' => null])->save();

            return $account;
        } catch (DnsException $e) {
            $provider->forceFill(['checked_at' => now(), 'last_error' => mb_substr($e->getMessage(), 0, 500)])->save();
            throw $e;
        }
    }

    /** Refresh the zone list of an account. */
    public function syncZones(DnsProvider $provider): int
    {
        try {
            $zones = $this->driver($provider)->zones();
        } catch (DnsException $e) {
            $provider->forceFill(['checked_at' => now(), 'last_error' => mb_substr($e->getMessage(), 0, 500)])->save();
            throw $e;
        }

        $names = [];
        foreach ($zones as $z) {
            $names[] = $z['name'];
            DnsZone::query()->updateOrCreate(
                ['provider_id' => $provider->id, 'name' => $z['name']],
                ['external_id' => $z['id'], 'manageable' => $z['manageable'], 'note' => $z['note'], 'records_count' => $z['records'], 'synced_at' => now()]
            );
        }
        $provider->zones()->whereNotIn('name', $names ?: ['-'])->delete();
        $provider->forceFill(['checked_at' => now(), 'last_error' => null])->save();

        return count($zones);
    }

    /** Most specific active and manageable zone that contains the host name. */
    public function zoneFor(string $host): ?DnsZone
    {
        $host = rtrim(strtolower(trim($host)), '.');
        $host = preg_replace('/^\*\./', '', $host);
        $candidates = [];
        $parts = explode('.', $host);
        for ($i = 0; $i < count($parts) - 1; $i++) {
            $candidates[] = implode('.', array_slice($parts, $i));
        }
        if (! $candidates) {
            return null;
        }

        return DnsZone::query()
            ->whereIn('name', $candidates)
            ->where('manageable', true)
            ->whereHas('provider', fn ($q) => $q->where('is_active', true))
            ->with('provider')
            ->get()
            ->sortByDesc(fn (DnsZone $z) => strlen($z->name))
            ->first();
    }

    protected function ref(DnsZone $zone): array
    {
        return ['id' => (string) $zone->external_id, 'name' => $zone->name];
    }

    public function records(DnsZone $zone): array
    {
        $records = $this->driver($zone->provider)->records($this->ref($zone));
        usort($records, fn ($a, $b) => [$a['name'] === '@' ? '' : $a['name'], $a['type']] <=> [$b['name'] === '@' ? '' : $b['name'], $b['type']]);
        $zone->forceFill(['records_count' => count($records), 'synced_at' => now()])->save();

        return $records;
    }

    public function create(DnsZone $zone, array $record): void
    {
        $this->driver($zone->provider)->create($this->ref($zone), $record);
    }

    public function update(DnsZone $zone, string $id, array $record): void
    {
        $this->driver($zone->provider)->update($this->ref($zone), $id, $record);
    }

    public function delete(DnsZone $zone, string $id): void
    {
        $this->driver($zone->provider)->delete($this->ref($zone), $id);
    }

    /* ============================================================ validation */

    /**
     * Validate a record from the form and return it normalized.
     *
     * @throws \InvalidArgumentException
     */
    public function normalize(DnsZone $zone, array $input): array
    {
        $class = self::driverClass($zone->provider->type);
        $type = strtoupper(trim((string) ($input['type'] ?? '')));
        if (! in_array($type, $class::types(), true)) {
            throw new \InvalidArgumentException("{$type} records are not supported by ".self::TYPES[$zone->provider->type]['name'].'.');
        }

        $name = strtolower(trim((string) ($input['name'] ?? '@')));
        $name = $name === '' ? '@' : rtrim($name, '.');
        if ($name !== '@' && ($relative = $zone->relative($name)) !== null && str_contains($name, $zone->name)) {
            $name = $relative;
        }
        $label = '([a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?)';
        if ($name !== '@' && ! preg_match('/^(\*|'.$label.')(\.'.$label.')*$/', $name)) {
            throw new \InvalidArgumentException('Invalid host name. Use @ for '.$zone->name.', or a name such as www or mail.');
        }
        if (strlen($zone->fqdn($name)) > 253) {
            throw new \InvalidArgumentException('The host name is too long.');
        }

        $content = trim((string) ($input['content'] ?? ''));
        $hostname = '/^(?=.{1,253}\.?$)([a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9])?\.)*[a-z0-9_]([a-z0-9-]{0,61}[a-z0-9])?\.?$/i';
        $ok = match ($type) {
            'A' => (bool) filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4),
            'AAAA' => (bool) filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6),
            'CNAME', 'MX', 'NS' => (bool) preg_match($hostname, $content),
            'TXT' => $content !== '' && strlen($content) <= 4000 && ! preg_match('/[\r\n]/', $content),
            'CAA' => (bool) preg_match('/^\d{1,3}\s+(issue|issuewild|iodef)\s+"?[^"\s]*"?$/i', $content),
            default => false,
        };
        if (! $ok) {
            throw new \InvalidArgumentException(match ($type) {
                'A' => 'Enter an IPv4 address.',
                'AAAA' => 'Enter an IPv6 address.',
                'CNAME', 'MX', 'NS' => 'Enter a host name, e.g. mail.example.com.',
                'TXT' => 'Enter the text value (single line, up to 4000 characters).',
                'CAA' => 'CAA records look like: 0 issue "letsencrypt.org"',
            });
        }
        if (in_array($type, ['CNAME', 'MX', 'NS'], true)) {
            $content = rtrim(strtolower($content), '.');
        }
        if ($type === 'CNAME' && $name !== '@' && $zone->fqdn($name) === $content) {
            throw new \InvalidArgumentException('A CNAME cannot point to itself.');
        }

        $ttl = (int) ($input['ttl'] ?? 1);
        if ($ttl > 1) {
            $ttl = max($class::minTtl(), min($ttl, 604800));
        } else {
            $ttl = 1;
        }

        $priority = null;
        if ($type === 'MX') {
            $priority = (int) ($input['priority'] ?? 10);
            if ($priority < 0 || $priority > 65535) {
                throw new \InvalidArgumentException('MX priority must be between 0 and 65535.');
            }
        }

        $proxied = null;
        if (! empty(self::TYPES[$zone->provider->type]['proxy']) && in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
            $proxied = filter_var($input['proxied'] ?? false, FILTER_VALIDATE_BOOL);
        }

        return ['type' => $type, 'name' => $name, 'content' => $content, 'ttl' => $ttl, 'priority' => $priority, 'proxied' => $proxied];
    }

    /* ======================================================= website helpers */

    public function publicIpv6(): ?string
    {
        return Cache::remember('gbx.public_ipv6', 3600, function () {
            if (Shell::simulating()) {
                return null;
            }
            $ip = trim(Shell::out('curl -6 -fsS --max-time 4 https://api64.ipify.org 2>/dev/null', 10, false));

            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? $ip : null;
        });
    }

    /**
     * Point host names (domain, www, ...) at this server with A (and AAAA) records.
     * Existing A/AAAA records of the name are updated; names with a CNAME are left alone.
     *
     * @return list<string> messages
     */
    public function pointToServer(array $hosts, bool $ipv6 = true, ?bool $proxied = null): array
    {
        $ip4 = app(SystemStats::class)->publicIp();
        $ip6 = $ipv6 ? $this->publicIpv6() : null;
        $messages = [];
        $byZone = [];

        foreach (array_unique(array_map('strtolower', $hosts)) as $host) {
            $zone = $this->zoneFor($host);
            if (! $zone || str_starts_with($host, '*.')) {
                $messages[] = "{$host}: no DNS zone managed by the panel";

                continue;
            }
            $byZone[$zone->id]['zone'] = $zone;
            $byZone[$zone->id]['names'][] = $zone->relative($host);
        }

        foreach ($byZone as ['zone' => $zone, 'names' => $names]) {
            try {
                $records = $this->records($zone);
                foreach ($names as $name) {
                    $host = $zone->fqdn($name);
                    $current = array_values(array_filter($records, fn ($r) => $r['name'] === $name));
                    if (array_filter($current, fn ($r) => $r['type'] === 'CNAME')) {
                        $messages[] = "{$host}: has a CNAME record, left unchanged";

                        continue;
                    }
                    foreach (array_filter(['A' => $ip4, 'AAAA' => $ip6]) as $type => $ip) {
                        $existing = array_values(array_filter($current, fn ($r) => $r['type'] === $type));
                        $record = ['type' => $type, 'name' => $name, 'content' => $ip, 'ttl' => $existing[0]['ttl'] ?? 1, 'priority' => null,
                            'proxied' => ! empty(self::TYPES[$zone->provider->type]['proxy']) ? ($proxied ?? ($existing[0]['proxied'] ?? false)) : null];
                        if (! $existing) {
                            $this->create($zone, $record);
                            $messages[] = "{$host}: {$type} {$ip} created in ".$zone->provider->label();
                        } elseif ($existing[0]['content'] !== $ip) {
                            $this->update($zone, $existing[0]['id'], $record);
                            $messages[] = "{$host}: {$type} changed from {$existing[0]['content']} to {$ip}";
                        } else {
                            $messages[] = "{$host}: {$type} already points to {$ip}";
                        }
                    }
                }
            } catch (DnsException $e) {
                $messages[] = $zone->name.': '.$e->getMessage();
            }
        }

        return $messages;
    }

    /* ========================================================== ACME DNS-01 */

    public static function challengeName(string $domain): string
    {
        return '_acme-challenge.'.preg_replace('/^\*\./', '', rtrim(strtolower($domain), '.'));
    }

    public function addChallenge(string $domain, string $value): DnsZone
    {
        $host = self::challengeName($domain);
        $zone = $this->zoneFor($host) ?? throw new DnsException("No DNS zone managed by the panel contains {$domain}. Add the DNS API account in DNS first.");
        $this->create($zone, ['type' => 'TXT', 'name' => $zone->relative($host), 'content' => $value, 'ttl' => max(self::driverClass($zone->provider->type)::minTtl(), 60), 'priority' => null, 'proxied' => null]);

        return $zone;
    }

    public function removeChallenge(string $domain, string $value): int
    {
        $host = self::challengeName($domain);
        $zone = $this->zoneFor($host);
        if (! $zone) {
            return 0;
        }
        $name = $zone->relative($host);
        $removed = 0;
        foreach ($this->records($zone) as $r) {
            if ($r['type'] === 'TXT' && $r['name'] === $name && $r['content'] === $value) {
                $this->delete($zone, $r['id']);
                $removed++;
            }
        }

        return $removed;
    }

    /** True when public resolvers (Cloudflare and Google DNS over HTTPS) return the TXT value. */
    public function txtVisible(string $host, string $value): bool
    {
        foreach (['https://cloudflare-dns.com/dns-query', 'https://dns.google/resolve'] as $resolver) {
            try {
                $answers = Http::timeout(10)->withHeaders(['Accept' => 'application/dns-json'])->get($resolver, ['name' => $host, 'type' => 'TXT', 'cd' => 'false'])->json('Answer') ?? [];
            } catch (\Throwable) {
                return false;
            }
            $values = array_map(fn ($a) => str_replace('"', '', preg_replace('/"\s+"/', '', (string) ($a['data'] ?? ''))), $answers);
            if (! in_array($value, $values, true)) {
                return false;
            }
        }

        return true;
    }

    /** Public A/AAAA records of a host (DNS over HTTPS), used to show where a domain points. */
    public function resolve(string $host): array
    {
        $ips = [];
        foreach (['A', 'AAAA'] as $type) {
            try {
                foreach (Http::timeout(6)->withHeaders(['Accept' => 'application/dns-json'])->get('https://cloudflare-dns.com/dns-query', ['name' => $host, 'type' => $type])->json('Answer') ?? [] as $a) {
                    if (in_array((int) ($a['type'] ?? 0), [1, 28], true)) {
                        $ips[] = (string) $a['data'];
                    }
                }
            } catch (\Throwable) {
            }
        }

        return array_values(array_unique($ips));
    }
}
