<?php

namespace App\Services\Ai\Tools;

use App\Services\FileManager;
use App\Services\Pm2Manager;
use App\Services\Shell;
use App\Services\SupervisorManager;
use App\Services\TaskRunner;

/** Supervisor workers, PM2 apps and Git deployments. */
class ProcessManagerTools extends ToolGroup
{
    public function __construct(protected SupervisorManager $supervisor, protected Pm2Manager $pm2) {}

    public function tools(): array
    {
        return [
            // ------------------------------------------------------------ supervisor
            'list_supervisor_programs' => self::tool('Supervisor programs (queue workers, daemons) with state, pid and uptime.', self::params(), false, false, fn () => 'Listed Supervisor programs'),
            'get_supervisor_program' => self::tool('Configuration and recent log of a Supervisor program.', self::params(['name' => self::str('Program name'), 'lines' => self::int('Log lines, default 100')], ['name']), false, false, fn ($a) => 'Inspected Supervisor program '.self::labelArg($a, 'name')),
            'save_supervisor_program' => self::tool('Create or update a Supervisor program, e.g. a Laravel queue worker: command="php /www/wwwroot/app/artisan queue:work --sleep=3 --tries=3", user=www-data, numprocs=2. It is started automatically.', self::params([
                'name' => self::str('Program name (lowercase, -, _)'),
                'command' => self::str('Command to run (foreground, not daemonized)'),
                'directory' => self::str('Working directory'),
                'user' => self::str('System user, default www-data'),
                'numprocs' => self::int('Number of processes, default 1'),
                'autostart' => self::bool('Start on boot, default true'),
                'autorestart' => self::bool('Restart when it exits, default true'),
                'environment' => self::map('Environment variables'),
                'stopwaitsecs' => self::int('Seconds to wait on stop, default 60'),
            ], ['name', 'command']), true, false, fn ($a) => 'Save Supervisor program '.self::labelArg($a, 'name')),
            'supervisor_action' => self::tool('Start, stop or restart a Supervisor program (all its processes), or reload all configuration.', self::params(['name' => self::str('Program name (ignored for reload)'), 'action' => self::str('Action', ['start', 'stop', 'restart', 'reload'])], ['action']), true, false, fn ($a) => ucfirst(self::labelArg($a, 'action')).' Supervisor '.self::labelArg($a, 'name')),
            'delete_supervisor_program' => self::tool('Stop and delete a Supervisor program created by the panel.', self::params(['name' => self::str('Program name')], ['name']), true, false, fn ($a) => 'Delete Supervisor program '.self::labelArg($a, 'name')),

            // ------------------------------------------------------------ pm2
            'list_pm2_processes' => self::tool('PM2 apps (Node.js etc.) with status, CPU, memory, restarts and working directory.', self::params(), false, false, fn () => 'Listed PM2 apps'),
            'get_pm2_logs' => self::tool('Recent logs of a PM2 app.', self::params(['name' => self::str('App name'), 'lines' => self::int('Lines, default 100')], ['name']), false, false, fn ($a) => 'Read PM2 logs of '.self::labelArg($a, 'name')),
            'pm2_start' => self::tool('Start an app with PM2 and save the process list. script can be a file (dist/server.js, app.py) or a binary like npm with args "run start".', self::params([
                'name' => self::str('App name'),
                'script' => self::str('Script path relative to cwd, or binary (npm, yarn, node)'),
                'cwd' => self::str('Working directory'),
                'args' => self::str('Arguments passed to the script, e.g. run start'),
                'interpreter' => self::str('Interpreter, e.g. node, python3, none'),
                'instances' => self::int('Cluster instances (omit for fork mode)'),
                'env' => self::map('Environment variables, e.g. {"PORT":"3000","NODE_ENV":"production"}'),
                'max_memory' => self::str('Restart when memory exceeds, e.g. 512M'),
            ], ['name', 'script', 'cwd']), true, false, fn ($a) => 'Start PM2 app '.self::labelArg($a, 'name')),
            'pm2_action' => self::tool('Restart, reload (zero-downtime), stop, start, delete or reset counters of a PM2 app ("all" for every app).', self::params(['name' => self::str('App name or all'), 'action' => self::str('Action', ['restart', 'reload', 'stop', 'start', 'delete', 'reset'])], ['name', 'action']), true, false, fn ($a) => ucfirst(self::labelArg($a, 'action')).' PM2 '.self::labelArg($a, 'name')),
            'pm2_enable_startup' => self::tool('Save the current PM2 process list and enable PM2 on boot (systemd).', self::params(), true, false, fn () => 'Enable PM2 on boot'),

            // ------------------------------------------------------------ deploy
            'git_deploy' => self::tool('Deploy code from a Git repository into a directory: clone if empty, otherwise fetch and hard-reset to the branch (local changes are discarded). Then run optional build commands (e.g. "composer install --no-dev", "npm ci", "npm run build", "php artisan migrate --force") and fix ownership. Background task.', self::params([
                'repository' => self::str('https:// or git@ repository URL (private repos need a deploy key on the server)'),
                'path' => self::str('Target directory, e.g. /www/wwwroot/example.com'),
                'branch' => self::str('Branch, default main'),
                'commands' => self::list('Commands to run inside the directory after pulling'),
                'owner' => self::str('Owner for the files, default www-data:www-data'),
            ], ['repository', 'path']), true, true, fn ($a) => 'Deploy '.self::labelArg($a, 'repository').' to '.self::labelArg($a, 'path')),
        ];
    }

