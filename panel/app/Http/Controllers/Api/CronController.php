<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\CronController as PanelCron;
use App\Models\CronJob;
use App\Services\CronManager;
use Illuminate\Http\Request;

class CronController extends ApiController
{
    public static function resource(CronJob $job): array
    {
        return [
            'id' => $job->id,
            'name' => $job->name,
            'type' => $job->type,
            'type_name' => CronManager::TYPES[$job->type] ?? $job->type,
            'schedule' => $job->schedule,
            'cycles' => $job->cycles,
            'run_as' => $job->run_as,
            'keep' => $job->keep,
            'params' => $job->params,
            'is_active' => (bool) $job->is_active,
            'last_run_at' => $job->last_run_at?->toIso8601String(),
            'last_status' => $job->last_status,
            'last_duration' => $job->last_duration,
            'client_id' => $job->client_id,
            'notes' => $job->notes,
        ];
    }

    public function index(Request $request)
    {
        return $this->page(CronJob::query()->orderBy('id'), $request, fn (CronJob $job) => self::resource($job));
    }

    public function show(CronJob $cron)
    {
        return $this->data(self::resource($cron));
    }

    public function log(Request $request, CronJob $cron)
    {
        return $this->forward(PanelCron::class, 'log', ['lines' => $request->query('lines')], ['cron' => $cron]);
    }

    public function run(CronJob $cron)
    {
        return $this->forward(PanelCron::class, 'run', [], ['cron' => $cron]);
    }

    public function toggle(CronJob $cron)
    {
        return $this->forward(PanelCron::class, 'toggle', [], ['cron' => $cron]);
    }

    public function destroy(CronJob $cron)
    {
        return $this->forward(PanelCron::class, 'destroy', [], ['cron' => $cron]);
    }
}
