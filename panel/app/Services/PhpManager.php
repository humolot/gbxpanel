<?php

namespace App\Services;

class PhpManager
{
    public const SETTINGS = [
        'memory_limit' => ['label' => 'Memory limit', 'pattern' => '/^(-1|\d+[KMG]?)$/i'],
        'upload_max_filesize' => ['label' => 'Upload max filesize', 'pattern' => '/^\d+[KMG]?$/i'],
        'post_max_size' => ['label' => 'Post max size', 'pattern' => '/^\d+[KMG]?$/i'],
        'max_execution_time' => ['label' => 'Max execution time (s)', 'pattern' => '/^\d+$/'],
        'max_input_time' => ['label' => 'Max input time (s)', 'pattern' => '/^-?\d+$/'],
        'max_input_vars' => ['label' => 'Max input vars', 'pattern' => '/^\d+$/'],
        'display_errors' => ['label' => 'Display errors', 'pattern' => '/^(On|Off)$/'],
        'date.timezone' => ['label' => 'Timezone', 'pattern' => '/^[A-Za-z_\/+-]*$/'],
        'short_open_tag' => ['label' => 'Short open tag', 'pattern' => '/^(On|Off)$/'],
    ];

    public static function validVersion(string $v): bool
    {
        return in_array($v, config('software.php.versions'), true);
    }

    public function iniPath(string $version): string
    {
        return "/etc/php/{$version}/fpm/php.ini";
    }

    public function settings(string $version): array
    {
        $values = [];
        if (Shell::simulating()) {
            return ['memory_limit' => '256M', 'upload_max_filesize' => '64M', 'post_max_size' => '64M', 'max_execution_time' => '300', 'max_input_time' => '60', 'max_input_vars' => '3000', 'display_errors' => 'Off', 'date.timezone' => 'UTC', 'short_open_tag' => 'Off'];
        }
        $ini = Shell::readFile($this->iniPath($version)) ?? '';
        foreach (array_keys(self::SETTINGS) as $key) {
            preg_match('/^\s*'.preg_quote($key, '/').'\s*=\s*"?([^"\n;]*)"?/m', $ini, $m);
            $values[$key] = trim($m[1] ?? '');
        }

        return $values;
    }

    public function saveSettings(string $version, array $data): ShellResult
    {
        $ini = $this->iniPath($version);
        $cmds = [];
        foreach (self::SETTINGS as $key => $def) {
            if (! array_key_exists($key, $data) || $data[$key] === null) {
                continue;
            }
            $value = trim((string) $data[$key]);
            if (! preg_match($def['pattern'], $value)) {
                return new ShellResult(1, '', "Invalid value for {$key}");
            }
            $k = preg_quote($key, '#');
            // replace existing (commented or not) directive, otherwise append
            $cmds[] = "if grep -qE '^\s*;?\s*{$k}\s*=' ".Shell::arg($ini)."; then sed -i -E 's#^\s*;?\s*{$k}\s*=.*#{$key} = {$value}#' ".Shell::arg($ini)."; else echo '{$key} = {$value}' >> ".Shell::arg($ini).'; fi';
        }
        $cmds[] = "php-fpm{$version} -t 2>&1 && systemctl reload php{$version}-fpm";

        return Shell::run(implode(' && ', $cmds), 60);
    }

    public function extensions(string $version): array
    {
        if (Shell::simulating()) {
            return ['bcmath', 'Core', 'ctype', 'curl', 'date', 'dom', 'fileinfo', 'gd', 'intl', 'json', 'mbstring', 'mysqli', 'openssl', 'pdo_mysql', 'pdo_sqlite', 'redis', 'sodium', 'xml', 'zip', 'Zend OPcache'];
        }
        $out = Shell::out("/usr/bin/php{$version} -m 2>/dev/null", 15, false);

        return array_values(array_filter(array_map('trim', explode("\n", $out)), fn ($l) => $l !== '' && ! str_starts_with($l, '[')));
    }

    public function installExtensionScript(string $version, string $extension): string
    {
        if (! preg_match('/^[a-z0-9_-]{2,30}$/', $extension)) {
            throw new \InvalidArgumentException('Invalid extension name');
        }

        return "export DEBIAN_FRONTEND=noninteractive\napt-get install -y php{$version}-{$extension}\nsystemctl restart php{$version}-fpm";
    }
}
