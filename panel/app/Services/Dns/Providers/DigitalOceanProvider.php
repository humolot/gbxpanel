<?php

namespace App\Services\Dns\Providers;

use App\Services\Dns\DnsException;
use Illuminate\Http\Client\PendingRequest;

/** DigitalOcean API v2 (personal access token with domain read and write scopes). */
class DigitalOceanProvider extends Provider
{
    public const API = 'https://api.digitalocean.com/v2';

    protected const RATE_PER_MINUTE = 250;

    public static function minTtl(): int
    {
        return 30;
    }

    protected function client(): PendingRequest
    {
        return parent::client()->withToken($this->credential('api_token'));
    }

    protected function call(string $method, string $path, array $data = []): array
    {
        $response = $this->send(fn (PendingRequest $c) => $method === 'get' ? $c->get(self::API.$path, $data) : $c->{$method}(self::API.$path, $data));
        if ($response->failed()) {
            throw new DnsException('DigitalOcean: '.self::errorOf($response));
        }

        return $response->json() ?? [];
    }

    public function verify(): string
    {
        if ($this->credential('api_token') === '') {
            throw new DnsException('Enter the API token.');
        }

        return (string) ($this->call('get', '/account')['account']['email'] ?? 'API token');
    }

    public function zones(): array
    {
        $zones = [];
        $page = 1;
        do {
            $json = $this->call('get', '/domains', ['per_page' => 200, 'page' => $page]);
            foreach ($json['domains'] ?? [] as $d) {
                $zones[] = ['id' => $d['name'], 'name' => strtolower($d['name']), 'manageable' => true, 'note' => null, 'records' => null];
            }
            $more = ! empty($json['links']['pages']['next']);
        } while ($more && $page++ < 50);

        return $zones;
    }

    public function records(array $zone): array
    {
        $records = [];
        $page = 1;
        do {
            $json = $this->call('get', '/domains/'.$zone['name'].'/records', ['per_page' => 200, 'page' => $page]);
            foreach ($json['domain_records'] ?? [] as $r) {
                $type = strtoupper($r['type']);
                if ($type === 'SOA') {
                    continue;
                }
                $content = (string) $r['data'];
                if ($type === 'CAA') {
                    $content = self::caa((int) ($r['flags'] ?? 0), (string) $r['tag'], $content);
                } elseif (in_array($type, ['CNAME', 'MX', 'NS'], true)) {
                    $content = $content === '@' ? $zone['name'] : rtrim($content, '.');
                }
                $records[] = self::record((string) $r['id'], $type, (string) $r['name'], $content, (int) ($r['ttl'] ?? 1800), isset($r['priority']) ? (int) $r['priority'] : null);
            }
            $more = ! empty($json['links']['pages']['next']);
        } while ($more && $page++ < 50);

        return $records;
    }

    protected function payload(array $record): array
    {
        $data = ['type' => $record['type'], 'name' => $record['name'], 'ttl' => max(self::minTtl(), (int) $record['ttl'] ?: 1800)];
        $type = $record['type'];
        if ($type === 'CAA') {
            $caa = self::parseCaa($record['content']);
            $data += ['data' => $caa['value'], 'flags' => $caa['flags'], 'tag' => $caa['tag']];
        } else {
            $data['data'] = in_array($type, ['CNAME', 'MX', 'NS'], true) ? self::dotted($record['content']) : $record['content'];
        }
        if ($type === 'MX') {
            $data['priority'] = (int) ($record['priority'] ?? 10);
        }

        return $data;
    }

    public function create(array $zone, array $record): void
    {
        $this->call('post', '/domains/'.$zone['name'].'/records', $this->payload($record));
    }

    public function update(array $zone, string $id, array $record): void
    {
        $this->call('put', '/domains/'.$zone['name'].'/records/'.$id, $this->payload($record));
    }

    public function delete(array $zone, string $id): void
    {
        $this->call('delete', '/domains/'.$zone['name'].'/records/'.$id);
    }
}
