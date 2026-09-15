<?php

namespace App\Services\Databases;

use App\Models\DbServer;
use App\Models\Setting;
use App\Models\Task;
use App\Services\Shell;
use App\Services\TaskRunner;
use Illuminate\Support\Facades\Cache;

/**
 * Redis administration: keyspace, key browser, values, configuration and RDB backups.
 * The panel talks RESP directly (RespClient); the local password is stored encrypted.
 */
class RedisManager
{
    public const TYPES = ['string', 'hash', 'list', 'set', 'zset'];

    public function installed(): bool
    {
        return Shell::simulating() || Shell::commandExists('redis-server');
    }

    public function client(?DbServer $server = null): RespClient
    {
        return $server
            ? new RespClient($server->host, $server->port, $server->password, $server->username ?: null)
            : new RespClient('127.0.0.1', 6379, Setting::secret('redis_password'));
    }

    protected function simulated(): array
    {
        return Cache::get('gbx.sim.redis', [
            0 => [
                'session:9f2c1' => ['type' => 'string', 'ttl' => 7200, 'value' => '{"user_id":42,"cart":[1,5]}'],
                'config:site' => ['type' => 'hash', 'ttl' => -1, 'value' => ['name' => 'Shop', 'theme' => 'dark']],
                'queue:emails' => ['type' => 'list', 'ttl' => -1, 'value' => ['welcome:42', 'invoice:1180']],
                'leaderboard' => ['type' => 'zset', 'ttl' => -1, 'value' => ['alice' => '320', 'bob' => '250']],
            ],
            1 => ['cache:home' => ['type' => 'string', 'ttl' => 300, 'value' => '<html>...</html>']],
        ]);
    }

    protected function saveSimulated(array $data): void
    {
        Cache::put('gbx.sim.redis', $data, 86400);
    }

    /** Server info, keyspace and the settings shown on the Redis tab. */
    public function overview(?DbServer $server = null): array
    {
        if (Shell::simulating()) {
            $data = $this->simulated();
            $keyspace = [];
            for ($db = 0; $db < 16; $db++) {
                $keyspace[$db] = ['keys' => count($data[$db] ?? []), 'expires' => count(array_filter($data[$db] ?? [], fn ($k) => $k['ttl'] > 0))];
            }

            return ['connected' => true, 'version' => '7.2.5', 'uptime' => 345600, 'memory' => '2.31M', 'peak' => '3.02M', 'clients' => 4, 'role' => 'master',
                'ops' => 12, 'hits' => 18420, 'misses' => 312, 'keyspace' => $keyspace, 'databases' => 16,
                'config' => ['maxmemory' => '0', 'maxmemory-policy' => 'noeviction', 'appendonly' => 'no', 'save' => '3600 1 300 100 60 10000', 'requirepass' => (bool) Setting::secret('redis_password')], 'error' => null];
        }

        try {
            $redis = $this->client($server)->connect();
            $info = RespClient::parseInfo((string) $redis->command('INFO'));
            $databases = 16;
            $config = [];
            try {
                foreach (['maxmemory', 'maxmemory-policy', 'appendonly', 'save', 'databases'] as $key) {
                    $pair = $redis->command('CONFIG', 'GET', $key);
                    $config[$key] = $pair[1] ?? null;
                }
                $databases = (int) ($config['databases'] ?? 16) ?: 16;
                $pass = $redis->command('CONFIG', 'GET', 'requirepass');
                $config['requirepass'] = ($pass[1] ?? '') !== '';
            } catch (\RuntimeException) {
                $config['disabled'] = true; // CONFIG renamed or ACL restricted
            }
            $keyspace = [];
            for ($db = 0; $db < $databases; $db++) {
                preg_match('/keys=(\d+),expires=(\d+)/', $info['keyspace']['db'.$db] ?? '', $m);
                $keyspace[$db] = ['keys' => (int) ($m[1] ?? 0), 'expires' => (int) ($m[2] ?? 0)];
            }

            return [
                'connected' => true,
                'version' => $info['server']['redis_version'] ?? null,
                'uptime' => (int) ($info['server']['uptime_in_seconds'] ?? 0),
                'memory' => $info['memory']['used_memory_human'] ?? null,
                'peak' => $info['memory']['used_memory_peak_human'] ?? null,
                'clients' => (int) ($info['clients']['connected_clients'] ?? 0),
                'role' => $info['replication']['role'] ?? null,
                'ops' => (int) ($info['stats']['instantaneous_ops_per_sec'] ?? 0),
                'hits' => (int) ($info['stats']['keyspace_hits'] ?? 0),
                'misses' => (int) ($info['stats']['keyspace_misses'] ?? 0),
                'keyspace' => $keyspace,
                'databases' => $databases,
                'config' => $config,
                'error' => null,
            ];
        } catch (\RuntimeException $e) {
            return ['connected' => false, 'error' => $e->getMessage(), 'auth' => str_contains($e->getMessage(), 'NOAUTH') || str_contains($e->getMessage(), 'WRONGPASS') || str_contains($e->getMessage(), 'invalid password')];
        }
    }

