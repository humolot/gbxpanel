<?php

namespace App\Http\Controllers;

use App\Models\CronJob;
use App\Models\CronScript;
use App\Models\MysqlDatabase;
use App\Models\Task;
use App\Models\Website;
use App\Services\CronManager;
use App\Services\FileManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Home > Cron Jobs: scheduled jobs, task scheduling (script + condition + action) and the script library.
 */
class CronController extends Controller
{
    /** Types whose scripts need root: they read every site or write to the backup directory. */
    protected const ROOT_ONLY = ['site_backup', 'db_backup', 'path_backup', 'log_cut', 'free_memory'];

    public function __construct(protected CronManager $cron) {}

    public function index(Request $request)
    {
        $tab = in_array($request->query('tab'), ['jobs', 'flows', 'scripts'], true) ? $request->query('tab') : 'jobs';
        $this->cron->refreshStates();

        $jobs = CronJob::query()->orderByDesc('id')->get();
        $scripts = CronScript::query()->orderBy('category')->orderBy('name')->get();

        return view('home.cron', [
            'tab' => $tab,
            'jobs' => $jobs->where('type', '!=', 'flow')->values(),
            'flows' => $jobs->where('type', 'flow')->values(),
            'scripts' => $scripts,
            'types' => array_diff_key(CronManager::TYPES, ['flow' => 1]),
            'categories' => CronScript::CATEGORIES,
            'users' => $this->cron->users(),
            'websites' => Website::query()->orderBy('domain')->get(['id', 'domain']),
            'databases' => MysqlDatabase::query()->whereNull('server_id')->whereIn('engine', ['mysql', 'pgsql', 'mongodb'])->orderBy('engine')->orderBy('name')->get(['id', 'engine', 'name']),
            'forbidden' => 'shutdown, halt, poweroff, init 0, mkfs, passwd, chpasswd and --stdin',
        ]);
    }

    /* ======================================================================== jobs */

    protected function validated(Request $request, ?CronJob $job = null): array
    {
        $type = (string) $request->input('type', $job?->type ?? 'shell');
        $request->validate([
            'type' => ['nullable', Rule::in(array_keys(CronManager::TYPES))],
            'name' => ['required', 'string', 'max:100'],
            'run_as' => ['nullable', 'string', 'max:32'],
            'keep' => ['nullable', 'integer', 'min:1', 'max:365'],
            'notes' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'cycles' => ['nullable'],
            'schedule' => ['nullable', 'string', 'max:100'],
        ]);

        // execution cycles; a plain cron expression is accepted for compatibility
        $input = $request->input('cycles');
        if (is_string($input)) {
            $input = json_decode($input, true);
        }
        if (! $input && $request->filled('schedule')) {
            $input = [['type' => 'custom', 'expr' => $request->input('schedule')]];
        }
        if (! is_array($input) || ! $input || count($input) > 10) {
            throw ValidationException::withMessages(['cycles' => 'Add between one and ten execution cycles.']);
        }
        try {
            $cycles = array_map(fn ($c) => CronManager::cycle((array) $c), array_values($input));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['cycles' => $e->getMessage()]);
        }
        $cycles = array_values(array_intersect_key($cycles, array_unique(array_column($cycles, 'cron'))));

        $runAs = in_array($type, self::ROOT_ONLY, true) ? 'root' : ((string) $request->input('run_as') ?: 'root');
        if (! CronManager::validUser($runAs)) {
            throw ValidationException::withMessages(['run_as' => 'Invalid system user.']);
        }

        [$command, $params] = $this->typeData($request, $type);

