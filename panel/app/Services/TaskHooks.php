<?php

namespace App\Services;

use App\Models\DatabaseRecycle;
use App\Models\DbServer;
use App\Models\MalwareScan;
use App\Models\MysqlDatabase;
use App\Models\Task;
use App\Models\Website;
use App\Services\Databases\QdrantManager;

/**
 * Follow-up work executed when a background task finishes
 * (by the queue worker, or immediately in simulation mode).
 */
class TaskHooks
{
    /** Queue one transfer per file of a finished backup task. */
    protected static function uploadBackup(Task $task, array $upload): void
    {
        $storage = \App\Models\BackupStorage::query()->where('is_active', true)->find((int) $upload['storage_id']);
        if (! $storage) {
            file_put_contents($task->logFile(), "\n[warning] The storage of this backup is disabled or was removed; the backup stays on this server.\n", FILE_APPEND);

            return;
        }
        $transfers = app(\App\Services\Backup\TransferManager::class);
        foreach ($upload['files'] ?? [] as $item) {
            if (empty($item['file']) || ! (Shell::simulating() || Shell::fileExists($item['file']))) {
                continue;
            }
            try {
                $transfer = $transfers->queueUpload($storage, $item['file'], (string) ($item['category'] ?? 'other'), $item['label'] ?? null, [
                    'delete_local' => (bool) ($upload['delete_local'] ?? false),
                    'keep' => $upload['keep'] ?? null,
                    'task_id' => $task->id,
                ]);
                $transfers->launch($transfer);
                file_put_contents($task->logFile(), "\n==> Upload of ".basename($item['file']).' to '.$storage->name." started (Backup > Transfers)\n", FILE_APPEND);
            } catch (\Throwable $e) {
                file_put_contents($task->logFile(), "\n[error] Upload not started: ".$e->getMessage()."\n", FILE_APPEND);
            }
        }
    }

