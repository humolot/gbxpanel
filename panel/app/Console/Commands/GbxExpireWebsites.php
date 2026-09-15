<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Website;
use App\Services\ApacheManager;
use Illuminate\Console\Command;

class GbxExpireWebsites extends Command
{
    protected $signature = 'gbx:expire-websites';

    protected $description = 'Stop websites whose expiration date has passed';

    public function handle(ApacheManager $apache): int
    {
        $stopped = 0;
        Website::query()->where('status', 'active')->whereNotNull('expires_at')->whereDate('expires_at', '<', today())
            ->each(function (Website $site) use ($apache, &$stopped) {
                $site->status = 'stopped';
                if ($apache->write($site)->ok()) {
                    $site->save();
                    ActivityLog::record('website', "Stopped expired website {$site->domain}", 'Expired on '.$site->expires_at->toDateString());
                    $this->line("Stopped {$site->domain} (expired {$site->expires_at->toDateString()})");
                    $stopped++;
                } else {
                    $this->error("Could not stop {$site->domain}");
                }
            });

        $this->info($stopped ? "{$stopped} expired website(s) stopped." : 'No expired websites.');

        return self::SUCCESS;
    }
}
