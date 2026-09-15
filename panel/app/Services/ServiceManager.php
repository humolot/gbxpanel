<?php

namespace App\Services;

class ServiceManager
{
    /** Services shown on the Services page when present on the system. */
    public const KNOWN = [
        'apache2' => 'Apache HTTP Server',
        'gbxpanel-fpm' => 'GBX Panel PHP-FPM',
        'gbxpanel-terminal' => 'GBX Panel web terminal',
        'mysql' => 'MySQL Server',
        'mariadb' => 'MariaDB Server',
        'php7.4-fpm' => 'PHP 7.4 FPM', 'php8.0-fpm' => 'PHP 8.0 FPM', 'php8.1-fpm' => 'PHP 8.1 FPM',
        'php8.2-fpm' => 'PHP 8.2 FPM', 'php8.3-fpm' => 'PHP 8.3 FPM', 'php8.4-fpm' => 'PHP 8.4 FPM', 'php8.5-fpm' => 'PHP 8.5 FPM',
        'redis-server' => 'Redis',
        'memcached' => 'Memcached',
        'pure-ftpd' => 'Pure-FTPd',
        'docker' => 'Docker Engine',
        'fail2ban' => 'Fail2ban',
        'supervisor' => 'Supervisor',
        'cron' => 'Cron daemon',
        'ssh' => 'OpenSSH Server',
        'ufw' => 'Uncomplicated Firewall',
    ];

    public static function validName(string $name): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9@._-]{1,64}$/', $name);
    }

    public function status(string $service): array
    {
        if (Shell::simulating()) {
            $active = ! in_array($service, ['memcached', 'mariadb'], true);

            return ['name' => $service, 'active' => $active, 'enabled' => $active, 'state' => $active ? 'active' : 'inactive', 'since' => now()->subDays(6)->toDateTimeString(), 'memory' => mt_rand(20, 400) * 1048576, 'installed' => $service !== 'mariadb'];
        }

        $s = Shell::arg($service);
        $out = Shell::out("systemctl show {$s} --no-pager -p LoadState -p ActiveState -p SubState -p UnitFileState -p ActiveEnterTimestamp -p MemoryCurrent", 10, false);
        $props = [];
        foreach (explode("\n", $out) as $line) {
            if (str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                $props[$k] = $v;
            }
        }

        return [
            'name' => $service,
            'installed' => ($props['LoadState'] ?? 'not-found') === 'loaded',
            'active' => ($props['ActiveState'] ?? '') === 'active',
            'enabled' => in_array($props['UnitFileState'] ?? '', ['enabled', 'enabled-runtime', 'alias'], true),
            'state' => trim(($props['ActiveState'] ?? 'unknown').' ('.($props['SubState'] ?? '').')'),
            'since' => $props['ActiveEnterTimestamp'] ?? '',
            'memory' => is_numeric($props['MemoryCurrent'] ?? null) ? (int) $props['MemoryCurrent'] : 0,
        ];
    }

    public function list(): array
    {
        $rows = [];
        foreach (self::KNOWN as $name => $label) {
            $status = $this->status($name);
            if ($status['installed']) {
                $rows[] = $status + ['label' => $label];
            }
        }

        return $rows;
    }

    public function action(string $service, string $action): ShellResult
    {
        if (! self::validName($service) || ! in_array($action, ['start', 'stop', 'restart', 'reload', 'enable', 'disable'], true)) {
            return new ShellResult(1, '', 'Invalid service or action');
        }

        $cmd = in_array($action, ['enable', 'disable'], true)
            ? "systemctl {$action} --now ".Shell::arg($service)
            : "systemctl {$action} ".Shell::arg($service);

        return Shell::run($cmd, 90);
    }

    public function journal(string $service, int $lines = 200): string
    {
        if (Shell::simulating()) {
            return "-- simulated journal for {$service} --\nStarted {$service}.";
        }

        return Shell::run('journalctl -u '.Shell::arg($service).' -n '.(int) $lines.' --no-pager 2>&1', 20)->output;
    }
}
