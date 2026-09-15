<?php

namespace App\Services\Ai\Tools;

use App\Models\CronJob;
use App\Services\CronManager;

class CronTools extends ToolGroup
{
    public function __construct(protected CronManager $cron) {}

    public function tools(): array
    {
        return [
            'list_cron_jobs' => self::tool('Cron jobs and scheduled tasks managed by the panel (id, type, cycle, command, user, active, last run and exit code).', self::params(), false, false, fn () => 'Listed cron jobs'),
            'get_cron_log' => self::tool('Recent output of a cron job.', self::params(['id' => self::int('Cron job id'), 'lines' => self::int('Lines, default 100')], ['id']), false, false, fn ($a) => 'Read log of cron #'.self::labelArg($a, 'id')),
            'manage_cron_job' => self::tool('Create, update, delete, enable, disable or run now a cron job. create/update only handle shell jobs (other types are edited in Home > Cron Jobs). schedule uses cron syntax (e.g. "*/5 * * * *", "0 3 * * *", "@daily").', self::params([
                'action' => self::str('Action', ['create', 'update', 'delete', 'enable', 'disable', 'run_now']),
                'id' => self::int('Cron job id (all actions except create)'),
                'name' => self::str('Name'),
                'schedule' => self::str('Cron expression'),
                'command' => self::str('Command'),
                'run_as' => self::str('System user, default root'),
            ], ['action']), true, false, fn ($a) => str_replace('_', ' ', ucfirst(self::labelArg($a, 'action'))).' cron '.(self::labelArg($a, 'name') ?: '#'.self::labelArg($a, 'id'))),
        ];
    }

    public function handle(string $name, array $a): mixed
    {
        if ($name === 'list_cron_jobs') {
            return CronJob::query()->orderBy('id')->get()->map(fn (CronJob $job) => $job->only(['id', 'name', 'type', 'schedule', 'command', 'run_as', 'is_active', 'last_run_at', 'last_status']) + ['cycle' => CronManager::describe($job)])->all();
        }

        if ($name === 'get_cron_log') {
            $job = CronJob::query()->find((int) $a['id']);

            return $job ? $this->cron->log($job, min(1000, (int) self::a($a, 'lines', 100))) : ['error' => 'Cron job not found'];
        }

        if ($name !== 'manage_cron_job') {
            return ['error' => "Unknown tool {$name}"];
        }

        $action = (string) $a['action'];
        $job = $action === 'create' ? null : CronJob::query()->find((int) self::a($a, 'id'));
        if ($action !== 'create' && ! $job) {
            return ['error' => 'Cron job not found. Use list_cron_jobs to get the id.'];
        }

        if (in_array($action, ['create', 'update'], true)) {
            $data = [
                'name' => self::a($a, 'name', $job?->name),
                'schedule' => preg_replace('/\s+/', ' ', (string) self::a($a, 'schedule', $job?->schedule)),
                'command' => self::a($a, 'command', $job?->command),
                'run_as' => self::a($a, 'run_as', $job?->run_as ?? 'root') ?: 'root',
            ];
            if (! $data['name'] || ! $data['command']) {
                return ['error' => 'name and command are required'];
            }
            if (! CronManager::validSchedule($data['schedule']) || ! CronManager::validUser($data['run_as'])) {
                return ['error' => 'Invalid cron expression or user'];
            }
            if ($job && $job->type !== 'shell' && self::a($a, 'command') !== null) {
                return ['error' => 'Only the command of shell jobs can be changed here.'];
            }
            if ($job?->type === 'shell' || ! $job) {
                if ($word = CronManager::forbidden((string) $data['command'])) {
                    return ['error' => "The command contains a forbidden command: {$word}"];
                }
            } else {
                unset($data['command']);
            }
            if (! $job || self::a($a, 'schedule') !== null) {
                // a new expression replaces the execution cycles
                $data['cycles'] = [CronManager::cycle(['type' => 'custom', 'expr' => $data['schedule']])];
            }
            $job = $job ? tap($job)->update($data) : CronJob::query()->create($data + ['type' => 'shell', 'is_active' => true]);

            return $this->shell($this->cron->sync(), "Cron job #{$job->id} saved") + ['id' => $job->id];
        }

        return match ($action) {
            'delete' => (function () use ($job) {
                $this->cron->clearLog($job);
                $job->delete();

                return $this->shell($this->cron->sync(), 'Cron job deleted');
            })(),
            'enable', 'disable' => (function () use ($job, $action) {
                $job->update(['is_active' => $action === 'enable']);

                return $this->shell($this->cron->sync(), 'Cron job '.$action.'d');
            })(),
            'run_now' => (function () use ($job) {
                return $this->queued($this->cron->runNow($job), "Cron job #{$job->id}");
            })(),
            default => ['error' => 'Unknown action'],
        };
    }
}
