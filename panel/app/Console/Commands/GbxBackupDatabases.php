<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\BackupStorage;
use App\Models\DatabaseRecycle;
use App\Models\MysqlDatabase;
use App\Models\Setting;
use App\Services\Backup\TransferManager;
use App\Services\Databases\Engines;
use App\Services\Databases\SqlServerEngine;
use App\Services\Shell;
use Illuminate\Console\Command;

class GbxBackupDatabases extends Command
{
    protected $signature = 'gbx:backup-databases {--scheduled : Only run when automatic backups are enabled} {--purge-recycle : Only delete expired recycle bin items}';

    protected $description = 'Back up every database managed by the panel and apply the retention policy';

    public function handle(): int
    {
        $this->purgeRecycle();
        if ($this->option('purge-recycle')) {
            return self::SUCCESS;
        }
        if ($this->option('scheduled') && ! Setting::get('db_backup_enabled', false)) {
            return self::SUCCESS;
        }

        $keep = max(1, (int) Setting::get('db_backup_keep', config('gbx.database_backup_keep')));
        $ok = 0;
        $failed = [];

        foreach (MysqlDatabase::query()->with('server')->orderBy('engine')->get() as $record) {
            $engine = Engines::get($record->engine);
            if (! $engine->canBackup($record->server) || (! $record->server && ! $engine->installed())) {
                continue;
            }
            $script = $engine instanceof SqlServerEngine
                ? "set -e\n".$engine->queryScript($engine->backupSql($record->name, $record->name.'_'.date('Ymd_His').'.bak'), $record->server)
                : $engine->backupScript($record->name, $record->server);
            $result = Shell::run($script, 3600);
            if ($result->ok()) {
                $ok++;
                $this->line("Backed up {$record->engine} {$record->name}");
                foreach (array_slice($engine->backups($record->name), $keep) as $old) {
                    Shell::run('rm -f '.Shell::arg($engine->backupPath($old['name'])));
                }
                $this->upload($engine, $record);
            } else {
                $failed[] = "{$record->engine} {$record->name}: ".$result->message();
                $this->error(end($failed));
            }
        }

        ActivityLog::record('database', "Automatic backup: {$ok} database(s)".($failed ? ', '.count($failed).' failed' : ''), $failed ? mb_substr(implode("\n", $failed), 0, 1000) : null);
        $this->info("{$ok} database(s) backed up, keeping the last {$keep} of each.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** Send the dump that was just written to the storage chosen in Databases > Automatic backup. */
    protected function upload(\App\Services\Databases\DatabaseEngine $engine, MysqlDatabase $record): void
    {
        $storage = BackupStorage::query()->where('is_active', true)->find((int) Setting::get('db_backup_storage', 0));
        $newest = $storage && ! ($engine instanceof SqlServerEngine) ? ($engine->backups($record->name)[0]['name'] ?? null) : null;
        if (! $newest) {
            return;
        }
        try {
            $transfers = app(TransferManager::class);
            $transfer = $transfers->queueUpload($storage, $engine->backupPath($newest), 'database', $record->engine.'/'.$record->name, [
                'delete_local' => (bool) Setting::get('db_backup_storage_move', false),
                'keep' => (int) Setting::get('db_backup_remote_keep', 30),
            ]);
            $transfers->launch($transfer);
            $this->line("  Upload of {$newest} to {$storage->name} started");
        } catch (\Throwable $e) {
            $this->error("  Upload of {$newest} not started: ".$e->getMessage());
        }
    }

    protected function purgeRecycle(): void
    {
        DatabaseRecycle::query()->where('created_at', '<', now()->subDays(DatabaseRecycle::KEEP_DAYS))->each(function (DatabaseRecycle $item) {
            Shell::run('rm -f '.Shell::arg($item->file));
            $item->delete();
            $this->line("Purged {$item->name} from the recycle bin");
        });
    }
}
