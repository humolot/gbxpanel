<?php

namespace App\Services\Dns\Providers;

use App\Services\Dns\DnsException;
use Illuminate\Http\Client\PendingRequest;

/**
 * GoDaddy Domains API v1 (key and secret). GoDaddy only grants DNS API access to accounts that
 * meet its requirements (10+ domains or Discount Domain Club).
 *
 * Records have no ids: they are addressed by type and name, so an id is derived from the record
 * and changes replace the whole type/name set.
 */
class GoDaddyProvider extends Provider
{
    public const API = 'https://api.godaddy.com';

    public const OTE = 'https://api.ote-godaddy.com';

    protected const RATE_PER_MINUTE = 60;

    public static function types(): array
    {
        return ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS'];
    }

    public static function minTtl(): int
    {
        return 600;
    }

    protected function base(): string
    {
        return $this->credential('ote') ? self::OTE : self::API;
    }

    protected function client(): PendingRequest
    {
        return parent::client()->withHeaders(['Authorization' => 'sso-key '.$this->credential('api_key').':'.$this->credential('api_secret')]);
    }

    protected function call(string $method, string $path, array $data = []): mixed
    {
        $url = $this->base().$path;
        $response = $this->send(fn (PendingRequest $c) => match ($method) {
            'get' => $c->get($url, $data),
            'delete' => $c->delete($url),
            default => $c->withBody(json_encode($data), 'application/json')->{$method}($url),
        });
        if ($response->failed()) {
            $message = self::errorOf($response);
            if ($response->status() === 403) {
                $message .= '. GoDaddy restricts API access to eligible accounts.';
            }
            throw new DnsException('GoDaddy: '.$message);
        }

        return $response->json();
    }

    public static function idOf(string $type, string $name, string $data): string
    {
        return substr(sha1(strtoupper($type).'|'.$name.'|'.$data), 0, 16);
    }

    public function verify(): string
    {
        if ($this->credential('api_key') === '' || $this->credential('api_secret') === '') {
            throw new DnsException('Enter the API key and secret.');
        }
        $this->call('get', '/v1/domains', ['limit' => 1]);

        return 'API key';
    }

    public function zones(): array
    {
        $zones = [];
        foreach ($this->call('get', '/v1/domains', ['limit' => 1000, 'statuses' => 'ACTIVE']) ?? [] as $d) {
            $zones[] = ['id' => (string) ($d['domainId'] ?? $d['domain']), 'name' => strtolower($d['domain']), 'manageable' => true, 'note' => null, 'records' => null];
        }

        return $zones;
    }

    protected function normalize(array $r): array
    {
        $type = strtoupper($r['type']);
        $data = (string) ($r['data'] ?? '');
        $name = (string) ($r['name'] ?? '@');

        return self::record(self::idOf($type, $name, $data), $type, $name, $type === 'TXT' ? $data : rtrim($data, '.'), (int) ($r['ttl'] ?? 3600), isset($r['priority']) ? (int) $r['priority'] : null);
    }

    public function records(array $zone): array
    {
        return array_map(fn ($r) => $this->normalize($r), $this->call('get', '/v1/domains/'.$zone['name'].'/records') ?? []);
    }

    protected function item(array $record): array
    {
        $item = ['data' => $record['content'], 'ttl' => max(self::minTtl(), (int) $record['ttl'])];
        if ($record['type'] === 'MX') {
            $item['priority'] = (int) ($record['priority'] ?? 10);
        }

        return $item;
    }

    /** Current records of one type and name, in the API shape. */
    protected function set(array $zone, string $type, string $name): array
    {
        return $this->call('get', '/v1/domains/'.$zone['name'].'/records/'.$type.'/'.rawurlencode($name)) ?? [];
    }

    protected function putSet(array $zone, string $type, string $name, array $items): void
    {
        $path = '/v1/domains/'.$zone['name'].'/records/'.$type.'/'.rawurlencode($name);
        $items ? $this->call('put', $path, array_values($items)) : $this->call('delete', $path);
    }

    public function create(array $zone, array $record): void
    {
        $this->call('patch', '/v1/domains/'.$zone['name'].'/records', [['type' => $record['type'], 'name' => $record['name']] + $this->item($record)]);
    }

    protected function find(array $zone, string $id): array
    {
        foreach ($this->records($zone) as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }
        throw new DnsException('The record no longer exists. Reload the records.');
    }

    public function update(array $zone, string $id, array $record): void
    {
        $old = $this->find($zone, $id);
        if ($old['type'] !== $record['type'] || $old['name'] !== $record['name']) {
            $this->delete($zone, $id);
            $this->create($zone, $record);

            return;
        }
        $items = array_map(fn ($r) => self::idOf($old['type'], $old['name'], (string) $r['data']) === $id ? $this->item($record) : array_intersect_key($r, array_flip(['data', 'ttl', 'priority'])), $this->set($zone, $old['type'], $old['name']));
        $this->putSet($zone, $old['type'], $old['name'], $items);
    }

    public function delete(array $zone, string $id): void
    {
        $old = $this->find($zone, $id);
        $items = [];
        foreach ($this->set($zone, $old['type'], $old['name']) as $r) {
            if (self::idOf($old['type'], $old['name'], (string) $r['data']) !== $id) {
                $items[] = array_intersect_key($r, array_flip(['data', 'ttl', 'priority']));
            }
        }
        $this->putSet($zone, $old['type'], $old['name'], $items);
    }
}
