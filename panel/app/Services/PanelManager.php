<?php

namespace App\Services;

/**
 * Panel level configuration: port, security entrance, server actions.
 */
class PanelManager
{
    public function setEnv(array $values): void
    {
        $file = base_path('.env');
        $env = is_file($file) ? file_get_contents($file) : '';
        foreach ($values as $key => $value) {
            $line = $key.'='.(preg_match('/[\s#"]/', (string) $value) ? '"'.addslashes((string) $value).'"' : $value);
            if (preg_match('/^'.preg_quote($key, '/').'=.*$/m', $env)) {
                $env = preg_replace('/^'.preg_quote($key, '/').'=.*$/m', str_replace('$', '\$', $line), $env);
            } else {
                $env = rtrim($env)."\n".$line."\n";
            }
        }
        file_put_contents($file, $env);

        if (is_file(base_path('bootstrap/cache/config.php'))) {
            @unlink(base_path('bootstrap/cache/config.php'));
        }
    }

    public static function validEntry(string $entry): bool
    {
        return $entry === '' || (bool) preg_match('/^[a-zA-Z0-9_-]{6,32}$/', $entry);
    }

    /** Change the Apache port the panel listens on. Applied a few seconds later. */
    public function changePort(int $port): ShellResult
    {
        if ($port < 1024 || $port > 65535 || in_array($port, [3306, 6379, 8080, 11211], true)) {
            return new ShellResult(1, '', 'Choose a port between 1024 and 65535 that is not used by a common service.');
        }
        if (! Shell::simulating() && Shell::test('ss -ltn "( sport = :'.$port.' )" | grep -q LISTEN', false)) {
            return new ShellResult(1, '', "Port {$port} is already in use.");
        }

        $old = (int) config('gbx.port');
        $conf = '/etc/apache2/sites-available/000-gbxpanel.conf';
        $cmd = "sed -i -E 's/^Listen [0-9]+/Listen {$port}/; s/<VirtualHost \*:[0-9]+>/<VirtualHost *:{$port}>/' {$conf}"
            ." && (command -v ufw >/dev/null && ufw allow {$port}/tcp comment 'GBX Panel' >/dev/null || true)"
            .' && apache2ctl configtest';
        $result = Shell::run($cmd, 30);
        if ($result->failed() && ! str_contains($result->error, 'Syntax OK')) {
            return $result;
        }

        $this->setEnv(['GBX_PORT' => $port]);
        $this->delayed('systemctl reload apache2'.($old && $old !== $port ? " && (command -v ufw >/dev/null && ufw delete allow {$old}/tcp >/dev/null || true)" : ''));

        return new ShellResult(0, 'ok', '');
    }

    /** Run a command detached a few seconds later (so the current request can finish). */
    public function delayed(string $command, int $seconds = 3): ShellResult
    {
        return Shell::run('systemd-run --on-active='.(int) $seconds.' --unit=gbx-delayed-'.bin2hex(random_bytes(4)).' /bin/bash -c '.Shell::arg($command), 20);
    }

    public function reboot(): ShellResult
    {
        return $this->delayed('/sbin/reboot', 3);
    }

    public function restartPanel(): ShellResult
    {
        return $this->delayed('systemctl restart gbxpanel-fpm; systemctl reload apache2; supervisorctl restart gbxpanel-worker:*', 2);
    }

    public function setHostname(string $hostname): ShellResult
    {
        if (! preg_match('/^(?=.{1,253}$)[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/', $hostname)) {
            return new ShellResult(1, '', 'Invalid hostname');
        }

        return Shell::run('hostnamectl set-hostname '.Shell::arg($hostname).' && (grep -q "127.0.1.1" /etc/hosts && sed -i "s/^127.0.1.1.*/127.0.1.1 '.$hostname.'/" /etc/hosts || echo "127.0.1.1 '.$hostname.'" >> /etc/hosts)', 20);
    }

    public function setTimezone(string $tz): ShellResult
    {
        if (! in_array($tz, timezone_identifiers_list(), true)) {
            return new ShellResult(1, '', 'Invalid timezone');
        }
        $this->setEnv(['APP_TIMEZONE' => $tz]);

        return Shell::run('timedatectl set-timezone '.Shell::arg($tz), 20);
    }

    public function currentTimezone(): string
    {
        if (Shell::simulating()) {
            return config('app.timezone');
        }

        return Shell::out('timedatectl show -p Timezone --value', 10, false) ?: 'UTC';
    }

    public function updateSystemScript(): string
    {
        return "export DEBIAN_FRONTEND=noninteractive\napt-get update -y\napt-get -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold upgrade -y\napt-get autoremove -y\n[ -f /var/run/reboot-required ] && echo 'NOTICE: a reboot is required to finish the update.' || true";
    }

    public function pendingUpdates(): int
    {
        if (Shell::simulating()) {
            return 12;
        }

        return (int) Shell::out('apt list --upgradable 2>/dev/null | grep -c upgradable', 60, false);
    }

    public function rebootRequired(): bool
    {
        return ! Shell::simulating() && is_file('/var/run/reboot-required');
    }
}
