<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\MalwareDetection;
use App\Models\MalwareScan;
use App\Models\Setting;
use App\Models\Task;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

/**
 * ClamAV integration.
 *
 *   Laravel --(unix socket, INSTREAM)--> clamd --> ClamAV engine
 *
 * - Single files the panel can read (uploads) are streamed to clamd over the
 *   socket, so no process is spawned and signatures stay loaded in memory.
 * - Directories are scanned by "clamdscan --multiscan --fdpass" as root in a
 *   background task (clamd opens the files through passed descriptors).
 * - When clamd is not running, "clamscan" is used as a fallback.
 *
 * Result codes follow clamscan: 0 = clean, 1 = infected, 2 = error.
 */
class ClamAvManager
{
    public const CLEAN = 0;

    public const INFECTED = 1;

    public const ERROR = 2;

    public const EICAR = 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE';

    protected const EXIT_MARKER = '__GBX_SCAN_EXIT__=';

    public function socket(): string
    {
        return (string) config('gbx.clamav.socket');
    }

    public function quarantineDir(): string
    {
        return rtrim((string) config('gbx.clamav.quarantine'), '/');
    }

    /* ------------------------------------------------------------ status */

    public function installed(): bool
    {
        return Shell::simulating() || is_file('/usr/sbin/clamd') || is_file('/usr/bin/clamscan');
    }

    /** Send a z-terminated command to clamd and return the response. */
    protected function command(string $command, int $timeout = 5): ?string
    {
        $fp = @stream_socket_client('unix://'.$this->socket(), $errno, $errstr, $timeout);
        if (! $fp) {
            return null;
        }
        stream_set_timeout($fp, $timeout);
        fwrite($fp, 'z'.$command."\0");
        $response = stream_get_contents($fp);
        fclose($fp);

        return $response === false ? null : trim($response, "\0\r\n ");
    }

    public function daemonAvailable(): bool
    {
        if (Shell::simulating()) {
            return true;
        }

        return Cache::remember('gbx.clamd.ping', 30, fn () => $this->command('PING', 3) === 'PONG');
    }

    public function version(): ?string
    {
        if (Shell::simulating()) {
            return 'ClamAV 1.4.3/27765/Wed Sep 17 03:14:02 2026';
        }

        return $this->daemonAvailable() ? $this->command('VERSION', 5) : (Shell::out('clamscan --version', 20, false) ?: null);
    }

    public function status(): array
    {
        $version = $this->version();
        $parts = $version ? explode('/', $version) : [];
        $services = app(ServiceManager::class);

        return [
            'installed' => $this->installed(),
            'daemon' => $this->installed() && $this->daemonAvailable(),
            'daemon_service' => $this->installed() ? $services->status('clamav-daemon') : null,
            'freshclam_service' => $this->installed() ? $services->status('clamav-freshclam') : null,
            'engine' => $parts[0] ?? null,
            'signatures_version' => $parts[1] ?? null,
            'signatures_date' => $parts[2] ?? null,
            'socket' => $this->socket(),
            'quarantine' => $this->quarantineDir(),
            'open_detections' => MalwareDetection::query()->open()->count(),
        ];
    }

    public function settings(): array
    {
        return [
            'scan_uploads' => (bool) Setting::get('av_scan_uploads', 1),
            'block_on_error' => (bool) Setting::get('av_block_on_error', 0),
            'auto_quarantine' => (bool) Setting::get('av_auto_quarantine', 0),
            'schedule' => (string) Setting::get('av_schedule', 'off'),
            'schedule_path' => (string) Setting::get('av_schedule_path', config('gbx.paths.www')),
        ];
    }

    public function saveSettings(array $data): void
    {
        foreach (['scan_uploads', 'block_on_error', 'auto_quarantine'] as $key) {
            if (array_key_exists($key, $data)) {
                Setting::put('av_'.$key, (bool) $data[$key]);
            }
        }
        if (isset($data['schedule']) && in_array($data['schedule'], ['off', 'daily', 'weekly'], true)) {
            Setting::put('av_schedule', $data['schedule']);
        }
        if (! empty($data['schedule_path'])) {
            Setting::put('av_schedule_path', FileManager::normalize($data['schedule_path']));
        }
    }

    /* --------------------------------------------------------- single file */

