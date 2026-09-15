<?php

namespace App\Services;

use App\Jobs\RunTaskJob;
use App\Models\ActivityLog;
use App\Models\Task;

/**
 * Long running operations (apt installs, certbot, updates...) are stored as
 * tasks and executed by the root queue worker managed by supervisor.
 */
class TaskRunner
{
    public static function dispatch(string $title, string $script, string $type = 'shell', array $meta = []): Task
    {
        $task = Task::query()->create([
            'type' => $type,
            'title' => $title,
            'script' => $script,
            'status' => 'queued',
            'meta' => $meta,
            'user_id' => auth('web')->id(),
            'client_id' => \App\Services\Clients\ClientContext::id(),
        ]);

        ActivityLog::record('task', 'Queued: '.$title, 'Task #'.$task->id);

        if (Shell::simulating()) {
            static::simulate($task);
        } else {
            RunTaskJob::dispatch($task->id);
        }

        return $task;
    }

    /** Fake output for local development on non-Linux hosts. */
    protected static function simulate(Task $task): void
    {
        @mkdir(dirname($task->logFile()), 0775, true);
        $lines = [
            '[simulation] GBX Panel is not running on Linux, commands are not executed.',
            '$ '.strtok(trim($task->script), "\n"),
            'Reading package lists... Done',
            'Building dependency tree... Done',
            'Setting up packages... Done',
            'Task finished successfully.',
        ];
        file_put_contents($task->logFile(), ($task->meta['simulate_output'] ?? null) ?: implode("\n", $lines)."\n");
        $task->update(['status' => 'success', 'exit_code' => 0, 'started_at' => now(), 'finished_at' => now()]);
        TaskHooks::finished($task->fresh());
    }

    public static function running(): int
    {
        return Task::query()->whereIn('status', ['queued', 'running'])->count();
    }
}