    protected function selected(int $db, ?DbServer $server): RespClient
    {
        $redis = $this->client($server)->connect();
        $redis->command('SELECT', (string) $db);

        return $redis;
    }

    /** @return array{keys: list<array>, cursor: string} */
    public function keys(int $db, string $pattern = '*', string $cursor = '0', ?DbServer $server = null, int $limit = 100): array
    {
        $pattern = $pattern === '' ? '*' : $pattern;
        if (Shell::simulating()) {
            $rows = [];
            foreach ($this->simulated()[$db] ?? [] as $key => $item) {
                if (fnmatch($pattern, $key)) {
                    $rows[] = ['key' => $key, 'type' => $item['type'], 'ttl' => $item['ttl'], 'size' => strlen(json_encode($item['value']))];
                }
            }

            return ['keys' => $rows, 'cursor' => '0'];
        }

        $redis = $this->selected($db, $server);
        $found = [];
        $rounds = 0;
        do {
            [$cursor, $batch] = $redis->command('SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '500');
            foreach ($batch as $key) {
                $found[$key] = true;
            }
        } while ($cursor !== '0' && count($found) < $limit && ++$rounds < 50);

        $rows = [];
        foreach (array_slice(array_keys($found), 0, $limit) as $key) {
            $size = null;
            try {
                $size = $redis->command('MEMORY', 'USAGE', (string) $key);
            } catch (\RuntimeException) {
            }
            $rows[] = ['key' => (string) $key, 'type' => $redis->command('TYPE', (string) $key), 'ttl' => $redis->command('TTL', (string) $key), 'size' => $size];
        }
        usort($rows, fn ($a, $b) => strcmp($a['key'], $b['key']));

        return ['keys' => $rows, 'cursor' => (string) $cursor];
    }

    /** Value of a key (collections are limited to the first 500 items). */
    public function get(int $db, string $key, ?DbServer $server = null): array
    {
        if (Shell::simulating()) {
            $item = $this->simulated()[$db][$key] ?? throw new \RuntimeException('Key not found');

            return ['key' => $key, 'type' => $item['type'], 'ttl' => $item['ttl'], 'value' => $item['value'], 'length' => is_array($item['value']) ? count($item['value']) : strlen($item['value'])];
        }

        $redis = $this->selected($db, $server);
        $type = $redis->command('TYPE', $key);
        $ttl = $redis->command('TTL', $key);
        [$value, $length] = match ($type) {
            'string' => [$redis->command('GET', $key), $redis->command('STRLEN', $key)],
            'hash' => [self::pairs($redis->command('HSCAN', $key, '0', 'COUNT', '500')[1]), $redis->command('HLEN', $key)],
            'list' => [$redis->command('LRANGE', $key, '0', '499'), $redis->command('LLEN', $key)],
            'set' => [$redis->command('SSCAN', $key, '0', 'COUNT', '500')[1], $redis->command('SCARD', $key)],
            'zset' => [self::pairs($redis->command('ZRANGE', $key, '0', '499', 'WITHSCORES')), $redis->command('ZCARD', $key)],
            'none' => throw new \RuntimeException('Key not found'),
            default => [null, null],
        };
        if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
            $value = '(binary, '.strlen($value).' bytes) '.bin2hex(substr($value, 0, 64));
        }

        return ['key' => $key, 'type' => $type, 'ttl' => $ttl, 'value' => $value, 'length' => $length];
    }