    /**
     * Scan a file readable by the panel user (e.g. an upload in storage).
     *
     * @return array{code:int, status:string, signature:?string, engine:string, message:string}
     */
    public function scanFile(string $path): array
    {
        if (Shell::simulating()) {
            $head = '';
            if (is_file($path) && ($fh = @fopen($path, 'rb'))) {
                $head = (string) fread($fh, 1048576);
                fclose($fh);
            }
            $infected = str_contains($head, self::EICAR);

            return $this->result($infected ? self::INFECTED : self::CLEAN, $infected ? 'Eicar-Test-Signature' : null, 'simulation');
        }

        if (! is_readable($path)) {
            return $this->scanAsRoot($path);
        }

        if ($this->daemonAvailable()) {
            if (filesize($path) <= (int) config('gbx.clamav.stream_max_bytes')) {
                return $this->instream($path);
            }

            return $this->scanAsRoot($path);
        }

        return $this->clamscan($path);
    }

    /** Stream the file to clamd (INSTREAM): 4-byte big-endian length + chunk, terminated by a zero length. */
    public function instream(string $path): array
    {
        $fp = @stream_socket_client('unix://'.$this->socket(), $errno, $errstr, 5);
        $fh = @fopen($path, 'rb');
        if (! $fp || ! $fh) {
            return $this->result(self::ERROR, null, 'clamd', $fp ? 'Cannot open file' : "Cannot connect to clamd: {$errstr}");
        }

        stream_set_timeout($fp, (int) config('gbx.clamav.timeout', 300));
        fwrite($fp, "zINSTREAM\0");
        while (! feof($fh)) {
            $chunk = fread($fh, 65536);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            if (fwrite($fp, pack('N', strlen($chunk)).$chunk) === false) {
                break;
            }
        }
        fwrite($fp, pack('N', 0));
        fclose($fh);

        $response = trim((string) stream_get_contents($fp), "\0\r\n ");
        fclose($fp);

        return $this->parseClamdResponse($response, 'clamd');
    }

    /** Parse "stream: OK", "stream: Eicar-Signature FOUND" or "... ERROR". */
    public function parseClamdResponse(string $response, string $engine): array
    {
        if (preg_match('/:\s*(.+)\s+FOUND$/', $response, $m)) {
            return $this->result(self::INFECTED, trim($m[1]), $engine);
        }
        if (preg_match('/:\s*OK$/', $response)) {
            return $this->result(self::CLEAN, null, $engine);
        }

        return $this->result(self::ERROR, null, $engine, $response ?: 'Empty response from clamd');
    }

    /** Fallback without the daemon: clamscan exit codes 0 clean, 1 infected, 2 error. */
    public function clamscan(string $path): array
    {
        if (! is_file('/usr/bin/clamscan')) {
            return $this->result(self::ERROR, null, 'none', 'ClamAV is not installed.');
        }

        $process = new Process(['/usr/bin/clamscan', '--no-summary', '--infected', '--stdout', $path], null, null, null, (int) config('gbx.clamav.timeout', 300));
        $process->run();

        return match ($process->getExitCode()) {
            0 => $this->result(self::CLEAN, null, 'clamscan'),
            1 => $this->result(self::INFECTED, self::parseOutput($process->getOutput())[0]['signature'] ?? 'Unknown', 'clamscan'),
            default => $this->result(self::ERROR, null, 'clamscan', trim($process->getErrorOutput().$process->getOutput()) ?: 'Scan error'),
        };
    }

    /** Single file anywhere on the server (file manager "Scan" action). */
    public function scanAsRootOrSimulate(string $path): array
    {
        if (Shell::simulating()) {
            $infected = str_contains(strtolower($path), 'shell') || str_contains(strtolower($path), 'eicar');

            return $this->result($infected ? self::INFECTED : self::CLEAN, $infected ? 'Php.Webshell.Generic-1' : null, 'simulation');
        }

        return $this->scanAsRoot($path);
    }

    /** Scan a path the panel user cannot read, through clamdscan --fdpass as root. */
    public function scanAsRoot(string $path): array
    {
        $bin = $this->daemonAvailable() ? 'clamdscan --fdpass' : 'clamscan';
        $r = Shell::run($bin.' --no-summary --infected --stdout '.Shell::arg($path), (int) config('gbx.clamav.timeout', 300));
        $found = self::parseOutput($r->output);

        return match ($r->exitCode) {
            0 => $this->result(self::CLEAN, null, strtok($bin, ' ')),
            1 => $this->result(self::INFECTED, $found[0]['signature'] ?? 'Unknown', strtok($bin, ' ')),
            default => $this->result(self::ERROR, null, strtok($bin, ' '), $r->message() ?: 'Scan error'),
        };
    }

    protected function result(int $code, ?string $signature, string $engine, string $message = ''): array
    {
        return [
            'code' => $code,
            'status' => [self::CLEAN => 'clean', self::INFECTED => 'infected'][$code] ?? 'error',
            'signature' => $signature,
            'engine' => $engine,
            'message' => $message ?: ($code === self::INFECTED ? "Malware found: {$signature}" : ($code === self::CLEAN ? 'No threats found' : 'Scan error')),
        ];
    }

