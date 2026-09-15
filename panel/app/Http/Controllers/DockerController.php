<?php

namespace App\Http\Controllers;

use App\Models\DockerNote;
use App\Models\DockerRegistry;
use App\Services\Docker\AppStore;
use App\Services\Docker\ComposeManager;
use App\Services\DockerManager;
use App\Services\FileManager;
use App\Services\Shell;
use App\Services\ShellResult;
use App\Services\SystemStats;
use App\Services\TaskRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Docker: overview, containers, images, networks and volumes.
 * Compose, the app store and settings have their own controllers.
 */
class DockerController extends Controller
{
    public const TABS = [
        'overview' => ['Overview', 'bi-speedometer2'],
        'containers' => ['Container', 'bi-box'],
        'apps' => ['One-Click Install', 'bi-grid'],
        'hub' => ['Cloud image', 'bi-cloud'],
        'images' => ['Local image', 'bi-layers'],
        'compose' => ['Docker Compose', 'bi-stack'],
        'networks' => ['Network', 'bi-diagram-3'],
        'volumes' => ['Volume', 'bi-hdd'],
        'registries' => ['Repository', 'bi-archive'],
        'settings' => ['Settings', 'bi-gear'],
    ];

    public function __construct(protected DockerManager $docker) {}

    public function index(Request $request)
    {
        $tab = array_key_exists((string) $request->query('tab'), self::TABS) ? (string) $request->query('tab') : 'overview';
        $installed = $this->docker->installed();
        $running = $installed && $this->docker->running();

        return view('docker.index', [
            'tab' => $tab,
            'tabs' => self::TABS,
            'installed' => $installed,
            'running' => $running,
            'version' => $running ? ($this->docker->info()['version'] ?? null) : null,
            'registries' => DockerRegistry::query()->orderBy('name')->get(['id', 'name', 'url', 'namespace']),
            'categories' => app(AppStore::class)->categories(),
            'projectsRoot' => ComposeManager::root(),
        ]);
    }

    protected function ensureRunning(): ?\Illuminate\Http\JsonResponse
    {
        return $this->docker->running() ? null : $this->fail('Docker is not running.', 409);
    }

    /* ====================================================================== overview */

    public function overview(ComposeManager $compose)
    {
        if ($error = $this->ensureRunning()) {
            return $error;
        }
        $containers = $this->docker->containerList();
        $disk = $this->docker->diskUsage();

        return $this->ok('ok', [
            'counts' => [
                'containers' => count($containers),
                'running' => count(array_filter($containers, fn ($c) => $c['state'] === 'running')),
                'compose' => count($compose->projects()),
                'images' => $disk['Images']['count'] ?? count($this->docker->imageList()),
                'networks' => count($this->docker->networkList()),
                'volumes' => $disk['Local Volumes']['count'] ?? 0,
                'registries' => DockerRegistry::query()->count(),
            ],
            'disk' => $disk,
            'containers' => $this->withNotes($containers),
        ]);
    }

    public function stats()
    {
        return $this->ok('ok', ['stats' => $this->docker->running() ? $this->docker->stats() : []]);
    }

    /* ==================================================================== containers */

    protected function withNotes(array $containers): array
    {
        $notes = DockerNote::map('container');

        return array_map(fn ($c) => array_diff_key($c, ['log_path' => 1]) + ['note' => $notes[$c['name']] ?? ''], $containers);
    }

    public function containers()
    {
        if ($error = $this->ensureRunning()) {
            return $error;
        }

        return $this->ok('ok', ['data' => $this->withNotes($this->docker->containerList())]);
    }

    public function container(Request $request, string $ref)
    {
        $c = $this->docker->container($ref);
        if (! $c) {
            return $this->fail('Container not found', 404);
        }
        $raw = $c['raw'];
        $env = ($raw['Config']['Env'] ?? []) ?: [];
        if (! $request->user()->canWrite()) {
            // read-only users do not see secrets passed as environment variables
            $env = array_map(fn ($e) => preg_replace('/=.*/s', '=********', $e), $env);
        }
        unset($c['raw'], $c['log_path']);

        return $this->ok('ok', ['container' => $c + [
            'env' => $env,
            'labels' => ($raw['Config']['Labels'] ?? []) ?: [],
            'hostname' => $raw['Config']['Hostname'] ?? null,
            'working_dir' => $raw['Config']['WorkingDir'] ?? null,
            'entrypoint' => implode(' ', (array) ($raw['Config']['Entrypoint'] ?? [])),
            'privileged' => (bool) ($raw['HostConfig']['Privileged'] ?? false),
            'network_mode' => $raw['HostConfig']['NetworkMode'] ?? null,
            'note' => DockerNote::map('container')[$c['name']] ?? '',
        ]]);
    }

