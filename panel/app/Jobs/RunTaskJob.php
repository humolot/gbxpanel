<?php

namespace App\Jobs;

use App\Models\Task;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Symfony\Component\Process\Process;

class RunTaskJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $taskId) {}

    public function handle(): void
    {
        $task = Task::query()->find($this->taskId);
        if (! $task || $task->status !== 'queued') {
            return;
        }

        $log = $task->logFile();
        @mkdir(dirname($log), 0775, true);
        $fh = fopen($log, 'a');

        $task->update(['status' => 'running', 'started_at' => now()]);
        fwrite($fh, '==> '.$task->title.' ('.now()->toDateTimeString().")\n");

        $script = "set -o pipefail\n".$task->script."\n";
        $argv = ['/bin/bash', '-c', $script];
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            $argv = array_merge(['sudo', '-n', '-H'], $argv);
        }

        $process = new Process($argv, '/tmp', [
            'DEBIAN_FRONTEND' => 'noninteractive',
            'LC_ALL' => 'C.UTF-8',
            'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/snap/bin',
            'HOME' => '/root',
            'COMPOSER_ALLOW_SUPERUSER' => '1',
        ], null, $this->timeout);

        try {
            $exit = $process->run(function ($type, $buffer) use ($fh) {
                fwrite($fh, $buffer);
                fflush($fh);
            });
        } catch (\Throwable $e) {
            fwrite($fh, "\n[error] ".$e->getMessage()."\n");
            $exit = 1;
        }

        fwrite($fh, "\n==> Finished with exit code {$exit}\n");
        fclose($fh);

        $task->update([
            'status' => $exit === 0 ? 'success' : 'failed',
            'exit_code' => $exit,
            'finished_at' => now(),
        ]);

        \App\Services\TaskHooks::finished($task->fresh());
    }
}