    /* ---------------------------------------------------------- directories */

    /** Queue a recursive scan of a path as a background task. */
    public function scanPath(string $path, string $trigger = 'manual', ?int $userId = null): MalwareScan
    {
        $path = FileManager::normalize($path);
        if (preg_match('#^/(proc|sys|dev|run)(/|$)#', $path)) {
            throw new \InvalidArgumentException('Pseudo filesystems cannot be scanned.');
        }
        if (! $this->installed()) {
            throw new \RuntimeException('ClamAV is not installed. Install it from Home > Software.');
        }

        $scan = MalwareScan::query()->create([
            'path' => $path,
            'trigger' => $trigger,
            'status' => 'queued',
            'engine' => $this->daemonAvailable() ? 'clamd' : 'clamscan',
            'user_id' => $userId,
        ]);

        $p = Shell::arg($path);
        $socket = Shell::arg($this->socket());
        $script = "echo 'Scanning {$path}'\n"
            ."if [ -S {$socket} ]; then\n  echo 'Engine: clamd (multiscan)'\n  clamdscan --multiscan --fdpass --infected --stdout {$p}\n"
            ."else\n  echo 'Engine: clamscan (clamd is not running, this is slower)'\n  clamscan -r --infected --stdout --cross-fs=no --exclude-dir='^/(proc|sys|dev|run)' {$p}\nfi\n"
            ."code=\$?\necho '".self::EXIT_MARKER."'\$code\n[ \$code -le 1 ]";

        $task = TaskRunner::dispatch("Malware scan {$path}", $script, 'antivirus', [
            'on_finish' => 'malware_scan',
            'malware_scan_id' => $scan->id,
            'simulate_output' => "Scanning {$path}\nEngine: clamd (multiscan)\n{$path}/uploads/shell.php: Php.Webshell.Generic-1 FOUND\n\n----------- SCAN SUMMARY -----------\nInfected files: 1\nTime: 3.412 sec (0 m 3 s)\n".self::EXIT_MARKER."1\n",
        ]);

        // the task may already be finalized (simulation), so never overwrite a final status
        $scan->refresh();
        $scan->update(['task_id' => $task->id] + ($scan->isFinished() ? [] : ['status' => 'running']));
        ActivityLog::record('antivirus', "Malware scan started: {$path}", 'trigger '.$trigger);

        return $scan->fresh();
    }

    /** Lines like "/path/file.php: Php.Webshell-1 FOUND". */
    public static function parseOutput(string $output): array
    {
        $found = [];
        if (preg_match_all('/^(\/.+?|stream): (.+) FOUND$/m', $output, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $found[] = ['path' => $m[1], 'signature' => trim($m[2])];
            }
        }

        return $found;
    }

    /** Called when the scan task finishes (see TaskHooks). */
    public function finalize(MalwareScan $scan, Task $task): void
    {
        $output = $task->output();
        preg_match('/'.self::EXIT_MARKER.'(\d+)/', $output, $m);
        $code = isset($m[1]) ? (int) $m[1] : ($task->status === 'success' ? 0 : 2);
        $found = self::parseOutput($output);
        $errors = preg_match_all('/: .* ERROR$/m', $output);
        preg_match('/----------- SCAN SUMMARY -----------\s*(.+)$/s', $output, $summary);

        foreach ($found as $item) {
            $detection = MalwareDetection::query()->firstOrCreate(
                ['path' => $item['path'], 'signature' => $item['signature'], 'status' => 'detected'],
                ['malware_scan_id' => $scan->id, 'source' => 'scan', 'user_id' => $scan->user_id],
            );
            if ($this->settings()['auto_quarantine']) {
                $this->quarantine($detection);
            }
        }

        $scan->update([
            'status' => $found ? 'infected' : ($code >= 2 && ! $found ? 'error' : 'clean'),
            'infected_count' => count($found),
            'error_count' => (int) $errors,
            'summary' => isset($summary[1]) ? trim(str_replace(self::EXIT_MARKER.$code, '', $summary[1])) : null,
            'finished_at' => now(),
        ]);

        if ($found) {
            ActivityLog::record('antivirus', 'Malware found in '.$scan->path, count($found).' infected file(s)');
        }
    }

    /* --------------------------------------------------------- detections */

