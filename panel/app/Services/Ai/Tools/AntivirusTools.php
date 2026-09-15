<?php

namespace App\Services\Ai\Tools;

use App\Models\MalwareDetection;
use App\Models\MalwareScan;
use App\Services\ClamAvManager;
use App\Services\FileManager;

class AntivirusTools extends ToolGroup
{
    public function __construct(protected ClamAvManager $av, protected FileManager $files) {}

    public function tools(): array
    {
        return [
            'get_antivirus_status' => self::tool('ClamAV status: engine and signature versions, clamd/freshclam state, protection settings, recent scans and open detections.', self::params(), false, false, fn () => 'Checked antivirus status'),
            'list_malware_detections' => self::tool('Malware detections with id, path, signature, source (scan, upload, file) and status.', self::params(['status' => self::str('Filter', ['detected', 'quarantined', 'blocked', 'deleted', 'restored', 'ignored', 'all'])]), false, false, fn () => 'Listed malware detections'),
            'get_malware_scan' => self::tool('Result of a malware scan (status, infected files, summary). Use wait_seconds to wait for a running scan.', self::params(['scan_id' => self::int('Scan id'), 'wait_seconds' => self::int('Wait up to N seconds (max 120)')], ['scan_id']), false, false, fn ($a) => 'Checked malware scan #'.self::labelArg($a, 'scan_id')),
            'scan_for_malware' => self::tool('Scan a file (immediately) or a directory (background scan with clamdscan) for malware, e.g. a website root or an uploads folder. Detections are recorded; they are only quarantined automatically if auto-quarantine is enabled.', self::params(['path' => self::str('Absolute file or directory path, e.g. /www/wwwroot/example.com')], ['path']), false, false, fn ($a) => 'Scan for malware: '.self::labelArg($a, 'path')),

            'handle_malware_detections' => self::tool('Quarantine, delete, restore (from quarantine) or ignore (false positive) malware detections by id.', self::params(['ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Detection ids'], 'action' => self::str('Action', ['quarantine', 'delete', 'restore', 'ignore'])], ['ids', 'action']), true, false, fn ($a) => ucfirst(self::labelArg($a, 'action')).' '.count((array) ($a['ids'] ?? [])).' detection(s)'),
            'update_antivirus_signatures' => self::tool('Download the latest ClamAV virus signatures with freshclam. Background task.', self::params(), true, false, fn () => 'Update antivirus signatures'),
            'configure_antivirus' => self::tool('Change antivirus protection: upload scanning, blocking on scan errors, automatic quarantine and the scheduled scan (off, daily, weekly) with its path.', self::params(['scan_uploads' => self::bool('Scan file manager uploads'), 'block_on_error' => self::bool('Block uploads when scanning fails'), 'auto_quarantine' => self::bool('Quarantine detections automatically'), 'schedule' => self::str('Scheduled scan', ['off', 'daily', 'weekly']), 'schedule_path' => self::str('Path for scheduled scans')]), true, true, fn () => 'Change antivirus settings'),
        ];
    }

    public function handle(string $name, array $a): mixed
    {
        if (! in_array($name, ['get_antivirus_status'], true) && ! $this->av->installed()) {
            return ['error' => 'ClamAV is not installed. Install it with manage_software key=clamav.'];
        }

        switch ($name) {
            case 'get_antivirus_status':
                return [
                    'status' => $this->av->status(),
                    'settings' => $this->av->settings(),
                    'recent_scans' => MalwareScan::query()->latest('id')->limit(10)->get(['id', 'path', 'status', 'infected_count', 'trigger', 'created_at']),
                    'open_detections' => MalwareDetection::query()->open()->latest('id')->limit(50)->get(['id', 'path', 'signature', 'source']),
                ];

            case 'list_malware_detections':
                $status = self::a($a, 'status', 'detected');

                return MalwareDetection::query()->when($status !== 'all', fn ($q) => $q->where('status', $status))->latest('id')->limit(200)
                    ->get(['id', 'path', 'signature', 'source', 'status', 'quarantine_path', 'created_at'])->toArray();

            case 'get_malware_scan':
                $scan = MalwareScan::query()->find((int) $a['scan_id']);
                if (! $scan) {
                    return ['error' => 'Scan not found'];
                }
                $deadline = time() + min(120, max(0, (int) self::a($a, 'wait_seconds', 0)));
                while (! $scan->isFinished() && time() < $deadline) {
                    sleep(3);
                    $scan->refresh();
                }

                return $scan->only(['id', 'path', 'status', 'engine', 'infected_count', 'error_count', 'summary', 'task_id']) + [
                    'finished' => $scan->isFinished(),
                    'detections' => $scan->detections()->get(['id', 'path', 'signature', 'status']),
                ];

            case 'scan_for_malware':
                $path = FileManager::normalize((string) $a['path']);
                if (! $this->files->isDir($path)) {
                    $result = $this->av->scanAsRootOrSimulate($path);
                    if ($result['status'] === 'infected') {
                        $d = MalwareDetection::query()->firstOrCreate(['path' => $path, 'signature' => $result['signature'], 'status' => 'detected'], ['source' => 'file', 'user_id' => auth()->id()]);
                        $result['detection_id'] = $d->id;
                    }

                    return $result;
                }
                $scan = $this->av->scanPath($path, 'ai', auth()->id());

                return ['ok' => true, 'queued' => ! $scan->isFinished(), 'scan_id' => $scan->id, 'task_id' => $scan->task_id, 'message' => "Scan #{$scan->id} started. Call get_malware_scan with scan_id={$scan->id} and wait_seconds to get the result."];

            case 'handle_malware_detections':
                $results = [];
                foreach (MalwareDetection::query()->whereIn('id', array_map('intval', (array) $a['ids']))->get() as $detection) {
                    $r = $this->av->handle($detection, (string) $a['action']);
                    $results[] = ['id' => $detection->id, 'path' => $detection->path, 'ok' => $r->ok(), 'status' => $detection->fresh()->status] + ($r->ok() ? [] : ['error' => $r->message()]);
                }

                return ['ok' => collect($results)->every('ok'), 'results' => $results];

            case 'update_antivirus_signatures':
                return $this->queued($this->av->updateSignatures(), 'The signature update');

            case 'configure_antivirus':
                $this->av->saveSettings($a);

                return ['ok' => true, 'settings' => $this->av->settings()];
        }

        return ['error' => "Unknown tool {$name}"];
    }
}
