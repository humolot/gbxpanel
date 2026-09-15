<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Reads live server statistics from /proc and standard tools.
 */
class SystemStats
{
    public function snapshot(): array
    {
        return [
            'time' => microtime(true),
            'cpu' => $this->cpu(),
            'load' => $this->load(),
            'memory' => $this->memory(),
            'disks' => $this->disks(),
            'network' => $this->network(),
            'diskio' => $this->diskIo(),
            'uptime' => $this->uptime(),
        ];
    }

    public function info(): array
    {
        if (Shell::simulating()) {
            return [
                'hostname' => 'gbx-dev',
                'os' => 'Ubuntu 24.04.1 LTS',
                'kernel' => '6.8.0-45-generic',
                'arch' => 'x86_64',
                'cpu_model' => 'Intel(R) Xeon(R) Platinum 8375C CPU @ 2.90GHz',
                'cores' => 4,
                'ip' => '142.93.121.40',
                'uptime' => $this->uptime(),
                'php' => PHP_VERSION,
                'panel' => config('gbx.version'),
            ];
        }

        $os = @parse_ini_file('/etc/os-release') ?: [];
        $cpuinfo = @file_get_contents('/proc/cpuinfo') ?: '';
        preg_match('/model name\s*:\s*(.+)/', $cpuinfo, $m);

        return [
            'hostname' => gethostname(),
            'os' => $os['PRETTY_NAME'] ?? php_uname('s'),
            'kernel' => php_uname('r'),
            'arch' => php_uname('m'),
            'cpu_model' => trim($m[1] ?? 'Unknown CPU'),
            'cores' => max(1, substr_count($cpuinfo, 'processor')),
            'ip' => $this->publicIp(),
            'uptime' => $this->uptime(),
            'php' => PHP_VERSION,
            'panel' => config('gbx.version'),
        ];
    }

    public function publicIp(): string
    {
        return Cache::remember('gbx.public_ip', 3600, function () {
            if (Shell::simulating()) {
                return '127.0.0.1';
            }
            $ip = Shell::out("curl -4 -fsS --max-time 4 https://api.ipify.org 2>/dev/null || hostname -I | awk '{print \$1}'", 10, false);

            return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '127.0.0.1';
        });
    }

    public function cpu(): array
    {
        $cores = $this->cores();
        if (Shell::simulating()) {
            return ['percent' => round(mt_rand(300, 3500) / 100, 1), 'cores' => $cores, 'per_core' => array_map(fn () => mt_rand(1, 60), range(1, $cores))];
        }

        $sample = $this->readCpuSample();
        $prev = Cache::get('gbx.cpu.sample');
        if (! $prev || (microtime(true) - $prev['t']) > 30) {
            usleep(250000);
            $prev = $sample;
            $sample = $this->readCpuSample();
        }
        Cache::put('gbx.cpu.sample', $sample, 120);

        $calc = function (array $a, array $b): float {
            $total = $b['total'] - $a['total'];
            $idle = $b['idle'] - $a['idle'];

            return $total > 0 ? round(100 * ($total - $idle) / $total, 1) : 0.0;
        };

        $perCore = [];
        foreach ($sample['cores'] as $i => $core) {
            $perCore[] = isset($prev['cores'][$i]) ? $calc($prev['cores'][$i], $core) : 0;
        }

        return ['percent' => $calc($prev['all'], $sample['all']), 'cores' => $cores, 'per_core' => $perCore];
    }

    protected function readCpuSample(): array
    {
        $out = ['t' => microtime(true), 'all' => ['total' => 0, 'idle' => 0], 'cores' => []];
        foreach (@file('/proc/stat') ?: [] as $line) {
            if (! str_starts_with($line, 'cpu')) {
                continue;
            }
            $parts = preg_split('/\s+/', trim($line));
            $name = array_shift($parts);
            $values = array_map('intval', array_slice($parts, 0, 8));
            $row = ['total' => array_sum($values), 'idle' => ($values[3] ?? 0) + ($values[4] ?? 0)];
            if ($name === 'cpu') {
                $out['all'] = $row;
            } else {
                $out['cores'][] = $row;
            }
        }

        return $out;
    }

