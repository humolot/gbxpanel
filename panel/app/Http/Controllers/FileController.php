<?php

namespace App\Http\Controllers;

use App\Services\ClamAvManager;
use App\Services\FileManager;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FileController extends Controller
{
    public function __construct(protected FileManager $files) {}

    public function index(Request $request)
    {
        return view('files.index', [
            'path' => FileManager::normalize($request->query('path', config('gbx.paths.www'))),
            'maxUpload' => min($this->iniBytes(ini_get('upload_max_filesize')), $this->iniBytes(ini_get('post_max_size'))),
        ]);
    }

    protected function iniBytes(string|false $value): int
    {
        $value = trim((string) $value);
        $unit = strtolower(substr($value, -1));
        $num = (int) $value;

        return match ($unit) { 'g' => $num * 1073741824, 'm' => $num * 1048576, 'k' => $num * 1024, default => $num };
    }

    public function list(Request $request)
    {
        $path = FileManager::normalize($request->query('path', '/'));

        return response()->json(['ok' => true, 'path' => $path, 'items' => $this->files->list($path)]);
    }

    /** Code editor (file tree, tabs, search). Loaded inside the editor modal with ?embed=1. */
    public function editor(Request $request)
    {
        $root = FileManager::normalize($request->query('root', config('gbx.paths.www')));
        $open = $request->query('open') ? FileManager::normalize($request->query('open')) : null;
        if ($open && ! $request->query('root')) {
            $root = dirname($open);
        }

        return view('files.editor', [
            'root' => $root,
            'open' => $open,
            'encodings' => array_keys(FileManager::ENCODINGS),
            'readOnly' => ! $request->user()->canWrite(),
            'embed' => $request->boolean('embed'),
        ]);
    }

    public function open(Request $request)
    {
        $data = $request->validate(['path' => ['required', 'string'], 'encoding' => ['nullable', 'string']]);

        return $this->ok('ok', $this->files->open($data['path'], $data['encoding'] ?? null));
    }

    public function write(Request $request)
    {
        $data = $request->validate([
            'path' => ['required', 'string'],
            'content' => ['present', 'nullable', 'string'],
            'encoding' => ['nullable', 'string'],
            'mtime' => ['nullable', 'integer'],
            'force' => ['nullable', 'boolean'],
        ]);
        $path = FileManager::normalize($data['path']);
        $result = $this->files->save($path, (string) $data['content'], $data['encoding'] ?? 'utf-8', $data['mtime'] ?? null, $request->boolean('force'));

        if (! $result['ok']) {
            return $this->fail($result['message'] ?? 'Unable to save file', ! empty($result['conflict']) ? 409 : 422, $result);
        }
        $this->audit('files', 'Edited file', $path);

        return $this->ok('Saved '.basename($path), $result);
    }

    public function search(Request $request)
    {
        $data = $request->validate([
            'dir' => ['required', 'string'],
            'query' => ['required', 'string', 'min:1', 'max:200'],
            'mode' => ['nullable', 'in:content,name'],
            'case' => ['nullable', 'boolean'],
            'word' => ['nullable', 'boolean'],
            'regex' => ['nullable', 'boolean'],
            'include' => ['nullable', 'string', 'max:200'],
            'skip_heavy' => ['nullable', 'boolean'],
        ]);
        $dir = FileManager::normalize($data['dir']);
        if ($dir === '/' && ($data['mode'] ?? 'content') === 'content') {
            return $this->fail('Choose a folder below / to search file contents.');
        }

        return $this->ok('ok', ['dir' => $dir] + $this->files->search($dir, $data['query'], [
            'mode' => $data['mode'] ?? 'content',
            'case' => $request->boolean('case'),
            'word' => $request->boolean('word'),
            'regex' => $request->boolean('regex'),
            'include' => $data['include'] ?? '',
            'skip_heavy' => $request->boolean('skip_heavy', true),
        ]));
    }

    public function create(Request $request)
    {
        $data = $request->validate(['dir' => ['required', 'string'], 'name' => ['required', 'string', 'max:255'], 'type' => ['required', 'in:file,dir']]);

        return $this->result($this->files->create($data['dir'], $data['name'], $data['type']), ($data['type'] === 'dir' ? 'Folder' : 'File').' created', 'files', $data['dir'].'/'.$data['name']);
    }

    public function rename(Request $request)
    {
        $data = $request->validate(['path' => ['required', 'string'], 'name' => ['required', 'string', 'max:255']]);

        return $this->result($this->files->rename($data['path'], $data['name']), 'Renamed', 'files', $data['path'].' -> '.$data['name']);
    }

    public function delete(Request $request)
    {
        $data = $request->validate(['paths' => ['required', 'array', 'min:1'], 'paths.*' => ['string']]);

        return $this->result($this->files->delete($data['paths']), count($data['paths']).' item(s) deleted', 'files', implode(', ', $data['paths']));
    }

    public function paste(Request $request)
    {
        $data = $request->validate(['paths' => ['required', 'array', 'min:1'], 'paths.*' => ['string'], 'destination' => ['required', 'string'], 'mode' => ['required', 'in:copy,cut']]);

        return $this->result($this->files->copyOrMove($data['paths'], $data['destination'], $data['mode'] === 'cut'), $data['mode'] === 'cut' ? 'Moved' : 'Copied', 'files', implode(', ', $data['paths']).' -> '.$data['destination']);
    }

    public function permissions(Request $request)
    {
        $data = $request->validate(['paths' => ['required', 'array', 'min:1'], 'paths.*' => ['string'], 'mode' => ['required', 'string'], 'owner' => ['nullable', 'string', 'max:64'], 'recursive' => ['nullable', 'boolean']]);

        return $this->result($this->files->chmod($data['paths'], $data['mode'], (string) ($data['owner'] ?? ''), $request->boolean('recursive')), 'Permissions updated', 'files', $data['mode'].' '.implode(', ', $data['paths']));
    }

    public function compress(Request $request)
    {
        $data = $request->validate(['dir' => ['required', 'string'], 'names' => ['required', 'array', 'min:1'], 'names.*' => ['string'], 'archive' => ['required', 'string', 'max:255'], 'format' => ['required', 'in:zip,tar.gz,tar']]);

        return $this->result($this->files->compress($data['dir'], $data['names'], $data['archive'], $data['format']), 'Archive created', 'files', $data['archive']);
    }

    public function extract(Request $request)
    {
        $data = $request->validate(['path' => ['required', 'string'], 'destination' => ['required', 'string']]);

        return $this->result($this->files->extract($data['path'], $data['destination']), 'Archive extracted', 'files', $data['path']);
    }

    public function upload(Request $request)
    {
        $request->validate(['dir' => ['required', 'string'], 'file' => ['required', 'file']]);
        $file = $request->file('file');
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));

        $tmpDir = storage_path('app/uploads');
        @mkdir($tmpDir, 0775, true);
        $tmp = $file->move($tmpDir, Str::random(24))->getPathname();

        // Antivirus: stream the upload to clamd before it reaches the website folder
        $av = app(ClamAvManager::class);
        $settings = $av->settings();
        if ($settings['scan_uploads'] && $av->installed()) {
            $scan = $av->scanFile($tmp);
            $target = FileManager::normalize($request->input('dir').'/'.$name);
            if ($scan['status'] === 'infected') {
                @unlink($tmp);
                $av->recordBlockedUpload($target, (string) $scan['signature']);

                return $this->fail("Upload blocked: {$name} is infected ({$scan['signature']}).", 422, ['malware' => $scan]);
            }
            if ($scan['status'] === 'error' && $settings['block_on_error']) {
                @unlink($tmp);

                return $this->fail("Upload blocked: {$name} could not be scanned ({$scan['message']}).");
            }
        }

        $result = $this->files->placeUpload($tmp, $request->input('dir'), $name);
        @unlink($tmp);

        return $this->result($result, "Uploaded {$name}", 'files', $request->input('dir').'/'.$name);
    }

    public function download(Request $request)
    {
        $path = FileManager::normalize($request->query('path'));
        $this->audit('files', 'Downloaded file', $path);

        return response()->streamDownload(fn () => $this->files->stream($path), basename($path), [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => $this->files->fileSize($path),
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function size(Request $request)
    {
        return $this->ok('ok', ['size' => $this->files->dirSize((string) $request->query('path'))]);
    }
}
