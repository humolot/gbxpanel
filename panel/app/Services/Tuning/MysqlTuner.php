<?php

namespace App\Services\Tuning;

use App\Services\MysqlManager;
use App\Services\Shell;
use App\Services\ShellResult;
use App\Services\SystemStats;

/**
 * MySQL and MariaDB.
 *
 * The panel never edits the files of the distribution: it writes its own include file, which both
 * servers read, so an update of the package cannot lose the settings and removing the file brings
 * the defaults back. Values that MySQL accepts while it runs are applied live, and only the rest
 * asks for a restart.
 */
class MysqlTuner extends Tuner
{
    public const FILE = '/etc/mysql/conf.d/gbx-tuning.cnf';

    public function __construct(protected MysqlManager $mysql) {}

    public function key(): string
    {
        return 'mysql';
    }

    public function label(): string
    {
        return 'MySQL / MariaDB';
    }

    public function installed(): bool
    {
        return $this->mysql->installed();
    }

    public function file(): string
    {
        return self::FILE;
    }

    /** "live" marks the settings that can be changed without restarting the server. */
    public function fields(): array
    {
        return [
            'innodb_buffer_pool_size' => ['label' => 'InnoDB buffer pool', 'type' => 'size', 'group' => 'Memory', 'live' => true, 'hint' => 'The most important setting: data and indexes are cached here. Ideally larger than the data of all databases together.'],
            'innodb_log_file_size' => ['label' => 'InnoDB log file', 'type' => 'size', 'group' => 'Memory', 'hint' => 'Larger values take more writes before flushing to disk. About one eighth of the buffer pool.'],
            'key_buffer_size' => ['label' => 'MyISAM key buffer', 'type' => 'size', 'group' => 'Memory', 'live' => true, 'hint' => 'Only used by MyISAM tables; most applications use InnoDB today.'],
            'tmp_table_size' => ['label' => 'Temporary table size', 'type' => 'size', 'group' => 'Memory', 'live' => true],
            'max_heap_table_size' => ['label' => 'Memory table limit', 'type' => 'size', 'group' => 'Memory', 'live' => true, 'hint' => 'Keep it equal to the temporary table size, otherwise the smaller one wins.'],

            'max_connections' => ['label' => 'Max connections', 'type' => 'number', 'group' => 'Connections', 'min' => 10, 'max' => 5000, 'live' => true, 'hint' => 'Each connection also uses the buffers below, so a very high number costs memory.'],
            'thread_cache_size' => ['label' => 'Thread cache', 'type' => 'number', 'group' => 'Connections', 'min' => 0, 'max' => 1000, 'live' => true],
            'table_open_cache' => ['label' => 'Open tables cache', 'type' => 'number', 'group' => 'Connections', 'min' => 100, 'max' => 100000, 'live' => true],
            'skip_name_resolve' => ['label' => 'Skip host name lookups', 'type' => 'bool', 'group' => 'Connections', 'hint' => 'Avoids a DNS lookup per connection. Grants must then use addresses instead of host names.'],

            'sort_buffer_size' => ['label' => 'Sort buffer (per connection)', 'type' => 'size', 'group' => 'Per connection', 'live' => true],
            'read_buffer_size' => ['label' => 'Read buffer (per connection)', 'type' => 'size', 'group' => 'Per connection', 'live' => true],
            'read_rnd_buffer_size' => ['label' => 'Random read buffer', 'type' => 'size', 'group' => 'Per connection', 'live' => true],
            'join_buffer_size' => ['label' => 'Join buffer', 'type' => 'size', 'group' => 'Per connection', 'live' => true, 'hint' => 'Large per connection buffers are a common cause of memory problems; keep them small.'],

            'innodb_flush_log_at_trx_commit' => ['label' => 'Write safety', 'type' => 'select', 'group' => 'Writes', 'live' => true, 'options' => [
                '1' => '1 - every transaction goes to disk (safest)',
                '2' => '2 - once per second (faster, can lose the last second on a power cut)',
                '0' => '0 - once per second, even on a clean shutdown (fastest, least safe)',
            ]],
            'innodb_flush_method' => ['label' => 'Flush method', 'type' => 'select', 'group' => 'Writes', 'options' => ['O_DIRECT' => 'O_DIRECT (recommended)', 'fsync' => 'fsync']],
            'innodb_io_capacity' => ['label' => 'Disk operations per second', 'type' => 'number', 'group' => 'Writes', 'min' => 100, 'max' => 100000, 'live' => true, 'hint' => 'About 200 for a hard disk, 2000 or more for SSD and NVMe.'],

            'slow_query_log' => ['label' => 'Slow query log', 'type' => 'bool', 'group' => 'Diagnostics', 'live' => true, 'hint' => 'Records the queries that make a site slow.'],
            'long_query_time' => ['label' => 'Slow after (seconds)', 'type' => 'number', 'group' => 'Diagnostics', 'min' => 1, 'max' => 60, 'live' => true],
        ];
    }