    public function cores(): int
    {
        if (Shell::simulating()) {
            return 4;
        }

        return max(1, (int) preg_match_all('/^processor/m', @file_get_contents('/proc/cpuinfo') ?: ''));
    }

    public function load(): array
    {
        $cores = $this->cores();
        if (Shell::simulating()) {
            $l = [round(mt_rand(5, 120) / 100, 2), 0.42, 0.38];
        } else {
            $parts = explode(' ', trim(@file_get_contents('/proc/loadavg') ?: '0 0 0'));
            $l = [(float) $parts[0], (float) ($parts[1] ?? 0), (float) ($parts[2] ?? 0)];
        }
        $percent = min(100, round($l[0] / ($cores * 1.0) * 100, 1));

        return [
            'one' => $l[0], 'five' => $l[1], 'fifteen' => $l[2],
            'percent' => $percent,
            'label' => $percent < 50 ? 'Smooth' : ($percent < 80 ? 'Normal' : ($percent < 95 ? 'Slow' : 'Overloaded')),
        ];
    }

    public function memory(): array
    {
        if (Shell::simulating()) {
            $total = 7941 * 1048576;
            $used = mt_rand(2300, 2700) * 1048576;

            return ['total' => $total, 'used' => $used, 'free' => $total - $used, 'cached' => 1900 * 1048576, 'buffers' => 120 * 1048576, 'percent' => round($used / $total * 100, 1), 'swap_total' => 2048 * 1048576, 'swap_used' => 12 * 1048576];
        }

        $info = [];
        foreach (@file('/proc/meminfo') ?: [] as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
                $info[$m[1]] = (int) $m[2] * 1024;
            }
        }
        $total = $info['MemTotal'] ?? 0;
        $available = $info['MemAvailable'] ?? (($info['MemFree'] ?? 0) + ($info['Cached'] ?? 0));
        $used = max(0, $total - $available);

