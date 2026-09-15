<?php

namespace App\Http\Controllers;

use App\Models\CronJob;
use App\Services\CronManager;
use Illuminate\Http\Request;

class CronController extends Controller
{
    public function __construct(protected CronManager $cron) {}

    public function index()
    {
        return view('home.cron', [
            'jobs' => CronJob::query()->orderBy('id')->get(),
            'presets' => CronManager::PRESETS,
        ]);
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'schedule' => ['required', 'string', 'max:100'],
            'command' => ['required', 'string', 'max:4000'],
            'run_as' => ['required', 'string', 'max:32'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['schedule'] = preg_replace('/\s+/', ' ', trim($data['schedule']));
        if (! CronManager::validSchedule($data['schedule'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['schedule' => 'Invalid cron expression.']);
        }
        if (! CronManager::validUser($data['run_as'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['run_as' => 'Invalid system user.']);
        }
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }

    public function store(Request $request)
    {
        $job = CronJob::query()->create($this->validated($request));
        $sync = $this->cron->sync();
        if ($sync->failed()) {
            return $this->fail('Saved, but writing crontab failed: '.$sync->message());
        }
        $this->audit('cron', 'Created cron job "'.$job->name.'"', $job->schedule.' '.$job->command);

        return $this->ok('Cron job created');
    }

    public function update(Request $request, CronJob $cron)
    {
        $cron->update($this->validated($request));

        return $this->result($this->cron->sync(), 'Cron job updated', 'cron', $cron->name);
    }

    public function destroy(CronJob $cron)
    {
        $name = $cron->name;
        $this->cron->clearLog($cron);
        $cron->delete();

        return $this->result($this->cron->sync(), 'Cron job deleted', 'cron', $name);
    }

    public function run(CronJob $cron)
    {
        $cron->update(['last_run_at' => now()]);

        return $this->task($this->cron->runNow($cron), 'Cron job started');
    }

    public function log(CronJob $cron)
    {
        return $this->ok('ok', ['log' => $this->cron->log($cron)]);
    }

    public function clearLog(CronJob $cron)
    {
        return $this->result($this->cron->clearLog($cron), 'Log cleared');
    }
}
