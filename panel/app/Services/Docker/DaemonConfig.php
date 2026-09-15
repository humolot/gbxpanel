<?php

namespace App\Services\Docker;

use App\Services\Shell;
use App\Services\ShellResult;

/** /etc/docker/daemon.json (Docker > Settings). */
class DaemonConfig
{
    public const FILE = '/etc/docker/daemon.json';

    public const LOG_DRIVERS = ['json-file', 'local', 'journald', 'syslog', 'none'];

    public function read(): array
    {
        if (Shell::simulating()) {
            return ['log-driver' => 'json-file', 'log-opts' => ['max-size' => '100m', 'max-file' => '3']];
        }
        $raw = Shell::readFile(self::FILE);
        $data = $raw ? json_decode($raw, true) : [];

        return is_array($data) ? $data : [];
    }

    /** Apply the settings form on top of the current configuration; keys the form does not manage are kept. */
    public static function merge(array $current, array $form): array
    {
        $lines = fn ($v) => array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) $v)))));
        $set = function (array &$cfg, string $key, $value) {
            if ($value === null || $value === [] || $value === '') {
                unset($cfg[$key]);
            } else {
                $cfg[$key] = $value;
            }
        };

        $mirrors = $lines($form['registry_mirrors'] ?? '');
        foreach ($mirrors as $url) {
            if (! preg_match('#^https?://[a-zA-Z0-9.-]+(:\d+)?(/\S*)?$#', $url)) {
                throw new \InvalidArgumentException("Invalid registry mirror: {$url}");
            }
        }
        $insecure = $lines($form['insecure_registries'] ?? '');
        foreach ($insecure as $host) {
            if (! preg_match('#^[a-zA-Z0-9.-]+(:\d+)?(/\d{1,2})?$#', $host)) {
                throw new \InvalidArgumentException("Invalid insecure registry: {$host}");
            }
        }
        $set($current, 'registry-mirrors', $mirrors);
        $set($current, 'insecure-registries', $insecure);

        $driver = (string) (($form['log_driver'] ?? '') ?: 'json-file');
        if (! in_array($driver, self::LOG_DRIVERS, true)) {
            throw new \InvalidArgumentException('Unsupported log driver');
        }
        $set($current, 'log-driver', $driver);
        $opts = (array) ($current['log-opts'] ?? []);
        unset($opts['max-size'], $opts['max-file']);
        if (in_array($driver, ['json-file', 'local'], true)) {
            $size = strtolower(trim((string) ($form['log_max_size'] ?? '')));
            $files = trim((string) ($form['log_max_file'] ?? ''));
            if ($size !== '') {
                if (! preg_match('/^\d+[kmg]$/', $size)) {
                    throw new \InvalidArgumentException('Log max size must look like 10m or 1g');
                }
                $opts['max-size'] = $size;
            }
            if ($files !== '') {
                if (! ctype_digit($files) || (int) $files < 1 || (int) $files > 100) {
                    throw new \InvalidArgumentException('Log files must be between 1 and 100');
                }
                $opts['max-file'] = $files;
            }
        }
        $set($current, 'log-opts', $opts ?: null);

        foreach (['live_restore' => 'live-restore', 'ipv6' => 'ipv6'] as $field => $key) {
            if (! empty($form[$field])) {
                $current[$key] = true;
            } else {
                unset($current[$key]);
            }
        }
        $cidr6 = trim((string) ($form['fixed_cidr_v6'] ?? ''));
        if (! empty($form['ipv6']) && $cidr6 !== '') {
            if (! preg_match('/^[0-9a-fA-F:]+\/\d{1,3}$/', $cidr6)) {
                throw new \InvalidArgumentException('Invalid IPv6 subnet');
            }
            $current['fixed-cidr-v6'] = $cidr6;
        } else {
            unset($current['fixed-cidr-v6']);
        }

        if (array_key_exists('iptables', $form)) {
            if ($form['iptables']) {
                unset($current['iptables']);
            } else {
                $current['iptables'] = false;
            }
        }

        return $current;
    }

    public static function encode(array $config): string
    {
        return $config ? json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n" : "{}\n";
    }

    /** Write, validate and restart Docker; the previous file is restored when Docker does not come back. */
    public function save(array $config): ShellResult
    {
        if (Shell::simulating()) {
            return new ShellResult(0, self::encode($config), '');
        }
        $f = Shell::arg(self::FILE);
        $b = Shell::arg(self::FILE.'.gbx-bak');
        Shell::run("mkdir -p /etc/docker; if [ -f {$f} ]; then cp -p {$f} {$b}; else rm -f {$b}; fi", 10);
        $write = Shell::writeFile(self::FILE, self::encode($config), '0644', 'root:root');
        if ($write->failed()) {
            return $write;
        }

        $restore = "if [ -f {$b} ]; then mv -f {$b} {$f}; else rm -f {$f}; fi";
        // dockerd --validate exists since Docker 23
        $validate = Shell::run("if dockerd --help 2>/dev/null | grep -q -- '--validate'; then dockerd --validate --config-file={$f}; fi", 30);
        if ($validate->failed()) {
            Shell::run($restore, 10);

            return new ShellResult(1, '', 'Docker rejected the configuration: '.trim($validate->output.$validate->error));
        }

        $restart = Shell::run('systemctl restart docker && sleep 2 && docker info >/dev/null', 180);
        if ($restart->failed()) {
            Shell::run($restore.' && systemctl restart docker', 180);

            return new ShellResult(1, '', 'Docker did not start with the new configuration, the previous file was restored. '.trim($restart->error));
        }
        Shell::run("rm -f {$b}", 10);

        return new ShellResult(0, 'Docker restarted', '');
    }
}
