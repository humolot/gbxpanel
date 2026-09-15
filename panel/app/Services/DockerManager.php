<?php

namespace App\Services;

use App\Models\DockerRegistry;

/**
 * Docker Engine: containers, images, networks, volumes and resource usage.
 * Lists are built from "docker inspect" so every tab has IPs, ports, mounts and labels.
 */
class DockerManager
{
    public const RESTART_POLICIES = ['no', 'always', 'unless-stopped', 'on-failure'];

    public const NETWORK_DRIVERS = ['bridge', 'macvlan', 'ipvlan'];

    public const BUILTIN_NETWORKS = ['bridge', 'host', 'none'];

    public static function validRef(string $value): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.\/:@-]{0,254}$/', $value);
    }

    public static function validName(string $value): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$/', $value);
    }

    public function installed(): bool
    {
        return Shell::simulating() || Shell::commandExists('docker');
    }

    public function running(): bool
    {
        return Shell::simulating() || Shell::test('docker info >/dev/null 2>&1');
    }

    protected function jsonLines(string $command, int $timeout = 30): array
    {
        $rows = [];
        foreach (Shell::run($command, $timeout)->lines() as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    protected function jsonArray(string $command, int $timeout = 60): array
    {
        $data = json_decode(Shell::run($command, $timeout)->output, true);

        return is_array($data) ? $data : [];
    }

    protected static function time(?string $value): ?int
    {
        if (! $value || str_starts_with($value, '0001-')) {
            return null;
        }
        $ts = strtotime(preg_replace('/\.\d+/', '', $value));

        return $ts ?: null;
    }

    /* ======================================================================== engine */

    public function info(): array
    {
        if (Shell::simulating()) {
            return ['version' => '27.3.1', 'compose' => '2.29.7', 'containers' => 6, 'running' => 6, 'images' => 9, 'storage_driver' => 'overlay2', 'root_dir' => '/var/lib/docker', 'cpus' => 4, 'memory' => 8321499136, 'os' => 'Ubuntu 24.04.1 LTS', 'kernel' => '6.8.0-45-generic'];
        }
        $i = json_decode(Shell::out("docker info --format '{{json .}}'", 20), true) ?: [];

        return [
            'version' => $i['ServerVersion'] ?? '-',
            'compose' => trim(Shell::out('docker compose version --short 2>/dev/null', 10)) ?: null,
            'containers' => $i['Containers'] ?? 0,
            'running' => $i['ContainersRunning'] ?? 0,
            'images' => $i['Images'] ?? 0,
            'storage_driver' => $i['Driver'] ?? '-',
            'root_dir' => $i['DockerRootDir'] ?? '-',
            'cpus' => $i['NCPU'] ?? null,
            'memory' => $i['MemTotal'] ?? null,
            'os' => $i['OperatingSystem'] ?? null,
            'kernel' => $i['KernelVersion'] ?? null,
        ];
    }

    /** Space used per resource type: [Images|Containers|Local Volumes|Build Cache => [count, active, size, reclaimable]]. */
    public function diskUsage(): array
    {
        if (Shell::simulating()) {
            return [
                'Images' => ['count' => 9, 'active' => 6, 'size' => '4.839GB', 'reclaimable' => '1.07GB (22%)'],
                'Containers' => ['count' => 6, 'active' => 6, 'size' => '702MB', 'reclaimable' => '0B (0%)'],
                'Local Volumes' => ['count' => 5, 'active' => 5, 'size' => '351.6MB', 'reclaimable' => '0B (0%)'],
                'Build Cache' => ['count' => 0, 'active' => 0, 'size' => '0B', 'reclaimable' => '0B'],
            ];
        }
        $out = [];
        foreach ($this->jsonLines("docker system df --format '{{json .}}'", 60) as $row) {
            $out[$row['Type'] ?? '?'] = ['count' => (int) ($row['TotalCount'] ?? 0), 'active' => (int) ($row['Active'] ?? 0), 'size' => $row['Size'] ?? '0B', 'reclaimable' => $row['Reclaimable'] ?? ''];
        }

        return $out;
    }

    /* ==================================================================== containers */

    /** Normalized containers from docker inspect. */
    public function containerList(): array
    {
        $raw = Shell::simulating()
            ? $this->fakeContainers()
            : $this->jsonArray('ids=$(docker ps -aq --no-trunc); if [ -n "$ids" ]; then docker inspect $ids; else echo "[]"; fi');

        $rows = array_map([$this, 'normalizeContainer'], $raw);
        usort($rows, fn ($a, $b) => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));

        return $rows;
    }

    public function container(string $ref): ?array
    {
        if (! self::validRef($ref)) {
            return null;
        }
        if (Shell::simulating()) {
            foreach ($this->fakeContainers() as $c) {
                if (ltrim($c['Name'], '/') === $ref || str_starts_with($c['Id'], $ref)) {
                    return $this->normalizeContainer($c) + ['raw' => $c];
                }
            }

            return null;
        }
        $data = $this->jsonArray('docker inspect '.Shell::arg($ref).' 2>/dev/null', 20);

        return isset($data[0]['Id']) ? $this->normalizeContainer($data[0]) + ['raw' => $data[0]] : null;
    }

    public function normalizeContainer(array $c): array
    {
        $ips = [];
        $ipv6 = null;
        foreach (($c['NetworkSettings']['Networks'] ?? []) ?: [] as $network => $n) {
            $ips[$network] = $n['IPAddress'] ?? '';
            $ipv6 ??= ($n['GlobalIPv6Address'] ?? '') ?: null;
        }

        $ports = [];
        foreach (($c['NetworkSettings']['Ports'] ?? []) ?: [] as $spec => $bindings) {
            [$port, $proto] = array_pad(explode('/', $spec), 2, 'tcp');
            if (! $bindings) {
                $ports[] = ['host_ip' => null, 'host_port' => null, 'port' => (int) $port, 'proto' => $proto];

                continue;
            }
            $seen = [];
            foreach ($bindings as $b) {
                // IPv4 and IPv6 bindings of the same port are shown once
                $key = ($b['HostPort'] ?? '').'/'.$proto;
                if (isset($seen[$key]) && in_array($b['HostIp'] ?? '', ['::', ''], true)) {
                    continue;
                }
                $seen[$key] = true;
                $ports[] = ['host_ip' => $b['HostIp'] ?? '', 'host_port' => (int) ($b['HostPort'] ?? 0), 'port' => (int) $port, 'proto' => $proto];
            }
        }
        usort($ports, fn ($a, $b) => $a['port'] <=> $b['port']);

        $labels = ($c['Config']['Labels'] ?? []) ?: [];
        $state = $c['State'] ?? [];

        return [
            'id' => substr((string) ($c['Id'] ?? ''), 0, 12),
            'full_id' => (string) ($c['Id'] ?? ''),
            'name' => ltrim((string) ($c['Name'] ?? ''), '/'),
            'image' => (string) ($c['Config']['Image'] ?? ''),
            'image_id' => (string) ($c['Image'] ?? ''),
            'state' => (string) ($state['Status'] ?? 'unknown'),
            'health' => $state['Health']['Status'] ?? null,
            'exit_code' => $state['ExitCode'] ?? null,
            'created' => self::time($c['Created'] ?? null),
            'started' => self::time($state['StartedAt'] ?? null),
            'finished' => self::time($state['FinishedAt'] ?? null),
            'ip' => array_values(array_filter($ips))[0] ?? null,
            'ips' => $ips,
            'ipv6' => $ipv6,
            'ports' => $ports,
            'networks' => array_keys($ips),
            'restart' => $c['HostConfig']['RestartPolicy']['Name'] ?? 'no',
            'memory_limit' => (int) ($c['HostConfig']['Memory'] ?? 0),
            'cpus' => ($c['HostConfig']['NanoCpus'] ?? 0) ? round($c['HostConfig']['NanoCpus'] / 1e9, 2) : null,
            'project' => $labels['com.docker.compose.project'] ?? null,
            'service' => $labels['com.docker.compose.service'] ?? null,
            'mounts' => array_map(fn ($m) => [
                'type' => $m['Type'] ?? '',
                'name' => $m['Name'] ?? null,
                'source' => $m['Source'] ?? '',
                'destination' => $m['Destination'] ?? '',
                'rw' => (bool) ($m['RW'] ?? true),
            ], ($c['Mounts'] ?? []) ?: []),
            'command' => trim(implode(' ', array_merge([(string) ($c['Path'] ?? '')], ($c['Args'] ?? []) ?: []))),
            'log_path' => $c['LogPath'] ?? null,
        ];
    }

    /** Live CPU and memory per container id (12 chars). */
    public function stats(): array
    {
        if (Shell::simulating()) {
            $fake = ['78da15e9fe21' => ['0.03%', '69.96MiB / 7.75GiB', '0.88%'], '6d9bfe1c6378' => ['0.00%', '303.3MiB / 7.75GiB', '3.82%'], '627c443d37d1' => ['0.00%', '58.48MiB / 7.75GiB', '0.74%'],
                '9cc7d7934236' => ['0.80%', '13.13MiB / 7.75GiB', '0.17%'], '16fc16ccac96' => ['0.10%', '301.2MiB / 7.75GiB', '3.80%'], '9bf9aac6435c' => ['0.09%', '132.9MiB / 7.75GiB', '1.67%']];
            $rows = [];
            foreach ($fake as $id => [$cpu, $mem, $perc]) {
                $rows[] = ['ID' => $id, 'CPUPerc' => $cpu, 'MemUsage' => $mem, 'MemPerc' => $perc, 'NetIO' => '1.2MB / 800kB', 'BlockIO' => '4.1MB / 0B', 'PIDs' => '12'];
            }
        } else {
            $rows = $this->jsonLines("docker stats --no-stream --format '{{json .}}'", 40);
        }

        $out = [];
        foreach ($rows as $row) {
            [$used, $limit] = array_pad(array_map('trim', explode('/', (string) ($row['MemUsage'] ?? ''))), 2, '');
            $out[substr((string) ($row['ID'] ?? ''), 0, 12)] = [
                'cpu' => (float) rtrim((string) ($row['CPUPerc'] ?? '0'), '%'),
                'mem_used' => $used,
                'mem_limit' => $limit,
                'mem_percent' => (float) rtrim((string) ($row['MemPerc'] ?? '0'), '%'),
                'net' => $row['NetIO'] ?? '',
                'block' => $row['BlockIO'] ?? '',
                'pids' => $row['PIDs'] ?? '',
            ];
        }

        return $out;
    }

    /** Compatibility for the AI tools: docker ps rows. */
    public function containers(): array
    {
        return array_map(fn ($c) => [
            'ID' => $c['id'], 'Names' => $c['name'], 'Image' => $c['image'], 'State' => $c['state'],
            'Status' => $c['state'].($c['health'] ? ' ('.$c['health'].')' : ''),
            'Ports' => implode(', ', array_map(fn ($p) => $p['host_port'] ? "{$p['host_ip']}:{$p['host_port']}->{$p['port']}/{$p['proto']}" : "{$p['port']}/{$p['proto']}", $c['ports'])),
            'Labels' => $c['project'] ? 'com.docker.compose.project='.$c['project'] : '',
            'CreatedAt' => $c['created'] ? date('Y-m-d H:i:s', $c['created']) : '',
        ], $this->containerList());
    }

    public function containerAction(string $id, string $action): ShellResult
    {
        if (! self::validRef($id) || ! in_array($action, ['start', 'stop', 'restart', 'pause', 'unpause', 'kill', 'rm'], true)) {
            return new ShellResult(1, '', 'Invalid container or action');
        }
        $flags = $action === 'rm' ? ' -f' : '';

        return Shell::run("docker {$action}{$flags} ".Shell::arg($id), 120);
    }

    public function rename(string $id, string $name): ShellResult
    {
        if (! self::validRef($id) || ! self::validName($name)) {
            return new ShellResult(1, '', 'Invalid container name. Use letters, numbers, dot, dash and underscore.');
        }

        return Shell::run('docker rename '.Shell::arg($id).' '.Shell::arg($name), 30);
    }

    /** Change restart policy and resource limits of a container. */
    public function updateContainer(string $id, string $restart, ?string $memory, ?float $cpus): ShellResult
    {
        if (! self::validRef($id) || ! in_array($restart, self::RESTART_POLICIES, true)) {
            return new ShellResult(1, '', 'Invalid container or restart policy');
        }
        $cmd = 'docker update --restart='.$restart;
        if ($memory !== null && $memory !== '') {
            if (! preg_match('/^\d+[mMgG]$/', $memory)) {
                return new ShellResult(1, '', 'Memory limit must look like 512m or 2g');
            }
            // the swap limit must be raised together with the memory limit
            $cmd .= ' --memory '.$memory.' --memory-swap -1';
        }
        if ($cpus !== null && $cpus > 0) {
            $cmd .= ' --cpus '.round($cpus, 2);
        }

        return Shell::run($cmd.' '.Shell::arg($id), 30);
    }

    public function commit(string $id, string $image): ShellResult
    {
        if (! self::validRef($id) || ! self::validRef($image)) {
            return new ShellResult(1, '', 'Invalid container or image name');
        }

        return Shell::run('docker commit '.Shell::arg($id).' '.Shell::arg($image), 600);
    }

    public function logs(string $id, int $lines = 300, ?string $since = null): string
    {
        if (Shell::simulating()) {
            return "2026-09-14T10:00:00.000000000Z Server listening on 0.0.0.0:3000\n2026-09-14T10:00:01.000000000Z Connected to database\n";
        }
        if (! self::validRef($id)) {
            return 'Invalid container';
        }
        $sinceArg = $since && preg_match('/^\d+[smhd]$/', $since) ? ' --since '.$since : '';

        return Shell::run('docker logs --tail '.max(1, min($lines, 10000)).$sinceArg.' --timestamps '.Shell::arg($id).' 2>&1', 30)->output;
    }

    /** Size of the json-file log of every container: [name => bytes]. */
    public function logSizes(): array
    {
        if (Shell::simulating()) {
            return ['gowa' => 1843200, 'evolution_api' => 52428800, 'evolution_postgres' => 204800, 'evolution_redis' => 10240, 'tika' => 3145728, 'qdrant' => 921600];
        }
        $out = Shell::out('ids=$(docker ps -aq --no-trunc); [ -n "$ids" ] || exit 0; docker inspect -f \'{{.Name}}|{{.LogPath}}\' $ids | while IFS="|" read -r n p; do echo "${n#/}|$(stat -c %s "$p" 2>/dev/null || echo 0)"; done', 30);
        $sizes = [];
        foreach (array_filter(explode("\n", $out)) as $line) {
            [$name, $size] = array_pad(explode('|', $line), 2, 0);
            $sizes[$name] = (int) $size;
        }

        return $sizes;
    }

    public function clearLogs(?string $id = null): ShellResult
    {
        if ($id !== null && ! self::validRef($id)) {
            return new ShellResult(1, '', 'Invalid container');
        }
        $targets = $id === null ? '$(docker ps -aq --no-trunc)' : Shell::arg($id);

        return Shell::run('for c in '.$targets.'; do p=$(docker inspect -f \'{{.LogPath}}\' "$c" 2>/dev/null); [ -n "$p" ] && [ -f "$p" ] && truncate -s 0 "$p"; done; true', 60);
    }

    public function inspect(string $id): string
    {
        if (Shell::simulating()) {
            return json_encode(['Id' => $id, 'State' => ['Status' => 'running']], JSON_PRETTY_PRINT);
        }

        return self::validRef($id) ? Shell::run('docker inspect '.Shell::arg($id), 20)->output : '';
    }

    /**
     * Script that creates a container ("docker run -d"). Ports, env, volumes and labels are one per line.
     */
    public function runScript(array $data): string
    {
        $image = (string) $data['image'];
        if (! self::validRef($image)) {
            throw new \InvalidArgumentException('Invalid image name');
        }

        $cmd = 'docker run -d';
        if (! empty($data['name'])) {
            if (! self::validName((string) $data['name'])) {
                throw new \InvalidArgumentException('Invalid container name');
            }
            $cmd .= ' --name '.Shell::arg($data['name']);
        }
        $restart = $data['restart'] ?? 'unless-stopped';
        if (in_array($restart, self::RESTART_POLICIES, true)) {
            $cmd .= ' --restart '.$restart;
        }
        if (! empty($data['network'])) {
            if (! self::validName((string) $data['network'])) {
                throw new \InvalidArgumentException('Invalid network');
            }
            $cmd .= ' --network '.Shell::arg($data['network']);
        }
        foreach ($this->splitLines($data['ports'] ?? '') as $port) {
            if (! preg_match('/^(\[?[\da-fA-F.:]+\]?:)?\d{1,5}(-\d{1,5})?:\d{1,5}(-\d{1,5})?(\/(tcp|udp))?$/', $port) && ! preg_match('/^\d{1,5}(\/(tcp|udp))?$/', $port)) {
                throw new \InvalidArgumentException("Invalid port mapping: {$port}");
            }
            $cmd .= ' -p '.Shell::arg($port);
        }
        foreach ($this->splitLines($data['env'] ?? '') as $env) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_.]*=/', $env)) {
                throw new \InvalidArgumentException("Invalid environment variable: {$env}");
            }
            $cmd .= ' -e '.Shell::arg($env);
        }
        foreach ($this->splitLines($data['volumes'] ?? '') as $vol) {
            if (! str_contains($vol, ':') || str_contains($vol, "\0")) {
                throw new \InvalidArgumentException("Invalid volume: {$vol}");
            }
            $cmd .= ' -v '.Shell::arg($vol);
        }
        foreach ($this->splitLines($data['labels'] ?? '') as $label) {
            if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-\/]*=/', $label)) {
                throw new \InvalidArgumentException("Invalid label: {$label}");
            }
            $cmd .= ' --label '.Shell::arg($label);
        }
        if (! empty($data['memory'])) {
            if (! preg_match('/^\d+[mMgG]$/', (string) $data['memory'])) {
                throw new \InvalidArgumentException('Memory limit must look like 512m or 2g');
            }
            $cmd .= ' --memory '.$data['memory'];
        }
        if (! empty($data['cpus'])) {
            $cmd .= ' --cpus '.round(max(0.01, (float) $data['cpus']), 2);
        }
        $cmd .= ' '.Shell::arg($image);
        if (! empty($data['command'])) {
            foreach (CronManager::splitArgs((string) $data['command']) as $arg) {
                $cmd .= ' '.Shell::arg($arg);
            }
        }

        $pull = ($data['pull'] ?? true) ? 'docker pull '.Shell::arg($image)."\n" : '';

        return "set -e\n{$pull}{$cmd}";
    }

    public function pruneContainers(): ShellResult
    {
        return Shell::run('docker container prune -f', 300);
    }

    public function prune(): ShellResult
    {
        return Shell::run('docker system prune -f', 300);
    }

    /* ======================================================================== images */

    public function imageList(): array
    {
        if (Shell::simulating()) {
            $raw = [
                ['sha256:2b942792de76be3940e507507f24aa01', ['ghcr.io/aldinokemal/go-whatsapp-web-multidevice:latest'], 307863552, '2026-08-29T11:01:19Z'],
                ['sha256:9b1d34adbce1dd07ee6e94b4a2cf0c11', ['postgres:15'], 632750080, '2026-08-24T19:42:46Z'],
                ['sha256:d4411a6e3f197ffc830ac5be9a055e72', [], 307728384, '2026-08-22T21:04:24Z'],
                ['sha256:a8b442501f601fb15015de974f9af2e1', ['apache/tika:latest'], 624291840, '2026-08-21T19:38:37Z'],
                ['sha256:0cff9eb0e7aee9953e55bc682852c7d1', ['dockurr/windows:latest'], 800333824, '2026-08-21T18:18:36Z'],
                ['sha256:ff02b58f971e7d7d156a1267e283f7a3', ['redis:7-alpine'], 57829184, '2026-08-18T11:56:46Z'],
                ['sha256:057ee3a8da769fe7310dd3537b4d11e0', ['qdrant/qdrant:latest'], 274825216, '2026-08-04T07:30:43Z'],
                ['sha256:28bd5fe8b56d1bd048e5babf5b10c9d8', ['alpine:latest'], 13002752, '2026-06-15T19:01:29Z'],
                ['sha256:966625532d9076a2381e973a2713ab44', ['evoapicloud/evolution-api:latest'], 1836098560, '2026-05-06T12:15:59Z'],
            ];
            $images = array_map(fn ($r) => ['Id' => $r[0], 'RepoTags' => $r[1], 'Size' => $r[2], 'Created' => $r[3]], $raw);
        } else {
            $images = $this->jsonArray('ids=$(docker images -aq --no-trunc | sort -u); if [ -n "$ids" ]; then docker image inspect $ids; else echo "[]"; fi');
        }

        $used = [];
        foreach ($this->containerList() as $c) {
            $used[$c['image_id']][] = $c['name'];
        }

        $rows = array_map(fn ($i) => [
            'id' => (string) $i['Id'],
            'short_id' => substr(str_replace('sha256:', '', (string) $i['Id']), 0, 12),
            'tags' => array_values(array_filter(($i['RepoTags'] ?? []) ?: [], fn ($t) => $t !== '<none>:<none>')),
            'name' => array_values(array_filter(($i['RepoTags'] ?? []) ?: [], fn ($t) => $t !== '<none>:<none>'))[0] ?? '<none>',
            'size' => (int) ($i['Size'] ?? 0),
            'created' => self::time($i['Created'] ?? null),
            'containers' => $used[$i['Id']] ?? [],
        ], $images);
        usort($rows, fn ($a, $b) => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));

        return $rows;
    }

    /** Compatibility for the AI tools. */
    public function images(): array
    {
        return array_map(fn ($i) => [
            'ID' => $i['short_id'],
            'Repository' => $i['name'] === '<none>' ? '<none>' : preg_replace('/:[^:\/]+$/', '', $i['name']),
            'Tag' => $i['name'] === '<none>' ? '<none>' : (preg_match('/:([^:\/]+)$/', $i['name'], $m) ? $m[1] : 'latest'),
            'Size' => SystemStats::bytes($i['size'], 1),
            'CreatedSince' => $i['created'] ? date('Y-m-d', $i['created']) : '',
        ], $this->imageList());
    }

    public function removeImage(string $id, bool $force = false): ShellResult
    {
        return self::validRef($id) ? Shell::run('docker rmi '.($force ? '-f ' : '').Shell::arg($id), 120) : new ShellResult(1, '', 'Invalid image');
    }

    public function pruneImages(bool $all): ShellResult
    {
        return Shell::run('docker image prune -f'.($all ? ' -a' : ''), 600);
    }

    /**
     * Lines that log in to a registry for the rest of the script without leaving credentials behind:
     * a temporary DOCKER_CONFIG receives the token and the password is read from a root-only file.
     */
    public function loginScript(?DockerRegistry $registry): string
    {
        if (! $registry || ! $registry->username) {
            return '';
        }
        $secret = Shell::secretFile((string) $registry->password);

        return 'export DOCKER_CONFIG=$(mktemp -d /root/.gbx-docker-XXXXXX)'."\n"
            .'GBX_SECRET='.Shell::arg($secret)."\n"
            ."trap 'rm -rf \"\$DOCKER_CONFIG\" \"\$GBX_SECRET\"' EXIT\n"
            .'docker login '.Shell::arg($registry->host()).' -u '.Shell::arg((string) $registry->username).' --password-stdin < "$GBX_SECRET"'."\n";
    }

    public function pullScript(string $image, ?DockerRegistry $registry = null): string
    {
        $ref = $registry ? $registry->reference($image) : $image;
        if (! self::validRef($ref)) {
            throw new \InvalidArgumentException('Invalid image name');
        }

        return "set -e\n".$this->loginScript($registry).'docker pull '.Shell::arg($ref)."\ndocker image ls ".Shell::arg(preg_replace('/[:@][^:\/]*$/', '', $ref));
    }

    public function pushScript(string $image, string $target, DockerRegistry $registry): string
    {
        $ref = $registry->reference($target);
        if (! self::validRef($image) || ! self::validRef($ref)) {
            throw new \InvalidArgumentException('Invalid image name');
        }

        return "set -e\n".$this->loginScript($registry).'docker tag '.Shell::arg($image).' '.Shell::arg($ref)."\ndocker push ".Shell::arg($ref)."\necho 'Pushed {$ref}'";
    }

    public function buildScript(string $tag, string $dockerfile, ?string $context = null): string
    {
        if (! self::validRef($tag)) {
            throw new \InvalidArgumentException('Invalid image tag');
        }
        $marker = 'GBX_'.strtoupper(bin2hex(random_bytes(4)));
        $script = "set -e\nBUILD=\$(mktemp -d /tmp/gbx-build-XXXXXX)\ntrap 'rm -rf \"\$BUILD\"' EXIT\n"
            ."cat > \"\$BUILD/Dockerfile\" <<'{$marker}'\n".rtrim(str_replace("\r\n", "\n", $dockerfile))."\n{$marker}\n";
        $ctx = '"$BUILD"';
        if ($context !== null && $context !== '') {
            $context = FileManager::normalize($context);
            $ctx = Shell::arg($context);
            $script .= '[ -d '.$ctx.' ] || { echo '.Shell::arg('Build context not found: '.$context).'; exit 1; }'."\n";
        }

        return $script.'docker build --progress=plain -t '.Shell::arg($tag).' -f "$BUILD/Dockerfile" '.$ctx."\ndocker image ls ".Shell::arg(preg_replace('/:[^:\/]*$/', '', $tag));
    }

    public function exportDir(): string
    {
        return rtrim(config('gbx.paths.backup'), '/').'/docker';
    }

    /** @return array{0: string, 1: string} script and archive path */
    public function exportScript(string $image): array
    {
        if (! self::validRef($image)) {
            throw new \InvalidArgumentException('Invalid image');
        }
        $label = trim(preg_replace('/[^a-zA-Z0-9_.-]+/', '_', str_replace('sha256:', '', $image)), '_') ?: 'image';
        $file = $this->exportDir().'/'.substr($label, 0, 80).'_'.date('Ymd_His').'.tar.gz';

        return ['set -e'."\nmkdir -p ".Shell::arg($this->exportDir())."\ndocker save ".Shell::arg($image).' | gzip > '.Shell::arg($file)."\nls -lh ".Shell::arg($file), $file];
    }

    public function importScript(string $file, bool $deleteAfter): string
    {
        $f = Shell::arg($file);

        return "set -e\n[ -f {$f} ] || { echo ".Shell::arg('File not found: '.$file)."; exit 1; }\ndocker load -i {$f}".($deleteAfter ? "\nrm -f {$f}" : '');
    }

    /* ====================================================================== networks */

    public function networkList(): array
    {
        if (Shell::simulating()) {
            $raw = [
                ['bridge', 'bridge', '172.17.0.0/16', '172.17.0.1', [], '2026-09-07T22:20:00Z', 3],
                ['evolution-api_evolution_network', 'bridge', '172.19.0.0/16', '172.19.0.1', ['com.docker.compose.network' => 'evolution_network', 'com.docker.compose.project' => 'evolution-api'], '2026-08-28T21:33:33Z', 3],
                ['baota_net', 'bridge', '172.18.0.0/16', '172.18.0.1', [], '2026-08-28T12:13:33Z', 0],
                ['none', 'null', null, null, [], '2026-08-28T12:05:42Z', 0],
                ['host', 'host', null, null, [], '2026-08-28T12:05:42Z', 0],
            ];
            $nets = array_map(fn ($r) => ['Name' => $r[0], 'Id' => md5($r[0]), 'Driver' => $r[1], 'Created' => $r[5], 'Labels' => $r[4], 'EnableIPv6' => false, 'Internal' => false,
                'IPAM' => ['Config' => $r[2] ? [['Subnet' => $r[2], 'Gateway' => $r[3]]] : []], 'Containers' => array_fill(0, $r[6], [])], $raw);
        } else {
            $nets = $this->jsonArray('docker network ls -q --no-trunc | xargs -r docker network inspect || echo "[]"');
        }

        return array_map(function ($n) {
            $v4 = $v6 = [];
            foreach (($n['IPAM']['Config'] ?? []) ?: [] as $cfg) {
                if (str_contains((string) ($cfg['Subnet'] ?? ''), ':')) {
                    $v6 = $cfg;
                } elseif (! empty($cfg['Subnet'])) {
                    $v4 = $cfg;
                }
            }

            return [
                'id' => substr((string) $n['Id'], 0, 12),
                'name' => (string) $n['Name'],
                'driver' => (string) ($n['Driver'] ?? ''),
                'subnet' => $v4['Subnet'] ?? null,
                'gateway' => $v4['Gateway'] ?? null,
                'subnet6' => $v6['Subnet'] ?? null,
                'gateway6' => $v6['Gateway'] ?? null,
                'internal' => (bool) ($n['Internal'] ?? false),
                'labels' => ($n['Labels'] ?? []) ?: [],
                'containers' => count(($n['Containers'] ?? []) ?: []),
                'created' => self::time($n['Created'] ?? null),
                'builtin' => in_array($n['Name'], self::BUILTIN_NETWORKS, true),
            ];
        }, $nets);
    }

    /** Compatibility for the AI tools. */
    public function networks(): array
    {
        return array_map(fn ($n) => ['ID' => $n['id'], 'Name' => $n['name'], 'Driver' => $n['driver'], 'Scope' => 'local'], $this->networkList());
    }

    public function createNetworkCommand(array $d): string
    {
        if (! self::validName((string) ($d['name'] ?? ''))) {
            throw new \InvalidArgumentException('Invalid network name');
        }
        $driver = (string) ($d['driver'] ?? 'bridge');
        if (! in_array($driver, self::NETWORK_DRIVERS, true)) {
            throw new \InvalidArgumentException('Unsupported driver');
        }
        $cmd = 'docker network create --driver '.$driver;
        $cidr4 = fn ($v) => preg_match('/^(\d{1,3}(\.\d{1,3}){3})\/(\d{1,2})$/', $v, $m) && filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && (int) $m[3] <= 32;
        $cidr6 = fn ($v) => preg_match('/^([0-9a-fA-F:]+)\/(\d{1,3})$/', $v, $m) && filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && (int) $m[2] <= 128;

        if (! empty($d['subnet'])) {
            if (! $cidr4($d['subnet'])) {
                throw new \InvalidArgumentException('Invalid IPv4 subnet, e.g. 172.30.0.0/16');
            }
            $cmd .= ' --subnet '.Shell::arg($d['subnet']);
            if (! empty($d['ip_range'])) {
                if (! $cidr4($d['ip_range'])) {
                    throw new \InvalidArgumentException('Invalid IPv4 range');
                }
                $cmd .= ' --ip-range '.Shell::arg($d['ip_range']);
            }
            if (! empty($d['gateway'])) {
                if (! filter_var($d['gateway'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    throw new \InvalidArgumentException('Invalid IPv4 gateway');
                }
                $cmd .= ' --gateway '.Shell::arg($d['gateway']);
            }
        }
        if (! empty($d['ipv6'])) {
            $cmd .= ' --ipv6';
            if (! empty($d['subnet6'])) {
                if (! $cidr6($d['subnet6'])) {
                    throw new \InvalidArgumentException('Invalid IPv6 subnet, e.g. fd00:30::/64');
                }
                $cmd .= ' --subnet '.Shell::arg($d['subnet6']);
                if (! empty($d['gateway6'])) {
                    if (! filter_var($d['gateway6'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                        throw new \InvalidArgumentException('Invalid IPv6 gateway');
                    }
                    $cmd .= ' --gateway '.Shell::arg($d['gateway6']);
                }
            }
        }
        if (! empty($d['internal'])) {
            $cmd .= ' --internal';
        }
        if (in_array($driver, ['macvlan', 'ipvlan'], true)) {
            if (empty($d['parent']) || ! preg_match('/^[a-zA-Z0-9_.:-]{1,15}$/', (string) $d['parent'])) {
                throw new \InvalidArgumentException('Enter the parent network interface, e.g. eth0');
            }
            $cmd .= ' -o parent='.Shell::arg($d['parent']);
        }
        foreach ($this->splitLines((string) ($d['labels'] ?? '')) as $label) {
            if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-\/]*=/', $label)) {
                throw new \InvalidArgumentException("Invalid label: {$label}");
            }
            $cmd .= ' --label '.Shell::arg($label);
        }

        return $cmd.' '.Shell::arg($d['name']);
    }

    public function removeNetwork(string $name): ShellResult
    {
        if (! self::validRef($name) || in_array($name, self::BUILTIN_NETWORKS, true)) {
            return new ShellResult(1, '', 'The bridge, host and none networks cannot be removed');
        }

        return Shell::run('docker network rm '.Shell::arg($name), 60);
    }

    public function pruneNetworks(): ShellResult
    {
        return Shell::run('docker network prune -f', 120);
    }

    /* ======================================================================= volumes */

    public function volumeList(): array
    {
        if (Shell::simulating()) {
            $raw = [
                ['evolution-api_evolution_instances', '2026-08-28T21:33:33Z', ['com.docker.compose.project' => 'evolution-api', 'com.docker.compose.volume' => 'evolution_instances']],
                ['evolution-api_postgres_data', '2026-08-28T21:33:33Z', ['com.docker.compose.project' => 'evolution-api', 'com.docker.compose.volume' => 'postgres_data']],
                ['evolution-api_redis_data', '2026-08-28T21:33:33Z', ['com.docker.compose.project' => 'evolution-api', 'com.docker.compose.volume' => 'redis_data']],
                ['qdrant_storage', '2026-08-28T12:19:51Z', []],
                ['gowa', '2026-08-28T12:15:37Z', []],
            ];
            $vols = array_map(fn ($r) => ['Name' => $r[0], 'Driver' => 'local', 'Mountpoint' => '/var/lib/docker/volumes/'.$r[0].'/_data', 'CreatedAt' => $r[1], 'Labels' => $r[2], 'Options' => null], $raw);
        } else {
            $vols = $this->jsonArray('docker volume ls -q | xargs -r docker volume inspect || echo "[]"');
        }

        $users = [];
        foreach ($this->containerList() as $c) {
            foreach ($c['mounts'] as $m) {
                if ($m['type'] === 'volume' && $m['name']) {
                    $users[$m['name']][] = $c['name'];
                }
            }
        }

        $rows = array_map(fn ($v) => [
            'name' => (string) $v['Name'],
            'driver' => (string) ($v['Driver'] ?? 'local'),
            'mountpoint' => (string) ($v['Mountpoint'] ?? ''),
            'labels' => ($v['Labels'] ?? []) ?: [],
            'options' => ($v['Options'] ?? []) ?: [],
            'created' => self::time($v['CreatedAt'] ?? null),
            'containers' => $users[$v['Name']] ?? [],
        ], $vols);
        usort($rows, fn ($a, $b) => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));

        return $rows;
    }

    /** Compatibility for the AI tools. */
    public function volumes(): array
    {
        return array_map(fn ($v) => ['Name' => $v['name'], 'Driver' => $v['driver'], 'Mountpoint' => $v['mountpoint']], $this->volumeList());
    }

    public function createVolumeCommand(array $d): string
    {
        if (! self::validName((string) ($d['name'] ?? ''))) {
            throw new \InvalidArgumentException('Invalid volume name');
        }
        $driver = (string) (($d['driver'] ?? '') ?: 'local');
        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.\/:-]{0,63}$/', $driver)) {
            throw new \InvalidArgumentException('Invalid driver');
        }
        $cmd = 'docker volume create --driver '.Shell::arg($driver);
        foreach ($this->splitLines((string) ($d['options'] ?? '')) as $opt) {
            if (! preg_match('/^[A-Za-z0-9_.-]+=/', $opt)) {
                throw new \InvalidArgumentException("Invalid driver option: {$opt}");
            }
            $cmd .= ' --opt '.Shell::arg($opt);
        }
        foreach ($this->splitLines((string) ($d['labels'] ?? '')) as $label) {
            if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-\/]*=/', $label)) {
                throw new \InvalidArgumentException("Invalid label: {$label}");
            }
            $cmd .= ' --label '.Shell::arg($label);
        }

        return $cmd.' '.Shell::arg($d['name']);
    }

    public function removeVolume(string $name): ShellResult
    {
        return self::validRef($name) ? Shell::run('docker volume rm '.Shell::arg($name), 60) : new ShellResult(1, '', 'Invalid volume');
    }

    public function pruneVolumes(bool $all): ShellResult
    {
        // Docker 23+ only removes anonymous volumes unless --all is given
        return Shell::run('docker volume prune -f'.($all ? ' --all' : ''), 300);
    }

    /* ======================================================================= helpers */

    protected function splitLines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $text))));
    }

    /** Containers shown in simulation mode (docker inspect shape). */
    protected function fakeContainers(): array
    {
        $make = function (string $id, string $name, string $image, string $imageId, string $created, array $ports, array $networks, ?string $project = null, array $mounts = []) {
            $portMap = [];
            foreach ($ports as [$spec, $host]) {
                $portMap[$spec] = $host ? [['HostIp' => '0.0.0.0', 'HostPort' => (string) $host], ['HostIp' => '::', 'HostPort' => (string) $host]] : null;
            }

            return [
                'Id' => $id.str_repeat('0', 52), 'Name' => '/'.$name, 'Created' => $created, 'Path' => 'docker-entrypoint.sh', 'Args' => [],
                'Image' => $imageId, 'State' => ['Status' => 'running', 'StartedAt' => $created, 'FinishedAt' => '0001-01-01T00:00:00Z', 'ExitCode' => 0],
                'Config' => ['Image' => $image, 'Labels' => $project ? ['com.docker.compose.project' => $project, 'com.docker.compose.service' => $name] : [], 'Env' => ['TZ=UTC', 'APP_SECRET=s3cr3t-value']],
                'HostConfig' => ['RestartPolicy' => ['Name' => $project ? 'always' : 'unless-stopped'], 'Memory' => 0, 'NanoCpus' => 0],
                'NetworkSettings' => ['Ports' => $portMap, 'Networks' => $networks],
                'Mounts' => $mounts, 'LogPath' => '/var/lib/docker/containers/'.$id.'/'.$id.'-json.log',
            ];
        };
        $net = fn ($name, $ip) => [$name => ['IPAddress' => $ip, 'GlobalIPv6Address' => '']];
        $vol = fn ($name, $dest) => ['Type' => 'volume', 'Name' => $name, 'Source' => '/var/lib/docker/volumes/'.$name.'/_data', 'Destination' => $dest, 'RW' => true];

        return [
            $make('78da15e9fe21', 'gowa', 'ghcr.io/aldinokemal/go-whatsapp-web-multidevice:latest', 'sha256:2b942792de76be3940e507507f24aa01', '2026-08-29T13:19:26Z', [['3000/tcp', 3000]], $net('bridge', '172.17.0.3'), null, [$vol('gowa', '/app/storages')]),
            $make('6d9bfe1c6378', 'evolution_api', 'evoapicloud/evolution-api:latest', 'sha256:966625532d9076a2381e973a2713ab44', '2026-08-28T21:33:33Z', [['8080/tcp', 8080]], $net('evolution-api_evolution_network', '172.19.0.2'), 'evolution-api', [$vol('evolution-api_evolution_instances', '/evolution/instances')]),
            $make('627c443d37d1', 'evolution_postgres', 'postgres:15', 'sha256:9b1d34adbce1dd07ee6e94b4a2cf0c11', '2026-08-28T21:33:32Z', [['5432/tcp', null]], $net('evolution-api_evolution_network', '172.19.0.3'), 'evolution-api', [$vol('evolution-api_postgres_data', '/var/lib/postgresql/data')]),
            $make('9cc7d7934236', 'evolution_redis', 'redis:7-alpine', 'sha256:ff02b58f971e7d7d156a1267e283f7a3', '2026-08-28T21:33:31Z', [['6379/tcp', null]], $net('evolution-api_evolution_network', '172.19.0.4'), 'evolution-api', [$vol('evolution-api_redis_data', '/data')]),
            $make('16fc16ccac96', 'tika', 'apache/tika:latest', 'sha256:a8b442501f601fb15015de974f9af2e1', '2026-08-28T17:18:14Z', [['9998/tcp', 9998]], $net('bridge', '172.17.0.2')),
            $make('9bf9aac6435c', 'qdrant', 'qdrant/qdrant:latest', 'sha256:057ee3a8da769fe7310dd3537b4d11e0', '2026-08-28T12:19:51Z', [['6333/tcp', 6333], ['6334/tcp', 6334]], $net('bridge', '172.17.0.4'), null, [$vol('qdrant_storage', '/qdrant/storage')]),
        ];
    }
}
