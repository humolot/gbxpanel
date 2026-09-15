<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\CronJob;
use App\Models\FtpAccount;
use App\Models\MysqlDatabase;
use App\Models\Task;
use App\Models\Website;
use App\Services\FirewallManager;
use App\Services\PanelManager;
use App\Services\SoftwareManager;
use App\Services\SystemStats;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    public function index(SystemStats $stats)
    {
        return view('home.index', [
            'info' => $stats->info(),
            'counts' => [
                'websites' => Website::query()->count(),
                'ftp' => FtpAccount::query()->count(),
                'databases' => MysqlDatabase::query()->count(),
                'cron' => CronJob::query()->count(),
            ],
            'activity' => ActivityLog::query()->with('user')->latest('id')->limit(8)->get(),
            'tasks' => Task::query()->latest('id')->limit(6)->get(),
        ]);
    }

    public function stats(SystemStats $stats)
    {
        return response()->json($stats->snapshot());
    }

    /** Slower data for the home page (software cards, firewall rules, updates). */
    public function overview(SoftwareManager $software, FirewallManager $firewall, PanelManager $panel)
    {
        $installed = array_values(array_filter($software->all(), fn ($s) => $s['installed']));

        $extra = Cache::remember('gbx.home.extra', 300, fn () => [
            'firewall_rules' => count($firewall->status()['rules'] ?? []),
            'updates' => $panel->pendingUpdates(),
            'reboot_required' => $panel->rebootRequired(),
        ]);

        return response()->json(['software' => $installed] + $extra);
    }
}
