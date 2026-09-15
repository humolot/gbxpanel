<?php

namespace App\Http\Controllers\Client;

use App\Services\Clients\ClientFiles;
use App\Services\Clients\ClientManager;
use App\Services\FileManager;
use Illuminate\Http\Request;

/** File manager limited to the websites of the client. */
class ClientFileController extends ClientPanelController
{
    protected function files(): ClientFiles
    {
        return ClientFiles::for($this->client());
    }

    /** Writes that add data are refused while the disk quota is full. */
    protected function quotaBlock(): ?\Illuminate\Http\JsonResponse
    {
        $client = $this->client();

        return $client->diskLimitBytes() > 0 && $client->disk_used > $client->diskLimitBytes() && ClientManager::settings()['client_over_disk'] !== 'none'
            ? $this->fail('Your disk quota is full. Delete files or upgrade your package.')
            : null;
    }

    protected function attempt(callable $action, string $message, ?string $audit = null)
    {
        try {
            $action();
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
        if ($audit) {
            $this->audit('files', $audit);
        }

        return $this->ok($message);
    }

    public function index(Request $request)
    {
        $roots = $this->files()->roots();
        $ini = function (string|false $value): int {
            $value = trim((string) $value);
            $num = (int) $value;

            return match (strtolower(substr($value, -1))) { 'g' => $num * 1073741824, 'm' => $num * 1048576, 'k' => $num * 1024, default => $num };
        };
        $max = min($ini(ini_get('upload_max_filesize')), $ini(ini_get('post_max_size')));

        return view('client.files', ['roots' => $roots, 'start' => (string) $request->query('path', reset($roots) ?: ''), 'maxUpload' => $max]);
    }

    public function list(Request $request)
    {
        $path = (string) $request->query('path');
        try {
            return $this->ok('ok', ['path' => FileManager::normalize($path), 'root' => $this->files()->rootOf(FileManager::normalize($path)), 'items' => $this->files()->list($path)]);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function read(Request $request)
    {
        try {
            return $this->ok('ok', ['content' => $this->files()->read((string) $request->query('path'))]);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** Code editor of the administrator (Monaco, tree, tabs, search) bound to the client routes. */
    public function editor(Request $request)
    {
        $files = $this->files();
        $roots = $files->roots();
        $root = FileManager::normalize((string) $request->query('root', reset($roots) ?: ''));
        $open = $request->query('open') ? FileManager::normalize((string) $request->query('open')) : null;
        if ($open && ! $request->query('root')) {
            $root = dirname($open);
        }
        try {
            $files->rootOf($root);
        } catch (\InvalidArgumentException) {
            $root = reset($roots) ?: '/';
            $open = null;
        }
        $client = $this->client();

        return view('files.editor', [
            'root' => $root,
            'open' => $open,
            'encodings' => array_keys(FileManager::ENCODINGS),
            'readOnly' => false,
            'embed' => $request->boolean('embed'),
            'account' => ['name' => $client->name, 'role' => 'client'],
            'roots' => $roots,
            'filesUrl' => route('client.files'),
            'storeKey' => 'gbx.client'.$client->id.'.editor',
            'routes' => [
                'list' => route('client.files.list'), 'open' => route('client.files.open'), 'write' => route('client.files.write'),
                'search' => route('client.files.search'), 'create' => route('client.files.create'), 'rename' => route('client.files.rename'),
                'del' => route('client.files.delete'), 'upload' => route('client.files.upload'), 'download' => route('client.files.download'),
            ],
        ]);
    }

    public function open(Request $request)
    {
        $data = $request->validate(['path' => ['required', 'string', 'max:4096'], 'encoding' => ['nullable', 'string']]);
        try {
            return $this->ok('ok', $this->files()->open($data['path'], $data['encoding'] ?? null));
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function search(Request $request)
    {
        $data = $request->validate([
            'dir' => ['required', 'string', 'max:4096'],
            'query' => ['required', 'string', 'min:1', 'max:200'],
            'mode' => ['nullable', 'in:content,name'],
            'include' => ['nullable', 'string', 'max:200'],
        ]);
        try {
            return $this->ok('ok', ['dir' => FileManager::normalize($data['dir'])] + $this->files()->search($data['dir'], $data['query'], [
                'mode' => $data['mode'] ?? 'content',
                'case' => $request->boolean('case'),
                'word' => $request->boolean('word'),
                'regex' => $request->boolean('regex'),
                'include' => $data['include'] ?? '',
                'skip_heavy' => $request->boolean('skip_heavy', true),
            ]));
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function write(Request $request)
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:4096'],
            'content' => ['present', 'nullable', 'string'],
            'encoding' => ['nullable', 'string'],
            'mtime' => ['nullable', 'integer'],
            'force' => ['nullable', 'boolean'],
        ]);
        if ($error = $this->quotaBlock()) {
            return $error;
        }
        if (strlen((string) $data['content']) > ClientFiles::EDIT_MAX) {
            return $this->fail('The file is too large to save online (max 3 MB).');
        }
        try {
            $result = $this->files()->save($data['path'], (string) $data['content'], $data['encoding'] ?? 'utf-8', $data['mtime'] ?? null, $request->boolean('force'));
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
        if (! $result['ok']) {
            return $this->fail($result['message'] ?? 'Unable to save file', ! empty($result['conflict']) ? 409 : 422, $result);
        }
        $this->audit('files', 'Edited '.$data['path']);

        return $this->ok('Saved '.basename($data['path']), $result);
    }

    public function create(Request $request)
    {
        $data = $request->validate(['dir' => ['required', 'string', 'max:4096'], 'name' => ['required', 'string', 'max:255'], 'type' => ['required', 'in:dir,file']]);
        if ($error = $this->quotaBlock()) {
            return $error;
        }

        return $this->attempt(fn () => $this->files()->create($data['dir'], $data['name'], $data['type']), $data['type'] === 'dir' ? 'Folder created' : 'File created');
    }

    public function rename(Request $request)
    {
        $data = $request->validate(['path' => ['required', 'string', 'max:4096'], 'name' => ['required', 'string', 'max:255']]);

        return $this->attempt(fn () => $this->files()->rename($data['path'], $data['name']), 'Renamed', 'Renamed '.$data['path'].' to '.$data['name']);
    }

    public function delete(Request $request)
    {
        $data = $request->validate(['paths' => ['required', 'array', 'max:500'], 'paths.*' => ['string', 'max:4096']]);

        return $this->attempt(fn () => $this->files()->delete($data['paths']), count($data['paths']).' item(s) deleted', 'Deleted '.implode(', ', array_slice($data['paths'], 0, 10)));
    }

    public function paste(Request $request)
    {
        $data = $request->validate(['paths' => ['required', 'array', 'max:500'], 'paths.*' => ['string', 'max:4096'], 'destination' => ['required', 'string', 'max:4096'], 'mode' => ['required', 'in:copy,cut']]);
        if ($data['mode'] === 'copy' && ($error = $this->quotaBlock())) {
            return $error;
        }

        return $this->attempt(fn () => $this->files()->copyOrMove($data['paths'], $data['destination'], $data['mode'] === 'cut'), $data['mode'] === 'cut' ? 'Moved' : 'Copied');
    }

    public function upload(Request $request)
    {
        // the file manager sends files[], the code editor one file per request
        $request->validate(['dir' => ['required', 'string', 'max:4096'], 'files' => ['required_without:file', 'array', 'max:50'], 'files.*' => ['file'], 'file' => ['nullable', 'file']]);
        if ($error = $this->quotaBlock()) {
            return $error;
        }
        $count = 0;
        try {
            foreach ($request->file('files') ?? [$request->file('file')] as $file) {
                $this->files()->upload($file->getRealPath(), (string) $request->input('dir'), $file->getClientOriginalName());
                $count++;
            }
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->fail(($count ? "{$count} file(s) uploaded. " : '').$e->getMessage());
        }
        $this->audit('files', "Uploaded {$count} file(s)", (string) $request->input('dir'));

        return $this->ok("{$count} file(s) uploaded");
    }

    public function download(Request $request)
    {
        try {
            $path = $this->files()->resolve((string) $request->query('path'));
        } catch (\InvalidArgumentException $e) {
            abort(404);
        }
        $files = $this->files();

        return response()->streamDownload(fn () => $files->stream($path), basename($path), ['Content-Type' => 'application/octet-stream', 'Content-Length' => $files->fileSize($path)]);
    }

    public function extract(Request $request)
    {
        $data = $request->validate(['path' => ['required', 'string', 'max:4096'], 'destination' => ['required', 'string', 'max:4096']]);
        if ($error = $this->quotaBlock()) {
            return $error;
        }

        return $this->attempt(fn () => $this->files()->extract($data['path'], $data['destination']), 'Archive extracted', 'Extracted '.$data['path']);
    }

    public function compress(Request $request)
    {
        $data = $request->validate(['dir' => ['required', 'string', 'max:4096'], 'names' => ['required', 'array', 'max:500'], 'names.*' => ['string', 'max:255'], 'archive' => ['required', 'string', 'max:255', 'regex:/\.(zip|tar\.gz)$/i']]);
        if ($error = $this->quotaBlock()) {
            return $error;
        }

        return $this->attempt(fn () => $this->files()->compress($data['dir'], $data['names'], $data['archive']), 'Archive created', 'Compressed '.count($data['names']).' item(s) into '.$data['archive']);
    }
}
