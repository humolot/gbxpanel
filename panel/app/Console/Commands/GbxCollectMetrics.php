<?php

namespace App\Console\Commands;

use App\Models\Metric;
use App\Models\Task;
use App\Services\SystemStats;
use Illuminate\Console\Command;

class GbxCollectMetrics extends Command
{
    protected $signature = 'gbx:collect-metrics';

    protected $description = 'Store a server metrics sample (runs every minute) and prune old data';

    public function handle(SystemStats $stats): int
    {
        $net1 = $stats->network();
        $stats->cpu(); // prime the CPU sample
        sleep(2);
        $net2 = $stats->network();
        $cpu = $stats->cpu();

        $disks = $stats->disks();
        $root = collect($disks)->firstWhere('mount', '/') ?? ($disks[0] ?? ['percent' => 0]);

        Metric::query()->create([
            'cpu' => $cpu['percent'],
            'memory' => $stats->memory()['percent'],
            'load1' => $stats->load()['one'],
            'disk' => $root['percent'],
            'net_rx' => max(0, (int) (($net2['rx'] - $net1['rx']) / 2)),
            'net_tx' => max(0, (int) (($net2['tx'] - $net1['tx']) / 2)),
        ]);

        // keep 8 days of metrics and 30 days of finished tasks
        Metric::query()->where('created_at', '<', now()->subDays(8))->delete();
        Task::query()->whereIn('status', ['success', 'failed'])->where('created_at', '<', now()->subDays(30))->get()
            ->each(function (Task $task) {
                @unlink($task->logFile());
                $task->delete();
            });

        return self::SUCCESS;
    }
}