    public static function finished(Task $task): void
    {
        $meta = $task->meta ?? [];

        try {
            // a backup that was asked to go to a storage: send the archive and its database dumps
            if ($task->status === 'success' && ! empty($meta['upload']['storage_id'])) {
                self::uploadBackup($task, (array) $meta['upload']);
            }

            if (($meta['on_success'] ?? null) === 'ssl_issued' && $task->status === 'success' && isset($meta['website_id'])) {
                $site = Website::query()->find($meta['website_id']);
                if ($site) {
                    $site->update(['ssl_enabled' => true, 'ssl_provider' => 'letsencrypt']);
                    app(ApacheManager::class)->write($site);
                    app(SslManager::class)->refreshExpiry($site);
                }
            }

            if (($meta['on_success'] ?? null) === 'git_deployed' && isset($meta['website_id'])) {
                $site = Website::query()->find($meta['website_id']);
                if ($site) {
                    preg_match('/^Commit: (.+)$/m', $task->output(), $m);
                    $site->putSetting('git.last_deploy', [
                        'at' => now()->toDateTimeString(),
                        'status' => $task->status,
                        'task_id' => $task->id,
                        'commit' => $m[1] ?? null,
                    ])->save();
                }
            }

            // software installed from the catalog
            if (! empty($meta['package']) && $task->status === 'success' && str_starts_with($task->title, 'Install ')) {
                self::afterInstall((string) $meta['package']);
            }

            // database dumped and dropped: keep it in the recycle bin
            if (($meta['on_success'] ?? null) === 'db_recycled' && $task->status === 'success') {
                $db = MysqlDatabase::query()->with('server')->find($meta['database_id'] ?? 0);
                $dropped = $db ? \App\Services\Databases\Engines::get($db->engine)->drop($db->name, $db->username, $db->server) : null;
                if ($dropped && $dropped->failed()) {
                    file_put_contents($task->logFile(), "\n[error] The dump was saved but the database could not be dropped: ".$dropped->message()."\n", FILE_APPEND);
                }
                if ($db && $dropped->ok()) {
                    DatabaseRecycle::query()->create([
                        'engine' => $db->engine, 'name' => $db->name, 'username' => $db->username, 'password' => $db->password,
                        'host' => $db->host, 'charset' => $db->charset, 'notes' => $db->notes, 'file' => $meta['file'],
                        'size' => Shell::simulating() ? 1048576 : (int) Shell::out('stat -c %s '.Shell::arg($meta['file']).' 2>/dev/null', 10),
                    ]);
                    $db->delete();
                }
            }

            // database restored from the recycle bin
            if (($meta['on_success'] ?? null) === 'db_restored' && $task->status === 'success') {
                $item = DatabaseRecycle::query()->find($meta['recycle_id'] ?? 0);
                if ($item) {
                    MysqlDatabase::query()->create([
                        'engine' => $item->engine, 'name' => $item->name, 'username' => $item->username, 'password' => $item->password,
                        'host' => $item->host ?: 'localhost', 'charset' => $item->charset ?: 'utf8mb4', 'notes' => $item->notes,
                    ]);
                    Shell::run('rm -f '.Shell::arg($item->file));
                    $item->delete();
                }
            }

            // cron job executed from the panel
            if (($meta['on_finish'] ?? null) === 'cron_run' && isset($meta['cron_id'])) {
                $started = $task->started_at ?? $task->created_at;
                \App\Models\CronJob::query()->whereKey($meta['cron_id'])->update([
                    'last_run_at' => $started,
                    'last_status' => (int) ($task->exit_code ?? ($task->status === 'success' ? 0 : 1)),
                    'last_duration' => $started ? max(0, (int) $started->diffInSeconds($task->finished_at ?? now(), true)) : null,
                ]);
            }

            // script from the library executed from the panel
            if (($meta['on_finish'] ?? null) === 'cron_script_run' && isset($meta['cron_script_id'])) {
                \App\Models\CronScript::query()->whereKey($meta['cron_script_id'])->update(['last_run_at' => $task->started_at ?? $task->created_at, 'last_task_id' => $task->id]);
            }

            if (($meta['on_finish'] ?? null) === 'malware_scan' && isset($meta['malware_scan_id'])) {
                $scan = MalwareScan::query()->find($meta['malware_scan_id']);
                if ($scan) {
                    app(ClamAvManager::class)->finalize($scan, $task);
                }
            }
            self::notify($task);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Tell the webhooks what happened, with a separate event for backups. */
    protected static function notify(Task $task): void
    {
        $failed = $task->status === 'failed';
        $payload = [
            'id' => $task->id,
            'type' => $task->type,
            'title' => $task->title,
            'status' => $task->status,
            'exit_code' => $task->exit_code,
            'client_id' => $task->client_id,
            'finished_at' => $task->finished_at?->toIso8601String(),
        ];
        \App\Services\Api\WebhookManager::event($failed ? 'task.failed' : 'task.finished', $payload);
        if ($task->type === 'backup') {
            \App\Services\Api\WebhookManager::event($failed ? 'backup.failed' : 'backup.finished', $payload + ['file' => ($task->meta ?? [])['file'] ?? null]);
        }
        if (($task->meta['on_success'] ?? null) === 'ssl_issued' && ! $failed) {
            $site = Website::query()->find($task->meta['website_id'] ?? 0);
            if ($site) {
                \App\Services\Api\WebhookManager::event('ssl.issued', ['website_id' => $site->id, 'domain' => $site->domain, 'expires_at' => $site->fresh()->ssl_expires_at?->toIso8601String()]);
            }
        }
        if (($task->meta['on_finish'] ?? null) === 'malware_scan' && ! $failed) {
            $scan = MalwareScan::query()->find($task->meta['malware_scan_id'] ?? 0);
            if ($scan && $scan->infected > 0) {
                \App\Services\Api\WebhookManager::event('malware.detected', ['scan_id' => $scan->id, 'path' => $scan->path, 'infected' => $scan->infected, 'files' => $scan->files]);
            }
        }
    }

    protected static function afterInstall(string $package): void
    {
        if ($package === 'qdrant') {
            // protect the API with a generated key
            app(QdrantManager::class)->applyLocal();
        }

        if ($package === 'sqlserver') {
            $password = Shell::simulating() ? 'SimulatedSa#1' : Shell::out('cat /root/.gbx-mssql-sa', 10);
            if ($password !== '') {
                DbServer::query()->updateOrCreate(
                    ['engine' => 'sqlserver', 'host' => '127.0.0.1', 'port' => 1433],
                    ['name' => 'Local SQL Server (Docker)', 'username' => 'sa', 'password' => $password]
                );
            }
        }
    }
}
