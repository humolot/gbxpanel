<?php

namespace App\Http\Controllers;

use App\Models\MalwareDetection;
use App\Models\MalwareScan;
use App\Models\Website;
use App\Services\ClamAvManager;
use App\Services\FileManager;
use Illuminate\Http\Request;

class AntivirusController extends Controller
{
    public function __construct(protected ClamAvManager $av) {}

    public function index()
    {
        return view('security.antivirus', [
            'status' => $this->av->status(),
            'settings' => $this->av->settings(),
            'scans' => MalwareScan::query()->with('user:id,username')->latest('id')->limit(25)->get(),
            'detections' => MalwareDetection::query()->latest('id')->limit(200)->get(),
            'websites' => Website::query()->orderBy('domain')->get(['id', 'domain', 'root_path']),
        ]);
    }

    public function scans()
    {
        return response()->json([
            'running' => MalwareScan::query()->whereIn('status', ['queued', 'running'])->count(),
            'open_detections' => MalwareDetection::query()->open()->count(),
        ]);
    }

    /** Scan a directory (background task) or a single file (immediately). */
    public function scan(Request $request, FileManager $files)
    {
        $data = $request->validate(['path' => ['required', 'string', 'max:1024']]);
        $path = FileManager::normalize($data['path']);

        if (! $files->isDir($path)) {
            $result = $this->av->scanAsRootOrSimulate($path);
            if ($result['status'] === 'infected') {
                MalwareDetection::query()->firstOrCreate(['path' => $path, 'signature' => $result['signature'], 'status' => 'detected'], ['source' => 'file', 'user_id' => auth()->id()]);
            }

            return $this->ok($result['message'], ['result' => $result]);
        }

        $scan = $this->av->scanPath($path, 'manual', auth()->id());

        return $this->ok('Scan started', ['scan_id' => $scan->id, 'task' => ['id' => $scan->task_id, 'title' => "Malware scan {$path}", 'status' => $scan->task?->status ?? 'queued']]);
    }

    public function scanWebsite(Website $website)
    {
        $scan = $this->av->scanPath($website->root_path, 'website', auth()->id());

        return $this->ok('Scan started', ['scan_id' => $scan->id, 'task' => ['id' => $scan->task_id, 'title' => "Malware scan {$website->domain}", 'status' => $scan->task?->status ?? 'queued']]);
    }

    public function showScan(MalwareScan $scan)
    {
        return $this->ok('ok', [
            'scan' => $scan->only(['id', 'path', 'trigger', 'status', 'engine', 'infected_count', 'error_count', 'summary', 'task_id']) + ['finished_at' => $scan->finished_at?->toDateTimeString()],
            'detections' => $scan->detections()->get(['id', 'path', 'signature', 'status']),
        ]);
    }

    public function action(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'action' => ['required', 'in:quarantine,restore,delete,ignore'],
        ]);

        $done = 0;
        $errors = [];
        foreach (MalwareDetection::query()->whereIn('id', $data['ids'])->get() as $detection) {
            $r = $this->av->handle($detection, $data['action']);
            $r->ok() ? $done++ : $errors[] = basename($detection->path).': '.$r->message();
        }

        if ($errors && ! $done) {
            return $this->fail(implode("\n", array_slice($errors, 0, 5)));
        }

        return $this->ok(ucfirst($data['action'])." applied to {$done} file(s)".($errors ? ' ('.count($errors).' failed)' : ''));
    }

    public function settings(Request $request)
    {
        $data = $request->validate([
            'scan_uploads' => ['nullable', 'boolean'],
            'block_on_error' => ['nullable', 'boolean'],
            'auto_quarantine' => ['nullable', 'boolean'],
            'schedule' => ['required', 'in:off,daily,weekly'],
            'schedule_path' => ['required', 'string', 'max:255'],
        ]);
        foreach (['scan_uploads', 'block_on_error', 'auto_quarantine'] as $key) {
            $data[$key] = $request->boolean($key);
        }
        $this->av->saveSettings($data);
        $this->audit('antivirus', 'Updated antivirus settings', json_encode($data));

        return $this->ok('Antivirus settings saved');
    }

    public function updateSignatures()
    {
        return $this->task($this->av->updateSignatures(), 'Updating signatures');
    }
}
