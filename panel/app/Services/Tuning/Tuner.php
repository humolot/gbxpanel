<?php

namespace App\Services\Tuning;

use App\Services\Shell;
use App\Services\ShellResult;
use App\Services\SystemStats;

/**
 * Performance settings of a server component (PHP-FPM, Apache, MySQL).
 *
 * Every tuner describes its fields, reads the current values, proposes values for the memory of
 * this machine and writes them back. Writes are guarded: the file is copied first, the component
 * validates the new configuration, and anything that fails puts the old file back.
 */
abstract class Tuner
{
    public const KEEP_BACKUPS = 5;

    /** Memory plans, as in the usual hosting panels; "auto" measures this server. */
    public const PLANS = [
        'auto' => ['label' => 'This server (measured)', 'mb' => 0],
        '1-2' => ['label' => '1 - 2 GB', 'mb' => 2048],
        '2-4' => ['label' => '2 - 4 GB', 'mb' => 4096],
        '4-8' => ['label' => '4 - 8 GB', 'mb' => 8192],
        '8-16' => ['label' => '8 - 16 GB', 'mb' => 16384],
        '16-32' => ['label' => '16 - 32 GB', 'mb' => 32768],
        '32-64' => ['label' => '32 - 64 GB', 'mb' => 65536],
    ];

    abstract public function key(): string;

    abstract public function label(): string;

    abstract public function installed(): bool;

    /** File the values are written to. */
    abstract public function file(): string;

    /** @return array<string, array<string, mixed>> field definitions, grouped */
    abstract public function fields(): array;

    /** @return array<string, string> current values */
    abstract public function values(): array;

    /** @return array<string, string> values proposed for a machine with this much memory */
    abstract public function suggest(int $ramMb): array;

    abstract public function apply(array $values): ShellResult;

    /** Live numbers and advice for the status panel. */
    public function status(): array
    {
        return [];
    }

    /* ================================================================ memory */

    public static function totalRamMb(): int
    {
        return max(512, (int) round(app(SystemStats::class)->memory()['total'] / 1048576));
    }

    public static function planRamMb(string $plan): int
    {
        return $plan === 'auto' || ! isset(self::PLANS[$plan]) ? self::totalRamMb() : (int) self::PLANS[$plan]['mb'];
    }

    protected static function clamp(float $value, int $min, int $max): int
    {
        return (int) max($min, min($max, round($value)));
    }

    /** "512M", "2G" or a plain number of bytes as megabytes. */
    public static function toMb(string $value): int
    {
        $value = trim($value);
        $number = (float) $value;

        return (int) round(match (strtoupper(substr($value, -1))) {
            'G' => $number * 1024,
            'M' => $number,
            'K' => $number / 1024,
            default => $number / 1048576,
        });
    }

    public static function mb(int $mb): string
    {
        return $mb >= 1024 && $mb % 1024 === 0 ? ($mb / 1024).'G' : $mb.'M';
    }

    /* =============================================================== writing */

    /** Check the values against the field definitions; returns the clean list or throws. */
    public function validate(array $input): array
    {
        $values = [];
        foreach ($this->fields() as $key => $field) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            $value = trim((string) $input[$key]);
            $label = $field['label'] ?? $key;

            $valid = match ($field['type'] ?? 'text') {
                'number' => preg_match('/^\d+$/', $value) && (int) $value >= ($field['min'] ?? 0) && (int) $value <= ($field['max'] ?? PHP_INT_MAX),
                'size' => (bool) preg_match('/^\d+[KMG]?$/i', $value),
                'select' => array_key_exists($value, $field['options'] ?? []),
                'bool' => in_array($value, ['0', '1'], true),
                default => (bool) preg_match('/^[\w.,:\/ -]{0,120}$/', $value),
            };
            if (! $valid) {
                throw new \InvalidArgumentException("Invalid value for {$label}: {$value}");
            }
            $values[$key] = $value;
        }
        if (! $values) {
            throw new \InvalidArgumentException('Nothing to change.');
        }