    public function action(Request $request)
    {
        $data = $request->validate(['id' => ['required', 'string', 'max:128'], 'action' => ['required', Rule::in(['start', 'stop', 'restart', 'pause', 'unpause', 'kill', 'rm'])]]);
        if ($data['action'] === 'rm') {
            DockerNote::query()->where('type', 'container')->where('ref', $data['id'])->delete();
        }

        return $this->result($this->docker->containerAction($data['id'], $data['action']), 'Container '.$data['id'].': '.$data['action'], 'docker', $data['id']);
    }

    public function bulk(Request $request)
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:200'], 'ids.*' => ['string', 'max:128'], 'action' => ['required', Rule::in(['start', 'stop', 'restart', 'rm'])]]);
        $errors = [];
        foreach ($data['ids'] as $id) {
            $r = $this->docker->containerAction($id, $data['action']);
            if ($r->failed()) {
                $errors[] = $id.': '.$r->message();
            }
        }
        $this->audit('docker', 'Containers '.$data['action'], implode(', ', $data['ids']));

        return $errors ? $this->fail(implode('; ', array_slice($errors, 0, 5))) : $this->ok(count($data['ids']).' container(s): '.$data['action']);
    }

    public function rename(Request $request)
    {
        $data = $request->validate(['id' => ['required', 'string'], 'name' => ['required', 'string', 'max:128']]);
        $result = $this->docker->rename($data['id'], $data['name']);
        if ($result->ok()) {
            DockerNote::query()->where('type', 'container')->where('ref', $data['id'])->update(['ref' => $data['name']]);
        }

        return $this->result($result, 'Container renamed', 'docker', $data['id'].' -> '.$data['name']);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'id' => ['required', 'string'],
            'restart' => ['required', Rule::in(DockerManager::RESTART_POLICIES)],
            'memory' => ['nullable', 'string', 'max:10'],
            'cpus' => ['nullable', 'numeric', 'min:0', 'max:512'],
        ]);

        return $this->result($this->docker->updateContainer($data['id'], $data['restart'], $data['memory'] ?? null, isset($data['cpus']) ? (float) $data['cpus'] : null), 'Container updated', 'docker', $data['id']);
    }

    public function commit(Request $request)
    {
        $data = $request->validate(['id' => ['required', 'string'], 'image' => ['required', 'string', 'max:255']]);
        if (! DockerManager::validRef($data['id']) || ! DockerManager::validRef($data['image'])) {
            return $this->fail('Invalid container or image name');
        }

        return $this->task(TaskRunner::dispatch("Save container {$data['id']} as {$data['image']}", 'docker commit '.Shell::arg($data['id']).' '.Shell::arg($data['image']).' && docker image ls '.Shell::arg(preg_replace('/:[^:\/]*$/', '', $data['image'])), 'docker'), 'Creating image');
    }

    public function note(Request $request)
    {
        $data = $request->validate(['type' => ['required', Rule::in(['container', 'project'])], 'ref' => ['required', 'string', 'max:128'], 'note' => ['nullable', 'string', 'max:255']]);
        DockerNote::put($data['type'], $data['ref'], $data['note'] ?? null);

        return $this->ok('Note saved');
    }

    public function logs(Request $request)
    {
        $data = $request->validate(['id' => ['required', 'string'], 'lines' => ['nullable', 'integer', 'min:10', 'max:10000'], 'since' => ['nullable', 'string', 'max:6']]);

        return $this->ok('ok', ['content' => $this->docker->logs($data['id'], (int) ($data['lines'] ?? 300), $data['since'] ?? null)]);
    }

    public function logSizes()
    {
        $sizes = $this->docker->logSizes();
        arsort($sizes);

        return $this->ok('ok', ['data' => array_map(fn ($name, $size) => ['name' => $name, 'size' => $size, 'human' => SystemStats::bytes($size, 1)], array_keys($sizes), $sizes)]);
    }

    public function clearLogs(Request $request)
    {
        $id = $request->input('id');

        return $this->result($this->docker->clearLogs($id ? (string) $id : null), $id ? 'Log cleared' : 'All container logs cleared', 'docker', $id ?: 'all containers');
    }

    public function inspect(Request $request)
    {
        if (! $request->user()->canWrite()) {
            return $this->fail('Your account does not have permission for this action.', 403);
        }

        return $this->ok('ok', ['content' => $this->docker->inspect((string) $request->query('id'))]);
    }

    public function create(Request $request)
    {
        $data = $request->validate([
            'image' => ['required', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:128'],
            'ports' => ['nullable', 'string', 'max:2000'],
            'env' => ['nullable', 'string', 'max:16000'],
            'volumes' => ['nullable', 'string', 'max:4000'],
            'labels' => ['nullable', 'string', 'max:4000'],
            'network' => ['nullable', 'string', 'max:128'],
            'restart' => ['nullable', Rule::in(DockerManager::RESTART_POLICIES)],
            'memory' => ['nullable', 'string', 'max:10'],
            'cpus' => ['nullable', 'numeric', 'min:0', 'max:512'],
            'command' => ['nullable', 'string', 'max:1000'],
            'pull' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $data['pull'] = $request->boolean('pull', true);
        try {
            $script = $this->docker->runScript($data);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        if (! empty($data['name']) && ! empty($data['note'])) {
            DockerNote::put('container', $data['name'], $data['note']);
        }

        return $this->task(TaskRunner::dispatch('Create container '.($data['name'] ?: $data['image']), $script."\nsleep 2\ndocker ps -a --latest --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'", 'docker'), 'Creating container');
    }

    public function pruneContainers()
    {
        return $this->result($this->docker->pruneContainers(), 'Stopped containers removed', 'docker');
    }

    /* ======================================================================== images */

    public function images()
    {
        if ($error = $this->ensureRunning()) {
            return $error;
        }

        return $this->ok('ok', ['data' => $this->docker->imageList()]);
    }

    protected function registry(Request $request): ?DockerRegistry
    {
        return $request->filled('registry_id') ? DockerRegistry::query()->findOrFail((int) $request->input('registry_id')) : null;
    }

    public function pull(Request $request)
    {
        $request->validate(['image' => ['required', 'string', 'max:255'], 'registry_id' => ['nullable', 'integer']]);
        $image = trim((string) $request->input('image'));
        try {
            $script = $this->docker->pullScript($image, $this->registry($request));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->task(TaskRunner::dispatch("Pull image {$image}", $script, 'docker'), 'Pulling image');
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => ['nullable', 'file', 'max:'.(1024 * 1024 * 4)],
            'path' => ['nullable', 'string', 'max:500'],
        ]);
        if ($request->hasFile('file')) {
            $name = $request->file('file')->getClientOriginalName();
            if (! preg_match('/\.(tar|tar\.gz|tgz|tar\.xz)$/i', $name)) {
                return $this->fail('Upload an image archive (.tar, .tar.gz, .tgz or .tar.xz) created with docker save.');
            }
            $stored = $request->file('file')->storeAs('docker-import', Str::random(16).'-'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $name));
            $path = storage_path('app/private/'.$stored);
            if (! is_file($path)) {
                $path = storage_path('app/'.$stored);
            }

            return $this->task(TaskRunner::dispatch("Import image {$name}", $this->docker->importScript($path, true), 'docker'), 'Importing image');
        }

        $path = FileManager::normalize((string) $request->input('path'));
        if (! preg_match('/\.(tar|tar\.gz|tgz|tar\.xz)$/i', $path)) {
            return $this->fail('Choose a file or enter the path of an image archive on the server.');
        }

        return $this->task(TaskRunner::dispatch('Import image '.basename($path), $this->docker->importScript($path, false), 'docker'), 'Importing image');
    }

    public function build(Request $request)
    {
        $data = $request->validate(['tag' => ['required', 'string', 'max:255'], 'dockerfile' => ['required', 'string', 'max:65000'], 'context' => ['nullable', 'string', 'max:500']]);
        try {
            $script = $this->docker->buildScript($data['tag'], $data['dockerfile'], $data['context'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->task(TaskRunner::dispatch("Build image {$data['tag']}", $script, 'docker'), 'Building image');
    }

    public function push(Request $request)
    {
        $data = $request->validate(['image' => ['required', 'string', 'max:255'], 'target' => ['required', 'string', 'max:255'], 'registry_id' => ['required', 'integer']]);
        $registry = DockerRegistry::query()->findOrFail($data['registry_id']);
        try {
            $script = $this->docker->pushScript($data['image'], $data['target'], $registry);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->task(TaskRunner::dispatch('Push '.$registry->reference($data['target']), $script, 'docker'), 'Pushing image');
    }

    public function export(Request $request)
    {
        $data = $request->validate(['image' => ['required', 'string', 'max:255']]);
        try {
            [$script, $file] = $this->docker->exportScript($data['image']);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->task(TaskRunner::dispatch('Export image '.$data['image'], $script, 'docker', ['file' => $file]), 'Exporting to '.$file);
    }

    public function removeImage(Request $request)
    {
        $data = $request->validate(['id' => ['required', 'string', 'max:255'], 'force' => ['nullable', 'boolean']]);

        return $this->result($this->docker->removeImage($data['id'], $request->boolean('force')), 'Image removed', 'docker', $data['id']);
    }

    public function bulkImages(Request $request)
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:200'], 'ids.*' => ['string', 'max:255']]);
        $errors = [];
        foreach ($data['ids'] as $id) {
            $r = $this->docker->removeImage($id);
            if ($r->failed()) {
                $errors[] = substr(str_replace('sha256:', '', $id), 0, 12).': '.$r->message();
            }
        }
        $this->audit('docker', 'Removed images', implode(', ', $data['ids']));

        return $errors ? $this->fail(implode('; ', array_slice($errors, 0, 4))) : $this->ok(count($data['ids']).' image(s) removed');
    }

    public function pruneImages(Request $request)
    {
        return $this->result($this->docker->pruneImages($request->boolean('all')), 'Unused images removed', 'docker');
    }

    /* ====================================================================== networks */

    public function networks()
    {
        if ($error = $this->ensureRunning()) {
            return $error;
        }

        return $this->ok('ok', ['data' => $this->docker->networkList()]);
    }

    public function networkStore(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'driver' => ['required', Rule::in(DockerManager::NETWORK_DRIVERS)],
            'subnet' => ['nullable', 'string', 'max:64'],
            'gateway' => ['nullable', 'string', 'max:64'],
            'ip_range' => ['nullable', 'string', 'max:64'],
            'ipv6' => ['nullable', 'boolean'],
            'subnet6' => ['nullable', 'string', 'max:64'],
            'gateway6' => ['nullable', 'string', 'max:64'],
            'internal' => ['nullable', 'boolean'],
            'parent' => ['nullable', 'string', 'max:15'],
            'labels' => ['nullable', 'string', 'max:2000'],
        ]);
        try {
            $cmd = $this->docker->createNetworkCommand($data);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->result(Shell::run($cmd, 60), 'Network created', 'docker', $data['name']);
    }

    public function networkDestroy(Request $request)
    {
        $data = $request->validate(['names' => ['required', 'array', 'max:100'], 'names.*' => ['string', 'max:128']]);

        return $this->removeMany($data['names'], fn ($n) => $this->docker->removeNetwork($n), 'network');
    }

    public function pruneNetworks()
    {
        return $this->result($this->docker->pruneNetworks(), 'Unused networks removed', 'docker');
    }

    /* ======================================================================= volumes */

    public function volumes()
    {
        if ($error = $this->ensureRunning()) {
            return $error;
        }

        return $this->ok('ok', ['data' => $this->docker->volumeList()]);
    }

    public function volumeStore(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:128'], 'driver' => ['nullable', 'string', 'max:64'], 'options' => ['nullable', 'string', 'max:2000'], 'labels' => ['nullable', 'string', 'max:2000']]);
        try {
            $cmd = $this->docker->createVolumeCommand($data);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->result(Shell::run($cmd, 60), 'Volume created', 'docker', $data['name']);
    }

    public function volumeDestroy(Request $request)
    {
        $data = $request->validate(['names' => ['required', 'array', 'max:100'], 'names.*' => ['string', 'max:255']]);

        return $this->removeMany($data['names'], fn ($n) => $this->docker->removeVolume($n), 'volume');
    }

    public function pruneVolumes(Request $request)
    {
        return $this->result($this->docker->pruneVolumes($request->boolean('all')), 'Unused volumes removed', 'docker');
    }

    /** @param callable(string): ShellResult $remove */
    protected function removeMany(array $names, callable $remove, string $label)
    {
        $errors = [];
        foreach ($names as $name) {
            $r = $remove($name);
            if ($r->failed()) {
                $errors[] = $name.': '.$r->message();
            }
        }
        $this->audit('docker', 'Removed '.$label.'(s)', implode(', ', $names));

        return $errors ? $this->fail(implode('; ', array_slice($errors, 0, 4))) : $this->ok(count($names).' '.$label.'(s) removed');
    }
}
