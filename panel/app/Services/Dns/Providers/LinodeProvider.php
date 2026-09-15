<?php

namespace App\Services\Dns\Providers;

use App\Services\Dns\DnsException;
use Illuminate\Http\Client\PendingRequest;

/** Linode (Akamai Cloud) API v4 with a personal access token (Domains read/write). */
class LinodeProvider extends Provider
{
    public const API = 'https://api.linode.com/v4';

    protected const RATE_PER_MINUTE = 200;

    /** TTL values accepted by Linode; others are rounded up. */
    public const TTLS = [300, 3600, 7200, 14400, 28800, 57600, 86400, 172800, 345600, 604800, 1209600, 2419200];

    public static function minTtl(): int
    {
        return 300;
    }

    protected function client(): PendingRequest
    {
        return parent::client()->withToken($this->credential('api_token'));
    }

    protected function call(string $method, string $path, array $data = []): array
    {
        $response = $this->send(fn (PendingRequest $c) => $method === 'get' ? $c->get(self::API.$path, $data) : $c->{$method}(self::API.$path, $data));
        if ($response->failed()) {
            throw new DnsException('Linode: '.self::errorOf($response));
        }

        return $response->json() ?? [];
    }

    public function verify(): string
    {
        if ($this->credential('api_token') === '') {
            throw new DnsException('Enter the personal access token.');
        }

        return (string) ($this->call('get', '/profile')['username'] ?? 'API token');
    }

    protected function paginate(string $path): array
    {
        $rows = [];
        $page = 1;
        do {
            $json = $this->call('get', $path, ['page' => $page, 'page_size' => 500]);
            array_push($rows, ...($json['data'] ?? []));
            $pages = (int) ($json['pages'] ?? 1);
        } while ($page++ < $pages && $page <= 50);

        return $rows;
    }

    public function zones(): array
    {
        return array_map(fn ($d) => [
            'id' => (string) $d['id'],
            'name' => strtolower($d['domain']),
            'manageable' => ($d['type'] ?? 'master') === 'master',
            'note' => ($d['type'] ?? 'master') === 'master' ? null : 'Slave zone: records come from the master server.',
            'records' => null,
        ], $this->paginate('/domains'));
    }

    public function records(array $zone): array
    {
        $records = [];
        foreach ($this->paginate('/domains/'.$zone['id'].'/records') as $r) {
            $type = strtoupper($r['type']);
            $content = (string) $r['target'];
            if ($type === 'CAA') {
                $content = self::caa(0, (string) ($r['tag'] ?? 'issue'), $content);
            }
            $records[] = self::record((string) $r['id'], $type, (string) $r['name'] === '' ? '@' : (string) $r['name'], $content, (int) ($r['ttl_sec'] ?? 0), $type === 'MX' ? (int) $r['priority'] : null);
        }

        return $records;
    }

    public static function roundTtl(int $ttl): int
    {
        if ($ttl <= 1) {
            return 0; // zone default
        }
        foreach (self::TTLS as $allowed) {
            if ($ttl <= $allowed) {
                return $allowed;
            }
        }

        return self::TTLS[count(self::TTLS) - 1];
    }

    protected function payload(array $record): array
    {
        $data = ['type' => $record['type'], 'name' => $record['name'] === '@' ? '' : $record['name'], 'ttl_sec' => self::roundTtl((int) $record['ttl'])];
        if ($record['type'] === 'CAA') {
            $caa = self::parseCaa($record['content']);
            $data += ['tag' => $caa['tag'], 'target' => $caa['value']];
        } else {
            $data['target'] = $record['content'];
        }
        if ($record['type'] === 'MX') {
            $data['priority'] = (int) ($record['priority'] ?? 10);
        }

        return $data;
    }

    public function create(array $zone, array $record): void
    {
        $this->call('post', '/domains/'.$zone['id'].'/records', $this->payload($record));
    }

    public function update(array $zone, string $id, array $record): void
    {
        $this->call('put', '/domains/'.$zone['id'].'/records/'.$id, $this->payload($record));
    }

    public function delete(array $zone, string $id): void
    {
        $this->call('delete', '/domains/'.$zone['id'].'/records/'.$id);
    }
}
