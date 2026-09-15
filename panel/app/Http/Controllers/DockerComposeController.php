<?php

namespace App\Http\Controllers;

use App\Models\DockerNote;
use App\Models\DockerTemplate;
use App\Services\Docker\AppStore;
use App\Services\Docker\ComposeManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Docker > Docker Compose: projects, templates, logs and configuration files. */
class DockerComposeController extends Controller
{
    public function __construct(protected ComposeManager $compose) {}

    protected function project(string $name): array
    {
        return $this->compose->find($name) ?? abort(404, 'Compose project not found');
    }

    public function index()
    {
        $notes = DockerNote::map('project');
        $apps = app(AppStore::class)->apps();

        return $this->ok('ok', ['data' => array_map(fn ($p) => $p + [
            'note' => $notes[$p['name']] ?? '',
            'app_name' => $p['app'] ? ($apps[$p['app']]['name'] ?? $p['app']) : null,
        ], $this->compose->projects())]);
    }

    public function show(Request $request, string $name)
    {
        $project = $this->project($name);
        $files = $this->compose->files($project);
        if (! $request->user()->canWrite()) {
            $files['env'] = preg_replace('/^([A-Za-z_][A-Za-z0-9_]*)=.*$/m', '$1=********', $files['env']);
        }

        return $this->ok('ok', [
            'project' => $project + ['note' => DockerNote::map('project')[$name] ?? ''],
            'containers' => array_map(fn ($c) => array_diff_key($c, ['log_path' => 1]), $this->compose->containers($project)),
            'files' => $files,
        ]);
    }

    public function logs(Request $request, string $name)
    {
        return $this->ok('ok', ['content' => $this->compose->logs($this->project($name), (int) $request->query('lines', 300))]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:63'],
            'content' => ['required', 'string', 'max:65000'],
            'env' => ['nullable', 'string', 'max:65000'],
            'note' => ['nullable', 'string', 'max:255'],
            'start' => ['nullable', 'boolean'],
        ]);
        $created = $this->compose->create($data['name'], $data['content'], $data['env'] ?? null);
        if ($created->failed()) {
            return $this->fail($created->message());
        }
        DockerNote::put('project', $data['name'], $data['note'] ?? null);
        $this->audit('docker', 'Created compose project '.$data['name'], ComposeManager::root().'/'.$data['name']);

        if (! $request->boolean('start', true)) {
            return $this->ok('Compose project created');
        }
        $dir = ComposeManager::root().'/'.$data['name'];
        $project = $this->compose->find($data['name']) ?? ['name' => $data['name'], 'dir' => $dir, 'files' => [$dir.'/compose.yaml'], 'managed' => true];

        return $this->task($this->compose->upTask($project), 'Starting compose project');
    }

    public function action(Request $request, string $name)
    {
        $data = $request->validate(['action' => ['required', Rule::in(['start', 'stop', 'restart', 'update', 'down'])]]);

        return $this->task($this->compose->actionTask($this->project($name), $data['action']), 'Compose '.$data['action']);
    }

    public function saveFile(Request $request, string $name)
    {
        $data = $request->validate(['which' => ['required', Rule::in(['compose', 'env'])], 'content' => ['present', 'nullable', 'string', 'max:65000'], 'apply' => ['nullable', 'boolean']]);
        $project = $this->project($name);
        $result = $this->compose->saveFile($project, $data['which'], (string) ($data['content'] ?? ''));
        if ($result->failed()) {
            return $this->fail($result->message());
        }
        $this->audit('docker', 'Edited compose project '.$name, $data['which'] === 'env' ? '.env' : ($project['files'][0] ?? ''));

        return $request->boolean('apply')
            ? $this->task($this->compose->upTask($project, false, "Apply compose {$name}"), 'Saved, applying changes')
            : $this->ok('Saved');
    }

    public function destroy(Request $request)
    {
        $data = $request->validate(['names' => ['required', 'array', 'max:50'], 'names.*' => ['string', 'max:63'], 'volumes' => ['nullable', 'boolean'], 'files' => ['nullable', 'boolean']]);
        $last = null;
        foreach ($data['names'] as $name) {
            $last = $this->compose->deleteTask($this->project($name), $request->boolean('volumes'), $request->boolean('files', true));
            DockerNote::put('project', $name, null);
        }
        $this->audit('docker', 'Deleted compose project(s)', implode(', ', $data['names']));

        return count($data['names']) === 1 ? $this->task($last, 'Removing project') : $this->ok(count($data['names']).' projects are being removed. Follow them in the task list.');
    }

    /* ====================================================================== templates */

    public function templates()
    {
        return $this->ok('ok', ['data' => DockerTemplate::query()->orderBy('name')->get()]);
    }

    public function templateStore(Request $request)
    {
        $template = DockerTemplate::query()->create($this->templateData($request));
        $this->audit('docker', 'Created compose template '.$template->name);

        return $this->ok('Template saved', ['template' => $template]);
    }

    public function templateUpdate(Request $request, DockerTemplate $template)
    {
        $template->update($this->templateData($request));

        return $this->ok('Template saved', ['template' => $template]);
    }

    public function templateDestroy(DockerTemplate $template)
    {
        $template->delete();

        return $this->ok('Template deleted');
    }

    protected function templateData(Request $request): array
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'remark' => ['nullable', 'string', 'max:255'], 'content' => ['required', 'string', 'max:65000'], 'env' => ['nullable', 'string', 'max:65000']]);
        if (! preg_match('/^\s*services\s*:/m', $data['content'])) {
            abort(response()->json(['ok' => false, 'message' => 'The compose file must contain a "services:" section.'], 422));
        }
        $data['content'] = str_replace("\r\n", "\n", $data['content']);
        $data['env'] = isset($data['env']) ? str_replace("\r\n", "\n", $data['env']) : null;

        return $data;
    }
}
