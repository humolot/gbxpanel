<?php

namespace App\Http\Controllers\Api;

use App\Models\Metric;
use App\Services\ServiceManager;
use App\Services\SoftwareManager;
use App\Services\SystemStats;
use Illuminate\Http\Request;

class SystemController extends ApiController
{
    public function overview(SystemStats $stats)
    {
        return $this->data([
            'panel' => ['version' => config('gbx.version'), 'title' => \App\Models\Setting::get('panel_title', 'GBX Panel'), 'simulation' => \App\Services\Shell::simulating()],
            'server' => $stats->info(),
            'cpu' => $stats->cpu(),
            'load' => $stats->load(),
            'memory' => $stats->memory(),
            'disks' => $stats->disks(),
            'network' => $stats->network(),
            'uptime' => $stats->uptime(),
            'counts' => [
                'websites' => \App\Models\Website::query()->count(),
                'databases' => \App\Models\MysqlDatabase::query()->count(),
                'ftp_accounts' => \App\Models\FtpAccount::query()->count(),
                'clients' => \App\Models\Client::query()->count(),
                'running_tasks' => \App\Models\Task::query()->whereIn('status', ['queued', 'running'])->count(),
            ],
        ]);
    }

    public function services(ServiceManager $services)
    {
        return $this->data($services->list());
    }

    public function serviceAction(Request $request, string $service)
    {
        return $this->forward(\App\Http\Controllers\ServiceController::class, 'action', [
            'service' => $service,
            'action' => (string) $request->input('action'),
        ]);
    }

    public function php(SoftwareManager $software)
    {
        return $this->data(['versions' => $software->phpVersions(), 'default' => config('gbx.default_php')]);
    }

    public function metrics(Request $request)
    {
        $hours = match ((string) $request->query('range', '24h')) {
            '1h' => 1,
            '6h' => 6,
            '7d' => 168,
            default => 24,
        };
        $rows = Metric::query()->where('created_at', '>=', now()->subHours($hours))->orderBy('id')->get();
        $step = max(1, (int) ceil($rows->count() / 360));

        return $this->data($rows->values()->filter(fn ($row, $i) => $i % $step === 0)->map(fn (Metric $m) => [
            'at' => $m->created_at->toIso8601String(),
            'cpu' => $m->cpu,
            'memory' => $m->memory,
            'disk' => $m->disk,
            'load1' => $m->load1,
            'net_rx' => $m->net_rx,
            'net_tx' => $m->net_tx,
        ])->values(), ['range' => $request->query('range', '24h'), 'points' => $rows->count()]);
    }
}