        return [
            'total' => $total,
            'used' => $used,
            'free' => $info['MemFree'] ?? 0,
            'cached' => $info['Cached'] ?? 0,
            'buffers' => $info['Buffers'] ?? 0,
            'percent' => $total ? round($used / $total * 100, 1) : 0,
            'swap_total' => $info['SwapTotal'] ?? 0,
            'swap_used' => ($info['SwapTotal'] ?? 0) - ($info['SwapFree'] ?? 0),
        ];
    }

    public function disks(): array
    {
        if (Shell::simulating()) {
            return [
                ['mount' => '/', 'fs' => 'ext4', 'device' => '/dev/vda1', 'total' => 165275648000, 'used' => 22752000000, 'percent' => 14, 'inodes_percent' => 4],
            ];
        }

        $rows = [];
        $inodes = [];
        foreach (explode("\n", Shell::out('df -Pi -x tmpfs -x devtmpfs -x squashfs -x overlay 2>/dev/null', 10, false)) as $line) {
            $p = preg_split('/\s+/', trim($line));
            if (count($p) >= 6 && $p[0] !== 'Filesystem') {
                $inodes[$p[5]] = (int) rtrim($p[4], '%');
            }
        }
        $out = Shell::out('df -PT -B1 -x tmpfs -x devtmpfs -x squashfs -x overlay -x efivarfs 2>/dev/null', 10, false);
        foreach (explode("\n", $out) as $line) {
            $p = preg_split('/\s+/', trim($line));
            if (count($p) < 7 || $p[0] === 'Filesystem' || str_starts_with($p[6], '/boot')) {
                continue;
            }
            $rows[] = [
                'mount' => $p[6],
                'fs' => $p[1],
                'device' => $p[0],
                'total' => (int) $p[2],
                'used' => (int) $p[3],
                'percent' => (int) rtrim($p[5], '%'),
                'inodes_percent' => $inodes[$p[6]] ?? 0,
            ];
        }

        return $rows;
    }

    public function network(): array
    {
        if (Shell::simulating()) {
            $base = (int) (microtime(true) * 1000);

            return ['rx' => $base * 48 + mt_rand(0, 40000), 'tx' => $base * 50 + mt_rand(0, 40000), 'interfaces' => ['eth0']];
        }

        $rx = $tx = 0;
        $ifaces = [];
        foreach (@file('/proc/net/dev') ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$name, $data] = array_map('trim', explode(':', $line, 2));
            if ($name === 'lo' || str_starts_with($name, 'veth') || str_starts_with($name, 'docker') || str_starts_with($name, 'br-')) {
                continue;
            }
            $p = preg_split('/\s+/', $data);
            $rx += (int) $p[0];
            $tx += (int) $p[8];
            $ifaces[] = $name;
        }

        return ['rx' => $rx, 'tx' => $tx, 'interfaces' => $ifaces];
    }

    public function diskIo(): array
    {
        if (Shell::simulating()) {
            $base = (int) (microtime(true) * 1000);

            return ['read' => $base * 8 + mt_rand(0, 90000), 'write' => $base * 20 + mt_rand(0, 90000)];
        }

        $read = $write = 0;
        foreach (@file('/proc/diskstats') ?: [] as $line) {
            $p = preg_split('/\s+/', trim($line));
            if (count($p) < 10 || ! preg_match('/^(sd[a-z]+|vd[a-z]+|xvd[a-z]+|nvme\d+n\d+|mmcblk\d+)$/', $p[2])) {
                continue;
            }
            $read += (int) $p[5] * 512;
            $write += (int) $p[9] * 512;
        }

        return ['read' => $read, 'write' => $write];
    }

    public function uptime(): array
    {
        $seconds = Shell::simulating() ? 6 * 86400 + 5000 : (int) (float) explode(' ', @file_get_contents('/proc/uptime') ?: '0')[0];

        return ['seconds' => $seconds, 'human' => static::humanDuration($seconds)];
    }

    public function processes(int $limit = 200): array
    {
        if (Shell::simulating()) {
            $names = ['apache2', 'php-fpm8.4', 'mysqld', 'dockerd', 'supervisord', 'sshd', 'systemd', 'cron', 'redis-server', 'node'];
            $rows = [];
            foreach (range(1, 40) as $i) {
                $n = $names[$i % count($names)];
                $rows[] = ['pid' => 1000 + $i * 7, 'user' => $i % 3 ? 'www-data' : 'root', 'cpu' => round(mt_rand(0, 900) / 100, 1), 'mem' => round(mt_rand(0, 600) / 100, 1), 'rss' => mt_rand(4000, 400000) * 1024, 'elapsed' => mt_rand(60, 500000), 'state' => 'S', 'name' => $n, 'command' => '/usr/sbin/'.$n.' -k start'];
            }
            usort($rows, fn ($a, $b) => $b['cpu'] <=> $a['cpu']);

            return $rows;
        }

        $out = Shell::out('ps -eo pid=,user:32=,pcpu=,pmem=,rss=,etimes=,stat=,comm=,args= --sort=-pcpu | head -n '.(int) $limit, 15, false);
        $rows = [];
        foreach (explode("\n", $out) as $line) {
            $p = preg_split('/\s+/', trim($line), 9);
            if (count($p) < 8) {
                continue;
            }
            $rows[] = [
                'pid' => (int) $p[0], 'user' => $p[1], 'cpu' => (float) $p[2], 'mem' => (float) $p[3],
                'rss' => (int) $p[4] * 1024, 'elapsed' => (int) $p[5], 'state' => $p[6], 'name' => $p[7], 'command' => $p[8] ?? $p[7],
            ];
        }

        return $rows;
    }

    public static function humanDuration(int $seconds): string
    {
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $m = intdiv($seconds % 3600, 60);

        return $d > 0 ? "{$d}d {$h}h {$m}m" : ($h > 0 ? "{$h}h {$m}m" : "{$m}m");
    }

    public static function bytes(int|float $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, $precision).' '.$units[$i];
    }
}