    protected function liveKeys(): array
    {
        return array_keys(array_filter($this->fields(), fn ($f) => ! empty($f['live'])));
    }

    /** Values MySQL is running with right now. */
    public function values(): array
    {
        if (Shell::simulating()) {
            return [
                'innodb_buffer_pool_size' => '128M', 'innodb_log_file_size' => '48M', 'key_buffer_size' => '8M',
                'tmp_table_size' => '16M', 'max_heap_table_size' => '16M', 'max_connections' => '151', 'thread_cache_size' => '9',
                'table_open_cache' => '4000', 'skip_name_resolve' => '0', 'sort_buffer_size' => '256K', 'read_buffer_size' => '128K',
                'read_rnd_buffer_size' => '256K', 'join_buffer_size' => '256K', 'innodb_flush_log_at_trx_commit' => '1',
                'innodb_flush_method' => 'fsync', 'innodb_io_capacity' => '200', 'slow_query_log' => '0', 'long_query_time' => '10',
            ];
        }

        $keys = array_keys($this->fields());
        $result = $this->mysql->query("SHOW GLOBAL VARIABLES WHERE Variable_name IN ('".implode("','", $keys)."');", 30);
        $values = [];
        foreach ($result->lines() as $line) {
            [$name, $value] = array_pad(explode("\t", $line, 2), 2, '');
            $field = $this->fields()[$name] ?? null;
            if (! $field) {
                continue;
            }
            $values[$name] = match ($field['type']) {
                'size' => self::mb(max(1, (int) round(((int) $value) / 1048576))),
                'bool' => in_array(strtoupper($value), ['ON', '1'], true) ? '1' : '0',
                'number' => (string) (int) $value,
                default => trim($value),
            };
        }
        // sizes below one megabyte read better in kilobytes
        foreach (['sort_buffer_size', 'read_buffer_size', 'read_rnd_buffer_size', 'join_buffer_size'] as $key) {
            if (isset($values[$key]) && $values[$key] === '1M') {
                $values[$key] = '1M';
            }
        }

        return $values;
    }

    public function suggest(int $ramMb): array
    {
        // the server also runs PHP and the web server, so MySQL gets about a third of the memory
        $pool = self::clamp($ramMb * 0.35, 128, 64 * 1024);
        $connections = self::clamp($ramMb / 32, 50, 500);

        return [
            'innodb_buffer_pool_size' => self::mb($pool),
            'innodb_log_file_size' => self::mb(self::clamp($pool / 8, 64, 2048)),
            'key_buffer_size' => self::mb(self::clamp($ramMb / 128, 8, 64)),
            'tmp_table_size' => self::mb(self::clamp($ramMb / 64, 16, 256)),
            'max_heap_table_size' => self::mb(self::clamp($ramMb / 64, 16, 256)),
            'max_connections' => (string) $connections,
            'thread_cache_size' => (string) self::clamp($connections / 4, 8, 128),
            'table_open_cache' => (string) ($ramMb >= 8192 ? 4000 : 2000),
            'skip_name_resolve' => '1',
            'sort_buffer_size' => '2M',
            'read_buffer_size' => '1M',
            'read_rnd_buffer_size' => '1M',
            'join_buffer_size' => '1M',
            'innodb_flush_log_at_trx_commit' => '1',
            'innodb_flush_method' => 'O_DIRECT',
            'innodb_io_capacity' => '2000',
            'slow_query_log' => '1',
            'long_query_time' => '2',
        ];
    }

