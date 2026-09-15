<?php

namespace App\Console\Commands;

use App\Services\CronManager;
use Illuminate\Console\Command;

class GbxCronSync extends Command
{
    protected $signature = 'gbx:cron-sync';

    protected $description = 'Write the cron runner, task scripts and /etc/cron.d/gbxpanel from the database';

    public function handle(CronManager $cron): int
    {
        $result = $cron->sync();
        if ($result->failed()) {
            $this->error('Cron sync failed: '.$result->message());

            return self::FAILURE;
        }
        $this->info('Cron jobs synchronized.');

        return self::SUCCESS;
    }
}
