<?php

namespace App\Services;

/**
 * Supervisor programs (queue workers, daemons). Programs created by the
 * panel live in /etc/supervisor/conf.d/gbx-<name>.conf.
 */
class SupervisorManager
{
    public const CONF_DIR = '/etc/supervisor/conf.d';

    /** Programs that belong to the panel itself and cannot be removed. */
    public const PROTECTED = ['gbxpanel-worker'];

    public static function validName(string $name): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9_-]{1,40}$/', $name);
    }

    public function installed(): bool
    {
        return Shell::simulating() || Shell::commandExists('supervisorctl');
    }

    public function confPath(string $name): string
    {
        return self::CONF_DIR.'/gbx-'.$name.'.conf';
    }

    public function list(): array
    {
        if (Shell::simulating()) {
            return [
                ['program' => 'gbxpanel-worker', 'process' => 'gbxpanel-worker:gbxpanel-worker_00', 'state' => 'RUNNING', 'info' => 'pid 812, uptime 6 days, 1:02:11', 'managed' => false],
                ['program' => 'shop-queue', 'process' => 'shop-queue:shop-queue_00', 'state' => 'RUNNING', 'info' => 'pid 1204, uptime 2 days, 3:10:00', 'managed' => true],
            ];
        }

        $rows = [];
        foreach (Shell::run('supervisorctl status 2>&1', 20)->lines() as $line) {
            if (! preg_match('/^(\S+)\s+(\S+)\s*(.*)$/', $line, $m)) {
                continue;
            }
            $program = explode(':', $m[1])[0];
            $rows[] = ['program' => $program, 'process' => $m[1], 'state' => $m[2], 'info' => trim($m[3]), 'managed' => Shell::fileExists($this->confPath($program))];
        }

        return $rows;
    }

    public function config(string $name): ?string
    {
        if (! self::validName($name)) {
            return null;
        }
        if (Shell::simulating()) {
            return "[program:{$name}]\ncommand=php artisan queue:work\n";
        }
        $file = Shell::out('grep -l '.Shell::arg('^\[program:'.$name.'\]').' '.self::CONF_DIR.'/*.conf 2>/dev/null | head -1', 10);

        return $file ? Shell::readFile($file) : null;
    }

    /**
     * Create or replace a program.
     *
     * @param  array{command:string, directory?:string, user?:string, numprocs?:int, autostart?:bool, autorestart?:bool, environment?:array, stopwaitsecs?:int}  $opt
     */
    public function save(string $name, array $opt): ShellResult
    {
        if (! self::validName($name)) {
            return new ShellResult(1, '', 'Program name must be 2-41 characters: lowercase letters, numbers, - and _.');
        }
        $command = trim(str_replace(["\r", "\n"], ' ', (string) ($opt['command'] ?? '')));
        if ($command === '') {
            return new ShellResult(1, '', 'The command is required.');
        }
        $user = (string) (($opt['user'] ?? '') ?: 'www-data');
        if (! preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $user)) {
            return new ShellResult(1, '', 'Invalid system user.');
        }
        $directory = FileManager::normalize((string) (($opt['directory'] ?? '') ?: '/tmp'));
        $numprocs = max(1, min(32, (int) ($opt['numprocs'] ?? 1)));

        $env = [];
        foreach ((array) ($opt['environment'] ?? []) as $key => $value) {
            if (is_int($key) && is_string($value) && str_contains($value, '=')) {
                [$key, $value] = explode('=', $value, 2);
            }
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $key)) {
                return new ShellResult(1, '', "Invalid environment variable name: {$key}");
            }
            $env[] = $key.'="'.str_replace(['"', '%', "\n"], ['\"', '%%', ' '], (string) $value).'"';
        }

        $log = '/var/log/supervisor/gbx-'.$name.'.log';
        $conf = implode("\n", array_filter([
            '; Managed by GBX Panel',
            "[program:{$name}]",
            'command='.str_replace('%', '%%', $command),
            "directory={$directory}",
            "user={$user}",
            "numprocs={$numprocs}",
            'process_name=%(program_name)s_%(process_num)02d',
            'autostart='.(($opt['autostart'] ?? true) ? 'true' : 'false'),
            'autorestart='.(($opt['autorestart'] ?? true) ? 'true' : 'false'),
            'stopasgroup=true',
            'killasgroup=true',
            'stopwaitsecs='.max(1, (int) ($opt['stopwaitsecs'] ?? 60)),
            'redirect_stderr=true',
            "stdout_logfile={$log}",
            'stdout_logfile_maxbytes=20MB',
            'stdout_logfile_backups=3',
            $env ? 'environment='.implode(',', $env) : null,
        ]))."\n";

        $write = Shell::writeFile($this->confPath($name), $conf, '0644', 'root:root');
        if ($write->failed()) {
            return $write;
        }

        return Shell::run('supervisorctl reread && supervisorctl update '.Shell::arg($name).' && sleep 1 && supervisorctl status '.Shell::arg($name.':*'), 60);
    }

    public function action(string $name, string $action): ShellResult
    {
        if (! self::validName($name) || ! in_array($action, ['start', 'stop', 'restart'], true)) {
            return new ShellResult(1, '', 'Invalid program or action');
        }

        return Shell::run('supervisorctl '.$action.' '.Shell::arg($name.':*').' 2>&1; supervisorctl status '.Shell::arg($name.':*'), 90);
    }

    public function reload(): ShellResult
    {
        return Shell::run('supervisorctl reread && supervisorctl update', 60);
    }

    public function delete(string $name): ShellResult
    {
        if (! self::validName($name) || in_array($name, self::PROTECTED, true)) {
            return new ShellResult(1, '', 'This program cannot be removed.');
        }
        if (! Shell::simulating() && ! Shell::fileExists($this->confPath($name))) {
            return new ShellResult(1, '', 'Only programs created by GBX Panel (gbx-<name>.conf) can be removed.');
        }

        return Shell::run('supervisorctl stop '.Shell::arg($name.':*').' >/dev/null 2>&1; rm -f '.Shell::arg($this->confPath($name)).' && supervisorctl reread && supervisorctl update', 60);
    }

    public function log(string $name, int $lines = 200): string
    {
        if (! self::validName($name)) {
            return 'Invalid program';
        }
        if (Shell::simulating()) {
            return "[simulation] {$name} log\nProcessing jobs...\n";
        }
        $file = '/var/log/supervisor/gbx-'.$name.'.log';

        return Shell::run('if [ -f '.Shell::arg($file).' ]; then tail -n '.(int) $lines.' '.Shell::arg($file).'; else supervisorctl tail -'.($lines * 200).' '.Shell::arg($name.':'.$name.'_00').' 2>&1; fi', 20)->output;
    }
}
