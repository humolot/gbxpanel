<?php

namespace App\Services\Tuning;

use App\Services\PhpManager;
use App\Services\Shell;
use App\Services\ShellResult;

/**
 * PHP-FPM pool and OPcache of one PHP version.
 *
 * The number of workers is not guessed from a fixed "20 MB per process": the panel measures how
 * much memory the running PHP processes of this version actually use and proposes a number from
 * that, which is what keeps a busy server from running out of memory.
 */
class PhpTuner extends Tuner
{
    /** Assumed size of a PHP worker when nothing is running yet. */
    public const DEFAULT_PROCESS_MB = 40;

    public function __construct(protected string $version) {}

    public function key(): string
    {
        return 'php'.$this->version;
    }

    public function label(): string
    {
        return 'PHP '.$this->version;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function installed(): bool
    {
        return Shell::simulating() || Shell::fileExists($this->file());
    }

    public function file(): string
    {
        return "/etc/php/{$this->version}/fpm/pool.d/www.conf";
    }

    public function iniFile(): string
    {
        return "/etc/php/{$this->version}/fpm/php.ini";
    }

    public function fields(): array
    {
        return [
            'pm' => ['label' => 'Process manager', 'type' => 'select', 'group' => 'Workers', 'options' => [
                'dynamic' => 'Dynamic (keeps spare workers ready)',
                'ondemand' => 'On demand (starts workers only when needed)',
                'static' => 'Static (fixed number of workers)',
            ], 'hint' => 'Dynamic fits most servers. On demand saves memory on small servers with quiet sites.'],
            'pm.max_children' => ['label' => 'Max workers', 'type' => 'number', 'group' => 'Workers', 'min' => 1, 'max' => 5000, 'hint' => 'Highest number of requests this PHP version can serve at the same time.'],
            'pm.start_servers' => ['label' => 'Workers at start', 'type' => 'number', 'group' => 'Workers', 'min' => 1, 'max' => 5000],
            'pm.min_spare_servers' => ['label' => 'Minimum idle workers', 'type' => 'number', 'group' => 'Workers', 'min' => 1, 'max' => 5000],
            'pm.max_spare_servers' => ['label' => 'Maximum idle workers', 'type' => 'number', 'group' => 'Workers', 'min' => 1, 'max' => 5000],
            'pm.max_requests' => ['label' => 'Requests before a worker restarts', 'type' => 'number', 'group' => 'Workers', 'min' => 0, 'max' => 100000, 'hint' => 'Restarting workers now and then releases memory leaked by extensions. 0 never restarts them.'],
            'pm.process_idle_timeout' => ['label' => 'Idle timeout (on demand)', 'type' => 'text', 'group' => 'Workers', 'hint' => 'Only used by the on demand manager, for example 10s.'],
            'request_terminate_timeout' => ['label' => 'Kill a request after', 'type' => 'text', 'group' => 'Workers', 'hint' => 'For example 300s. 0 never kills it; a stuck request then holds a worker forever.'],
            'slowlog_enabled' => ['label' => 'Slow request log', 'type' => 'bool', 'group' => 'Workers', 'hint' => 'Writes a backtrace of requests slower than the value below.'],
            'request_slowlog_timeout' => ['label' => 'Slow request after', 'type' => 'text', 'group' => 'Workers', 'hint' => 'For example 10s.'],

            'opcache.enable' => ['label' => 'OPcache', 'type' => 'bool', 'group' => 'OPcache', 'hint' => 'Keeps compiled PHP in memory. Turning it off makes every site slower.'],
            'opcache.memory_consumption' => ['label' => 'OPcache memory (MB)', 'type' => 'number', 'group' => 'OPcache', 'min' => 16, 'max' => 4096],
            'opcache.interned_strings_buffer' => ['label' => 'Strings buffer (MB)', 'type' => 'number', 'group' => 'OPcache', 'min' => 4, 'max' => 512],
            'opcache.max_accelerated_files' => ['label' => 'Files kept in cache', 'type' => 'number', 'group' => 'OPcache', 'min' => 1000, 'max' => 1000000, 'hint' => 'Must be higher than the number of .php files of all sites together.'],
            'opcache.validate_timestamps' => ['label' => 'Check files for changes', 'type' => 'bool', 'group' => 'OPcache', 'hint' => 'Turn it off only on servers where the code changes through a deploy: new files are ignored until PHP is reloaded.'],
            'opcache.revalidate_freq' => ['label' => 'Check every (seconds)', 'type' => 'number', 'group' => 'OPcache', 'min' => 0, 'max' => 3600],
            'opcache.jit_buffer_size' => ['label' => 'JIT buffer (MB)', 'type' => 'number', 'group' => 'OPcache', 'min' => 0, 'max' => 1024, 'hint' => '0 turns the JIT off. It helps calculation-heavy code, not typical websites.'],
        ];
    }

    /** Memory a running worker of this version uses on average, measured now. */
    public function processMb(): ?int
    {
        if (Shell::simulating()) {
            return 38;
        }
        $out = Shell::out("ps -o rss= -C php-fpm{$this->version} 2>/dev/null | awk '{ total += \$1; n++ } END { if (n > 1) print int(total / n / 1024) }'", 20);

        return is_numeric($out) && (int) $out > 0 ? (int) $out : null;
    }

    public function values(): array
    {
        if (Shell::simulating()) {
            return [
                'pm' => 'dynamic', 'pm.max_children' => '20', 'pm.start_servers' => '5', 'pm.min_spare_servers' => '3', 'pm.max_spare_servers' => '8',
                'pm.max_requests' => '500', 'pm.process_idle_timeout' => '10s', 'request_terminate_timeout' => '300s',
                'slowlog_enabled' => '1', 'request_slowlog_timeout' => '10s',
                'opcache.enable' => '1', 'opcache.memory_consumption' => '128', 'opcache.interned_strings_buffer' => '8',
                'opcache.max_accelerated_files' => '10000', 'opcache.validate_timestamps' => '1', 'opcache.revalidate_freq' => '2', 'opcache.jit_buffer_size' => '0',
            ];
        }

        $pool = $this->readIni($this->file(), ['pm', 'pm.max_children', 'pm.start_servers', 'pm.min_spare_servers', 'pm.max_spare_servers', 'pm.max_requests', 'pm.process_idle_timeout', 'request_terminate_timeout', 'request_slowlog_timeout', 'slowlog']);
        $ini = $this->readIni($this->iniFile(), ['opcache.enable', 'opcache.memory_consumption', 'opcache.interned_strings_buffer', 'opcache.max_accelerated_files', 'opcache.validate_timestamps', 'opcache.revalidate_freq', 'opcache.jit_buffer_size']);

        $values = $pool + $ini;
        $values['slowlog_enabled'] = ($pool['slowlog'] ?? '') !== '' ? '1' : '0';
        unset($values['slowlog']);
        foreach (['opcache.enable', 'opcache.validate_timestamps'] as $flag) {
            $values[$flag] = in_array(strtolower((string) ($values[$flag] ?? '')), ['1', 'on', 'true', 'yes'], true) ? '1' : '0';
        }
        $values['opcache.jit_buffer_size'] = (string) self::toMb((string) ($values['opcache.jit_buffer_size'] ?? '0'));

        return $values;
    }

    public function suggest(int $ramMb): array
    {
        $processMb = $this->processMb() ?? self::DEFAULT_PROCESS_MB;
        // half of the memory goes to PHP workers; the rest is for the database, the web server and the system
        $children = self::clamp($ramMb * 0.5 / $processMb, 5, 500);

        return [
            'pm' => $ramMb <= 2048 ? 'ondemand' : 'dynamic',
            'pm.max_children' => (string) $children,
            'pm.start_servers' => (string) self::clamp($children * 0.25, 2, $children),
            'pm.min_spare_servers' => (string) self::clamp($children * 0.15, 2, $children),
            'pm.max_spare_servers' => (string) self::clamp($children * 0.45, 4, $children),
            'pm.max_requests' => '500',
            'pm.process_idle_timeout' => '10s',
            'request_terminate_timeout' => '300s',
            'slowlog_enabled' => '1',
            'request_slowlog_timeout' => '10s',
            'opcache.enable' => '1',
            'opcache.memory_consumption' => (string) self::clamp($ramMb / 32, 128, 512),
            'opcache.interned_strings_buffer' => (string) self::clamp($ramMb / 512, 8, 64),
            'opcache.max_accelerated_files' => $ramMb >= 8192 ? '32531' : '16229',
            'opcache.validate_timestamps' => '1',
            'opcache.revalidate_freq' => '2',
            'opcache.jit_buffer_size' => '0',
        ];
    }

    public function apply(array $values): ShellResult
    {
        $values = $this->validate($values);
        $pool = array_intersect_key($values, array_flip(['pm', 'pm.max_children', 'pm.start_servers', 'pm.min_spare_servers', 'pm.max_spare_servers', 'pm.max_requests', 'pm.process_idle_timeout', 'request_terminate_timeout', 'request_slowlog_timeout']));
        $ini = array_intersect_key($values, array_flip(['opcache.enable', 'opcache.memory_consumption', 'opcache.interned_strings_buffer', 'opcache.max_accelerated_files', 'opcache.validate_timestamps', 'opcache.revalidate_freq', 'opcache.jit_buffer_size']));

        if (isset($values['pm.max_children'], $values['pm.max_spare_servers']) && (int) $values['pm.max_spare_servers'] > (int) $values['pm.max_children']) {
            return new ShellResult(1, '', 'The maximum number of idle workers cannot be higher than the number of workers.');
        }
        if (array_key_exists('slowlog_enabled', $values)) {
            $pool['slowlog'] = $values['slowlog_enabled'] === '1' ? "/var/log/php{$this->version}-fpm-slow.log" : ';disabled';
        }
        foreach (['opcache.memory_consumption', 'opcache.interned_strings_buffer', 'opcache.jit_buffer_size'] as $key) {
            if (isset($ini[$key])) {
                $ini[$key] = $ini[$key].'M';
            }
        }
        if (isset($ini['opcache.jit_buffer_size']) && $ini['opcache.jit_buffer_size'] !== '0M') {
            $ini['opcache.jit'] = 'tracing';
        }

        $write = [];
        if ($pool) {
            $write[] = $this->iniWriter($this->file(), $pool);
        }
        if ($ini) {
            $write[] = $this->iniWriter($this->iniFile(), $ini);
        }

        return $this->guarded($this->file(), implode(' && ', $write), "php-fpm{$this->version} -t", "systemctl reload php{$this->version}-fpm || systemctl restart php{$this->version}-fpm");
    }

    /* =============================================================== status */

    public function status(): array
    {
        $processMb = $this->processMb();
        $values = $this->values();
        $children = (int) ($values['pm.max_children'] ?? 0);
        $ramMb = self::totalRamMb();
        $worstCase = $processMb && $children ? $processMb * $children : null;

        $running = Shell::simulating() ? 7 : (int) Shell::out("pgrep -c -f 'php-fpm{$this->version}: pool' 2>/dev/null", 15);

        $rows = [
            ['label' => 'Workers running now', 'value' => (string) $running],
            ['label' => 'Memory per worker (measured)', 'value' => $processMb ? $processMb.' MB' : 'no worker running yet'],
            ['label' => 'Memory if every worker is busy', 'value' => $worstCase ? $worstCase.' MB of '.$ramMb.' MB' : '-'],
        ];

        $advice = [];
        if ($worstCase && $worstCase > $ramMb * 0.8) {
            $advice[] = ['level' => 'warning', 'text' => 'With '.$children.' workers of about '.$processMb.' MB this version alone can ask for '.$worstCase.' MB, more than 80% of the memory of the server. Lower the number of workers or add memory.'];
        }
        if ($processMb && $children && $worstCase < $ramMb * 0.2) {
            $advice[] = ['level' => 'info', 'text' => 'There is room for more workers: the current limit uses only '.round($worstCase / $ramMb * 100).'% of the memory.'];
        }
        if (($values['opcache.enable'] ?? '1') !== '1') {
            $advice[] = ['level' => 'warning', 'text' => 'OPcache is off. Turning it on is the single biggest speed change for any PHP site.'];
        }

        return ['rows' => $rows, 'advice' => $advice];
    }

    /* ==================================================== disabled functions */

    /** Functions PHP refuses to run, a common hardening step on shared servers. */
    public function disabledFunctions(): array
    {
        if (Shell::simulating()) {
            return ['exec', 'system', 'passthru', 'shell_exec', 'proc_open', 'popen'];
        }
        $value = $this->readIni($this->iniFile(), ['disable_functions'])['disable_functions'] ?? '';

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    public const RECOMMENDED_DISABLED = ['exec', 'system', 'passthru', 'shell_exec', 'proc_open', 'popen', 'proc_close', 'proc_get_status', 'pcntl_exec', 'dl', 'symlink', 'link', 'chgrp', 'chown', 'ini_alter', 'ini_restore', 'openlog', 'syslog', 'show_source'];

    public function saveDisabledFunctions(array $functions): ShellResult
    {
        $clean = [];
        foreach ($functions as $function) {
            $function = trim((string) $function);
            if ($function !== '' && ! preg_match('/^[a-z_][a-z0-9_]{1,40}$/i', $function)) {
                return new ShellResult(1, '', "Not a function name: {$function}");
            }
            if ($function !== '') {
                $clean[strtolower($function)] = strtolower($function);
            }
        }
        // the panel itself runs on this PHP version through its own pool; blocking these would break it
        foreach (['putenv', 'escapeshellarg', 'escapeshellcmd'] as $needed) {
            unset($clean[$needed]);
        }

        return $this->guarded(
            $this->iniFile(),
            $this->iniWriter($this->iniFile(), ['disable_functions' => implode(',', $clean)]),
            "php-fpm{$this->version} -t",
            "systemctl reload php{$this->version}-fpm || systemctl restart php{$this->version}-fpm"
        );
    }

    public static function versions(): array
    {
        return array_values(array_filter(app(\App\Services\SoftwareManager::class)->phpVersions(), fn ($v) => PhpManager::validVersion($v)));
    }
}
