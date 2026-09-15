<?php

namespace App\Services\Dns\Providers;

use App\Services\Dns\DnsException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Cloudflare API v4. Authentication with an API token (Zone:DNS:Edit and Zone:Zone:Read)
 * or with the account e-mail and the Global API Key.
 */
class CloudflareProvider extends Provider
{
    public const API = 'https://api.cloudflare.com/client/v4';

    protected const RATE_PER_MINUTE = 200;

    public static function types(): array
    {
        return ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'CAA', 'NS'];
    }

    protected function client(): PendingRequest
    {
        $client = parent::client();
        $token = $this->credential('api_token');
        if ($token !== '') {
            return $client->withToken($token);
        }

        return $client->withHeaders(['X-Auth-Email' => $this->credential('email'), 'X-Auth-Key' => $this->credential('api_key')]);
    }

    protected function call(string $method, string $path, array $data = []): array
    {
        $response = $this->send(fn (PendingRequest $c) => $method === 'get' ? $c->get(self::API.$path, $data) : $c->{$method}(self::API.$path, $data));
        $json = $response->json();
        if ($response->failed() || ! ($json['success'] ?? false)) {
            throw new DnsException('Cloudflare: '.$this->message($response));
        }

        return $json;
    }

    protected function message(Response $response): string
    {
        $errors = $response->json('errors') ?? [];
        $text = implode('; ', array_map(fn ($e) => ($e['message'] ?? 'error').(isset($e['code']) ? ' ['.$e['code'].']' : ''), $errors));

        return ($text ?: 'request failed').' (HTTP '.$response->status().')';
    }

    public function verify(): string
    {
        if ($this->credential('api_token') !== '') {
            $json = $this->call('get', '/user/tokens/verify');
            if (($json['result']['status'] ?? '') !== 'active') {
                throw new DnsException('Cloudflare: the API token is not active.');
            }

            return 'API token';
        }
        if ($this->credential('email') === '' || $this->credential('api_key') === '') {
            throw new DnsException('Enter an API token, or the account e-mail and the Global API Key.');
        }

        return (string) ($this->call('get', '/user')['result']['email'] ?? 'Global API Key');
    }

    public function zones(): array
    {
        $zones = [];
        $page = 1;
        do {
            $json = $this->call('get', '/zones', ['per_page' => 50, 'page' => $page]);
            foreach ($json['result'] ?? [] as $z) {
                $active = ($z['status'] ?? 'active') === 'active';
                $zones[] = ['id' => (string) $z['id'], 'name' => strtolower($z['name']), 'manageable' => true, 'note' => $active ? null : 'Zone status: '.$z['status'], 'records' => null];
            }
            $pages = (int) ($json['result_info']['total_pages'] ?? 1);
        } while ($page++ < $pages && $page <= 100);

        return $zones;
    }

    public function records(array $zone): array
    {
        $records = [];
        $page = 1;
        do {
            $json = $this->call('get', '/zones/'.$zone['id'].'/dns_records', ['per_page' => 500, 'page' => $page]);
            foreach ($json['result'] ?? [] as $r) {
                $type = strtoupper($r['type']);
                $content = (string) ($r['content'] ?? '');
                if ($type === 'TXT') {
                    $content = self::unquote($content);
                } elseif ($type === 'CAA' && isset($r['data']['tag'])) {
                    $content = self::caa((int) ($r['data']['flags'] ?? 0), $r['data']['tag'], (string) $r['data']['value']);
                }
                $records[] = self::record((string) $r['id'], $type, self::relative($r['name'], $zone['name']), $content, (int) ($r['ttl'] ?? 1), isset($r['priority']) ? (int) $r['priority'] : null,
                    in_array($type, ['A', 'AAAA', 'CNAME'], true) ? (bool) ($r['proxied'] ?? false) : null);
            }
            $pages = (int) ($json['result_info']['total_pages'] ?? 1);
        } while ($page++ < $pages && $page <= 100);

        return $records;
    }

    protected function payload(array $zone, array $record): array
    {
        $type = $record['type'];
        $data = [
            'type' => $type,
            'name' => self::fqdn($record['name'], $zone['name']),
            'ttl' => $record['ttl'] <= 1 ? 1 : max(60, (int) $record['ttl']),
        ];
        if ($type === 'CAA') {
            $data['data'] = self::parseCaa($record['content']);
        } else {
            $data['content'] = $type === 'TXT' ? self::quote($record['content']) : $record['content'];
        }
        if ($type === 'MX') {
            $data['priority'] = (int) ($record['priority'] ?? 10);
        }
        if (in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
            $data['proxied'] = (bool) ($record['proxied'] ?? false);
            if ($data['proxied']) {
                $data['ttl'] = 1; // proxied records always use automatic TTL
            }
        }

        return $data;
    }

    public function create(array $zone, array $record): void
    {
        $this->call('post', '/zones/'.$zone['id'].'/dns_records', $this->payload($zone, $record));
    }

    public function update(array $zone, string $id, array $record): void
    {
        $this->call('put', '/zones/'.$zone['id'].'/dns_records/'.$id, $this->payload($zone, $record));
    }

    public function delete(array $zone, string $id): void
    {
        $this->call('delete', '/zones/'.$zone['id'].'/dns_records/'.$id);
    }
}
