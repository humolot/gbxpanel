<?php

namespace App\Services;

use Symfony\Component\Process\Process;

/**
 * Thin wrapper around Symfony Process.
 *
 * Every privileged action of the panel goes through this class so there is a
 * single place that decides how commands are executed (sudo, simulation, env).
 */
class Shell
{
    public static function simulating(): bool
    {
        return (bool) config('gbx.simulate');
    }

    public static function isRoot(): bool
    {
        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    public static function arg(string|int|float|null $value): string
    {
        return escapeshellarg((string) $value);
    }

    /**
     * Run a bash command line. Runs as root (via sudo) unless $root is false.
     */
    public static function run(string $command, int $timeout = 120, ?string $input = null, bool $root = true, ?string $cwd = null): ShellResult
    {
        if (static::simulating()) {
            return new ShellResult(0, '', '', $command);
        }

        $argv = ['/bin/bash', '-c', $command];
        if ($root && ! static::isRoot() && config('gbx.use_sudo')) {
            $argv = array_merge(['sudo', '-n', '-H'], $argv);
        }

        $process = new Process($argv, $cwd, [
            'LC_ALL' => 'C.UTF-8',
            'LANG' => 'C.UTF-8',
            'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/snap/bin',
            'DEBIAN_FRONTEND' => 'noninteractive',
            'COMPOSER_ALLOW_SUPERUSER' => '1',
        ], $input, $timeout);

        try {
            $process->run();
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
            return new ShellResult(124, $process->getOutput(), 'Command timed out after '.$timeout.'s', $command);
        } catch (\Throwable $e) {
            return new ShellResult(1, '', $e->getMessage(), $command);
        }

        return new ShellResult((int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput(), $command);
    }

    /** Run and return trimmed stdout (empty string on failure). */
    public static function out(string $command, int $timeout = 30, bool $root = true): string
    {
        $result = static::run($command, $timeout, null, $root);

        return $result->ok() ? trim($result->output) : '';
    }

    public static function test(string $command, bool $root = true): bool
    {
        return ! static::simulating() && static::run($command, 20, null, $root)->ok();
    }

    /** Write content to any path as root, creating parent directories. */
    public static function writeFile(string $path, string $content, string $mode = '0644', ?string $owner = null): ShellResult
    {
        $p = static::arg($path);
        $cmd = 'mkdir -p "$(dirname '.$p.')" && cat > '.$p.' && chmod '.static::arg($mode).' '.$p;
        if ($owner) {
            $cmd .= ' && chown '.static::arg($owner).' '.$p;
        }

        return static::run($cmd, 60, $content);
    }

    public static function readFile(string $path, int $maxBytes = 5242880): ?string
    {
        if (static::simulating()) {
            return null;
        }
        $result = static::run('head -c '.(int) $maxBytes.' '.static::arg($path), 30);

        return $result->ok() ? $result->output : null;
    }

    public static function fileExists(string $path): bool
    {
        return static::test('test -e '.static::arg($path));
    }

    public static function commandExists(string $binary): bool
    {
        return static::test('command -v '.static::arg($binary).' >/dev/null 2>&1');
    }
}
