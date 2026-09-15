<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Support\Facades\Cache;

class SoftwareManager
{
    public function __construct(protected ServiceManager $services) {}

    public function catalog(): array
    {
        return config('software');
    }

    public function get(string $key): ?array
    {
        return $this->catalog()[$key] ?? null;
    }

    protected function fill(?string $text, ?string $version): ?string
    {
        return $text === null ? null : str_replace('{v}', (string) $version, $text);
    }

    /** Detect installed state for every package (cached for a short time). */
    public function all(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget('gbx.software');
        }

        return Cache::remember('gbx.software', 60, function () {
            $rows = [];
            foreach ($this->catalog() as $key => $item) {
                $versions = $item['versions'] ?? [null];
                foreach ($versions as $v) {
                    $rows[] = $this->detect($key, $item, $v);
                }
            }

            return $rows;
        });
    }

    public function detect(string $key, array $item, ?string $version): array
    {
        $installed = Shell::simulating()
            ? in_array($key.($version ? '-'.$version : ''), ['php-8.4', 'php-8.3', 'apache', 'mysql', 'nodejs-22', 'composer', 'supervisor', 'certbot', 'phpmyadmin', 'redis', 'docker'], true)
            : Shell::test($this->fill($item['detect'], $version), false);

        $service = $this->fill($item['service'] ?? null, $version);
        $running = null;
        $installedVersion = null;
        if ($installed) {
            if (Shell::simulating()) {
                $installedVersion = ['php' => $version.'.12', 'apache' => '2.4.58', 'mysql' => '8.0.45', 'nodejs' => '22.9.0 / npm 10.8.3', 'composer' => '2.8.10', 'supervisor' => '4.2.5', 'certbot' => '2.9.0', 'phpmyadmin' => '5.2.2', 'redis' => '7.0.15', 'docker' => '27.3.1'][$key] ?? null;
            } elseif (! empty($item['version_cmd'])) {
                $installedVersion = Shell::out($this->fill($item['version_cmd'], $version), 10, false) ?: null;
            }
            if ($service) {
                $running = $this->services->status($service)['active'];
            }
        }

        return [
            'key' => $key,
            'id' => $key.($version ? '-'.$version : ''),
            'name' => $item['name'].($version ? ' '.$version : ''),
            'category' => $item['category'],
            'icon' => $item['icon'],
            'description' => $item['description'],
            'version' => $version,
            'installed' => $installed,
            'installed_version' => $installedVersion ? mb_substr($installedVersion, 0, 40) : null,
            'service' => $service,
            'running' => $running,
            'config_file' => $this->fill($item['config_file'] ?? null, $version),
            'core' => (bool) ($item['core'] ?? false),
            'can_uninstall' => ! empty($item['uninstall']),
        ];
    }

    public function install(string $key, ?string $version): Task
    {
        $item = $this->get($key) ?? throw new \InvalidArgumentException('Unknown package');
        $this->assertVersion($item, $version);
        Cache::forget('gbx.software');

        return TaskRunner::dispatch('Install '.$item['name'].($version ? ' '.$version : ''), $this->fill($item['install'], $version), 'software', ['package' => $key, 'version' => $version]);
    }

    public function uninstall(string $key, ?string $version): Task
    {
        $item = $this->get($key) ?? throw new \InvalidArgumentException('Unknown package');
        $this->assertVersion($item, $version);
        if (empty($item['uninstall'])) {
            throw new \InvalidArgumentException($item['name'].' is a core component and cannot be removed.');
        }
        Cache::forget('gbx.software');

        return TaskRunner::dispatch('Uninstall '.$item['name'].($version ? ' '.$version : ''), $this->fill($item['uninstall'], $version), 'software', ['package' => $key, 'version' => $version]);
    }

    protected function assertVersion(array $item, ?string $version): void
    {
        $versions = $item['versions'] ?? null;
        if ($versions && ! in_array($version, $versions, true)) {
            throw new \InvalidArgumentException('Invalid version');
        }
        if (! $versions && $version) {
            throw new \InvalidArgumentException('This package has no versions');
        }
    }

    /** Installed PHP versions (used by website forms). */
    public function phpVersions(): array
    {
        $versions = [];
        foreach (config('software.php.versions') as $v) {
            $installed = Shell::simulating() ? in_array($v, ['8.4', '8.3'], true) : is_file("/usr/sbin/php-fpm{$v}");
            if ($installed) {
                $versions[] = $v;
            }
        }

        return $versions;
    }

    public function mysqlInstalled(): bool
    {
        return Shell::simulating() || Shell::commandExists('mysql');
    }
}
