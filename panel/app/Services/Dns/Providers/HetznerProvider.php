<?php

namespace App\Services\Dns\Providers;

use App\Services\Dns\DnsException;
use Illuminate\Http\Client\PendingRequest;

/** Hetzner DNS Console API (dns.hetzner.com) with an API token. */
class HetznerProvider extends Provider
{
    public const API = 'https://dns.hetzner.com/api/v1';

    protected const RATE_PER_MINUTE = 60;

    protected function client(): PendingRequest
    {
        return parent::client()->withHeaders(['Auth-API-Token' => $this->credential('api_token')]);
    }

    protected function call(string $method, string $path, array $data = []): array
    {
        $response = $this->send(fn (PendingRequest $c) => $method === 'get' ? $c->get(self::API.$path, $data) : $c->{$method}(self::API.$path, $data));
        if ($response->failed()) {
            throw new DnsException('Hetzner: '.self::errorOf($response));
        }

        return $response->json() ?? [];
    }

    public function verify(): string
    {
        if ($this->credential('api_token') === '') {
            throw new DnsException('Enter the API token.');
        }
        $this->call('get', '/zones', ['per_page' => 1]);

        return 'API token';
    }

    public function zones(): array
    {
        $zones = [];
        $page = 1;
        do {
            $json = $this->call('get', '/zones', ['per_page' => 100, 'page' => $page]);
            foreach ($json['zones'] ?? [] as $z) {
                $zones[] = ['id' => (string) $z['id'], 'name' => strtolower($z['name']), 'manageable' => true, 'note' => null, 'records' => isset($z['records_count']) ? (int) $z['records_count'] : null];
            }
            $last = (int) ($json['meta']['pagination']['last_page'] ?? 1);
        } while ($page++ < $last && $page <= 50);

        return $zones;
    }

    public function records(array $zone): array
    {
        $records = [];
        foreach ($this->call('get', '/records', ['zone_id' => $zone['id']])['records'] ?? [] as $r) {
            $type = strtoupper($r['type']);
            if ($type === 'SOA') {
                continue;
            }
            $value = (string) $r['value'];
            $priority = null;
            if ($type === 'MX' && preg_match('/^(\d+)\s+(\S+)$/', $value, $m)) {
                [$priority, $value] = [(int) $m[1], $m[2]];
            }
            $content = match ($type) {
                'TXT' => self::unquote($value),
                'CNAME', 'MX', 'NS' => rtrim($value, '.'),
                default => $value,
            };
            $records[] = self::record((string) $r['id'], $type, (string) $r['name'], $content, (int) ($r['ttl'] ?? 86400), $priority);
        }

        return $records;
    }

    protected function payload(array $zone, array $record): array
    {
        $type = $record['type'];
        $value = match ($type) {
            'MX' => ((int) ($record['priority'] ?? 10)).' '.self::dotted($record['content']),
            'CNAME', 'NS' => self::dotted($record['content']),
            'TXT' => self::quote($record['content']),
            'CAA' => (function () use ($record) {
                $caa = self::parseCaa($record['content']);

                return self::caa($caa['flags'], $caa['tag'], $caa['value']);
            })(),
            default => $record['content'],
        };
        $data = ['zone_id' => $zone['id'], 'type' => $type, 'name' => $record['name'], 'value' => $value];
        if ((int) $record['ttl'] > 1) {
            $data['ttl'] = max(self::minTtl(), (int) $record['ttl']);
        }

        return $data;
    }

    public function create(array $zone, array $record): void
    {
        $this->call('post', '/records', $this->payload($zone, $record));
    }

    public function update(array $zone, string $id, array $record): void
    {
        $this->call('put', '/records/'.$id, $this->payload($zone, $record));
    }

    public function delete(array $zone, string $id): void
    {
        $this->call('delete', '/records/'.$id);
    }
}