    /**
     * Save to the include file and, where MySQL allows it, apply without a restart.
     */
    public function apply(array $values): ShellResult
    {
        $values = $this->validate($values);
        if (isset($values['tmp_table_size'], $values['max_heap_table_size']) && self::toMb($values['tmp_table_size']) !== self::toMb($values['max_heap_table_size'])) {
            $values['max_heap_table_size'] = $values['tmp_table_size'];
        }

        $current = Shell::simulating() ? [] : $this->readIni(self::FILE, array_keys($this->fields()));
        $merged = array_filter(array_merge(array_filter($current, fn ($v) => $v !== ''), $values), fn ($v) => $v !== '');

        $lines = ["# Written by GBX Panel. Delete this file to go back to the defaults of the package.\n[mysqld]"];
        foreach ($merged as $key => $value) {
            $field = $this->fields()[$key] ?? null;
            $lines[] = $key.' = '.($field && $field['type'] === 'bool' ? ($value === '1' ? 'ON' : 'OFF') : $value);
        }
        $content = implode("\n", $lines)."\n";

        if (Shell::simulating()) {
            return new ShellResult(0, $content, '');
        }

        $this->backup(self::FILE);
        $write = Shell::writeFile(self::FILE, $content, '0644', 'root:root');
        if ($write->failed()) {
            return $write;
        }

        // apply what can be changed live, so most settings take effect without stopping the databases
        $restartNeeded = [];
        foreach ($values as $key => $value) {
            $field = $this->fields()[$key];
            if (empty($field['live'])) {
                $restartNeeded[] = $key;

                continue;
            }
            $sql = $field['type'] === 'bool'
                ? "SET GLOBAL {$key} = ".($value === '1' ? '1' : '0').';'
                : ($field['type'] === 'size' ? "SET GLOBAL {$key} = ".(self::toMb($value) * 1048576).';' : "SET GLOBAL {$key} = ".(is_numeric($value) ? $value : "'".addslashes($value)."'").';');
            if ($this->mysql->query($sql, 30)->failed()) {
                $restartNeeded[] = $key;
            }
        }

        $message = $restartNeeded
            ? 'Saved. These need a restart of the database to take effect: '.implode(', ', array_unique($restartNeeded)).'.'
            : 'Saved and applied without restarting the database.';

        return new ShellResult(0, $message, '');
    }

    public function restart(): ShellResult
    {
        $service = $this->mysql->service();
        $result = Shell::run('systemctl restart '.Shell::arg($service), 180);
        if ($result->failed()) {
            // a value the server refuses keeps it down: remove the file and start again
            Shell::run('mv -f '.Shell::arg(self::FILE).' '.Shell::arg(self::FILE.'.refused').' && systemctl restart '.Shell::arg($service), 180);

            return new ShellResult(1, '', 'The database refused the new settings and was started with the previous ones. The rejected file is kept at '.self::FILE.'.refused: '.$result->message());
        }

        return $result;
    }

    /* =============================================================== status */

