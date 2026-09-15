<?php

namespace App\Console\Commands;

use App\Models\BackupTransfer;
use App\Services\Backup\TransferManager;
use Illuminate\Console\Command;

class GbxBackupTransfer extends Command
{
    protected $signature = 'gbx:backup-transfer {transfer : ID of the transfer} {--quiet-log : Do not print the rclone output}';

    protected $description = 'Run a queued backup transfer (upload to or download from a storage)';

    public function handle(TransferManager $transfers): int
    {
        $transfer = BackupTransfer::query()->with('storage')->find((int) $this->argument('transfer'));
        if (! $transfer) {
            $this->error('Transfer not found.');

            return self::FAILURE;
        }
        if (! $transfer->storage) {
            $transfer->update(['status' => 'failed', 'message' => 'The storage was removed.', 'finished_at' => now()]);

            return self::FAILURE;
        }

        $echo = $this->option('quiet-log') ? null : fn (string $line) => $this->line($line);

        return $transfers->execute($transfer, $echo) ? self::SUCCESS : self::FAILURE;
    }
}
