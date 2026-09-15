<?php

namespace App\Http\Controllers;

use App\Models\DbServer;
use App\Services\Databases\QdrantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class QdrantController extends Controller
{
    public function __construct(protected QdrantManager $qdrant) {}

    protected function server(Request $request): ?DbServer
    {
        $id = $request->input('server_id');

        return $id ? DbServer::query()->where('engine', 'qdrant')->findOrFail($id) : null;
    }

    protected function collection(string $name): string
    {
        abort_unless(QdrantManager::validCollection($name), 404);

        return $name;
    }

    public function overview(Request $request)
    {
        $server = $this->server($request);
        $overview = $this->qdrant->overview($server);

        return $this->ok('ok', ['overview' => $overview, 'collections' => $overview['connected'] ? $this->qdrant->collections($server) : []]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'regex:/^[A-Za-z0-9_\-]{1,120}$/'],
            'size' => ['required', 'integer', 'min:1', 'max:65536'],
            'distance' => ['required', Rule::in(QdrantManager::DISTANCES)],
            'on_disk' => ['nullable', 'boolean'],
        ]);
        $this->qdrant->create($data['name'], (int) $data['size'], $data['distance'], $request->boolean('on_disk'), $this->server($request));
        $this->audit('database', "Qdrant: created collection {$data['name']}", "{$data['size']} {$data['distance']}");

        return $this->ok('Collection created');
    }

    public function show(Request $request, string $name)
    {
        return $this->ok('ok', ['info' => $this->qdrant->info($this->collection($name), $this->server($request))]);
    }

    public function destroy(Request $request, string $name)
    {
        $this->qdrant->delete($this->collection($name), $this->server($request));
        $this->audit('database', "Qdrant: deleted collection {$name}");

        return $this->ok('Collection deleted');
    }

    public function points(Request $request, string $name)
    {
        $offset = $request->input('offset');
        $offset = is_numeric($offset) ? (int) $offset : ($offset ?: null);

        return $this->ok('ok', $this->qdrant->points($this->collection($name), (int) $request->input('limit', 20), $offset, $this->server($request)));
    }

    public function snapshots(Request $request, string $name)
    {
        return $this->ok('ok', ['snapshots' => $this->qdrant->snapshots($this->collection($name), $this->server($request))]);
    }

    public function createSnapshot(Request $request, string $name)
    {
        $snapshot = $this->qdrant->createSnapshot($this->collection($name), $this->server($request));
        $this->audit('database', "Qdrant: snapshot of {$name}", $snapshot['name'] ?? null);

        return $this->ok('Snapshot created', ['snapshot' => $snapshot]);
    }

    public function deleteSnapshot(Request $request, string $name, string $snapshot)
    {
        $this->qdrant->deleteSnapshot($this->collection($name), $snapshot, $this->server($request));

        return $this->ok('Snapshot deleted');
    }

    public function downloadSnapshot(Request $request, string $name, string $snapshot)
    {
        $body = $this->qdrant->downloadSnapshot($this->collection($name), $snapshot, $this->server($request));
        $this->audit('database', "Qdrant: downloaded snapshot {$snapshot}");

        return response()->streamDownload(function () use ($body) {
            while (! $body->eof()) {
                echo $body->read(1048576);
                flush();
            }
        }, $snapshot, ['Content-Type' => 'application/octet-stream']);
    }

    public function restoreSnapshot(Request $request, string $name)
    {
        $request->validate(['snapshot' => ['required', 'file']]);
        $file = $request->file('snapshot');
        $path = $file->move(storage_path('app/imports'), Str::random(16).'.snapshot')->getPathname();
        try {
            $this->qdrant->restoreSnapshot($this->collection($name), $path, $this->server($request));
        } finally {
            @unlink($path);
        }
        $this->audit('database', "Qdrant: restored {$name} from a snapshot", $file->getClientOriginalName());

        return $this->ok('Collection restored from the snapshot');
    }

    /** Local service: regenerate the API key or change the network binding. */
    public function settings(Request $request)
    {
        $data = $request->validate(['action' => ['required', 'in:regenerate,public']]);
        $result = $data['action'] === 'regenerate'
            ? $this->qdrant->applyLocal(Str::random(48))
            : $this->qdrant->applyLocal(null, $request->boolean('public'));
        if ($result->failed()) {
            return $this->fail($result->message());
        }
        $this->audit('database', $data['action'] === 'regenerate' ? 'Qdrant: regenerated API key' : 'Qdrant: '.($request->boolean('public') ? 'public' : 'local').' access');

        return $this->ok($data['action'] === 'regenerate' ? 'New API key applied. Update the key in your applications.' : ($request->boolean('public') ? 'Qdrant now listens on all interfaces (ports 6333 and 6334)' : 'Qdrant now listens on 127.0.0.1 only'), ['api_key' => $this->qdrant->apiKey()]);
    }
}