    protected static function pairs(array $flat): array
    {
        $out = [];
        for ($i = 0; $i + 1 < count($flat); $i += 2) {
            $out[(string) $flat[$i]] = $flat[$i + 1];
        }

        return $out;
    }

    /**
     * Create or replace a key. For collections $value is one item per line:
     * hash "field=value", zset "score member", list and set "item".
     */
    public function set(int $db, string $key, string $type, string $value, int $ttl = 0, ?DbServer $server = null): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported type.');
        }
        $lines = array_values(array_filter(preg_split('/\r?\n/', $value), fn ($l) => $l !== ''));

        $parsed = match ($type) {
            'string' => $value,
            'hash' => collect($lines)->mapWithKeys(function ($l) {
                if (! str_contains($l, '=')) {
                    throw new \InvalidArgumentException("Use field=value per line ({$l}).");
                }
                [$f, $v] = explode('=', $l, 2);

                return [$f => $v];
            })->all(),
            'zset' => collect($lines)->mapWithKeys(function ($l) {
                if (! preg_match('/^(-?\d+(?:\.\d+)?)\s+(.+)$/', $l, $m)) {
                    throw new \InvalidArgumentException("Use \"score member\" per line ({$l}).");
                }

                return [$m[2] => $m[1]];
            })->all(),
            default => $lines,
        };
        if ($type !== 'string' && ! $parsed) {
            throw new \InvalidArgumentException('Add at least one item.');
        }

        if (Shell::simulating()) {
            $data = $this->simulated();
            $data[$db][$key] = ['type' => $type, 'ttl' => $ttl > 0 ? $ttl : -1, 'value' => $parsed];
            $this->saveSimulated($data);

            return;
        }

        $redis = $this->selected($db, $server);
        $redis->command('DEL', $key);
        match ($type) {
            'string' => $redis->command('SET', $key, $parsed),
            'hash' => $redis->command('HSET', $key, ...array_merge(...array_map(fn ($f, $v) => [(string) $f, (string) $v], array_keys($parsed), $parsed))),
            'list' => $redis->command('RPUSH', $key, ...$parsed),
            'set' => $redis->command('SADD', $key, ...$parsed),
            'zset' => $redis->command('ZADD', $key, ...array_merge(...array_map(fn ($m, $s) => [(string) $s, (string) $m], array_keys($parsed), $parsed))),
        };
        if ($ttl > 0) {
            $redis->command('EXPIRE', $key, (string) $ttl);
        }
    }

    public function delete(int $db, array $keys, ?DbServer $server = null): int
    {
        if (Shell::simulating()) {
            $data = $this->simulated();
            $before = count($data[$db] ?? []);
            $data[$db] = array_diff_key($data[$db] ?? [], array_flip($keys));
            $this->saveSimulated($data);

            return $before - count($data[$db]);
        }

        return $keys ? (int) $this->selected($db, $server)->command('DEL', ...$keys) : 0;
    }

    public function expire(int $db, string $key, int $ttl, ?DbServer $server = null): void
    {
        if (Shell::simulating()) {
            $data = $this->simulated();
            if (isset($data[$db][$key])) {
                $data[$db][$key]['ttl'] = $ttl > 0 ? $ttl : -1;
                $this->saveSimulated($data);
            }

            return;
        }
        $redis = $this->selected($db, $server);
        $ttl > 0 ? $redis->command('EXPIRE', $key, (string) $ttl) : $redis->command('PERSIST', $key);
    }

    public function flush(int $db, ?DbServer $server = null): void
    {
        if (Shell::simulating()) {
            $data = $this->simulated();
            $data[$db] = [];
            $this->saveSimulated($data);

            return;
        }
        $this->selected($db, $server)->command('FLUSHDB');
    }

    /** Apply maxmemory, eviction policy, persistence and password, then persist with CONFIG REWRITE. */
    public function configure(array $settings, ?DbServer $server = null): void
    {
        if (Shell::simulating()) {
            if (array_key_exists('requirepass', $settings) && ! $server) {
                Setting::putSecret('redis_password', $settings['requirepass'] ?: null);
            }

            return;
        }
        $redis = $this->client($server)->connect();
        foreach (['maxmemory', 'maxmemory-policy', 'appendonly'] as $key) {
            if (isset($settings[$key])) {
                $redis->command('CONFIG', 'SET', $key, (string) $settings[$key]);
            }
        }
        if (array_key_exists('requirepass', $settings)) {
            $redis->command('CONFIG', 'SET', 'requirepass', (string) $settings['requirepass']);
            if (! $server) {
                Setting::putSecret('redis_password', $settings['requirepass'] ?: null);
            }
        }
        try {
            $this->client($server ? tap(clone $server, fn ($s) => $s->password = $settings['requirepass'] ?? $server->password) : null)->connect()->command('CONFIG', 'REWRITE');
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('Settings applied but not saved to redis.conf: '.$e->getMessage());
        }
    }

    /* ------------------------------------------------------------ backups */

    public function backupDir(): string
    {
        return rtrim(config('gbx.paths.backup'), '/').'/database/redis';
    }

    public function backups(): array
    {
        if (Shell::simulating()) {
            return [['name' => 'redis_'.date('Ymd', time() - 86400).'_023000.rdb', 'size' => 1048576, 'time' => time() - 86400]];
        }
        $out = Shell::out('find '.Shell::arg($this->backupDir())." -maxdepth 1 -type f -name 'redis_*.rdb' -printf '%f\\t%s\\t%T@\\n' 2>/dev/null | sort -t\$'\\t' -k3 -rn", 15);
        $rows = [];
        foreach (array_filter(explode("\n", $out)) as $line) {
            [$n, $s, $t] = array_pad(explode("\t", $line), 3, 0);
            $rows[] = ['name' => $n, 'size' => (int) $s, 'time' => (int) $t];
        }

        return $rows;
    }

    public function backupPath(string $file): string
    {
        if (! preg_match('/^redis_\d{8}_\d{6}\.rdb$/', $file)) {
            throw new \InvalidArgumentException('Invalid backup file.');
        }

        return $this->backupDir().'/'.$file;
    }

    /** Snapshot with BGSAVE and copy the RDB file (local server). */
    public function backup(): Task
    {
        $target = $this->backupDir().'/redis_'.date('Ymd_His').'.rdb';
        if (! Shell::simulating()) {
            $redis = $this->client()->connect();
            $last = $redis->command('LASTSAVE');
            $redis->command('BGSAVE');
            for ($i = 0; $i < 120 && $redis->command('LASTSAVE') === $last; $i++) {
                usleep(500000);
            }
            $dir = $redis->command('CONFIG', 'GET', 'dir')[1] ?? '/var/lib/redis';
            $file = $redis->command('CONFIG', 'GET', 'dbfilename')[1] ?? 'dump.rdb';
            $source = rtrim($dir, '/').'/'.$file;
        } else {
            $source = '/var/lib/redis/dump.rdb';
        }

        return TaskRunner::dispatch('Backup Redis', "set -e\nmkdir -p ".Shell::arg($this->backupDir())."\ncp ".Shell::arg($source).' '.Shell::arg($target)."\nls -lh ".Shell::arg($target), 'database');
    }

    /** Replace the dataset with a backup: stop Redis, copy the RDB over the data file, start. */
    public function restore(string $file): Task
    {
        $path = $this->backupPath($file);
        $source = '/var/lib/redis/dump.rdb';
        $aof = false;
        if (! Shell::simulating()) {
            $redis = $this->client()->connect();
            $source = rtrim($redis->command('CONFIG', 'GET', 'dir')[1] ?? '/var/lib/redis', '/').'/'.($redis->command('CONFIG', 'GET', 'dbfilename')[1] ?? 'dump.rdb');
            $aof = ($redis->command('CONFIG', 'GET', 'appendonly')[1] ?? 'no') === 'yes';
        }
        if ($aof) {
            throw new \RuntimeException('Append-only persistence is enabled; Redis would ignore the RDB file. Disable appendonly first.');
        }

        return TaskRunner::dispatch('Restore Redis '.$file, "set -e\nsystemctl stop redis-server\ncp ".Shell::arg($path).' '.Shell::arg($source)."\nchown redis:redis ".Shell::arg($source)."\nsystemctl start redis-server\necho 'Redis restored from {$file}'", 'database');
    }
}
