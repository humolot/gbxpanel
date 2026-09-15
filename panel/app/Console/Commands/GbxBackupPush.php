<?php

namespace App\Console\Commands;

use App\Models\BackupStorage;
use App\Services\Backup\TransferManager;
use Illuminate\Console\Command;

/**
 * Called by scheduled backup jobs right after an archive or dump is written: it sends the file
 * to a storage and waits for the result, so the cron log shows what happened.
 */
class GbxBackupPush extends Command
{
    protected $signature = 'gbx:backup-push
        {file : Backup file inside the backup folder}
        {--storage= : ID of the destination}
        {--category=other : site, database, path or other}
        {--label= : Folder of the series at the destination (domain, engine/database...)}
        {--keep= : Number of copies to keep at the destination}
        {--move : Delete the local copy after a successful upload}
        {--cron= : ID of the cron job that created the backup}';

    protected $description = 'Upload a backup file to a storage and wait for it to finish';

    public function handle(TransferManager $transfers): int
    {
        $storage = BackupStorage::query()->find((int) $this->option('storage'));
        if (! $storage) {
            $this->warn('The storage of this job no longer exists: the backup stays on this server.');

            return self::SUCCESS;
        }
        if (! $storage->is_active) {
            $this->warn("The storage {$storage->name} is disabled: the backup stays on this server.");

            return self::SUCCESS;
        }

        try {
            $transfer = $transfers->queueUpload($storage, (string) $this->argument('file'), (string) $this->option('category'), (string) $this->option('label'), [
                'delete_local' => (bool) $this->option('move'),
                'keep' => $this->option('keep'),
                'cron_job_id' => $this->option('cron') ? (int) $this->option('cron') : null,
            ]);
        } catch (\Throwable $e) {
            $this->error('Upload not started: '.$e->getMessage());

            return self::FAILURE;
        }

        return $transfers->execute($transfer, fn (string $line) => $this->line($line)) ? self::SUCCESS : self::FAILURE;
    }
}
