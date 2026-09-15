<?php

namespace App\Services\Dns\Providers;

use App\Services\Dns\DnsException;
use Illuminate\Http\Client\PendingRequest;

/**
 * Porkbun API v3 (API key and secret key). API access must be enabled on each domain
 * in the Porkbun dashboard.
 */
class PorkbunProvider extends Provider
{
    public const API = 'https://api.porkbun.com/api/json/v3';

    protected const RATE_PER_MINUTE = 60;

    public static function minTtl(): int
    {
        return 600;
    }

    protected function call(string $path, array $data = []): array
    {
        $body = ['apikey' => $this->credential('api_key'), 'secretapikey' => $this->credential('secret_key')] + $data;
        $response = $this->send(fn (PendingRequest $c) => $c->asJson()->post(self::API.$path, $body));
        $json = $response->json() ?? [];
        if ($response->failed() || ($json['status'] ?? '') !== 'SUCCESS') {
            $message = $json['message'] ?? ('HTTP '.$response->status());
            throw new DnsException('Porkbun: '.$message);
        }

        return $json;
    }

    public function verify(): string
    {
        if ($this->credential('api_key') === '' || $this->credential('secret_key') === '') {
            throw new DnsException('Enter the API key and secret key.');
        }
        $json = $this->call('/ping');

        return 'API key (IP '.($json['yourIp'] ?? '?').')';
    }

    public function zones(): array
    {
        $zones = [];
        $start = 0;
        do {
            $rows = $this->call('/domain/listAll', ['start' => $start])['domains'] ?? [];
            foreach ($rows as $d) {
                $zones[] = ['id' => $d['domain'], 'name' => strtolower($d['domain']), 'manageable' => true, 'note' => null, 'records' => null];
            }
            $start += 1000;
        } while (count($rows) === 1000 && $start < 20000);

        return $zones;
    }

    public function records(array $zone): array
    {
        $records = [];
        foreach ($this->call('/dns/retrieve/'.$zone['name'])['records'] ?? [] as $r) {
            $type = strtoupper($r['type']);
            if ($type === 'SOA') {
                continue;
            }
            $records[] = self::record((string) $r['id'], $type, self::relative((string) $r['name'], $zone['name']), (string) $r['content'], (int) ($r['ttl'] ?? 600),
                in_array($type, ['MX', 'SRV'], true) && isset($r['prio']) ? (int) $r['prio'] : null);
        }

        return $records;
    }

    protected function payload(array $record): array
    {
        $data = [
            'name' => $record['name'] === '@' ? '' : $record['name'],
            'type' => $record['type'],
            'content' => $record['content'],
            'ttl' => (string) max(self::minTtl(), (int) $record['ttl']),
        ];
        if ($record['type'] === 'MX') {
            $data['prio'] = (string) ($record['priority'] ?? 10);
        }

        return $data;
    }

    public function create(array $zone, array $record): void
    {
        $this->call('/dns/create/'.$zone['name'], $this->payload($record));
    }

    public function update(array $zone, string $id, array $record): void
    {
        $this->call('/dns/edit/'.$zone['name'].'/'.$id, $this->payload($record));
    }

    public function delete(array $zone, string $id): void
    {
        $this->call('/dns/delete/'.$zone['name'].'/'.$id);
    }
}
