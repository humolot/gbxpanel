<?php

namespace App\Services\Tuning;

use App\Services\Shell;
use App\Services\ShellResult;

/**
 * Apache with the event MPM: how many connections it accepts at the same time and how long it
 * keeps them open. Apache itself uses little memory here, because PHP runs in its own pool.
 */
class ApacheTuner extends Tuner
{
    public const MPM = '/etc/apache2/mods-available/mpm_event.conf';

    public const MAIN = '/etc/apache2/apache2.conf';

    protected const MPM_KEYS = ['StartServers', 'ServerLimit', 'ThreadsPerChild', 'MinSpareThreads', 'MaxSpareThreads', 'MaxRequestWorkers', 'MaxConnectionsPerChild'];

    protected const MAIN_KEYS = ['Timeout', 'KeepAlive', 'MaxKeepAliveRequests', 'KeepAliveTimeout'];

    public function key(): string
    {
        return 'apache';
    }

    public function label(): string
    {
        return 'Apache';
    }

    public function installed(): bool
    {
        return Shell::simulating() || Shell::fileExists(self::MAIN);
    }

    public function file(): string
    {
        return self::MPM;
    }

    public function fields(): array
    {
        return [
            'MaxRequestWorkers' => ['label' => 'Connections at the same time', 'type' => 'number', 'group' => 'Capacity', 'min' => 10, 'max' => 20000, 'hint' => 'Requests Apache serves at once. Above this they wait in line.'],
            'ThreadsPerChild' => ['label' => 'Threads per process', 'type' => 'number', 'group' => 'Capacity', 'min' => 5, 'max' => 200, 'hint' => '25 fits almost every server. Connections divided by this is the number of processes.'],
            'ServerLimit' => ['label' => 'Processes', 'type' => 'number', 'group' => 'Capacity', 'min' => 1, 'max' => 500, 'hint' => 'Must be at least connections divided by threads per process; the panel keeps it in step.'],
            'StartServers' => ['label' => 'Processes at start', 'type' => 'number', 'group' => 'Capacity', 'min' => 1, 'max' => 100],
            'MinSpareThreads' => ['label' => 'Minimum idle threads', 'type' => 'number', 'group' => 'Capacity', 'min' => 5, 'max' => 5000],
            'MaxSpareThreads' => ['label' => 'Maximum idle threads', 'type' => 'number', 'group' => 'Capacity', 'min' => 10, 'max' => 10000],
            'MaxConnectionsPerChild' => ['label' => 'Requests before a process restarts', 'type' => 'number', 'group' => 'Capacity', 'min' => 0, 'max' => 1000000, 'hint' => '0 never restarts it.'],

            'Timeout' => ['label' => 'Request timeout (seconds)', 'type' => 'number', 'group' => 'Connections', 'min' => 5, 'max' => 3600],
            'KeepAlive' => ['label' => 'Keep connections open', 'type' => 'select', 'group' => 'Connections', 'options' => ['On' => 'On (recommended)', 'Off' => 'Off'], 'hint' => 'Reuses one connection for several files of the same page.'],
            'MaxKeepAliveRequests' => ['label' => 'Requests per connection', 'type' => 'number', 'group' => 'Connections', 'min' => 0, 'max' => 100000],
            'KeepAliveTimeout' => ['label' => 'Hold an idle connection for (seconds)', 'type' => 'number', 'group' => 'Connections', 'min' => 1, 'max' => 300, 'hint' => 'Long values keep threads busy doing nothing; 5 seconds is a good balance.'],
        ];
    }

    public function values(): array
    {
        if (Shell::simulating()) {
            return [
                'StartServers' => '2', 'ServerLimit' => '16', 'ThreadsPerChild' => '25', 'MinSpareThreads' => '25', 'MaxSpareThreads' => '75',
                'MaxRequestWorkers' => '400', 'MaxConnectionsPerChild' => '0',
                'Timeout' => '60', 'KeepAlive' => 'On', 'MaxKeepAliveRequests' => '100', 'KeepAliveTimeout' => '5',
            ];
        }

        $pattern = '/^\s*%s\s+(\S+)/mi';

        return $this->readIni(self::MPM, self::MPM_KEYS, $pattern) + $this->readIni(self::MAIN, self::MAIN_KEYS, $pattern);
    }

