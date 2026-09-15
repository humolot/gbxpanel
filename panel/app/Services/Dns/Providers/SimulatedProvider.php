<?php

namespace App\Services\Dns\Providers;

use App\Services\Dns\DnsException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Local development driver (simulation mode): zones and records kept in the cache. */
class SimulatedProvider extends Provider
{
    public function __construct(array $credentials, bool $rateLimit = false, string $cacheKey = 'dns', protected string $type = 'cloudflare')
    {
        parent::__construct($credentials, $rateLimit, $cacheKey);
    }

    public function verify(): string
    {
        if (in_array('fail', $this->credentials, true)) {
            throw new DnsException('Simulated authentication error (the credentials contain "fail").');
        }

        return 'simulation@'.$this->type;
    }

    protected function key(): string
    {
        return 'dns.sim.'.$this->type.'.'.md5(json_encode($this->credentials));
    }

    protected function data(): array
    {
        return Cache::rememberForever($this->key(), function () {
            $zones = [];
            foreach (['goodbits.tech', 'example.com', 'my-shop.store'] as $i => $name) {
                $zones[$name] = [
                    ['id' => Str::random(12), 'type' => 'A', 'name' => '@', 'content' => '198.74.54.107', 'ttl' => 1, 'priority' => null, 'proxied' => $this->type === 'cloudflare' ? false : null],
                    ['id' => Str::random(12), 'type' => 'CNAME', 'name' => 'www', 'content' => $name, 'ttl' => 1, 'priority' => null, 'proxied' => $this->type === 'cloudflare' ? true : null],
                    ['id' => Str::random(12), 'type' => 'MX', 'name' => '@', 'content' => 'mail.'.$name, 'ttl' => 3600, 'priority' => 10, 'proxied' => null],
                    ['id' => Str::random(12), 'type' => 'TXT', 'name' => '@', 'content' => 'v=spf1 a mx ~all', 'ttl' => 3600, 'priority' => null, 'proxied' => null],
                ];
                if ($i === 0) {
                    $zones[$name][] = ['id' => Str::random(12), 'type' => 'A', 'name' => 'pruebas', 'content' => '198.74.54.107', 'ttl' => 300, 'priority' => null, 'proxied' => $this->type === 'cloudflare' ? false : null];
                }
            }

            return $zones;
        });
    }

    protected function save(array $data): void
    {
        Cache::forever($this->key(), $data);
    }

    public function zones(): array
    {
        return array_map(fn ($name) => ['id' => $name, 'name' => $name, 'manageable' => true, 'note' => null, 'records' => count($this->data()[$name])], array_keys($this->data()));
    }

    public function records(array $zone): array
    {
        return array_values($this->data()[$zone['name']] ?? []);
    }

    public function create(array $zone, array $record): void
    {
        $data = $this->data();
        $data[$zone['name']][] = ['id' => Str::random(12)] + $record;
        $this->save($data);
    }

    public function update(array $zone, string $id, array $record): void
    {
        $data = $this->data();
        foreach ($data[$zone['name']] ?? [] as $i => $r) {
            if ($r['id'] === $id) {
                $data[$zone['name']][$i] = ['id' => $id] + $record;
                $this->save($data);

                return;
            }
        }
        throw new DnsException('The record no longer exists.');
    }

    public function delete(array $zone, string $id): void
    {
        $data = $this->data();
        $data[$zone['name']] = array_values(array_filter($data[$zone['name']] ?? [], fn ($r) => $r['id'] !== $id));
        $this->save($data);
    }
}
