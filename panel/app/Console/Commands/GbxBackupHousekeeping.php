<?php

namespace App\Console\Commands;

use App\Models\BackupStorage;
use App\Services\Backup\StorageManager;
use App\Services\Backup\TransferManager;
use Illuminate\Console\Command;

class GbxBackupHousekeeping extends Command
{
    protected $signature = 'gbx:backup-housekeeping {--usage : Also measure the space used at every storage}';

    protected $description = 'Mark interrupted transfers, delete old transfer history and refresh storage usage';

    public function handle(TransferManager $transfers, StorageManager $storages): int
    {
        $stale = $transfers->refreshStale();
        $purged = $transfers->purgeHistory();
        $this->info("{$stale} interrupted transfer(s) marked, {$purged} old record(s) removed.");

        if ($this->option('usage')) {
            foreach (BackupStorage::query()->where('is_active', true)->get() as $storage) {
                try {
                    $about = $storages->about($storage);
                    $storage->update(['used_bytes' => $about['used'] ?? null, 'total_bytes' => $about['total'] ?? null, 'checked_at' => now(), 'last_error' => null]);
                    $this->line("{$storage->name}: ".\App\Services\SystemStats::bytes((int) ($about['used'] ?? 0), 1).' used');
                } catch (\Throwable $e) {
                    $storage->update(['last_error' => mb_substr($e->getMessage(), 0, 500)]);
                    $this->error("{$storage->name}: ".$e->getMessage());
                }
            }
        }

        return self::SUCCESS;
    }
}
