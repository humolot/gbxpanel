<?php

namespace App\Services\Databases;

use App\Models\DbServer;
use App\Models\Setting;
use App\Services\Shell;
use App\Services\ShellResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Qdrant vector database over its REST API. The local service (installed from the software
 * catalog) listens on 127.0.0.1:6333 and is protected with an API key kept by the panel.
 */
class QdrantManager
{
    public const CONFIG = '/etc/qdrant/config.yaml';

    public const DISTANCES = ['Cosine', 'Euclid', 'Dot', 'Manhattan'];

    public function installed(): bool
    {
        return Shell::simulating() || Shell::test('test -x /usr/local/bin/qdrant');
    }

    public function apiKey(): ?string
    {
        return Setting::secret('qdrant_api_key');
    }

    public function isPublic(): bool
    {
        return (bool) Setting::get('qdrant_public', false);
    }

    public function baseUrl(?DbServer $server = null): string
    {
        if (! $server) {
            return 'http://127.0.0.1:6333';
        }
        $host = str_starts_with($server->host, 'http') ? rtrim($server->host, '/') : 'http://'.$server->host;

        return preg_match('#:\d+$#', $host) ? $host : $host.':'.$server->port;
    }

    protected function http(?DbServer $server = null, int $timeout = 20): PendingRequest
    {
        $key = $server ? $server->password : $this->apiKey();

        return Http::baseUrl($this->baseUrl($server))->timeout($timeout)->acceptJson()
            ->withHeaders(array_filter(['api-key' => $key]));
    }

    protected function check(Response $response): array
    {
        if ($response->failed()) {
            $message = $response->json('status.error') ?? $response->json('status') ?? $response->body();
            throw new \RuntimeException('Qdrant: '.(is_string($message) ? mb_substr($message, 0, 300) : 'HTTP '.$response->status()));
        }

        return (array) $response->json();
    }

    /* ----------------------------------------------------------- simulated */

    protected function simulated(): array
    {
        return Cache::get('gbx.sim.qdrant', [
            'documents' => ['status' => 'green', 'points_count' => 128430, 'indexed_vectors_count' => 128000, 'segments_count' => 6, 'config' => ['params' => ['vectors' => ['size' => 1536, 'distance' => 'Cosine', 'on_disk' => false]]]],
            'products' => ['status' => 'green', 'points_count' => 9021, 'indexed_vectors_count' => 9021, 'segments_count' => 2, 'config' => ['params' => ['vectors' => ['size' => 768, 'distance' => 'Dot', 'on_disk' => true]]]],
        ]);
    }

    /* -------------------------------------------------------------- server */

    public function overview(?DbServer $server = null): array
    {
        if (Shell::simulating()) {
            return ['connected' => true, 'version' => '1.12.4', 'error' => null];
        }
        try {
            $root = $this->check($this->http($server, 5)->get('/'));

            return ['connected' => true, 'version' => $root['version'] ?? null, 'error' => null];
        } catch (\Throwable $e) {
            return ['connected' => false, 'version' => null, 'error' => $e->getMessage()];
        }
    }

    /** @return list<array{name: string, status: string, points: int, vectors: string, segments: int, on_disk: bool}> */
    public function collections(?DbServer $server = null): array
    {
        $details = [];
        if (Shell::simulating()) {
            $details = $this->simulated();
        } else {
            $names = array_column($this->check($this->http($server)->get('/collections'))['result']['collections'] ?? [], 'name');
            foreach ($names as $name) {
                $details[$name] = $this->check($this->http($server)->get('/collections/'.rawurlencode($name)))['result'] ?? [];
            }
        }

        $rows = [];
        foreach ($details as $name => $info) {
            $vectors = $info['config']['params']['vectors'] ?? [];
            $named = isset($vectors['size']) ? ['' => $vectors] : $vectors;
            $rows[] = [
                'name' => $name,
                'status' => $info['status'] ?? 'unknown',
                'points' => (int) ($info['points_count'] ?? 0),
                'indexed' => (int) ($info['indexed_vectors_count'] ?? 0),
                'segments' => (int) ($info['segments_count'] ?? 0),
                'vectors' => implode(', ', array_map(fn ($k, $v) => ($k !== '' ? $k.': ' : '').($v['size'] ?? '?').' '.($v['distance'] ?? ''), array_keys($named), $named)),
                'on_disk' => (bool) collect($named)->contains(fn ($v) => ! empty($v['on_disk'])),
            ];
        }
        usort($rows, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $rows;
    }

    public static function validCollection(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_\-]{1,120}$/', $name);
    }

    public function info(string $name, ?DbServer $server = null): array
    {
        if (Shell::simulating()) {
            return $this->simulated()[$name] ?? throw new \RuntimeException('Collection not found');
        }

        return $this->check($this->http($server)->get('/collections/'.rawurlencode($name)))['result'] ?? [];
    }

    public function create(string $name, int $size, string $distance, bool $onDisk = false, ?DbServer $server = null): void
    {
        if (! self::validCollection($name) || $size < 1 || $size > 65536 || ! in_array($distance, self::DISTANCES, true)) {
            throw new \InvalidArgumentException('Invalid collection settings.');
        }
        if (Shell::simulating()) {
            $data = $this->simulated();
            $data[$name] = ['status' => 'green', 'points_count' => 0, 'indexed_vectors_count' => 0, 'segments_count' => 2, 'config' => ['params' => ['vectors' => ['size' => $size, 'distance' => $distance, 'on_disk' => $onDisk]]]];
            Cache::put('gbx.sim.qdrant', $data, 86400);

            return;
        }
        $this->check($this->http($server)->put('/collections/'.rawurlencode($name), ['vectors' => ['size' => $size, 'distance' => $distance, 'on_disk' => $onDisk]]));
    }

