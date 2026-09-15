<?php

namespace App\Services\Docker;

use App\Models\Task;
use App\Services\DockerManager;
use App\Services\Shell;
use App\Services\ShellResult;
use App\Services\TaskRunner;

/**
 * Docker Compose projects. Projects created by the panel live in /www/docker/<name>;
 * projects started elsewhere are discovered with "docker compose ls".
 */
class ComposeManager
{
    public const FILES = ['compose.yaml', 'compose.yml', 'docker-compose.yml', 'docker-compose.yaml'];

    public function __construct(protected DockerManager $docker) {}

    public static function root(): string
    {
        return rtrim((string) config('gbx.docker.projects', '/www/docker'), '/');
    }

    public static function validName(string $name): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/', $name);
    }

    /**
     * @return list<array{name: string, dir: string, files: list<string>, running: int, total: int, status: string, created: int|null, app: string|null, managed: bool}>
     */
    public function projects(): array
    {
        if (Shell::simulating()) {
            return [
                ['name' => 'evolution-api', 'dir' => self::root().'/evolution-api', 'files' => [self::root().'/evolution-api/compose.yaml'], 'running' => 3, 'total' => 3, 'status' => 'running', 'created' => strtotime('2026-08-28 21:33:33'), 'app' => 'evolution-api', 'managed' => true],
            ];
        }

        $projects = [];
        // compose files in the panel directory, including projects that were never started
        $scan = Shell::out('for d in '.Shell::arg(self::root()).'/*/; do [ -d "$d" ] || continue; for f in '.implode(' ', self::FILES).'; do if [ -f "$d$f" ]; then echo "$d$f|$(stat -c %Y "$d$f")|$(cat "$d.gbx-app" 2>/dev/null | head -c 64)"; break; fi; done; done', 20);
        foreach (array_filter(explode("\n", $scan)) as $line) {
            [$file, $mtime, $app] = array_pad(explode('|', $line), 3, '');
            $dir = dirname($file);
            $name = basename($dir);
            if (! self::validName($name)) {
                continue;
            }
            $projects[$name] = ['name' => $name, 'dir' => $dir, 'files' => [$file], 'running' => 0, 'total' => 0, 'status' => 'stopped', 'created' => (int) $mtime ?: null, 'app' => trim($app) ?: null, 'managed' => true];
        }

        $ls = json_decode(Shell::out('docker compose ls -a --format json 2>/dev/null', 20), true) ?: [];
        foreach ($ls as $row) {
            $name = (string) ($row['Name'] ?? '');
            if ($name === '') {
                continue;
            }
            $files = array_values(array_filter(array_map('trim', explode(',', (string) ($row['ConfigFiles'] ?? '')))));
            $projects[$name] ??= ['name' => $name, 'dir' => $files ? dirname($files[0]) : '', 'files' => $files, 'running' => 0, 'total' => 0, 'status' => 'stopped', 'created' => null, 'app' => null, 'managed' => false];
            if ($files && ! $projects[$name]['managed']) {
                $projects[$name]['files'] = $files;
            }
        }

        // container counts per project
        foreach ($this->docker->containerList() as $c) {
            if ($c['project'] && isset($projects[$c['project']])) {
                $p = &$projects[$c['project']];
                $p['total']++;
                if ($c['state'] === 'running') {
                    $p['running']++;
                }
                $p['created'] = $p['created'] ? min($p['created'], $c['created'] ?? $p['created']) : $c['created'];
                unset($p);
            }
        }
        foreach ($projects as &$p) {
            $p['status'] = $p['total'] === 0 ? 'stopped' : ($p['running'] === $p['total'] ? 'running' : ($p['running'] > 0 ? 'partial' : 'exited'));
        }
        unset($p);

        uasort($projects, fn ($a, $b) => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));

        return array_values($projects);
    }

    public function find(string $name): ?array
    {
        if (! self::validName($name)) {
            return null;
        }
        foreach ($this->projects() as $project) {
            if ($project['name'] === $name) {
                return $project;
            }
        }

        return null;
    }

    /** "cd <dir> && docker compose -p <name> -f <file>..." */
    public function base(array $project): string
    {
        $files = implode('', array_map(fn ($f) => ' -f '.Shell::arg($f), $project['files']));

        return 'cd '.Shell::arg($project['dir'] ?: '/').' && docker compose -p '.Shell::arg($project['name']).$files;
    }

    public function containers(array $project): array
    {
        return array_values(array_filter($this->docker->containerList(), fn ($c) => $c['project'] === $project['name']));
    }

    public function logs(array $project, int $lines = 300): string
    {
        if (Shell::simulating()) {
            return "evolution_api       | > evolution-api@2.3.7 start:prod\nevolution_api       | > node dist/main\nevolution_postgres  | LOG:  database system is ready to accept connections\nevolution_redis     | * Ready to accept connections tcp\n";
        }

        return Shell::run($this->base($project).' logs --no-color --tail '.max(1, min($lines, 5000)).' 2>&1', 60)->output;
    }

    /** Main compose file and the .env next to it. */
    public function files(array $project): array
    {
        $compose = $project['files'][0] ?? null;
        $env = $project['dir'] ? $project['dir'].'/.env' : null;
        if (Shell::simulating()) {
            return [
                'compose_path' => $compose, 'env_path' => $env,
                'compose' => "services:\n  evolution_api:\n    container_name: evolution_api\n    image: evoapicloud/evolution-api:latest\n    restart: always\n    ports:\n      - \"8080:8080\"\n    env_file:\n      - .env\n",
                'env' => "SERVER_URL=http://127.0.0.1:8080\nSERVER_PORT=8080\n",
            ];
        }

        return [
            'compose_path' => $compose,
            'env_path' => $env,
            'compose' => $compose ? (Shell::readFile($compose) ?? '') : '',
            'env' => $env ? (Shell::readFile($env) ?? '') : '',
        ];
    }

    /**
     * Save the compose file or .env and validate the project; the previous file is restored when
     * "docker compose config" rejects the change.
     */
    public function saveFile(array $project, string $which, string $content): ShellResult
    {
        $path = $which === 'env' ? ($project['dir'] ? $project['dir'].'/.env' : null) : ($project['files'][0] ?? null);
        if (! $path) {
            return new ShellResult(1, '', 'The project has no configuration file');
        }
        $content = rtrim(str_replace("\r\n", "\n", $content))."\n";
        if (Shell::simulating()) {
            return new ShellResult(0, 'saved', '');
        }

        $p = Shell::arg($path);
        $backup = Shell::arg($path.'.gbx-bak');
        Shell::run("if [ -f {$p} ]; then cp -p {$p} {$backup}; else rm -f {$backup}; fi", 10);
        $write = Shell::writeFile($path, $content, $which === 'env' ? '0600' : '0644');
        if ($write->failed()) {
            return $write;
        }

        $check = Shell::run($this->base($project).' config -q 2>&1', 60);
        if ($check->failed()) {
            Shell::run("if [ -f {$backup} ]; then mv -f {$backup} {$p}; else rm -f {$p}; fi", 10);

            return new ShellResult(1, '', 'The configuration is invalid and was not saved: '.trim($check->output.$check->error));
        }
        Shell::run("rm -f {$backup}", 10);

        return new ShellResult(0, 'saved', '');
    }

    /** Write a new project to /www/docker/<name> and validate it. */
    public function create(string $name, string $compose, ?string $env, ?string $app = null): ShellResult
    {
        if (! self::validName($name)) {
            return new ShellResult(1, '', 'Project name: lowercase letters, numbers, - and _ (max 63).');
        }
        if (! preg_match('/^\s*services\s*:/m', $compose)) {
            return new ShellResult(1, '', 'The compose file must contain a "services:" section.');
        }
        if ($this->find($name)) {
            return new ShellResult(1, '', "A project named {$name} already exists.");
        }
        if (Shell::simulating()) {
            return new ShellResult(0, '', '');
        }

        $dir = self::root().'/'.$name;
        $d = Shell::arg($dir);
        if (Shell::test("[ -e {$d} ]")) {
            return new ShellResult(1, '', "The directory {$dir} already exists.");
        }

        foreach ([
            Shell::writeFile($dir.'/compose.yaml', rtrim(str_replace("\r\n", "\n", $compose))."\n", '0644'),
            $env !== null && trim($env) !== '' ? Shell::writeFile($dir.'/.env', rtrim(str_replace("\r\n", "\n", $env))."\n", '0600') : new ShellResult(0, '', ''),
            $app ? Shell::writeFile($dir.'/.gbx-app', $app."\n", '0644') : new ShellResult(0, '', ''),
        ] as $write) {
            if ($write->failed()) {
                Shell::run("rm -rf {$d}", 20);

                return $write;
            }
        }

        $check = Shell::run("cd {$d} && docker compose -p ".Shell::arg($name).' config -q 2>&1', 60);
        if ($check->failed()) {
            Shell::run("rm -rf {$d}", 20);

            return new ShellResult(1, '', 'Invalid compose file: '.trim($check->output.$check->error));
        }

        return new ShellResult(0, $dir, '');
    }

    public function upTask(array $project, bool $pull = true, ?string $title = null, array $extra = []): Task
    {
        $base = $this->base($project);
        $script = "set -e\n".($pull ? "{$base} pull\n" : '')."{$base} up -d --remove-orphans\nsleep 2\n{$base} ps"
            .(isset($extra['after']) ? "\n".$extra['after'] : '');

        return TaskRunner::dispatch($title ?? "Compose up {$project['name']}", $script, 'docker', ['compose' => $project['name']]);
    }

    public function actionTask(array $project, string $action): Task
    {
        $base = $this->base($project);
        $cmd = match ($action) {
            'start' => "{$base} up -d --remove-orphans",
            'stop' => "{$base} stop",
            'restart' => "{$base} restart",
            'update' => "{$base} pull\n{$base} up -d --remove-orphans",
            'down' => "{$base} down --remove-orphans",
            default => throw new \InvalidArgumentException('Unknown action'),
        };

        return TaskRunner::dispatch('Compose '.$action.' '.$project['name'], "set -e\n{$cmd}\n{$base} ps -a", 'docker', ['compose' => $project['name']]);
    }

    /** Stop and remove the project; files are only deleted for projects inside the panel directory. */
    public function deleteTask(array $project, bool $volumes, bool $files): Task
    {
        $script = "set -e\n".$this->base($project).' down --remove-orphans'.($volumes ? ' -v' : '');
        if ($files && $project['managed'] && $project['dir'] === self::root().'/'.$project['name']) {
            $script .= "\ncd /\nrm -rf ".Shell::arg($project['dir'])."\necho 'Removed {$project['dir']}'";
        }

        return TaskRunner::dispatch('Delete compose '.$project['name'], $script, 'docker', ['compose' => $project['name']]);
    }
}