    public function quarantine(MalwareDetection $detection): ShellResult
    {
        if ($detection->status !== 'detected') {
            return new ShellResult(1, '', 'Only open detections can be quarantined.');
        }
        $path = FileManager::normalize($detection->path);
        if (in_array($path, FileManager::PROTECTED, true)) {
            return new ShellResult(1, '', 'Protected path.');
        }

        $dest = $this->quarantineDir().'/'.$detection->id.'_'.preg_replace('/[^\w.-]/', '_', basename($path));
        $meta = Shell::simulating() ? 'www-data:www-data 644' : Shell::out('stat -c "%U:%G %a" '.Shell::arg($path), 10);
        $r = Shell::run('mkdir -p '.Shell::arg($this->quarantineDir()).' && chmod 700 '.Shell::arg($this->quarantineDir())
            .' && mv -f -- '.Shell::arg($path).' '.Shell::arg($dest).' && chmod 000 '.Shell::arg($dest), 60);

        if ($r->ok()) {
            [$owner, $mode] = array_pad(explode(' ', trim($meta)), 2, null);
            $detection->update(['status' => 'quarantined', 'quarantine_path' => $dest, 'meta' => ['owner' => $owner, 'mode' => $mode], 'user_id' => auth()->id() ?? $detection->user_id]);
            ActivityLog::record('antivirus', 'Quarantined '.$path, $detection->signature);
        }

        return $r;
    }

    public function restore(MalwareDetection $detection): ShellResult
    {
        if ($detection->status !== 'quarantined' || ! $detection->quarantine_path) {
            return new ShellResult(1, '', 'The file is not in quarantine.');
        }
        $owner = preg_match('/^[\w-]+:[\w-]+$/', (string) ($detection->meta['owner'] ?? '')) ? $detection->meta['owner'] : 'root:root';
        $mode = preg_match('/^[0-7]{3,4}$/', (string) ($detection->meta['mode'] ?? '')) ? $detection->meta['mode'] : '644';
        $path = FileManager::normalize($detection->path);

        $r = Shell::run('mkdir -p '.Shell::arg(dirname($path)).' && mv -n -- '.Shell::arg($detection->quarantine_path).' '.Shell::arg($path)
            .' && chown '.Shell::arg($owner).' '.Shell::arg($path).' && chmod '.$mode.' '.Shell::arg($path), 60);
        if ($r->ok()) {
            $detection->update(['status' => 'restored', 'quarantine_path' => null]);
            ActivityLog::record('antivirus', 'Restored '.$path, $detection->signature);
        }

        return $r;
    }

    public function delete(MalwareDetection $detection): ShellResult
    {
        $target = $detection->status === 'quarantined' ? $detection->quarantine_path : FileManager::normalize($detection->path);
        if (! in_array($detection->status, ['detected', 'quarantined'], true) || ! $target || in_array($target, FileManager::PROTECTED, true)) {
            return new ShellResult(1, '', 'This detection cannot be deleted.');
        }

        $r = Shell::run('rm -f -- '.Shell::arg($target), 30);
        if ($r->ok()) {
            $detection->update(['status' => 'deleted']);
            ActivityLog::record('antivirus', 'Deleted infected file '.$detection->path, $detection->signature);
        }

        return $r;
    }

    public function ignore(MalwareDetection $detection): ShellResult
    {
        if ($detection->status !== 'detected') {
            return new ShellResult(1, '', 'Only open detections can be ignored.');
        }
        $detection->update(['status' => 'ignored']);
        ActivityLog::record('antivirus', 'Ignored detection '.$detection->path, $detection->signature);

        return new ShellResult(0, 'ignored', '');
    }

    public function handle(MalwareDetection $detection, string $action): ShellResult
    {
        return match ($action) {
            'quarantine' => $this->quarantine($detection),
            'restore' => $this->restore($detection),
            'delete' => $this->delete($detection),
            'ignore' => $this->ignore($detection),
            default => new ShellResult(1, '', 'Unknown action'),
        };
    }

    public function recordBlockedUpload(string $path, string $signature): MalwareDetection
    {
        ActivityLog::record('antivirus', 'Blocked infected upload '.$path, $signature);

        return MalwareDetection::query()->create(['path' => $path, 'signature' => $signature, 'source' => 'upload', 'status' => 'blocked', 'user_id' => auth()->id()]);
    }

    public function updateSignatures(): Task
    {
        Cache::forget('gbx.clamd.ping');

        return TaskRunner::dispatch('Update ClamAV signatures', "systemctl stop clamav-freshclam || true\nfreshclam --stdout\ncode=\$?\nsystemctl start clamav-freshclam || true\nclamdscan --reload >/dev/null 2>&1 || true\n# freshclam: 0 = updated, 1 = already up to date\n[ \$code -le 1 ]", 'antivirus');
    }
}
