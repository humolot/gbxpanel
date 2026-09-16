<?php

namespace App\Console\Commands;

use App\Services\Api\WebhookManager;
use Illuminate\Console\Command;

class GbxWebhooks extends Command
{
    protected $signature = 'gbx:webhooks {--retry : Send the deliveries waiting for another attempt} {--purge : Delete old delivery history} {--days=14 : History to keep with --purge}';

    protected $description = 'Retry failed webhook deliveries and clean up their history';

    public function handle(WebhookManager $webhooks): int
    {
        if ($this->option('retry') || ! $this->option('purge')) {
            $this->info($webhooks->retryPending().' delivery(ies) tried again.');
        }
        if ($this->option('purge')) {
            $this->info($webhooks->purge(max(1, (int) $this->option('days'))).' old delivery record(s) removed.');
        }

        return self::SUCCESS;
    }
}
