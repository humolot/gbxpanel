<?php

namespace App\Http\Controllers\Client;

use App\Models\Task;
use App\Services\SystemStats;
use App\Services\WebsiteTraffic;
use Illuminate\Http\Request;

class ClientDashboardController extends ClientPanelController
{
    public function index()
    {
        return view('client.overview', ['client' => $this->client()->load('package')]);
    }

    public function data(WebsiteTraffic $traffic)
    {
        $client = $this->client()->load('package');
        $sites = $client->websites()->get();

        // requests per hour over the last 24 hours (all websites of the client)
        $hours = array_fill(0, 24, 0);
        foreach ($traffic->hourly($sites) as $row) {
            foreach ($row['hours'] as $i => $count) {
                $hours[$i] += $count;
            }
        }
        $labels = [];
        for ($i = 23; $i >= 0; $i--) {
            $labels[] = date('H:00', time() - $i * 3600);
        }
        $todayHours = (int) date('G') + 1;

        $days = [];
        $usage = $client->usage()->where('day', '>=', now()->subDays(29)->toDateString())->get()->keyBy(fn ($u) => substr((string) $u->day, 0, 10));
        $lastDisk = null;
        for ($i = 29; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $row = $usage->get($day);
            $lastDisk = $row?->disk ?? $lastDisk;
            $days[] = ['day' => $day, 'bandwidth' => (int) ($row?->bandwidth ?? 0), 'requests' => (int) ($row?->requests ?? 0), 'disk' => $lastDisk];
        }

        return $this->ok('ok', [
            'counts' => [
                'websites' => [$sites->count(), $client->limit('max_websites')],
                'ftp' => [$client->ftpAccounts()->count(), $client->limit('max_ftp')],
                'databases' => [$client->databases()->count(), $client->limit('max_databases')],
            ],
            'requests_today' => array_sum(array_slice($hours, 24 - $todayHours)),
            'requests_24h' => ['labels' => $labels, 'values' => $hours],
            'bandwidth' => ['used' => $client->bandwidth_used, 'limit' => $client->bandwidthLimitBytes(), 'used_h' => SystemStats::bytes($client->bandwidth_used, 1), 'limit_h' => $client->bandwidthLimitBytes() ? SystemStats::bytes($client->bandwidthLimitBytes(), 0) : null],
            'disk' => ['used' => $client->disk_used, 'limit' => $client->diskLimitBytes(), 'used_h' => SystemStats::bytes($client->disk_used, 1), 'limit_h' => $client->diskLimitBytes() ? SystemStats::bytes($client->diskLimitBytes(), 0) : null],
            'days' => $days,
            'updated' => $client->usage_updated_at?->diffForHumans(),
        ]);
    }

    /* ======================================================= background tasks */

    public function tasks()
    {
        $query = Task::query()->where('client_id', $this->client()->id);

        return response()->json([
            'data' => (clone $query)->latest('id')->limit(30)->get()->map(fn (Task $t) => [
                'id' => $t->id, 'title' => $t->title, 'status' => $t->status, 'user' => null, 'created' => $t->created_at->diffForHumans(),
            ]),
            'running' => (clone $query)->whereIn('status', ['queued', 'running'])->count(),
        ]);
    }

    public function taskShow(Request $request, Task $task)
    {
        $this->owned($task);
        $offset = max(0, (int) $request->query('offset', 0));
        $chunk = $task->output($offset);

        return response()->json([
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'exit_code' => $task->exit_code,
            'output' => mb_convert_encoding($chunk, 'UTF-8', 'UTF-8'),
            'offset' => $offset + strlen($chunk),
            'finished' => $task->isFinished(),
        ]);
    }
}