    public function delete(string $name, ?DbServer $server = null): void
    {
        if (Shell::simulating()) {
            $data = $this->simulated();
            unset($data[$name]);
            Cache::put('gbx.sim.qdrant', $data, 86400);

            return;
        }
        $this->check($this->http($server)->delete('/collections/'.rawurlencode($name)));
    }

    /** First points of a collection with payload (vectors omitted). */
    public function points(string $name, int $limit = 20, mixed $offset = null, ?DbServer $server = null): array
    {
        if (Shell::simulating()) {
            return ['points' => array_map(fn ($i) => ['id' => $i, 'payload' => ['title' => "Document {$i}", 'source' => 'kb/article-'.$i.'.md', 'lang' => 'en']], range(1, min($limit, 5))), 'next' => null];
        }
        $body = array_filter(['limit' => max(1, min(100, $limit)), 'with_payload' => true, 'with_vector' => false, 'offset' => $offset], fn ($v) => $v !== null);
        $result = $this->check($this->http($server)->post('/collections/'.rawurlencode($name).'/points/scroll', $body))['result'] ?? [];

        return ['points' => $result['points'] ?? [], 'next' => $result['next_page_offset'] ?? null];
    }

    /* ----------------------------------------------------------- snapshots */

    public function snapshots(string $name, ?DbServer $server = null): array
    {
        if (Shell::simulating()) {
            return [['name' => $name.'-2026-09-15-02-30-00.snapshot', 'size' => 73741824, 'creation_time' => date('Y-m-d\TH:i:s', time() - 86400)]];
        }

        return $this->check($this->http($server)->get('/collections/'.rawurlencode($name).'/snapshots'))['result'] ?? [];
    }

    public function createSnapshot(string $name, ?DbServer $server = null): array
    {
        if (Shell::simulating()) {
            return ['name' => $name.'-'.date('Y-m-d-H-i-s').'.snapshot'];
        }

        return $this->check($this->http($server, 1800)->post('/collections/'.rawurlencode($name).'/snapshots?wait=true'))['result'] ?? [];
    }

    public static function validSnapshot(string $snapshot): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_\-.]{1,200}\.snapshot$/', $snapshot);
    }

    public function deleteSnapshot(string $name, string $snapshot, ?DbServer $server = null): void
    {
        if (! self::validSnapshot($snapshot) || Shell::simulating()) {
            return;
        }
        $this->check($this->http($server)->delete('/collections/'.rawurlencode($name).'/snapshots/'.rawurlencode($snapshot)));
    }

    /** Streamed download of a snapshot. */
    public function downloadSnapshot(string $name, string $snapshot, ?DbServer $server = null)
    {
        if (! self::validSnapshot($snapshot)) {
            throw new \InvalidArgumentException('Invalid snapshot name.');
        }

        return $this->http($server, 3600)->withOptions(['stream' => true])->get('/collections/'.rawurlencode($name).'/snapshots/'.rawurlencode($snapshot))->toPsrResponse()->getBody();
    }

    /** Recover a collection from an uploaded snapshot file (replaces the collection data). */
    public function restoreSnapshot(string $name, string $path, ?DbServer $server = null): void
    {
        if (! self::validCollection($name)) {
            throw new \InvalidArgumentException('Invalid collection name.');
        }
        if (Shell::simulating()) {
            return;
        }
        $this->check($this->http($server, 3600)->attach('snapshot', fopen($path, 'r'), basename($path))
            ->post('/collections/'.rawurlencode($name).'/snapshots/upload?priority=snapshot&wait=true'));
    }

    /* ------------------------------------------------------- local service */

    public function config(string $apiKey, bool $public): string
    {
        $host = $public ? '0.0.0.0' : '127.0.0.1';

        return <<<YAML
# Managed by GBX Panel (Databases > Qdrant). Manual changes may be overwritten.
log_level: INFO
storage:
  storage_path: /var/lib/qdrant/storage
  snapshots_path: /var/lib/qdrant/snapshots
service:
  host: {$host}
  http_port: 6333
  grpc_port: 6334
  enable_cors: false
  api_key: "{$apiKey}"
telemetry_disabled: true

YAML;
    }

    /** Write the service configuration (new API key and/or public binding) and restart Qdrant. */
    public function applyLocal(?string $apiKey = null, ?bool $public = null): ShellResult
    {
        $apiKey ??= $this->apiKey() ?: Str::random(48);
        $public ??= $this->isPublic();

        if (! Shell::simulating()) {
            Shell::writeFile(self::CONFIG, $this->config($apiKey, $public), '0640', 'root:qdrant')->throw('Unable to write the Qdrant configuration');
            $firewall = $public
                ? 'command -v ufw >/dev/null && ufw status | grep -q "Status: active" && { ufw allow 6333/tcp; ufw allow 6334/tcp; } || true'
                : 'command -v ufw >/dev/null && { ufw delete allow 6333/tcp; ufw delete allow 6334/tcp; } >/dev/null 2>&1 || true';
            $result = Shell::run("systemctl restart qdrant && sleep 2 && systemctl is-active --quiet qdrant || { journalctl -u qdrant -n 20 --no-pager; exit 1; }\n{$firewall}", 60);
            if ($result->failed()) {
                return $result;
            }
        }
        Setting::putSecret('qdrant_api_key', $apiKey);
        Setting::put('qdrant_public', $public);

        return new ShellResult(0, 'ok', '');
    }
}
