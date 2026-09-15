<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\User;
use App\Services\PanelManager;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GbxSetup extends Command
{
    protected $signature = 'gbx:setup
        {--username= : Admin username (random when omitted)}
        {--password= : Admin password (random when omitted)}
        {--port= : Panel port}
        {--entry= : Security entrance (random when omitted)}
        {--json : Print credentials as JSON}';

    protected $description = 'First-time setup: create the administrator and security entrance';

    public function handle(PanelManager $panel): int
    {
        $username = $this->option('username') ?: Str::lower(Str::random(8));
        $password = $this->option('password') ?: Str::password(16, symbols: false);
        $entry = $this->option('entry') ?: Str::lower(Str::random(8));
        $port = (int) ($this->option('port') ?: config('gbx.port'));

        if (! PanelManager::validEntry($entry)) {
            $this->error('Invalid entrance.');

            return self::FAILURE;
        }

        User::query()->updateOrCreate(
            ['username' => $username],
            ['name' => 'Administrator', 'password' => $password, 'role' => 'admin', 'is_active' => true]
        );

        $panel->setEnv(['GBX_ENTRY' => $entry, 'GBX_PORT' => $port]);
        Setting::put('panel_title', Setting::get('panel_title', 'GBX Panel'));
        Setting::put('installed_at', now()->toDateTimeString());

        $result = ['username' => $username, 'password' => $password, 'port' => $port, 'entry' => $entry];
        if ($this->option('json')) {
            $this->line(json_encode($result));
        } else {
            $this->table(['Key', 'Value'], collect($result)->map(fn ($v, $k) => [$k, $v])->values()->all());
        }

        return self::SUCCESS;
    }
}
