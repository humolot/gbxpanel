<?php

namespace App\Services\Ai\Tools;

use App\Services\DockerManager;
use App\Services\Shell;
use App\Services\TaskRunner;
use Illuminate\Support\Str;

class DockerTools extends ToolGroup
{
    public const PROJECTS = '/www/docker';

    public function __construct(protected DockerManager $docker) {}

    public static function validProject(string $name): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9_-]{1,40}$/', $name);
    }

    public function tools(): array
    {
        return [
            'list_docker' => self::tool('Docker overview: engine info, containers (state, ports, CPU/memory), images, volumes, networks and compose projects in /www/docker.', self::params(), false, false, fn () => 'Listed Docker resources'),
            'get_container_logs' => self::tool('Recent logs of a container.', self::params(['container' => self::str('Name or ID'), 'lines' => self::int('Lines, default 150')], ['container']), false, false, fn ($a) => 'Read logs of '.self::labelArg($a, 'container')),
            'inspect_docker' => self::tool('docker inspect of a container, image, volume or network.', self::params(['target' => self::str('Name or ID')], ['target']), false, false, fn ($a) => 'Inspected '.self::labelArg($a, 'target')),
            'get_compose_project' => self::tool('Show a compose project: docker-compose.yml, .env keys, services status and recent logs.', self::params(['project' => self::str('Project name (folder in /www/docker)')], ['project']), false, false, fn ($a) => 'Inspected compose project '.self::labelArg($a, 'project')),

            'docker_run_container' => self::tool('Pull an image and start a container (docker run -d). Background task.', self::params([
                'image' => self::str('Image, e.g. nginx:alpine, redis:7, louislam/uptime-kuma:1'),
                'name' => self::str('Container name'),
                'ports' => self::list('Port mappings host:container, e.g. 127.0.0.1:3001:3001 (bind to 127.0.0.1 when a website reverse proxy will expose it)'),
                'env' => self::list('Environment variables KEY=value'),
                'volumes' => self::list('Volumes host_path_or_volume:container_path'),
                'restart' => self::str('Restart policy', ['unless-stopped', 'always', 'on-failure', 'no']),
                'memory' => self::str('Memory limit, e.g. 512m'),
                'command' => self::str('Optional command / args'),
            ], ['image']), true, false, fn ($a) => 'Run container from '.self::labelArg($a, 'image')),
            'docker_container_action' => self::tool('Start, stop, restart, pause, unpause, kill or remove a container.', self::params(['container' => self::str('Name or ID'), 'action' => self::str('Action', ['start', 'stop', 'restart', 'pause', 'unpause', 'kill', 'rm'])], ['container', 'action']), true, false, fn ($a) => ucfirst(self::labelArg($a, 'action')).' container '.self::labelArg($a, 'container')),
            'docker_compose_deploy' => self::tool('Create or update a docker compose project in /www/docker/<project> with the given docker-compose.yml (and optional .env), validate it and run "docker compose up -d". Background task.', self::params([
                'project' => self::str('Project name (lowercase, used as folder)'),
                'compose' => self::str('Full docker-compose.yml content'),
                'env_file' => self::str('Optional .env content'),
                'pull' => self::bool('Pull images before starting, default true'),
            ], ['project', 'compose']), true, false, fn ($a) => 'Deploy compose project '.self::labelArg($a, 'project')),
            'docker_compose_action' => self::tool('Run an action on a compose project: up, down, restart, stop, start, pull (pull + recreate), or remove (down with volumes and delete the folder).', self::params(['project' => self::str('Project name'), 'action' => self::str('Action', ['up', 'down', 'restart', 'stop', 'start', 'pull', 'remove'])], ['project', 'action']), true, false, fn ($a) => ucfirst(self::labelArg($a, 'action')).' compose '.self::labelArg($a, 'project')),
            'docker_image_action' => self::tool('Pull or remove a Docker image.', self::params(['image' => self::str('Image reference'), 'action' => self::str('Action', ['pull', 'remove'])], ['image', 'action']), true, false, fn ($a) => ucfirst(self::labelArg($a, 'action')).' image '.self::labelArg($a, 'image')),
            'docker_prune' => self::tool('Remove stopped containers, unused networks, dangling images and build cache (optionally unused volumes too).', self::params(['volumes' => self::bool('Also remove unused volumes (data loss)')]), true, false, fn () => 'Prune Docker'),
            'docker_exec' => self::tool('Run a command inside a running container (docker exec, non-interactive).', self::params(['container' => self::str('Name or ID'), 'command' => self::str('Command, e.g. php artisan migrate --force')], ['container', 'command']), true, true, fn ($a) => 'Exec in '.self::labelArg($a, 'container').': '.self::labelArg($a, 'command')),
        ];
    }

    protected function projectDir(string $project): string
    {
        if (! self::validProject($project)) {
            throw new \InvalidArgumentException('Project name must be 2-41 characters: lowercase letters, numbers, - and _.');
        }

        return self::PROJECTS.'/'.$project;
    }

    public function handle(string $name, array $a): mixed
    {
        $needsDocker = ! in_array($name, ['list_docker'], true);
        if ($needsDocker && ! $this->docker->installed()) {
            return ['error' => 'Docker is not installed. Use manage_software with key=docker.'];
        }

        switch ($name) {
            case 'list_docker':
                if (! $this->docker->installed()) {
                    return ['installed' => false, 'hint' => 'Install with manage_software key=docker'];
                }

                return [
                    'info' => $this->docker->info(),
                    'containers' => array_map(fn ($c) => ['name' => $c['Names'] ?? '', 'image' => $c['Image'] ?? '', 'state' => $c['State'] ?? '', 'status' => $c['Status'] ?? '', 'ports' => $c['Ports'] ?? '', 'compose_project' => $c['Labels'] ?? null ? (preg_match('/com\.docker\.compose\.project=([^,]+)/', $c['Labels'], $m) ? $m[1] : null) : null], $this->docker->containers()),
                    'stats' => $this->docker->stats(),
                    'images' => array_map(fn ($i) => ($i['Repository'] ?? '').':'.($i['Tag'] ?? '').' ('.($i['Size'] ?? '').')', $this->docker->images()),
                    'volumes' => array_column($this->docker->volumes(), 'Name'),
                    'networks' => array_column($this->docker->networks(), 'Name'),
                    'compose_projects' => Shell::simulating() ? ['uptime'] : array_values(array_filter(explode("\n", Shell::out('ls -1 '.self::PROJECTS.' 2>/dev/null', 10)))),
                ];

            case 'get_container_logs':
                return Str::limit($this->docker->logs((string) $a['container'], min(2000, (int) self::a($a, 'lines', 150))), 20000);

            case 'inspect_docker':
                return Str::limit($this->docker->inspect((string) $a['target']), 20000);

            case 'get_compose_project':
                $dir = $this->projectDir((string) $a['project']);
                if (Shell::simulating()) {
                    return ['compose' => "services:\n  web:\n    image: nginx:alpine", 'status' => 'web running'];
                }
                $env = Shell::readFile($dir.'/.env') ?? '';

                return [
                    'directory' => $dir,
                    'compose' => Shell::readFile($dir.'/docker-compose.yml') ?? Shell::readFile($dir.'/compose.yaml') ?? 'not found',
                    'env_keys' => array_values(array_filter(array_map(fn ($l) => strtok($l, '='), preg_grep('/^[A-Za-z_]+=/', explode("\n", $env))))),
                    'status' => Shell::run('cd '.Shell::arg($dir).' && docker compose ps -a 2>&1', 30)->output,
                    'logs' => Str::limit(Shell::run('cd '.Shell::arg($dir).' && docker compose logs --tail 60 --no-color 2>&1', 30)->output, 12000),
                ];

            case 'docker_run_container':
                $script = $this->docker->runScript([
                    'image' => (string) $a['image'],
                    'name' => self::a($a, 'name'),
                    'ports' => implode("\n", self::strings($a['ports'] ?? [])),
                    'env' => implode("\n", self::strings($a['env'] ?? [])),
                    'volumes' => implode("\n", self::strings($a['volumes'] ?? [])),
                    'restart' => self::a($a, 'restart', 'unless-stopped'),
                    'memory' => self::a($a, 'memory'),
                    'command' => self::a($a, 'command'),
                ])."\nsleep 2\ndocker ps -a --filter ".Shell::arg('ancestor='.$a['image'])." --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'";

                return $this->queued(TaskRunner::dispatch('Run container '.(self::a($a, 'name') ?: $a['image']), $script, 'docker'), 'The container deployment');

            case 'docker_container_action':
                return $this->shell($this->docker->containerAction((string) $a['container'], (string) $a['action']), "Container {$a['action']}: done");

            case 'docker_compose_deploy':
                $dir = $this->projectDir((string) $a['project']);
                $compose = str_replace("\r\n", "\n", (string) $a['compose']);
                if (! preg_match('/^\s*services\s*:/m', $compose)) {
                    return ['ok' => false, 'error' => 'The compose file must contain a "services:" section.'];
                }
                $w = Shell::writeFile($dir.'/docker-compose.yml', rtrim($compose)."\n", '0644');
                if ($w->failed()) {
                    return ['ok' => false, 'error' => $w->message()];
                }
                if (isset($a['env_file']) && $a['env_file'] !== '') {
                    Shell::writeFile($dir.'/.env', rtrim(str_replace("\r\n", "\n", (string) $a['env_file']))."\n", '0600');
                }
                $d = Shell::arg($dir);
                $script = "set -e\ncd {$d}\ndocker compose config -q\necho 'Compose file is valid'\n"
                    .(self::a($a, 'pull', true) ? "docker compose pull\n" : '')
                    ."docker compose up -d --remove-orphans\nsleep 3\ndocker compose ps";

                return $this->queued(TaskRunner::dispatch("Compose deploy {$a['project']}", $script, 'docker'), "The deployment of {$a['project']}") + ['directory' => $dir];

            case 'docker_compose_action':
                $dir = $this->projectDir((string) $a['project']);
                $d = Shell::arg($dir);
                $cmd = match ($a['action']) {
                    'up' => 'docker compose up -d --remove-orphans',
                    'down' => 'docker compose down',
                    'restart' => 'docker compose restart',
                    'stop' => 'docker compose stop',
                    'start' => 'docker compose start',
                    'pull' => 'docker compose pull && docker compose up -d --remove-orphans',
                    'remove' => 'docker compose down -v --remove-orphans && cd / && rm -rf '.$d,
                    default => null,
                };
                if (! $cmd) {
                    return ['error' => 'Unknown action'];
                }

                return $this->queued(TaskRunner::dispatch("Compose {$a['action']} {$a['project']}", "set -e\ncd {$d}\n{$cmd}\n".($a['action'] === 'remove' ? "echo 'Project removed'" : 'docker compose ps'), 'docker'), "Compose {$a['action']}");

            case 'docker_image_action':
                $image = (string) $a['image'];
                if (! DockerManager::validRef($image)) {
                    return ['error' => 'Invalid image reference'];
                }

                return $a['action'] === 'pull'
                    ? $this->queued(TaskRunner::dispatch("Pull image {$image}", 'docker pull '.Shell::arg($image), 'docker'), "Pulling {$image}")
                    : $this->shell($this->docker->removeImage($image), 'Image removed');

            case 'docker_prune':
                return $this->shell(Shell::run('docker system prune -f'.(self::a($a, 'volumes') ? ' --volumes' : ''), 600), 'Docker pruned');

            case 'docker_exec':
                if (! DockerManager::validRef((string) $a['container'])) {
                    return ['error' => 'Invalid container'];
                }
                $r = Shell::run('docker exec '.Shell::arg((string) $a['container']).' sh -c '.Shell::arg((string) $a['command']), 600);

                return ['ok' => $r->ok(), 'exit_code' => $r->exitCode, 'output' => Str::limit($r->output.$r->error, 16000)];
        }

        return ['error' => "Unknown tool {$name}"];
    }
}
