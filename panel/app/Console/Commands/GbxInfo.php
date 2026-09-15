<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PanelManager;
use App\Services\SystemStats;
use Illuminate\Console\Command;

class GbxInfo extends Command
{
    protected $signature = 'gbx:info {--set-entry= : Change the security entrance ("off" disables it)}';

    protected $description = 'Show panel address and settings';

    public function handle(SystemStats $stats, PanelManager $panel): int
    {
        if ($this->option('set-entry') !== null) {
            $entry = $this->option('set-entry') === 'off' ? '' : trim($this->option('set-entry'), '/');
            if (! PanelManager::validEntry($entry)) {
                $this->error('Entrance must be 6-32 characters: letters, numbers, - and _');

                return self::FAILURE;
            }
            $panel->setEnv(['GBX_ENTRY' => $entry]);
            config(['gbx.entry' => $entry]);
            $this->info($entry === '' ? 'Security entrance disabled.' : "Security entrance set to /{$entry}");
        }

        $entry = config('gbx.entry') ? '/'.config('gbx.entry') : '';
        $port = config('gbx.port');
        $internal = trim((string) shell_exec("hostname -I 2>/dev/null | awk '{print $1}'")) ?: '127.0.0.1';

        $this->newLine();
        $this->line('  GBX Panel '.config('gbx.version'));
        $this->line('  External URL : https://'.$stats->publicIp().':'.$port.$entry);
        $this->line('  Internal URL : https://'.$internal.':'.$port.$entry);
        $this->line('  Admin users  : '.User::query()->where('role', 'admin')->pluck('username')->implode(', '));
        $this->newLine();

        return self::SUCCESS;
    }
}
