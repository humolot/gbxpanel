<?php

namespace App\Services\Ai\Tools;

use App\Models\DnsZone;
use App\Services\Dns\DnsException;
use App\Services\Dns\DnsManager;

/** DNS records in the provider accounts configured in DNS > DNS API. */
class DnsTools extends ToolGroup
{
    public function __construct(protected DnsManager $dns) {}

    public function tools(): array
    {
        return [
            'list_dns_zones' => self::tool('Domains (DNS zones) available through the DNS provider APIs configured in the panel (Cloudflare, Namecheap, GoDaddy, DigitalOcean, Hetzner, Linode, Vultr, Porkbun).', self::params(), false, false, fn () => 'Listed DNS zones'),
            'get_dns_records' => self::tool('DNS records of a domain read live from its provider.', self::params(['domain' => self::str('Domain or host name inside the zone')], ['domain']), false, false, fn ($a) => 'Read DNS records of '.self::labelArg($a, 'domain')),
            'manage_dns_record' => self::tool('Create, update or delete a DNS record through the provider API. name is relative to the zone ("@" for the apex, "www"). For update and delete pass the record id from get_dns_records.', self::params([
                'action' => self::str('Action', ['create', 'update', 'delete']),
                'domain' => self::str('Zone domain, e.g. example.com'),
                'id' => self::str('Record id (update and delete)'),
                'type' => self::str('Record type', ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'CAA', 'NS']),
                'name' => self::str('Host relative to the zone: @, www, mail'),
                'content' => self::str('Value: IP, host name or text'),
                'ttl' => self::int('TTL in seconds, 1 = automatic'),
                'priority' => self::int('MX priority'),
                'proxied' => self::bool('Cloudflare proxy (A, AAAA, CNAME)'),
            ], ['action', 'domain']), true, false, fn ($a) => ucfirst(self::labelArg($a, 'action')).' DNS record '.trim(self::labelArg($a, 'type').' '.self::labelArg($a, 'name')).' in '.self::labelArg($a, 'domain')),
            'point_domain_to_server' => self::tool('Create or update the A/AAAA records of host names (for example example.com and www.example.com) so they point to this server.', self::params(['hosts' => self::list('Host names'), 'ipv6' => self::bool('Also AAAA when the server has IPv6, default true')], ['hosts']), true, false, fn ($a) => 'Point '.implode(', ', self::strings($a['hosts'] ?? [])).' to this server'),
        ];
    }

    protected function zone(string $domain): ?DnsZone
    {
        return $this->dns->zoneFor($domain);
    }

    public function handle(string $name, array $a): mixed
    {
        try {
            switch ($name) {
                case 'list_dns_zones':
                    return DnsZone::query()->with('provider')->orderBy('name')->get()->map(fn (DnsZone $z) => [
                        'domain' => $z->name, 'provider' => $z->provider->label(), 'active' => $z->provider->is_active, 'manageable' => $z->manageable, 'note' => $z->note, 'records' => $z->records_count,
                    ])->all() ?: ['hint' => 'No DNS API configured. An administrator can add one in DNS > DNS API.'];

                case 'get_dns_records':
                    $zone = $this->zone((string) $a['domain']);

                    return $zone ? ['zone' => $zone->name, 'provider' => $zone->provider->label(), 'records' => $this->dns->records($zone)] : ['error' => 'No DNS zone managed by the panel contains '.$a['domain']];

                case 'manage_dns_record':
                    $zone = $this->zone((string) $a['domain']);
                    if (! $zone) {
                        return ['error' => 'No DNS zone managed by the panel contains '.$a['domain']];
                    }
                    if ($a['action'] === 'delete') {
                        $this->dns->delete($zone, (string) self::a($a, 'id'));

                        return ['ok' => true, 'message' => 'Record deleted'];
                    }
                    $record = $this->dns->normalize($zone, $a);
                    $a['action'] === 'update' ? $this->dns->update($zone, (string) self::a($a, 'id'), $record) : $this->dns->create($zone, $record);

                    return ['ok' => true, 'message' => 'Record saved', 'record' => $record, 'zone' => $zone->name];

                case 'point_domain_to_server':
                    return ['messages' => $this->dns->pointToServer(self::strings($a['hosts'] ?? []), (bool) self::a($a, 'ipv6', true))];
            }
        } catch (DnsException|\InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return ['error' => "Unknown tool {$name}"];
    }
}
