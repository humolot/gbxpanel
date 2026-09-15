<?php

namespace App\Console\Commands;

use App\Services\Clients\ClientManager;
use Illuminate\Console\Command;

class GbxClientsUsage extends Command
{
    protected $signature = 'gbx:clients-usage {--disk : Also measure the disk usage of every client}';

    protected $description = 'Count client bandwidth and requests from the access logs, measure disk usage and apply expiration and quotas';

    public function handle(ClientManager $clients): int
    {
        $result = $clients->refresh((bool) $this->option('disk'));
        $this->line("Processed {$result['hours']} hour(s) of access logs.");
        foreach ($result['actions'] as $action) {
            $this->line($action);
        }

        return self::SUCCESS;
    }
}
