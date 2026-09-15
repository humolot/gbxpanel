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
    public static function finished(Task $task): void
    {
        $meta = $task->meta ?? [];

        try {
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

            if (($meta['on_finish'] ?? null) === 'malware_scan' && isset($meta['malware_scan_id'])) {
                $scan = MalwareScan::query()->find($meta['malware_scan_id']);
                if ($scan) {
                    app(ClamAvManager::class)->finalize($scan, $task);
                }
            }
        } catch (\Throwable $e) {
            report($e);
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
