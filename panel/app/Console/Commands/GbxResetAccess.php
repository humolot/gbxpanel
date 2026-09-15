<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class GbxResetAccess extends Command
{
    protected $signature = 'gbx:reset-access';

    protected $description = 'Clear the panel IP allow list (use when locked out)';

    public function handle(): int
    {
        Setting::put('allowed_ips', '');
        Cache::flush();
        $this->info('IP allow list cleared. The panel is reachable from any IP again.');

        return self::SUCCESS;
    }
}
