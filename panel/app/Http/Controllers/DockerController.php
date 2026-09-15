<?php

namespace App\Http\Controllers;

use App\Services\DockerManager;
use App\Services\Shell;
use App\Services\TaskRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DockerController extends Controller
{
    public function __construct(protected DockerManager $docker) {}

    public function index()
    {
        $installed = $this->docker->installed();

        return view('docker.index', [
            'installed' => $installed,
            'running' => $installed && $this->docker->running(),
            'info' => $installed && $this->docker->running() ? $this->docker->info() : null,
        ]);
    }

    public function data(Request $request)
    {
        return response()->json(match ($request->query('type')) {
            'images' => ['data' => $this->docker->images()],
            'volumes' => ['data' => $this->docker->volumes()],
            'networks' => ['data' => $this->docker->networks()],
            default => ['data' => $this->docker->containers(), 'stats' => $this->docker->stats()],
        });
    }

    public function action(Request $request)
    {
        $data = $request->validate(['id' => ['required', 'string'], 'action' => ['required', 'string']]);

        return $this->result($this->docker->containerAction($data['id'], $data['action']), 'Container '.$data['action'].': done', 'docker', $data['id']);
    }

    public function logs(Request $request)
    {
        return $this->ok('ok', ['content' => $this->docker->logs((string) $request->query('id'))]);
    }

    public function inspect(Request $request)
    {
        return $this->ok('ok', ['content' => $this->docker->inspect((string) $request->query('id'))]);
    }

    public function create(Request $request)
    {
        $data = $request->validate([
            'image' => ['required', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:64'],
            'ports' => ['nullable', 'string', 'max:2000'],
            'env' => ['nullable', 'string', 'max:8000'],
            'volumes' => ['nullable', 'string', 'max:4000'],
            'restart' => ['nullable', 'string'],
            'memory' => ['nullable', 'string', 'max:10'],
            'command' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->task(TaskRunner::dispatch('Create container from '.$data['image'], $this->docker->runScript($data), 'docker'), 'Pulling image and starting container');
    }

    public function pull(Request $request)
    {
        $image = (string) $request->input('image');
        if (! DockerManager::validRef($image)) {
            return $this->fail('Invalid image name');
        }

        return $this->task(TaskRunner::dispatch("Pull image {$image}", 'docker pull '.Shell::arg($image), 'docker'));
    }

    public function removeImage(Request $request)
    {
        return $this->result($this->docker->removeImage((string) $request->input('id')), 'Image removed', 'docker');
    }

    public function removeVolume(Request $request)
    {
        return $this->result($this->docker->removeVolume((string) $request->input('name')), 'Volume removed', 'docker');
    }

    public function prune()
    {
        return $this->result($this->docker->prune(), 'Unused data removed', 'docker');
    }

    /** Deploy a docker-compose project stored in /www/docker/<name>. */
    public function compose(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_-]{1,40}$/'],
            'content' => ['required', 'string', 'max:65000'],
        ]);
        $dir = '/www/docker/'.$data['name'];
        $tmp = storage_path('app/compose-'.Str::random(10).'.yml');
        file_put_contents($tmp, str_replace("\r\n", "\n", $data['content']));

        $script = "set -e\nmkdir -p ".Shell::arg($dir)."\nmv ".Shell::arg($tmp).' '.Shell::arg($dir.'/docker-compose.yml')
            ."\ncd ".Shell::arg($dir)."\ndocker compose up -d\ndocker compose ps";

        return $this->task(TaskRunner::dispatch("Compose up {$data['name']}", $script, 'docker'), 'Deploying compose project');
    }
}
