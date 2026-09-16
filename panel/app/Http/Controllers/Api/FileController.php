<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\FileController as PanelFiles;
use App\Services\FileManager;
use Illuminate\Http\Request;

class FileController extends ApiController
{
    public function index(Request $request)
    {
        return $this->forward(PanelFiles::class, 'list', ['path' => $request->query('path', '/')]);
    }

    public function read(Request $request)
    {
        return $this->forward(PanelFiles::class, 'open', ['path' => $request->query('path'), 'encoding' => $request->query('encoding')]);
    }

    public function download(Request $request, FileManager $files)
    {
        $path = FileManager::normalize((string) $request->query('path'));
        abort_if($path === '/' || ! $files->stat($path), 404);

        return response()->streamDownload(fn () => $files->stream($path), basename($path), ['Content-Type' => 'application/octet-stream']);
    }

    public function write(Request $request)
    {
        return $this->forward(PanelFiles::class, 'write', [
            'path' => $request->input('path'),
            'content' => (string) $request->input('content'),
            'encoding' => $request->input('encoding'),
            'force' => $this->flag($request, 'force', true),
        ]);
    }

    public function create(Request $request)
    {
        return $this->forward(PanelFiles::class, 'create', [
            'dir' => $request->input('dir'),
            'name' => $request->input('name'),
            'type' => $request->input('type', 'file'),
        ]);
    }

    public function rename(Request $request)
    {
        return $this->forward(PanelFiles::class, 'rename', ['path' => $request->input('path'), 'name' => $request->input('name')]);
    }

    public function destroy(Request $request)
    {
        return $this->forward(PanelFiles::class, 'delete', ['paths' => (array) $request->input('paths', array_filter([$request->input('path')]))]);
    }

    public function compress(Request $request)
    {
        return $this->forward(PanelFiles::class, 'compress', [
            'dir' => $request->input('dir'),
            'names' => (array) $request->input('names', []),
            'archive' => $request->input('archive'),
            'format' => $request->input('format', str_ends_with((string) $request->input('archive'), '.zip') ? 'zip' : 'tar.gz'),
        ]);
    }

    public function extract(Request $request)
    {
        return $this->forward(PanelFiles::class, 'extract', [
            'path' => $request->input('path'),
            'destination' => $request->input('destination'),
        ]);
    }
}
