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

    /* ========================================================= patch updates */

    /** PHP version the panel itself runs on; it needs a restart after its packages change. */
    public static function panelVersion(): string
    {
        preg_match('/(\d+\.\d+)/', (string) config('gbx.php_cli', '/usr/bin/php8.4'), $m);

        return $m[1] ?? PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
    }

    /**
     * Installed and available release of every PHP version on this server.
     *
     * PHP publishes patch releases (8.4.12 to 8.4.15 and so on) every month; they carry the
     * security fixes and never change how the sites behave. Reading what apt already knows is
     * instant; $refresh asks the repository for a new list first.
     *
     * @return array<string, array{installed: ?string, candidate: ?string, update: bool}>
     */
    public function updates(array $versions, bool $refresh = false): array
    {
        if (! $versions) {
            return [];
        }
        if (Shell::simulating()) {
            return collect($versions)->mapWithKeys(fn ($v) => [$v => [
                'installed' => $v.'.12',
                'candidate' => $v === '8.4' ? $v.'.15' : $v.'.12',
                'update' => $v === '8.4',
            ]])->all();
        }

        if ($refresh) {
            Shell::run(Apt::preamble(120).'apt-get update -y >/dev/null 2>&1 || true', 300);
        }

        $packages = implode(' ', array_map(fn ($v) => Shell::arg("php{$v}-fpm"), $versions));
        $out = Shell::out('LC_ALL=C apt-cache policy '.$packages.' 2>/dev/null', 60);

        $result = [];
        foreach ($versions as $version) {
            $result[$version] = ['installed' => null, 'candidate' => null, 'update' => false];
        }
        $current = null;
        foreach (explode("\n", $out) as $line) {
            if (preg_match('/^php(\d+\.\d+)-fpm:/', trim($line), $m)) {
                $current = $m[1];

                continue;
            }
            if (! $current || ! isset($result[$current])) {
                continue;
            }
            if (preg_match('/^\s*(Installed|Candidate):\s*(\S+)/', $line, $m)) {
                $value = $m[2] === '(none)' ? null : self::shortVersion($m[2]);
                $result[$current][strtolower($m[1])] = $value;
            }
        }
        foreach ($result as $version => $row) {
            $result[$version]['update'] = $row['installed'] !== null && $row['candidate'] !== null && version_compare($row['candidate'], $row['installed'], '>');
        }

        return $result;
    }

    /** "8.4.15-1+ubuntu24.04.1+deb.sury.org+1" is shown as "8.4.15". */
    public static function shortVersion(string $package): string
    {
        preg_match('/^\d+:?(\d+(\.\d+)*)/', ltrim($package, '0123456789:'), $m);

        return $m[1] ?? (preg_match('/(\d+(\.\d+)+)/', $package, $n) ? $n[1] : $package);
    }

    /**
     * Install the newest packages of the given PHP versions.
     *
     * Only the packages already installed for that version are upgraded, so nothing new is added
     * to the server. When the version the panel runs on is updated, its own services are
     * restarted afterwards, detached, so the task can finish writing its log first.
     */
    /** Per version part of the update script; {V} is the version. */
    protected const UPDATE_TEMPLATE = <<<'BASH'
echo '==> PHP {V}'
BEFORE="$(/usr/bin/php{V} -r 'echo PHP_VERSION;' 2>/dev/null)"
PKGS="$(dpkg-query -W -f='${binary:Package}\t${db:Status-Status}\n' 'php{V}' 'php{V}-*' 'libapache2-mod-php{V}' 'libphp{V}-embed' 2>/dev/null | awk -F'\t' '$2 == "installed" { print $1 }')"
UPGRADE=""
for p in $PKGS; do
    cand="$(LC_ALL=C apt-cache policy "$p" 2>/dev/null | awk '/Candidate:/ { print $2 }')"
    case "$cand" in
        ''|'(none)') echo "    skipping $p: the repository no longer carries this package" ;;
        *) UPGRADE="$UPGRADE $p" ;;
    esac
done
if [ -n "$UPGRADE" ]; then
    apt-get install -y --only-upgrade -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold $UPGRADE || FAILED=1
    systemctl restart php{V}-fpm >/dev/null 2>&1 || true
else
    echo '    nothing to upgrade for this version'
fi
AFTER="$(/usr/bin/php{V} -r 'echo PHP_VERSION;' 2>/dev/null)"
if [ "$BEFORE" != "$AFTER" ]; then
    echo "    PHP {V}: $BEFORE -> $AFTER"
    CHANGED="$CHANGED {V}"
else
    echo "    PHP {V}: already at $AFTER"
fi
BASH;

    /**
     * Install the newest packages of the given PHP versions.
     *
     * Only the packages already installed for that version are upgraded, and only those the
     * repository still carries: a transitional package that became virtual (php8.4-json, for
     * example) makes apt refuse the whole command, which used to leave every version untouched.
     *
     * When the version that runs the panel really changed, its services are reloaded afterwards,
     * detached and gracefully, so the request being served at that moment does not fail.
     */
    public function updateScript(array $versions): string
    {
        $script = Apt::preamble()."FAILED=0\nCHANGED=''\napt-get update -y\n\n";

        foreach ($versions as $version) {
            if (! self::validVersion($version)) {
                throw new \InvalidArgumentException('Unknown PHP version: '.$version);
            }
            $script .= str_replace('{V}', $version, self::UPDATE_TEMPLATE)."

";
        }
        $script .= "apt-get autoremove -y\n";

        $panel = self::panelVersion();
        if (in_array($panel, $versions, true)) {
            $script .= <<<BASH

                case "\$CHANGED" in
                    *" {$panel}"*)
                        echo '==> The panel runs on PHP {$panel}: its services reload in a few seconds'
                        setsid nohup bash -c 'sleep 5; systemctl reload gbxpanel-fpm || systemctl restart gbxpanel-fpm; supervisorctl restart gbxpanel-worker:* >/dev/null 2>&1' >/dev/null 2>&1 </dev/null &
                        ;;
                esac

                BASH;
        }

        return $script."\nexit \$FAILED\n";
    }
    public function installExtensionScript(string $version, string $extension): string
    {
        if (! preg_match('/^[a-z0-9_-]{2,30}$/', $extension)) {
            throw new \InvalidArgumentException('Invalid extension name');
        }

        return Apt::guard("apt-get install -y php{$version}-{$extension}\nsystemctl restart php{$version}-fpm");
    }
}