    public function suggest(int $ramMb): array
    {
        $threads = 25;
        // Apache only passes requests to PHP here, so the limit follows memory loosely
        $workers = self::clamp($ramMb / 1024 * 150, 150, 4000);
        $workers = (int) (ceil($workers / $threads) * $threads);

        return [
            'ThreadsPerChild' => (string) $threads,
            'MaxRequestWorkers' => (string) $workers,
            'ServerLimit' => (string) (int) ceil($workers / $threads),
            'StartServers' => (string) self::clamp($workers / $threads / 4, 2, 10),
            'MinSpareThreads' => (string) self::clamp($workers * 0.1, 25, 250),
            'MaxSpareThreads' => (string) self::clamp($workers * 0.3, 75, 750),
            'MaxConnectionsPerChild' => '0',
            'Timeout' => '60',
            'KeepAlive' => 'On',
            'MaxKeepAliveRequests' => '100',
            'KeepAliveTimeout' => '5',
        ];
    }

    public function apply(array $values): ShellResult
    {
        $values = $this->validate($values);

        // Apache refuses to start when it may need more processes than ServerLimit allows
        $threads = (int) ($values['ThreadsPerChild'] ?? $this->values()['ThreadsPerChild'] ?? 25);
        $workers = (int) ($values['MaxRequestWorkers'] ?? $this->values()['MaxRequestWorkers'] ?? 150);
        if ($threads > 0 && isset($values['MaxRequestWorkers'])) {
            $values['ServerLimit'] = (string) max((int) ($values['ServerLimit'] ?? 0), (int) ceil($workers / $threads));
        }
        if (isset($values['MinSpareThreads'], $values['MaxSpareThreads']) && (int) $values['MinSpareThreads'] > (int) $values['MaxSpareThreads']) {
            return new ShellResult(1, '', 'The minimum number of idle threads cannot be higher than the maximum.');
        }

        $mpm = array_intersect_key($values, array_flip(self::MPM_KEYS));
        $main = array_intersect_key($values, array_flip(self::MAIN_KEYS));
        $write = [];
        if ($mpm) {
            $write[] = $this->directiveWriter(self::MPM, $mpm);
        }
        if ($main) {
            $write[] = $this->directiveWriter(self::MAIN, $main);
        }

        return $this->guarded(self::MPM, implode(' && ', $write), 'apache2ctl configtest', 'systemctl reload apache2');
    }

    public function status(): array
    {
        if (Shell::simulating()) {
            return [
                'rows' => [
                    ['label' => 'Processes running', 'value' => '3'],
                    ['label' => 'Memory used by Apache', 'value' => '184 MB'],
                    ['label' => 'Multi-processing module', 'value' => 'mpm_event'],
                ],
                'advice' => [['level' => 'info', 'text' => 'Apache passes PHP requests to PHP-FPM, so the number of PHP workers is what limits heavy pages.']],
            ];
        }

        $processes = (int) Shell::out("pgrep -c apache2 2>/dev/null", 15);
        $memoryKb = (int) Shell::out("ps -o rss= -C apache2 2>/dev/null | awk '{ total += \$1 } END { print total + 0 }'", 20);
        $mpm = Shell::out("apache2ctl -M 2>/dev/null | grep -o 'mpm_[a-z]*' | head -1", 20) ?: 'unknown';

        $values = $this->values();
        $advice = [];
        if ($mpm !== 'mpm_event' && $mpm !== 'unknown') {
            $advice[] = ['level' => 'warning', 'text' => 'Apache is running with '.$mpm.'. The event module handles many more connections with the same memory when PHP runs in FPM.'];
        }
        $workers = (int) ($values['MaxRequestWorkers'] ?? 0);
        $threads = (int) ($values['ThreadsPerChild'] ?? 0);
        $limit = (int) ($values['ServerLimit'] ?? 0);
        if ($workers && $threads && $limit && $limit * $threads < $workers) {
            $advice[] = ['level' => 'warning', 'text' => 'Processes multiplied by threads ('.($limit * $threads).') is lower than the number of connections ('.$workers.'), so Apache will serve fewer requests than configured.'];
        }
        if ((int) ($values['KeepAliveTimeout'] ?? 5) > 15) {
            $advice[] = ['level' => 'info', 'text' => 'Idle connections are held for '.$values['KeepAliveTimeout'].' seconds; lowering it to about 5 frees threads sooner on a busy server.'];
        }

        return [
            'rows' => [
                ['label' => 'Processes running', 'value' => (string) $processes],
                ['label' => 'Memory used by Apache', 'value' => round($memoryKb / 1024).' MB'],
                ['label' => 'Multi-processing module', 'value' => $mpm],
            ],
            'advice' => $advice,
        ];
    }
}
