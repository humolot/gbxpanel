<?php

namespace App\Services\Backup;

use App\Models\ActivityLog;
use App\Models\BackupStorage;
use App\Models\BackupTransfer;
use App\Models\MysqlDatabase;
use App\Models\Setting;
use App\Models\Website;
use App\Services\BackupManager;
use App\Services\Databases\Engines;
use App\Services\Shell;
use App\Services\TaskRunner;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/**
 * Sends backups to a storage and brings them back.
 *
 * Transfers run outside the queue worker (a 10 GB upload takes longer than any job timeout): the
 * panel starts a detached process per transfer, which streams the rclone output to its own log.
 * Uploads are resumable in practice: destinations without resumable uploads (FTP, SFTP, WebDAV)
 * receive the archive in parts, and a repeated transfer only sends the parts that are missing.
 */
class TransferManager
{
    public const CATEGORIES = ['site', 'database', 'path', 'panel', 'other'];

    public const DEFAULT_SPLIT_MB = 1024;

    public function __construct(protected StorageManager $storages) {}

    /* ================================================================ queue */

    public static function label(?string $label): string
    {
        $label = trim((string) $label, '/');
        $parts = array_filter(explode('/', $label), fn ($p) => $p !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $p) && $p !== '..');

        return implode('/', array_slice($parts, 0, 2));
    }

    /** Folder of a backup series inside the storage: site/example.com, database/mysql/shop... */
    public static function remoteDir(string $category, ?string $label): string
    {
        $category = in_array($category, self::CATEGORIES, true) ? $category : 'other';
        $label = self::label($label);

        return $label === '' ? $category : $category.'/'.$label;
    }

    /** Local backups only: a transfer must never read or overwrite files outside the backup folder. */
    protected static function assertLocal(string $path): string
    {
        return app(BackupManager::class)->resolve($path);
    }

    public function queueUpload(BackupStorage $storage, string $file, string $category, ?string $label, array $options = []): BackupTransfer
    {
        $file = self::assertLocal($file);
        $dir = self::remoteDir($category, $label);

        return BackupTransfer::query()->create([
            'storage_id' => $storage->id,
            'direction' => 'upload',
            'category' => in_array($category, self::CATEGORIES, true) ? $category : 'other',
            'label' => self::label($label),
            'local_path' => $file,
            'remote_path' => $dir.'/'.basename($file),
            'status' => 'queued',
            'delete_local' => (bool) ($options['delete_local'] ?? false),
            'keep' => ($options['keep'] ?? null) ? max(1, (int) $options['keep']) : null,
            'cron_job_id' => $options['cron_job_id'] ?? null,
            'task_id' => $options['task_id'] ?? null,
        ]);
    }

    /** Local file a remote backup is downloaded to (database dumps go back to their engine folder). */
    public function localTarget(string $remotePath): string
    {
        $remotePath = StorageManager::cleanPath($remotePath);
        $parts = explode('/', $remotePath);
        $name = preg_replace('/\.parts$/', '', (string) array_pop($parts));
        $category = $parts[0] ?? 'other';
        $root = rtrim((string) config('gbx.paths.backup'), '/');

        if ($category === 'database' && isset($parts[1]) && Engines::isDatabaseEngine($parts[1])) {
            return Engines::get($parts[1])->backupDir().'/'.$name;
        }

        return $root.'/'.(in_array($category, ['site', 'path'], true) ? $category : 'downloads').'/'.$name;
    }

    public function queueDownload(BackupStorage $storage, string $remotePath, array $after = []): BackupTransfer
    {
        $remotePath = StorageManager::cleanPath($remotePath);
        $local = self::assertLocal($this->localTarget($remotePath));
        $parts = explode('/', $remotePath);

        return BackupTransfer::query()->create([
            'storage_id' => $storage->id,
            'direction' => 'download',
            'category' => in_array($parts[0] ?? '', self::CATEGORIES, true) ? $parts[0] : 'other',
            'label' => self::label(implode('/', array_slice($parts, 1, count($parts) - 2))),
            'local_path' => $local,
            'remote_path' => $remotePath,
            'status' => 'queued',
            'after' => $after ?: null,
        ]);
    }

    /** Start a transfer in its own process, detached from the web request and the queue worker. */
    public function launch(BackupTransfer $transfer): void
    {
        if (Shell::simulating()) {
            $this->execute($transfer);

            return;
        }
        $php = Shell::arg((string) config('gbx.php_cli', '/usr/bin/php8.4'));
        $artisan = Shell::arg(base_path('artisan'));
        Shell::run('setsid nohup '.$php.' '.$artisan.' gbx:backup-transfer '.(int) $transfer->id.' >/dev/null 2>&1 </dev/null &', 20, null, false, base_path());
    }

    public function maxParallel(): int
    {
        return max(1, min(10, (int) Setting::get('backup_max_parallel', 2)));
    }

    public function partBytes(): int
    {
        return max(64, min(20480, (int) Setting::get('backup_split_mb', self::DEFAULT_SPLIT_MB))) * 1048576;
    }

    /* ============================================================== running */

    /** Run a queued transfer. Returns true when it finished successfully. */
    public function execute(BackupTransfer $transfer, ?callable $echo = null): bool
    {
        $transfer->refresh();
        if (! $transfer->isActive()) {
            return $transfer->status === 'success';
        }
        @mkdir(dirname($transfer->logFile()), 0775, true);
        $log = fopen($transfer->logFile(), 'a');
        $write = function (string $line) use ($log, $echo) {
            $line = rtrim($line, "\r\n");
            if ($log) {
                fwrite($log, $line."\n");
                fflush($log);
            }
            if ($echo) {
                $echo($line);
            }
        };

        $transfer->update(['pid' => function_exists('getmypid') ? (getmypid() ?: null) : null, 'message' => null]);
        if (! $this->waitForSlot($transfer, $write)) {
            return false;
        }

        $storage = $transfer->storage;
        $transfer->update(['status' => 'running', 'started_at' => now(), 'attempts' => $transfer->attempts + 1, 'progress' => null]);
        $write('==> '.ucfirst($transfer->direction).' '.basename($transfer->local_path).' '.($transfer->direction === 'upload' ? 'to' : 'from').' '.$storage->name.' ('.now()->toDateTimeString().')');

        try {
            if (! $storage->is_active) {
                throw new StorageException('The storage '.$storage->name.' is disabled.');
            }
            if (! $this->storages->installed()) {
                throw new StorageException('rclone is not installed on this server.');
            }
            $transfer->direction === 'upload' ? $this->upload($transfer, $storage, $write) : $this->download($transfer, $storage, $write);
            $transfer->update(['status' => 'success', 'finished_at' => now(), 'progress' => null]);
            $write('==> Finished in '.$this->duration($transfer));
            $this->notify($transfer, true);
        } catch (\Throwable $e) {
            $transfer->refresh();
            $message = mb_substr($e->getMessage(), 0, 1000);
            if ($transfer->status === 'canceled') {
                $write('==> Canceled');
            } else {
                $transfer->update(['status' => 'failed', 'finished_at' => now(), 'message' => $message, 'progress' => null]);
                $write('[error] '.$message);
                $this->notify($transfer, false);
            }
        } finally {
            if ($log) {
                fclose($log);
            }
        }

        return $transfer->fresh()->status === 'success';
    }

    protected function duration(BackupTransfer $transfer): string
    {
        $seconds = max(0, (int) optional($transfer->started_at)->diffInSeconds(now()));

        return $seconds >= 3600 ? round($seconds / 3600, 1).' h' : ($seconds >= 60 ? round($seconds / 60, 1).' min' : $seconds.' s');
    }

    /** Keep the number of simultaneous transfers under the configured limit. */
    protected function waitForSlot(BackupTransfer $transfer, callable $write): bool
    {
        $waited = 0;
        while (true) {
            $running = BackupTransfer::query()->where('status', 'running')->whereKeyNot($transfer->id)->count();
            if ($running < $this->maxParallel() || Shell::simulating()) {
                return true;
            }
            if ($waited === 0) {
                $write('Waiting for a free transfer slot ('.$running.' running)');
                $transfer->update(['progress' => 'Waiting for a free transfer slot']);
            }
            sleep(10);
            $waited += 10;
            if ($transfer->fresh()->status === 'canceled') {
                $write('==> Canceled while waiting');

                return false;
            }
        }
    }

    /**
     * Run an rclone command as root, streaming its output to the transfer log.
     */
    protected function stream(BackupTransfer $transfer, string $command, callable $write): void
    {
        if (Shell::simulating()) {
            $write('[simulation] '.mb_substr($command, 0, 200));

            return;
        }
        $argv = ['/bin/bash', '-c', 'exec '.$command];
        if (! Shell::isRoot() && config('gbx.use_sudo')) {
            $argv = array_merge(['sudo', '-n', '-H'], $argv);
        }
        $process = new Process($argv, '/tmp', ['LC_ALL' => 'C.UTF-8', 'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', 'HOME' => '/root'], null, null);
        $last = 0;
        $tail = '';
        $exit = $process->run(function ($type, $buffer) use ($write, $transfer, &$last, &$tail) {
            foreach (preg_split('/\r\n|\r|\n/', $buffer) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $write($line);
                $tail = $line;
                // progress lines from --stats-one-line, shown in the transfers table
                if (preg_match('/(ETA|100%)/', $line) && time() - $last >= 5) {
                    $last = time();
                    $transfer->update(['progress' => mb_substr(preg_replace('/^\d{4}\/\d\d\/\d\d \d\d:\d\d:\d\d [A-Z]+ ?: ?/', '', $line), 0, 200)]);
                }
            }
        });

        if ($exit !== 0) {
            throw new StorageException(mb_substr(preg_replace('/^\d{4}\/\d\d\/\d\d \d\d:\d\d:\d\d [A-Z]+ ?: ?/', '', $tail) ?: 'rclone exited with code '.$exit, 0, 500));
        }
    }

    protected function rclone(BackupStorage $storage, string $config, bool $transfer = true): string
    {
        return 'nice -n 10 ionice -c2 -n7 rclone '.$this->storages->flags($storage, $config, $transfer);
    }

    protected function fileSize(string $path): int
    {
        return Shell::simulating() ? 194560000 : (int) Shell::out('stat -c %s -- '.Shell::arg($path), 20);
    }

    /* =============================================================== upload */

    protected function upload(BackupTransfer $transfer, BackupStorage $storage, callable $write): void
    {
        $file = $transfer->local_path;
        if (! Shell::simulating() && ! Shell::fileExists($file)) {
            throw new StorageException('The backup file no longer exists: '.$file);
        }
        $size = $this->fileSize($file);
        $transfer->update(['size' => $size]);
        $write('Local file: '.$file.' ('.\App\Services\SystemStats::bytes($size, 1).')');

        $write('Checksum (sha256)...');
        $hash = Shell::simulating() ? str_repeat('0', 64) : (string) strtok(Shell::out('sha256sum -- '.Shell::arg($file), 7200), ' ');
        if (strlen($hash) !== 64) {
            throw new StorageException('The checksum of the backup could not be calculated.');
        }
        $transfer->update(['sha256' => $hash]);

        $config = $this->storages->writeConfig($storage, 't'.$transfer->id);
        try {
            $rclone = $this->rclone($storage, $config);
            $destination = $this->storages->path($storage, $transfer->remote_path);
            $split = ! empty($storage->definition()['split']) && $size > $this->partBytes();

            if ($split) {
                $this->uploadInParts($transfer, $rclone, $destination, $size, $write);
            } else {
                $write('Uploading to '.$destination);
                $this->stream($transfer, $rclone.' copyto '.Shell::arg($file).' '.Shell::arg($destination), $write);
                $remote = json_decode(Shell::out('rclone '.$this->storages->flags($storage, $config).' lsjson --stat '.Shell::arg($destination), 120), true);
                if (! Shell::simulating() && (int) ($remote['Size'] ?? -1) !== $size) {
                    throw new StorageException('The uploaded file has a different size at the destination.');
                }
            }

            // checksum file next to the backup, used to verify a restore
            Shell::run('rclone '.$this->storages->flags($storage, $config).' rcat '.Shell::arg($this->storages->path($storage, preg_replace('/\.parts$/', '', $transfer->remote_path).'.sha256')), 120, $hash.'  '.basename($file)."\n");

            if ($transfer->keep) {
                $this->applyRetention($storage, $config, dirname($transfer->remote_path), $transfer->keep, $write);
            }
            $write('Uploaded '.basename($file));
        } finally {
            $this->storages->finishConfig($storage, $config);
        }

        if ($transfer->delete_local && ! Shell::simulating()) {
            Shell::run('rm -f -- '.Shell::arg($file), 60);
            $write('Local copy deleted (the backup is only on '.$storage->name.' now)');
        }
    }

    /**
     * Destinations without resumable uploads receive the archive in parts: a repeated transfer
     * only sends the parts that are missing at the destination.
     */
    protected function uploadInParts(BackupTransfer $transfer, string $rclone, string $destination, int $size, callable $write): void
    {
        $partBytes = $this->partBytes();
        $count = (int) ceil($size / $partBytes);
        $dir = rtrim((string) config('gbx.paths.backup'), '/').'/.parts/'.$transfer->id;
        $transfer->update(['parts' => $count, 'remote_path' => str_ends_with($transfer->remote_path, '.parts') ? $transfer->remote_path : $transfer->remote_path.'.parts']);
        $destination = rtrim($destination, '/');
        $destination = str_ends_with($destination, '.parts') ? $destination : $destination.'.parts';

        $write('Splitting into '.$count.' part(s) of '.round($partBytes / 1048576).' MB');
        if (! Shell::simulating()) {
            $d = Shell::arg($dir);
            Shell::run('rm -rf -- '.$d.' && mkdir -p '.$d.' && chmod 700 '.$d, 60)->throw('Unable to prepare the parts folder');
            Shell::run('split -b '.$partBytes.' -d -a 4 -- '.Shell::arg($transfer->local_path).' '.Shell::arg($dir.'/part-'), 7200)->throw('Unable to split the archive');
        }

        try {
            $write('Uploading parts to '.$destination);
            $this->stream($transfer, $rclone.' copy '.Shell::arg($dir).' '.Shell::arg($destination), $write);
            $write('Verifying parts at the destination');
            $this->stream($transfer, $rclone.' check '.Shell::arg($dir).' '.Shell::arg($destination).' --one-way', $write);
        } finally {
            if (! Shell::simulating()) {
                Shell::run('rm -rf -- '.Shell::arg($dir), 120);
            }
        }
    }

    /** Keep only the newest backups of a series at the destination. */
    public function applyRetention(BackupStorage $storage, string $config, string $dir, int $keep, callable $write): void
    {
        $listing = Shell::simulating() ? '[]' : Shell::out('rclone '.$this->storages->flags($storage, $config).' lsjson --max-depth 1 '.Shell::arg($this->storages->path($storage, $dir)), 180);
        $items = [];
        foreach ((array) json_decode($listing, true) as $row) {
            $name = (string) ($row['Name'] ?? '');
            if ($name === '' || str_ends_with($name, '.sha256') || ((bool) ($row['IsDir'] ?? false) && ! str_ends_with($name, '.parts'))) {
                continue;
            }
            preg_match('/_(\d{8}_\d{6})/', $name, $m);
            $items[] = ['name' => $name, 'key' => $m[1] ?? date('Ymd_His', strtotime((string) ($row['ModTime'] ?? '')) ?: 0)];
        }
        usort($items, fn ($a, $b) => strcmp($b['key'], $a['key']));

        foreach (array_slice($items, $keep) as $old) {
            $path = $dir.'/'.$old['name'];
            $target = Shell::arg($this->storages->path($storage, $path));
            Shell::run('rclone '.$this->storages->flags($storage, $config).' '.(str_ends_with($old['name'], '.parts') ? 'purge' : 'deletefile').' '.$target, 300);
            Shell::run('rclone '.$this->storages->flags($storage, $config).' deletefile '.Shell::arg($this->storages->path($storage, preg_replace('/\.parts$/', '', $path).'.sha256')), 120);
            $write('Removed old backup at the destination: '.$old['name']);
        }
    }

    /* ============================================================= download */

    protected function download(BackupTransfer $transfer, BackupStorage $storage, callable $write): void
    {
        $target = $transfer->local_path;
        $temp = $target.'.part';
        $config = $this->storages->writeConfig($storage, 't'.$transfer->id);

        try {
            $rclone = $this->rclone($storage, $config);
            $source = $this->storages->path($storage, $transfer->remote_path);
            Shell::run('mkdir -p '.Shell::arg(dirname($target)), 30);

            if (str_ends_with($transfer->remote_path, '.parts')) {
                $dir = rtrim((string) config('gbx.paths.backup'), '/').'/.parts/'.$transfer->id;
                Shell::run('rm -rf -- '.Shell::arg($dir).' && mkdir -p '.Shell::arg($dir).' && chmod 700 '.Shell::arg($dir), 60);
                try {
                    $write('Downloading parts from '.$source);
                    $this->stream($transfer, $rclone.' copy '.Shell::arg($source).' '.Shell::arg($dir), $write);
                    $this->stream($transfer, 'bash -c '.Shell::arg('set -o pipefail; cat '.Shell::arg($dir).'/part-* > '.Shell::arg($temp)), $write);
                } finally {
                    Shell::run('rm -rf -- '.Shell::arg($dir), 120);
                }
            } else {
                $write('Downloading '.$source);
                $this->stream($transfer, $rclone.' copyto '.Shell::arg($source).' '.Shell::arg($temp), $write);
            }

            $expected = trim((string) strtok(Shell::out('rclone '.$this->storages->flags($storage, $config).' cat '.Shell::arg($this->storages->path($storage, preg_replace('/\.parts$/', '', $transfer->remote_path).'.sha256')), 120), ' '));
            if (! Shell::simulating() && strlen($expected) === 64) {
                $write('Verifying checksum');
                $actual = (string) strtok(Shell::out('sha256sum -- '.Shell::arg($temp), 7200), ' ');
                if ($actual !== $expected) {
                    Shell::run('rm -f -- '.Shell::arg($temp), 30);
                    throw new StorageException('The downloaded file does not match the checksum stored with the backup.');
                }
                $transfer->update(['sha256' => $expected]);
            }

            Shell::run('mv -f -- '.Shell::arg($temp).' '.Shell::arg($target), 300)->throw('Unable to store the downloaded backup');
            $transfer->update(['size' => $this->fileSize($target)]);
            $write('Saved as '.$target);
        } finally {
            $this->storages->finishConfig($storage, $config);
        }

        $this->runAfter($transfer, $write);
    }

    /** Optional restore started right after a download. */
    protected function runAfter(BackupTransfer $transfer, callable $write): void
    {
        $after = $transfer->after ?? [];
        $action = $after['action'] ?? null;
        if (! $action) {
            return;
        }

        if ($action === 'restore_site' && ($site = Website::query()->find($after['website_id'] ?? 0))) {
            $task = app(BackupManager::class)->restoreArchive($transfer->local_path, dirname($site->root_path));
            $write('Restore of '.$site->domain.' started (task #'.$task->id.')');
        } elseif ($action === 'restore_db' && ($db = MysqlDatabase::query()->with('server')->find($after['database_id'] ?? 0))) {
            $engine = Engines::get($db->engine);
            $task = TaskRunner::dispatch("Restore {$db->name} from ".basename($transfer->local_path), $engine->importScript($db->name, $transfer->local_path, $db->server), 'database');
            $write('Restore of '.$db->name.' started (task #'.$task->id.')');
        }
    }

    /* ============================================================== control */

    public function cancel(BackupTransfer $transfer): void
    {
        $pid = (int) $transfer->pid;
        $transfer->update(['status' => 'canceled', 'finished_at' => now(), 'message' => 'Canceled', 'progress' => null]);
        if ($pid > 0 && ! Shell::simulating()) {
            // the transfer runs php -> sudo -> rclone: stop the children first, then the command itself
            Shell::run('pkill -TERM -P '.$pid.' 2>/dev/null; kill -TERM '.$pid.' 2>/dev/null; true', 20);
        }
    }

    public function retry(BackupTransfer $transfer): void
    {
        if ($transfer->isActive()) {
            throw new \RuntimeException('This transfer is still running.');
        }
        $transfer->update(['status' => 'queued', 'message' => null, 'progress' => null, 'started_at' => null, 'finished_at' => null]);
        $this->launch($transfer);
    }

    /** Transfers whose process disappeared (server restart, out of memory) are not left "running". */
    public function refreshStale(): int
    {
        $stale = 0;
        foreach (BackupTransfer::query()->whereIn('status', BackupTransfer::ACTIVE)->get() as $transfer) {
            $alive = $transfer->pid && ! Shell::simulating()
                ? (function_exists('posix_kill') ? @posix_kill((int) $transfer->pid, 0) : Shell::test('kill -0 '.(int) $transfer->pid))
                : true;
            if ($transfer->pid && ! $alive) {
                $transfer->update(['status' => 'failed', 'finished_at' => now(), 'message' => 'The transfer was interrupted before it finished.']);
                $this->notify($transfer, false);
                $stale++;
            }
        }

        return $stale;
    }

    public function purgeHistory(): int
    {
        $days = max(1, (int) Setting::get('backup_history_days', 30));
        $removed = 0;
        foreach (BackupTransfer::query()->whereNotIn('status', BackupTransfer::ACTIVE)->where('finished_at', '<', now()->subDays($days))->get() as $transfer) {
            @unlink($transfer->logFile());
            $transfer->delete();
            $removed++;
        }

        return $removed;
    }

    /* ======================================================== notifications */

    protected function notify(BackupTransfer $transfer, bool $ok): void
    {
        if (! $ok) {
            ActivityLog::record('backup', 'Transfer failed: '.basename($transfer->local_path), mb_substr((string) $transfer->message, 0, 1000));
        }
        \App\Services\Api\WebhookManager::event($ok ? 'transfer.finished' : 'transfer.failed', [
            'id' => $transfer->id,
            'direction' => $transfer->direction,
            'file' => basename($transfer->local_path),
            'remote_path' => $transfer->remote_path,
            'storage' => $transfer->storage?->name,
            'size' => $transfer->size,
            'status' => $transfer->status,
            'message' => $transfer->message,
        ]);
        $webhook = trim((string) Setting::get('backup_webhook', ''));
        if ($webhook === '' || ($ok && ! Setting::get('backup_webhook_success', false))) {
            return;
        }
        try {
            Http::timeout(15)->post($webhook, [
                'event' => $ok ? 'backup.transfer_finished' : 'backup.transfer_failed',
                'panel' => Setting::get('panel_title', 'GBX Panel'),
                'host' => gethostname(),
                'transfer' => [
                    'id' => $transfer->id,
                    'direction' => $transfer->direction,
                    'storage' => $transfer->storage?->name,
                    'file' => basename($transfer->local_path),
                    'remote' => $transfer->remote_path,
                    'size' => $transfer->size,
                    'status' => $transfer->status,
                    'message' => $transfer->message,
                ],
                'at' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            ActivityLog::record('backup', 'Backup webhook failed', mb_substr($e->getMessage(), 0, 500));
        }
    }
}
