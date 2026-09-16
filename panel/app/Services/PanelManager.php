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

    /**
     * Regular update ("upgrade") or full update ("dist-upgrade").
     *
     * A regular update never installs a package that needs new dependencies or that would remove
     * something, and never installs a new kernel: those stay listed as pending. The full update
     * takes them too, which is why it is a separate button with its own warning.
     */
    public function updateSystemScript(bool $full = false): string
    {
        $command = $full ? 'dist-upgrade' : 'upgrade';

        return "export DEBIAN_FRONTEND=noninteractive\napt-get update -y\n"
            ."apt-get -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold {$command} -y\n"
            ."apt-get autoremove -y\n"
            ."echo; echo '== Still pending =='; apt list --upgradable 2>/dev/null | grep upgradable || echo 'nothing'\n"
            ."[ -f /var/run/reboot-required ] && echo 'NOTICE: a reboot is required to finish the update.' || true";
    }

    /**
     * Packages waiting to be installed. "held" marks the ones a regular update leaves behind
     * (new dependencies, kernels, or an update Ubuntu is still rolling out gradually).
     *
     * @return array{count: int, security: int, held: list<string>, packages: list<array{name: string, current: string, candidate: string, source: string, security: bool, held: bool}>}
     */
    public function updates(): array
    {
        if (Shell::simulating()) {
            $packages = [
                ['name' => 'openssl', 'current' => '3.0.13-0ubuntu3.1', 'candidate' => '3.0.13-0ubuntu3.4', 'source' => 'noble-security', 'security' => true, 'held' => false],
                ['name' => 'curl', 'current' => '8.5.0-2ubuntu10.1', 'candidate' => '8.5.0-2ubuntu10.6', 'source' => 'noble-updates', 'security' => false, 'held' => false],
                ['name' => 'linux-image-generic', 'current' => '6.8.0-45.45', 'candidate' => '6.8.0-52.53', 'source' => 'noble-updates', 'security' => false, 'held' => true],
            ];

            return ['count' => count($packages), 'security' => 1, 'held' => ['linux-image-generic'], 'packages' => $packages];
        }

        $packages = [];
        foreach (Shell::run('apt list --upgradable 2>/dev/null', 60, null, false)->lines() as $line) {
            if (! preg_match('#^([^/\s]+)/(\S+)\s+(\S+)\s+\S+\s+\[upgradable from:\s*([^\]]+)\]#', trim($line), $m)) {
                continue;
            }
            $packages[] = [
                'name' => $m[1],
                'source' => $m[2],
                'candidate' => $m[3],
                'current' => trim($m[4]),
                'security' => str_contains($m[2], 'security'),
                'held' => false,
            ];
        }

        // what a regular update would leave behind
        $held = [];
        if ($packages && preg_match('/kept back:\s*\R((?:[ \t]+\S.*\R)+)/', Shell::run('apt-get -s upgrade 2>/dev/null', 120, null, false)->output, $m)) {
            $held = array_values(array_filter(preg_split('/\s+/', trim($m[1]))));
        }
        foreach ($packages as &$package) {
            $package['held'] = in_array($package['name'], $held, true);
        }
        unset($package);

        return [
            'count' => count($packages),
            'security' => count(array_filter($packages, fn ($p) => $p['security'])),
            'held' => $held,
            'packages' => $packages,
        ];
    }

    public function pendingUpdates(): int
    {
        return $this->updates()['count'];
    }

    public function rebootRequired(): bool
    {
        return ! Shell::simulating() && is_file('/var/run/reboot-required');
    }
}