    public function status(): array
    {
        if (Shell::simulating()) {
            return [
                'rows' => [
                    ['label' => 'Version', 'value' => '8.0.45'],
                    ['label' => 'Running for', 'value' => '6 days'],
                    ['label' => 'Queries per second', 'value' => '24.8'],
                    ['label' => 'Connections in use', 'value' => '12 of 151 (peak 48)'],
                    ['label' => 'Buffer pool', 'value' => '128 MB for 2.1 GB of data'],
                    ['label' => 'Buffer pool hit rate', 'value' => '97.4%'],
                    ['label' => 'Slow queries', 'value' => '184'],
                ],
                'advice' => [
                    ['level' => 'warning', 'text' => 'The buffer pool (128 MB) is smaller than the data (2.1 GB), so MySQL keeps reading from disk. Raising it is the change that helps the most.'],
                    ['level' => 'info', 'text' => 'The slow query log is off; turning it on shows which queries make the sites slow.'],
                ],
            ];
        }

        $status = $this->pairs('SHOW GLOBAL STATUS;');
        $variables = $this->pairs('SHOW GLOBAL VARIABLES;');
        if (! $status) {
            return ['rows' => [['label' => 'Status', 'value' => 'The database did not answer.']], 'advice' => []];
        }

        $uptime = max(1, (int) ($status['Uptime'] ?? 1));
        $queries = (int) ($status['Questions'] ?? 0);
        $poolMb = (int) round(((int) ($variables['innodb_buffer_pool_size'] ?? 0)) / 1048576);
        $dataMb = (int) round(((int) $this->mysql->query('SELECT IFNULL(SUM(DATA_LENGTH + INDEX_LENGTH), 0) FROM information_schema.TABLES WHERE ENGINE = \'InnoDB\';', 30)->output) / 1048576);
        $reads = (int) ($status['Innodb_buffer_pool_read_requests'] ?? 0);
        $diskReads = (int) ($status['Innodb_buffer_pool_reads'] ?? 0);
        $hit = $reads > 0 ? round(($reads - $diskReads) / $reads * 100, 2) : 100.0;
        $maxConnections = (int) ($variables['max_connections'] ?? 0);
        $maxUsed = (int) ($status['Max_used_connections'] ?? 0);
        $slow = (int) ($status['Slow_queries'] ?? 0);

        $rows = [
            ['label' => 'Version', 'value' => (string) $this->mysql->version()],
            ['label' => 'Running for', 'value' => SystemStats::humanDuration($uptime)],
            ['label' => 'Queries per second', 'value' => (string) round($queries / $uptime, 1)],
            ['label' => 'Connections in use', 'value' => ($status['Threads_connected'] ?? '0').' of '.$maxConnections.' (peak '.$maxUsed.')'],
            ['label' => 'Buffer pool', 'value' => $poolMb.' MB for '.($dataMb >= 1024 ? round($dataMb / 1024, 1).' GB' : $dataMb.' MB').' of data'],
            ['label' => 'Buffer pool hit rate', 'value' => $hit.'%'],
            ['label' => 'Slow queries', 'value' => (string) $slow],
        ];

        $advice = [];
        if ($dataMb > 0 && $poolMb < min($dataMb, (int) (self::totalRamMb() * 0.35))) {
            $advice[] = ['level' => 'warning', 'text' => 'The buffer pool ('.$poolMb.' MB) is smaller than the data ('.$dataMb.' MB), so MySQL keeps reading from disk. Raising it is usually the change that helps the most.'];
        }
        if ($hit < 99 && $reads > 100000) {
            $advice[] = ['level' => 'warning', 'text' => 'Only '.$hit.'% of the reads are served from memory. A larger buffer pool brings this above 99%.'];
        }
        if ($maxConnections > 0 && $maxUsed >= $maxConnections * 0.85) {
            $advice[] = ['level' => 'warning', 'text' => 'Connections reached '.$maxUsed.' of '.$maxConnections.'. Raise the limit, or lower the number of PHP workers that open connections.'];
        }
        if (($variables['slow_query_log'] ?? 'OFF') === 'OFF') {
            $advice[] = ['level' => 'info', 'text' => 'The slow query log is off; turning it on shows which queries make the sites slow.'];
        }
        if (($variables['skip_name_resolve'] ?? 'OFF') === 'OFF') {
            $advice[] = ['level' => 'info', 'text' => 'Host name lookups are on: every connection waits for DNS. Turning them off removes that wait (grants must use addresses).'];
        }
        if ($uptime < 3600) {
            $advice[] = ['level' => 'info', 'text' => 'The database was restarted less than an hour ago, so these numbers are still settling.'];
        }

        return ['rows' => $rows, 'advice' => $advice];
    }

    /** @return array<string, string> */
    protected function pairs(string $sql): array
    {
        $result = $this->mysql->query($sql, 30);
        $pairs = [];
        foreach ($result->lines() as $line) {
            [$name, $value] = array_pad(explode("\t", $line, 2), 2, '');
            if ($name !== '') {
                $pairs[$name] = trim($value);
            }
        }

        return $pairs;
    }
}
