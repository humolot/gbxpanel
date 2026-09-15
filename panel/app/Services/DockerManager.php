<?php

namespace App\Services;

class DockerManager
{
    public static function validRef(string $value): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.\/:@-]{0,254}$/', $value);
    }

    public function installed(): bool
    {
        return Shell::simulating() || Shell::commandExists('docker');
    }

    public function running(): bool
    {
        return Shell::simulating() || Shell::test('docker info >/dev/null 2>&1');
    }

    protected function jsonLines(string $command): array
    {
        $rows = [];
        foreach (Shell::run($command, 30)->lines() as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public function info(): array
    {
        if (Shell::simulating()) {
            return ['version' => '27.3.1', 'containers' => 3, 'running' => 2, 'images' => 5, 'storage_driver' => 'overlay2', 'root_dir' => '/var/lib/docker'];
        }
        $i = json_decode(Shell::out("docker info --format '{{json .}}'", 20), true) ?: [];

        return [
            'version' => $i['ServerVersion'] ?? '-',
            'containers' => $i['Containers'] ?? 0,
            'running' => $i['ContainersRunning'] ?? 0,
            'images' => $i['Images'] ?? 0,
            'storage_driver' => $i['Driver'] ?? '-',
            'root_dir' => $i['DockerRootDir'] ?? '-',
        ];
    }

    public function containers(): array
    {
        if (Shell::simulating()) {
            return [
                ['ID' => 'a1b2c3d4e5f6', 'Names' => 'redis-cache', 'Image' => 'redis:7-alpine', 'State' => 'running', 'Status' => 'Up 6 days', 'Ports' => '127.0.0.1:6380->6379/tcp', 'CreatedAt' => '2026-09-08 10:00:00'],
                ['ID' => 'f6e5d4c3b2a1', 'Names' => 'uptime-kuma', 'Image' => 'louislam/uptime-kuma:1', 'State' => 'running', 'Status' => 'Up 2 days', 'Ports' => '0.0.0.0:3001->3001/tcp', 'CreatedAt' => '2026-09-12 10:00:00'],
                ['ID' => '0a9b8c7d6e5f', 'Names' => 'old-app', 'Image' => 'node:20', 'State' => 'exited', 'Status' => 'Exited (0) 3 hours ago', 'Ports' => '', 'CreatedAt' => '2026-09-01 10:00:00'],
            ];
        }

        return $this->jsonLines("docker ps -a --no-trunc --format '{{json .}}'");
    }

    public function stats(): array
    {
        if (Shell::simulating()) {
            return ['redis-cache' => ['cpu' => '0.21%', 'mem' => '8.2MiB / 7.75GiB'], 'uptime-kuma' => ['cpu' => '1.02%', 'mem' => '96MiB / 7.75GiB']];
        }
        $out = [];
        foreach ($this->jsonLines("docker stats --no-stream --format '{{json .}}'") as $row) {
            $out[$row['Name'] ?? ''] = ['cpu' => $row['CPUPerc'] ?? '', 'mem' => $row['MemUsage'] ?? ''];
        }

        return $out;
    }

    public function images(): array
    {
        if (Shell::simulating()) {
            return [
                ['ID' => 'sha256:1a2b3c', 'Repository' => 'redis', 'Tag' => '7-alpine', 'Size' => '41MB', 'CreatedSince' => '3 weeks ago'],
                ['ID' => 'sha256:4d5e6f', 'Repository' => 'louislam/uptime-kuma', 'Tag' => '1', 'Size' => '430MB', 'CreatedSince' => '2 months ago'],
                ['ID' => 'sha256:7a8b9c', 'Repository' => 'node', 'Tag' => '20', 'Size' => '1.1GB', 'CreatedSince' => '5 weeks ago'],
            ];
        }

        return $this->jsonLines("docker images --format '{{json .}}'");
    }

    public function volumes(): array
    {
        if (Shell::simulating()) {
            return [['Name' => 'kuma_data', 'Driver' => 'local', 'Mountpoint' => '/var/lib/docker/volumes/kuma_data/_data']];
        }

        return $this->jsonLines("docker volume ls --format '{{json .}}'");
    }

    public function networks(): array
    {
        if (Shell::simulating()) {
            return [['ID' => 'b1c2', 'Name' => 'bridge', 'Driver' => 'bridge', 'Scope' => 'local'], ['ID' => 'c3d4', 'Name' => 'host', 'Driver' => 'host', 'Scope' => 'local']];
        }

        return $this->jsonLines("docker network ls --format '{{json .}}'");
    }

    public function containerAction(string $id, string $action): ShellResult
    {
        if (! self::validRef($id) || ! in_array($action, ['start', 'stop', 'restart', 'pause', 'unpause', 'kill', 'rm'], true)) {
            return new ShellResult(1, '', 'Invalid container or action');
        }
        $flags = $action === 'rm' ? ' -f' : '';

        return Shell::run("docker {$action}{$flags} ".Shell::arg($id), 90);
    }

    public function logs(string $id, int $lines = 300): string
    {
        if (Shell::simulating()) {
            return "1:C 14 Sep 2026 10:00:00.000 * Ready to accept connections tcp\n";
        }
        if (! self::validRef($id)) {
            return 'Invalid container';
        }
        $r = Shell::run('docker logs --tail '.(int) $lines.' --timestamps '.Shell::arg($id).' 2>&1', 30);

        return $r->output;
    }

    public function inspect(string $id): string
    {
        if (Shell::simulating()) {
            return json_encode(['Id' => $id, 'State' => ['Status' => 'running']], JSON_PRETTY_PRINT);
        }

        return self::validRef($id) ? Shell::run('docker inspect '.Shell::arg($id), 20)->output : '';
    }

    public function removeImage(string $id): ShellResult
    {
        return self::validRef($id) ? Shell::run('docker rmi '.Shell::arg($id), 60) : new ShellResult(1, '', 'Invalid image');
    }

    public function removeVolume(string $name): ShellResult
    {
        return self::validRef($name) ? Shell::run('docker volume rm '.Shell::arg($name), 60) : new ShellResult(1, '', 'Invalid volume');
    }

    public function prune(): ShellResult
    {
        return Shell::run('docker system prune -f', 300);
    }

    /**
     * Build a "docker run" script from the create form.
     */
    public function runScript(array $data): string
    {
        $image = $data['image'];
        if (! self::validRef($image)) {
            throw new \InvalidArgumentException('Invalid image name');
        }

        $cmd = 'docker run -d';
        if (! empty($data['name'])) {
            if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]+$/', $data['name'])) {
                throw new \InvalidArgumentException('Invalid container name');
            }
            $cmd .= ' --name '.Shell::arg($data['name']);
        }
        $restart = $data['restart'] ?? 'unless-stopped';
        if (in_array($restart, ['no', 'always', 'unless-stopped', 'on-failure'], true)) {
            $cmd .= ' --restart '.$restart;
        }
        foreach ($this->splitLines($data['ports'] ?? '') as $port) {
            if (! preg_match('/^([\d.]+:)?\d{1,5}:\d{1,5}(\/(tcp|udp))?$/', $port)) {
                throw new \InvalidArgumentException("Invalid port mapping: {$port}");
            }
            $cmd .= ' -p '.Shell::arg($port);
        }
        foreach ($this->splitLines($data['env'] ?? '') as $env) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $env)) {
                throw new \InvalidArgumentException("Invalid environment variable: {$env}");
            }
            $cmd .= ' -e '.Shell::arg($env);
        }
        foreach ($this->splitLines($data['volumes'] ?? '') as $vol) {
            if (! str_contains($vol, ':')) {
                throw new \InvalidArgumentException("Invalid volume: {$vol}");
            }
            $cmd .= ' -v '.Shell::arg($vol);
        }
        if (! empty($data['memory']) && preg_match('/^\d+[mMgG]$/', $data['memory'])) {
            $cmd .= ' --memory '.$data['memory'];
        }
        $cmd .= ' '.Shell::arg($image);
        if (! empty($data['command'])) {
            $cmd .= ' '.$data['command'];
        }

        return "set -e\ndocker pull ".Shell::arg($image)."\n".$cmd;
    }

    protected function splitLines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $text))));
    }
}
