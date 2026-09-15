<?php

namespace App\Services\Dns\Providers;

use App\Services\Dns\DnsException;
use Illuminate\Http\Client\PendingRequest;

/** Vultr API v2 with an API key (the server IP must be allowed in the key access control). */
class VultrProvider extends Provider
{
    public const API = 'https://api.vultr.com/v2';

    // Vultr allows 30 requests per second; stay far below
    protected const RATE_PER_MINUTE = 300;

    protected function client(): PendingRequest
    {
        return parent::client()->withToken($this->credential('api_key'));
    }

    protected function call(string $method, string $path, array $data = []): array
    {
        $response = $this->send(fn (PendingRequest $c) => $method === 'get' ? $c->get(self::API.$path, $data) : $c->{$method}(self::API.$path, $data));
        if ($response->failed()) {
            throw new DnsException('Vultr: '.self::errorOf($response));
        }

        return $response->json() ?? [];
    }

    public function verify(): string
    {
        if ($this->credential('api_key') === '') {
            throw new DnsException('Enter the API key.');
        }

        return (string) ($this->call('get', '/account')['account']['email'] ?? 'API key');
    }

    protected function cursor(string $path, string $key): array
    {
        $rows = [];
        $cursor = null;
        $guard = 0;
        do {
            $json = $this->call('get', $path, array_filter(['per_page' => 500, 'cursor' => $cursor]));
            array_push($rows, ...($json[$key] ?? []));
            $cursor = $json['meta']['links']['next'] ?? null;
        } while ($cursor && ++$guard < 50);

        return $rows;
    }

    public function zones(): array
    {
        return array_map(fn ($d) => ['id' => $d['domain'], 'name' => strtolower($d['domain']), 'manageable' => true, 'note' => null, 'records' => null], $this->cursor('/domains', 'domains'));
    }

    public function records(array $zone): array
    {
        $records = [];
        foreach ($this->cursor('/domains/'.$zone['name'].'/records', 'records') as $r) {
            $type = strtoupper($r['type']);
            if ($type === 'SOA') {
                continue;
            }
            $content = (string) $r['data'];
            if ($type === 'TXT') {
                $content = self::unquote($content);
            }
            $records[] = self::record((string) $r['id'], $type, (string) $r['name'] === '' ? '@' : (string) $r['name'], $content, (int) ($r['ttl'] ?? 300), $type === 'MX' ? (int) ($r['priority'] ?? 0) : null);
        }

        return $records;
    }

    protected function payload(array $record): array
    {
        $type = $record['type'];
        $data = [
            'type' => $type,
            'name' => $record['name'] === '@' ? '' : $record['name'],
            'data' => match ($type) {
                'TXT' => self::quote($record['content']),
                'CAA' => (function () use ($record) {
                    $caa = self::parseCaa($record['content']);

                    return self::caa($caa['flags'], $caa['tag'], $caa['value']);
                })(),
                default => $record['content'],
            },
            'ttl' => max(self::minTtl(), (int) $record['ttl'] > 1 ? (int) $record['ttl'] : 300),
        ];
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
        $data = $this->payload($record);
        unset($data['type']); // the type of a Vultr record cannot change
        $current = collect($this->records($zone))->firstWhere('id', $id);
        if ($current && $current['type'] !== $record['type']) {
            $this->delete($zone, $id);
            $this->create($zone, $record);

            return;
        }
        $this->call('patch', '/domains/'.$zone['name'].'/records/'.$id, $data);
    }

    public function delete(array $zone, string $id): void
    {
        $this->call('delete', '/domains/'.$zone['name'].'/records/'.$id);
    }
}