        return [
            'name' => $request->input('name'),
            'type' => $type,
            'schedule' => $cycles[0]['cron'],
            'cycles' => $cycles,
            'command' => $command,
            'params' => $params,
            'run_as' => $runAs,
            'keep' => in_array($type, ['site_backup', 'db_backup', 'path_backup', 'log_cut'], true) ? (int) $request->input('keep', 3) : null,
            'notes' => $request->input('notes'),
            'is_active' => $request->boolean('is_active', $job?->is_active ?? true),
        ];
    }

    /** @return array{0: string, 1: array} command column and type parameters */
    protected function typeData(Request $request, string $type): array
    {
        $scriptRule = ['required', 'integer', Rule::exists('cron_scripts', 'id')];
        $website = ['nullable', function ($attr, $value, $fail) {
            if ($value !== null && $value !== 'all' && ! Website::query()->whereKey($value)->exists()) {
                $fail('Website not found.');
            }
        }];

        switch ($type) {
            case 'shell':
                $data = $request->validate(['command' => ['required', 'string', 'max:20000']]);
                if ($word = CronManager::forbidden($data['command'])) {
                    throw ValidationException::withMessages(['command' => "The script contains a forbidden command: {$word}"]);
                }

                return [str_replace("\r\n", "\n", $data['command']), []];

            case 'site_backup':
                $p = $request->validate(['website' => $website, 'databases' => ['nullable', 'boolean'], 'exclude' => ['nullable', 'string', 'max:500']]);
                $p = ['website' => $p['website'] ?? 'all', 'databases' => $request->boolean('databases', true), 'exclude' => (string) ($p['exclude'] ?? '')];

                return ['Backup website '.($p['website'] === 'all' ? 'all' : Website::query()->find($p['website'])->domain), $p];

            case 'db_backup':
                $p = $request->validate([
                    'engine' => ['nullable', Rule::in(['all', 'mysql', 'pgsql', 'mongodb'])],
                    'database' => ['nullable', function ($attr, $value, $fail) {
                        if ($value !== null && $value !== 'all' && ! MysqlDatabase::query()->whereKey($value)->whereNull('server_id')->exists()) {
                            $fail('Only databases on this server can be scheduled.');
                        }
                    }],
                ]);
                $p = ['engine' => $p['engine'] ?? 'all', 'database' => $p['database'] ?? 'all'];

                return ['Backup database '.($p['database'] === 'all' ? $p['engine'] : MysqlDatabase::query()->find($p['database'])->name), $p];

            case 'path_backup':
                $p = $request->validate(['path' => ['required', 'string', 'max:500', 'starts_with:/'], 'exclude' => ['nullable', 'string', 'max:500']]);
                $p['path'] = FileManager::normalize($p['path']);
                if (in_array($p['path'], ['/', '/proc', '/sys', '/dev', '/run'], true)) {
                    throw ValidationException::withMessages(['path' => 'This directory cannot be archived.']);
                }

                return ['Backup directory '.$p['path'], ['path' => $p['path'], 'exclude' => (string) ($p['exclude'] ?? '')]];

            case 'log_cut':
                $p = $request->validate(['website' => $website]);

                return ['Cut logs', ['website' => $p['website'] ?? 'all']];

            case 'url':
                $p = $request->validate(['url' => ['required', 'url:http,https', 'max:1000'], 'method' => ['nullable', Rule::in(['GET', 'POST'])], 'timeout' => ['nullable', 'integer', 'min:5', 'max:600']]);

                return [$p['url'], ['url' => $p['url'], 'method' => $p['method'] ?? 'GET', 'timeout' => (int) ($p['timeout'] ?? 60)]];

            case 'free_memory':
                return ['Release memory cache', []];

            case 'script':
                $p = $request->validate(['script_id' => $scriptRule, 'args' => ['nullable', 'string', 'max:500']]);

                return ['Script: '.CronScript::query()->find($p['script_id'])->name, ['script_id' => (int) $p['script_id'], 'args' => (string) ($p['args'] ?? '')]];

            case 'laravel':
                $p = $request->validate(['path' => ['required', 'string', 'max:500', 'starts_with:/'], 'php' => ['nullable', 'regex:/^\d\.\d$/']]);
                $p['path'] = FileManager::normalize($p['path']);

                return ['php artisan schedule:run in '.$p['path'], ['path' => $p['path'], 'php' => $p['php'] ?? null]];

            case 'flow':
                $p = $request->validate([
                    'script_id' => $scriptRule,
                    'args' => ['nullable', 'string', 'max:500'],
                    'condition' => ['required', Rule::in(['always', 'contains', 'not_contains', 'failed'])],
                    'match' => ['nullable', 'required_if:condition,contains,not_contains', 'string', 'max:200'],
                    'then_script_id' => ['nullable', 'integer', Rule::exists('cron_scripts', 'id')],
                    'then_args' => ['nullable', 'string', 'max:500'],
                    'webhook' => ['nullable', 'url:http,https', 'max:1000'],
                ]);
                if (empty($p['then_script_id']) && empty($p['webhook'])) {
                    throw ValidationException::withMessages(['then_script_id' => 'Choose a script to run or a webhook to call when the condition is met.']);
                }
                $p['script_id'] = (int) $p['script_id'];
                $p['then_script_id'] = ! empty($p['then_script_id']) ? (int) $p['then_script_id'] : null;

                return ['Flow: '.CronScript::query()->find($p['script_id'])->name, array_map(fn ($v) => $v ?? '', $p)];
        }

        throw ValidationException::withMessages(['type' => 'Unknown task type.']);
    }

    protected function synced(string $message, string $details)
    {
        $sync = $this->cron->sync();
        if ($sync->failed()) {
            return $this->fail('Saved, but the crontab could not be written: '.$sync->message());
        }
        $this->audit('cron', $message, $details);

        return $this->ok($message);
    }

    public function store(Request $request)
    {
        $job = CronJob::query()->create($this->validated($request));

        return $this->synced(($job->isFlow() ? 'Scheduled task' : 'Cron job').' "'.$job->name.'" created', CronManager::describe($job).' - '.$job->command);
    }

    public function update(Request $request, CronJob $cron)
    {
        $cron->update($this->validated($request, $cron));

        return $this->synced(($cron->isFlow() ? 'Scheduled task' : 'Cron job').' "'.$cron->name.'" updated', CronManager::describe($cron).' - '.$cron->command);
    }

    public function show(CronJob $cron)
    {
        return $this->ok('ok', ['job' => ['cycles' => CronManager::cyclesOf($cron)] + $cron->only(['id', 'name', 'type', 'command', 'params', 'run_as', 'keep', 'notes', 'is_active'])]);
    }

    public function toggle(CronJob $cron)
    {
        $cron->update(['is_active' => ! $cron->is_active]);

        return $this->synced('Cron job "'.$cron->name.'" '.($cron->is_active ? 'enabled' : 'disabled'), CronManager::describe($cron));
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
        return $this->task($this->cron->runNow($cron), 'Cron job started');
    }

    public function bulk(Request $request)
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:500'], 'ids.*' => ['integer'], 'action' => ['required', Rule::in(['enable', 'disable', 'delete', 'run'])]]);
        $jobs = CronJob::query()->whereKey($data['ids'])->get();
        if ($jobs->isEmpty()) {
            return $this->fail('Select at least one job.');
        }

        if ($data['action'] === 'run') {
            if ($jobs->count() > 20) {
                return $this->fail('Run at most 20 jobs at once.');
            }
            foreach ($jobs as $job) {
                $last = $this->cron->runNow($job);
            }

            return $jobs->count() === 1 ? $this->task($last, 'Cron job started') : $this->ok($jobs->count().' jobs started. Follow them in the task list.');
        }

        foreach ($jobs as $job) {
            if ($data['action'] === 'delete') {
                $this->cron->clearLog($job);
                $job->delete();
            } else {
                $job->update(['is_active' => $data['action'] === 'enable']);
            }
        }

        return $this->result($this->cron->sync(), $jobs->count().' job(s): '.$data['action'], 'cron', $jobs->pluck('name')->implode(', '));
    }

    public function log(CronJob $cron)
    {
        return $this->ok('ok', ['log' => $this->cron->log($cron), 'file' => $cron->logFile()]);
    }

    public function clearLog(CronJob $cron)
    {
        return $this->result($this->cron->clearLog($cron), 'Log cleared');
    }

    /* ============================================================= import / export */

    public function export(Request $request)
    {
        $query = CronJob::query()->orderBy('id');
        if ($request->filled('ids')) {
            $query->whereKey(array_map('intval', explode(',', (string) $request->query('ids'))));
        }
        $scripts = [];
        $jobs = $query->get()->map(function (CronJob $job) use (&$scripts) {
            foreach (['script_id', 'then_script_id'] as $key) {
                if ($id = $job->param($key)) {
                    $script = CronScript::query()->find($id);
                    if ($script) {
                        $scripts[$script->id] = $script->only(['id', 'name', 'category', 'language', 'content', 'remark', 'success_match', 'args_hint']);
                    }
                }
            }

            return ['cycles' => CronManager::cyclesOf($job)] + $job->only(['name', 'type', 'command', 'params', 'run_as', 'keep', 'notes', 'is_active']);
        });

        $json = json_encode(['gbx_cron' => 1, 'exported_at' => now()->toIso8601String(), 'jobs' => $jobs, 'scripts' => array_values($scripts)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return response($json, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="gbx-cron-'.date('Ymd_His').'.json"',
        ]);
    }

    public function import(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'max:2048']]);
        $data = json_decode((string) file_get_contents($request->file('file')->getRealPath()), true);
        if (! is_array($data) || ! isset($data['jobs']) || ! is_array($data['jobs'])) {
            return $this->fail('This is not a cron export file.');
        }

        // scripts referenced by the jobs: reuse an identical one or create it
        $map = [];
        foreach ((array) ($data['scripts'] ?? []) as $s) {
            if (! is_array($s) || empty($s['name']) || ! isset($s['content'])) {
                continue;
            }
            $existing = CronScript::query()->where('name', $s['name'])->where('content', $s['content'])->first();
            $map[(int) ($s['id'] ?? 0)] = ($existing ?? CronScript::query()->create([
                'name' => mb_substr((string) $s['name'], 0, 100),
                'category' => array_key_exists($s['category'] ?? '', CronScript::CATEGORIES) ? $s['category'] : 'custom',
                'language' => array_key_exists($s['language'] ?? '', CronScript::LANGUAGES) ? $s['language'] : 'bash',
                'content' => (string) $s['content'],
                'remark' => mb_substr((string) ($s['remark'] ?? ''), 0, 255) ?: null,
                'success_match' => mb_substr((string) ($s['success_match'] ?? ''), 0, 255) ?: null,
                'args_hint' => mb_substr((string) ($s['args_hint'] ?? ''), 0, 255) ?: null,
            ]))->id;
        }

        $created = 0;
        $errors = [];
        foreach ($data['jobs'] as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $params = (array) ($row['params'] ?? []);
            foreach (['script_id', 'then_script_id'] as $key) {
                if (! empty($params[$key])) {
                    $params[$key] = $map[(int) $params[$key]] ?? $params[$key];
                }
            }
            try {
                $fake = Request::create('/', 'POST', $params + [
                    'name' => $row['name'] ?? '', 'type' => $row['type'] ?? 'shell', 'cycles' => $row['cycles'] ?? null,
                    'schedule' => $row['schedule'] ?? null, 'command' => $row['command'] ?? '', 'run_as' => $row['run_as'] ?? 'root',
                    'keep' => $row['keep'] ?? 3, 'notes' => $row['notes'] ?? null, 'is_active' => (bool) ($row['is_active'] ?? true),
                ]);
                CronJob::query()->create($this->validated($fake));
                $created++;
            } catch (ValidationException $e) {
                $errors[] = ($row['name'] ?? '#'.($i + 1)).': '.collect($e->errors())->flatten()->first();
            }
        }

        $sync = $this->cron->sync();
        if ($created) {
            $this->audit('cron', "Imported {$created} cron job(s)");
        }
        $message = "{$created} job(s) imported".($errors ? '. Skipped: '.implode('; ', array_slice($errors, 0, 5)) : '');

        return $sync->failed() ? $this->fail($message.'. The crontab could not be written: '.$sync->message()) : ($created ? $this->ok($message) : $this->fail($message));
    }

    /* ============================================================ script library */

    protected function validatedScript(Request $request, ?CronScript $script = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'category' => ['required', Rule::in(array_keys(CronScript::CATEGORIES))],
            'language' => ['required', Rule::in(array_keys(CronScript::LANGUAGES))],
            'content' => ['required', 'string', 'max:50000'],
            'remark' => ['nullable', 'string', 'max:255'],
            'success_match' => ['nullable', 'string', 'max:255'],
            'args_hint' => ['nullable', 'string', 'max:255'],
        ]);
        $data['content'] = str_replace("\r\n", "\n", $data['content']);
        if ($word = CronManager::forbidden($data['content'])) {
            throw ValidationException::withMessages(['content' => "The script contains a forbidden command: {$word}"]);
        }

        return $data;
    }

    public function scriptStore(Request $request)
    {
        $script = CronScript::query()->create($this->validatedScript($request));
        $this->audit('cron', 'Created script "'.$script->name.'"');

        return $this->ok('Script created', ['script' => $script->only(['id', 'name'])]);
    }

    public function scriptUpdate(Request $request, CronScript $script)
    {
        $script->update($this->validatedScript($request, $script));

        // jobs embed the script content: render them again
        return $this->synced('Script "'.$script->name.'" updated', $script->category);
    }

    public function scriptShow(CronScript $script)
    {
        return $this->ok('ok', ['script' => $script->only(['id', 'name', 'category', 'language', 'content', 'remark', 'success_match', 'args_hint', 'is_builtin'])]);
    }

    public function scriptDestroy(CronScript $script)
    {
        $used = CronJob::query()->whereIn('type', ['script', 'flow'])->get()
            ->filter(fn (CronJob $job) => in_array($script->id, [(int) $job->param('script_id'), (int) $job->param('then_script_id')], true));
        if ($used->isNotEmpty()) {
            return $this->fail('The script is used by: '.$used->pluck('name')->implode(', ').'. Delete those tasks first.');
        }
        $name = $script->name;
        $script->delete();
        $this->audit('cron', 'Deleted script "'.$name.'"');

        return $this->ok('Script deleted');
    }

    public function scriptRun(Request $request, CronScript $script)
    {
        $data = $request->validate(['args' => ['nullable', 'string', 'max:500']]);

        return $this->task($this->cron->runScript($script, (string) ($data['args'] ?? '')), 'Script started');
    }

    /** Output of the last execution of a library script. */
    public function scriptLog(CronScript $script)
    {
        $task = $script->last_task_id ? Task::query()->find($script->last_task_id) : null;
        if (! $task) {
            return $this->fail('This script has not been executed from the panel yet.');
        }

        return $this->ok('ok', ['task' => ['id' => $task->id, 'title' => $task->title, 'status' => $task->status]]);
    }
}
