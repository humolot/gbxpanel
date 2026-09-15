<?php

namespace App\Services;

/**
 * PM2 process manager (runs as root, PM2_HOME=/root/.pm2).
 */
class Pm2Manager
{
    public static function validName(string $name): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,60}$/', $name);
    }

    public function installed(): bool
    {
        return Shell::simulating() || Shell::commandExists('pm2');
    }

    protected function pm2(string $args, int $timeout = 60): ShellResult
    {
        return Shell::run('export PM2_HOME=/root/.pm2 HOME=/root; pm2 '.$args, $timeout);
    }

    public function list(): array
    {
        if (Shell::simulating()) {
            return [['id' => 0, 'name' => 'api', 'status' => 'online', 'cpu' => 1.2, 'memory' => '84.1 MB', 'restarts' => 0, 'uptime' => '2d 4h', 'cwd' => '/www/wwwroot/api.example.com', 'script' => 'dist/server.js', 'instances' => 'fork']];
        }
        if (! $this->installed()) {
            return [];
        }

        $json = json_decode($this->pm2('jlist 2>/dev/null', 30)->output, true) ?: [];

        return array_map(fn ($p) => [
            'id' => $p['pm_id'] ?? null,
            'name' => $p['name'] ?? '',
            'status' => $p['pm2_env']['status'] ?? 'unknown',
            'cpu' => $p['monit']['cpu'] ?? 0,
            'memory' => SystemStats::bytes((int) ($p['monit']['memory'] ?? 0), 1),
            'restarts' => $p['pm2_env']['restart_time'] ?? 0,
            'uptime' => isset($p['pm2_env']['pm_uptime']) ? SystemStats::humanDuration((int) (time() - $p['pm2_env']['pm_uptime'] / 1000)) : null,
            'cwd' => $p['pm2_env']['pm_cwd'] ?? null,
            'script' => $p['pm2_env']['pm_exec_path'] ?? null,
            'instances' => $p['pm2_env']['exec_mode'] ?? null,
        ], $json);
    }

    /**
     * @param  array{name:string, script:string, cwd?:string, interpreter?:string, args?:string, instances?:int, env?:array, max_memory?:string}  $opt
     */
    public function start(array $opt): ShellResult
    {
        $name = (string) ($opt['name'] ?? '');
        if (! self::validName($name)) {
            return new ShellResult(1, '', 'Invalid process name.');
        }
        $script = trim((string) ($opt['script'] ?? ''));
        if ($script === '' || str_contains($script, "\n")) {
            return new ShellResult(1, '', 'Script or command is required (e.g. dist/server.js, npm, "npm run start").');
        }

        $env = '';
        foreach ((array) ($opt['env'] ?? []) as $key => $value) {
            if (is_int($key) && is_string($value) && str_contains($value, '=')) {
                [$key, $value] = explode('=', $value, 2);
            }
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $key)) {
                return new ShellResult(1, '', "Invalid environment variable: {$key}");
            }
            $env .= ' '.$key.'='.Shell::arg((string) $value);
        }

        $cwd = FileManager::normalize((string) (($opt['cwd'] ?? '') ?: '/root'));
        $cmd = 'cd '.Shell::arg($cwd).' && export PM2_HOME=/root/.pm2 HOME=/root && env'.$env.' pm2 start '.Shell::arg($script).' --name '.Shell::arg($name).' --cwd '.Shell::arg($cwd);
        if (! empty($opt['interpreter'])) {
            if (! preg_match('/^[a-z0-9._\/-]+$/i', (string) $opt['interpreter'])) {
                return new ShellResult(1, '', 'Invalid interpreter.');
            }
            $cmd .= ' --interpreter '.Shell::arg((string) $opt['interpreter']);
        }
        if (! empty($opt['instances'])) {
            $cmd .= ' -i '.max(1, min(64, (int) $opt['instances']));
        }
        if (! empty($opt['max_memory']) && preg_match('/^\d+[MG]$/i', (string) $opt['max_memory'])) {
            $cmd .= ' --max-memory-restart '.$opt['max_memory'];
        }
        if (! empty($opt['args'])) {
            $cmd .= ' -- '.str_replace(["\n", ';', '&', '|', '`', '$('], ' ', (string) $opt['args']);
        }

        return Shell::run($cmd.' && pm2 save', 120);
    }

    public function action(string $name, string $action): ShellResult
    {
        if (($name !== 'all' && ! self::validName($name)) || ! in_array($action, ['restart', 'reload', 'stop', 'start', 'delete', 'reset'], true)) {
            return new ShellResult(1, '', 'Invalid process or action');
        }

        return $this->pm2($action.' '.Shell::arg($name).' && pm2 save', 90);
    }

    public function saveStartup(): ShellResult
    {
        return $this->pm2('save && pm2 startup systemd -u root --hp /root', 90);
    }

    public function logs(string $name, int $lines = 150): string
    {
        if (Shell::simulating()) {
            return "[simulation] {$name}: Server listening on port 3000\n";
        }
        if (! self::validName($name)) {
            return 'Invalid process';
        }

        return $this->pm2('logs '.Shell::arg($name).' --lines '.(int) $lines.' --nostream --raw 2>&1', 30)->output;
    }
}