    public function handle(string $name, array $a): mixed
    {
        switch ($name) {
            case 'list_supervisor_programs':
                return $this->supervisor->installed() ? $this->supervisor->list() : ['error' => 'Supervisor is not installed. Use manage_software key=supervisor.'];

            case 'get_supervisor_program':
                return ['config' => $this->supervisor->config((string) $a['name']) ?? 'not found', 'log' => $this->supervisor->log((string) $a['name'], min(1000, (int) self::a($a, 'lines', 100)))];

            case 'save_supervisor_program':
                if (in_array($a['name'], SupervisorManager::PROTECTED, true)) {
                    return ['ok' => false, 'error' => 'This program belongs to GBX Panel and cannot be changed.'];
                }

                return $this->shell($this->supervisor->save((string) $a['name'], $a), "Supervisor program {$a['name']} saved and started");

            case 'supervisor_action':
                return self::a($a, 'action') === 'reload'
                    ? $this->shell($this->supervisor->reload(), 'Supervisor configuration reloaded')
                    : $this->shell($this->supervisor->action((string) self::a($a, 'name', ''), (string) $a['action']), ucfirst((string) $a['action']).' '.$a['name'].': done');

            case 'delete_supervisor_program':
                return $this->shell($this->supervisor->delete((string) $a['name']), "Supervisor program {$a['name']} deleted");

            case 'list_pm2_processes':
                return $this->pm2->installed() ? $this->pm2->list() : ['error' => 'PM2 is not installed. Install nodejs, then manage_software key=pm2.'];

            case 'get_pm2_logs':
                return $this->pm2->logs((string) $a['name'], min(1000, (int) self::a($a, 'lines', 100)));

            case 'pm2_start':
                if (! $this->pm2->installed()) {
                    return ['error' => 'PM2 is not installed. Install nodejs, then manage_software key=pm2.'];
                }

                return $this->shell($this->pm2->start($a), "PM2 app {$a['name']} started");

            case 'pm2_action':
                return $this->shell($this->pm2->action((string) $a['name'], (string) $a['action']), ucfirst((string) $a['action']).' '.$a['name'].': done');

            case 'pm2_enable_startup':
                return $this->shell($this->pm2->saveStartup(), 'PM2 will start on boot');

            case 'git_deploy':
                return $this->gitDeploy($a);
        }

        return ['error' => "Unknown tool {$name}"];
    }

    protected function gitDeploy(array $a): array
    {
        $repo = (string) $a['repository'];
        if (! preg_match('#^(https://[\w.@:/\-~]+|git@[\w.\-]+:[\w./\-~]+|ssh://[\w.@:/\-~]+)$#', $repo)) {
            return ['error' => 'Use an https://, git@host:owner/repo.git or ssh:// repository URL.'];
        }
        $branch = (string) self::a($a, 'branch', 'main');
        if (! preg_match('#^[\w./\-]{1,100}$#', $branch)) {
            return ['error' => 'Invalid branch name'];
        }
        $path = FileManager::normalize((string) $a['path']);
        if (in_array($path, FileManager::PROTECTED, true) || substr_count($path, '/') < 2) {
            return ['error' => 'Choose a project directory such as /www/wwwroot/example.com'];
        }
        $owner = (string) self::a($a, 'owner', 'www-data:www-data');
        if (! preg_match('/^[a-z_][\w-]*(:[a-z_][\w-]*)?$/i', $owner)) {
            return ['error' => 'Invalid owner'];
        }

        $p = Shell::arg($path);
        $b = Shell::arg($branch);
        $script = "set -e\ncommand -v git >/dev/null || { export DEBIAN_FRONTEND=noninteractive; apt-get install -y git; }\n"
            ."mkdir -p {$p}\ncd {$p}\ngit config --global --add safe.directory {$p} || true\n"
            ."if [ -d .git ]; then\n  echo 'Updating existing checkout'\n  git fetch --prune origin {$b}\n  git reset --hard origin/{$branch}\n  git clean -fd -e .env -e storage -e node_modules -e vendor\n"
            ."elif [ -z \"\$(ls -A {$p} | grep -v -e '^.well-known$' -e '^index.html$')\" ]; then\n  echo 'Cloning repository'\n  rm -f index.html\n  git clone --branch {$b} --depth 50 ".Shell::arg($repo)." .\n"
            ."else\n  echo 'The directory is not empty and is not a git checkout. Aborting to avoid overwriting files.'; exit 1\nfi\n"
            ."git log -1 --pretty='Deployed commit %h: %s (%an, %ar)'\n";

        foreach (self::strings($a['commands'] ?? []) as $command) {
            $script .= 'echo '.Shell::arg('$ '.$command)."\n".$command."\n";
        }
        $script .= 'chown -R '.Shell::arg($owner).' '.$p."\necho 'Deploy finished'";

        return $this->queued(TaskRunner::dispatch("Git deploy {$path}", $script, 'deploy', ['path' => $path]), "The deployment to {$path}");
    }
}