        return $values;
    }

    /** Copy of a file kept before it is changed, so a bad configuration can be undone. */
    protected function backup(string $file): string
    {
        $copy = $file.'.gbx-'.date('Ymd_His');
        Shell::run('[ -f '.Shell::arg($file).' ] && cp -a '.Shell::arg($file).' '.Shell::arg($copy).' || true', 30);
        Shell::run('ls -1t '.Shell::arg($file).'.gbx-* 2>/dev/null | tail -n +'.(self::KEEP_BACKUPS + 1).' | xargs -r rm -f --', 30);

        return $copy;
    }

    /**
     * Write, check and reload. When the check or the reload fails, the previous file comes back
     * and the component is reloaded with it, so a wrong value never leaves the service down.
     *
     * @param  string  $write  commands that change the file
     * @param  string  $test  command that validates the configuration (empty: no check)
     * @param  string  $reload  command that applies it
     */
    protected function guarded(string $file, string $write, string $test, string $reload): ShellResult
    {
        if (Shell::simulating()) {
            return new ShellResult(0, "[simulation] {$write}", '');
        }
        $copy = $this->backup($file);
        $result = Shell::run($write, 60);
        if ($result->failed()) {
            return $result;
        }

        if ($test !== '') {
            $check = Shell::run($test, 60);
            if ($check->failed()) {
                Shell::run('[ -f '.Shell::arg($copy).' ] && mv -f '.Shell::arg($copy).' '.Shell::arg($file), 30);

                return new ShellResult(1, '', 'The new configuration was refused, nothing changed: '.$check->message());
            }
        }

        $applied = Shell::run($reload, 180);
        if ($applied->failed()) {
            Shell::run('[ -f '.Shell::arg($copy).' ] && mv -f '.Shell::arg($copy).' '.Shell::arg($file), 30);
            Shell::run($reload, 180);

            return new ShellResult(1, '', 'The service did not accept the new configuration, the previous one was restored: '.$applied->message());
        }

        return $applied;
    }

    /** Replace "key = value" lines in an ini style file, adding the ones that are missing. */
    protected function iniWriter(string $file, array $values, string $glue = ' = '): string
    {
        $commands = [];
        foreach ($values as $key => $value) {
            $quoted = preg_quote($key, '#');
            $line = $key.$glue.$value;
            $commands[] = 'if grep -qE '.Shell::arg("^[; ]*{$quoted}\s*=").' '.Shell::arg($file)
                .'; then sed -i -E '.Shell::arg("s#^[; ]*{$quoted}\\s*=.*#".str_replace('#', '\#', $line).'#').' '.Shell::arg($file)
                .'; else echo '.Shell::arg($line).' >> '.Shell::arg($file).'; fi';
        }

        return implode(' && ', $commands);
    }

    /** Replace "Directive value" lines in an Apache style file. */
    protected function directiveWriter(string $file, array $values): string
    {
        $commands = [];
        foreach ($values as $key => $value) {
            $quoted = preg_quote($key, '#');
            $line = "\t".$key.' '.$value;
            $commands[] = 'if grep -qE '.Shell::arg("^[\t ]*#?[\t ]*{$quoted}[\t ]")." ".Shell::arg($file)
                .'; then sed -i -E '.Shell::arg("0,/^[\\t ]*#?[\\t ]*{$quoted}[\\t ].*/s##".str_replace('#', '\#', $line).'#').' '.Shell::arg($file)
                .'; else echo '.Shell::arg($line).' >> '.Shell::arg($file).'; fi';
        }

        return implode(' && ', $commands);
    }

    /** Read "key = value" pairs from a file. */
    protected function readIni(string $file, array $keys, string $pattern = '/^\s*%s\s*=\s*"?([^";\n]*)"?/m'): array
    {
        $content = Shell::readFile($file) ?? '';
        $values = [];
        foreach ($keys as $key) {
            preg_match(sprintf($pattern, preg_quote($key, '/')), $content, $m);
            $values[$key] = trim($m[1] ?? '');
        }

        return $values;
    }
}
