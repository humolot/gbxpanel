<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Website;
use App\Services\Shell;
use Illuminate\Http\Request;

class LogController extends Controller
{
    /** Every readable log, grouped: key => [label, path]. */
    protected function sources(): array
    {
        $groups = [];
        foreach (config('gbx.log_files') as $group => $files) {
            foreach ($files as $key => $path) {
                $groups[$group][$key] = ['label' => basename($path), 'path' => $path];
            }
        }
        foreach (Website::query()->orderBy('domain')->get() as $site) {
            $groups['Websites']['site-error-'.$site->id] = ['label' => $site->domain.' (error)', 'path' => $site->logPath('error')];
            $groups['Websites']['site-access-'.$site->id] = ['label' => $site->domain.' (access)', 'path' => $site->logPath('access')];
        }
        $groups['Panel']['panel'] = ['label' => 'GBX Panel (laravel.log)', 'path' => storage_path('logs/laravel.log')];
        $groups['Panel']['worker'] = ['label' => 'Queue worker', 'path' => rtrim(config('gbx.root'), '/').'/logs/worker.log'];

        return $groups;
    }

    protected function find(string $key): ?array
    {
        foreach ($this->sources() as $files) {
            if (isset($files[$key])) {
                return $files[$key];
            }
        }

        return null;
    }

    public function index()
    {
        return view('logs.index', ['sources' => $this->sources()]);
    }

    public function read(Request $request)
    {
        $source = $this->find((string) $request->query('key'));
        if (! $source) {
            return $this->fail('Unknown log');
        }

        $lines = min(10000, max(50, (int) $request->query('lines', 500)));
        $search = trim((string) $request->query('search', ''));

        if (Shell::simulating()) {
            $content = collect(range(1, 40))->map(fn ($i) => now()->subMinutes(40 - $i)->format('M d H:i:s').' gbx-dev '.['systemd[1]: Started session.', 'sshd[812]: Accepted publickey for root', 'CRON[1022]: (root) CMD (run-parts /etc/cron.hourly)', 'apache2[640]: AH00558: Could not reliably determine the server name'][$i % 4])->implode("\n");
            $size = strlen($content);
        } else {
            $p = Shell::arg($source['path']);
            $cmd = 'tail -n '.$lines.' '.$p.' 2>&1';
            if ($search !== '') {
                $cmd = 'grep -i -F -- '.Shell::arg($search).' '.$p.' 2>/dev/null | tail -n '.$lines;
            }
            $content = Shell::run("[ -f {$p} ] && { {$cmd}; } || echo 'Log file not found: '".$p, 30)->output;
            $size = (int) Shell::out("stat -c %s {$p} 2>/dev/null", 10);
        }

        return $this->ok('ok', [
            'path' => $source['path'],
            'size' => $size,
            'content' => mb_convert_encoding($content, 'UTF-8', 'UTF-8'),
        ]);
    }

    public function clear(Request $request)
    {
        $source = $this->find((string) $request->input('key'));
        if (! $source) {
            return $this->fail('Unknown log');
        }

        return $this->result(Shell::run('truncate -s 0 '.Shell::arg($source['path']), 20), 'Log cleared', 'logs', $source['path']);
    }

    public function activity(Request $request)
    {
        $query = ActivityLog::query()->with('user:id,name,username')->latest('id');
        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        return response()->json([
            'data' => $query->limit(1000)->get()->map(fn ($a) => [
                'time' => $a->created_at?->format('Y-m-d H:i:s'),
                'user' => $a->user?->username ?? '-',
                'category' => $a->category,
                'action' => $a->action,
                'details' => $a->details,
                'ip' => $a->ip,
            ]),
        ]);
    }
}
