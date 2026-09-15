@extends('layouts.app')

@section('title', 'Cron Jobs')

@php
    $canWrite = auth()->user()->canWrite();
    $tabs = [
        'jobs' => ['Cron Job', 'bi-calendar2-week', $jobs->count()],
        'flows' => ['Task Scheduling', 'bi-diagram-3', $flows->count()],
        'scripts' => ['Script library', 'bi-code-square', $scripts->count()],
    ];
    $lastRun = function ($job) {
        if (! $job->last_run_at) {
            return '<span class="cell-sub">Never</span>';
        }
        $badge = $job->last_status === null ? '' : ($job->last_status === 0
            ? ' <span class="badge badge-success">OK</span>'
            : ' <span class="badge badge-danger" title="Exit code '.$job->last_status.'">Exit '.$job->last_status.'</span>');

        return '<span title="'.e($job->last_run_at->toDateTimeString()).'">'.e($job->last_run_at->format('Y-m-d H:i')).'</span>'.$badge
            .($job->last_duration !== null ? '<div class="cell-sub">'.e($job->last_duration < 60 ? $job->last_duration.'s' : intdiv($job->last_duration, 60).'m '.($job->last_duration % 60).'s').'</div>' : '');
    };
    $scriptNames = $scripts->pluck('name', 'id');
@endphp

@section('content')
    <div class="db-tabs">
        <nav class="db-tabs-nav">
            @foreach ($tabs as $key => [$tabLabel, $icon, $count])
                <a href="{{ route('home.cron', ['tab' => $key]) }}" class="{{ $key === $tab ? 'active' : '' }}"><i class="bi {{ $icon }}"></i> {{ $tabLabel }} <span class="cron-count">{{ $count }}</span></a>
            @endforeach
        </nav>
        <div class="db-tabs-meta">
            <span class="gbx-chip" title="Jobs are written to this file"><i class="bi bi-file-earmark-code"></i> /etc/cron.d/gbxpanel</span>
        </div>
    </div>

    @if ($tab === 'jobs')
        <div class="gbx-card">
            <div class="db-toolbar">
                @if ($canWrite)
                    <button class="btn btn-primary" data-cron-add="shell"><i class="bi bi-plus-lg"></i> Add Task</button>
                    <button class="btn btn-outline-secondary" id="cronImport"><i class="bi bi-upload"></i> Import</button>
                @endif
                <a class="btn btn-outline-secondary" href="{{ route('home.cron.export') }}" id="cronExport"><i class="bi bi-download"></i> Export</a>
                <div class="db-toolbar-right">
                    <select class="form-select" id="cronTypeFilter">
                        <option value="">All types</option>
                        @foreach ($types as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <div class="db-search"><input type="search" class="form-control" id="cronSearch" placeholder="Search tasks"><i class="bi bi-search"></i></div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover db-table cron-table" id="cronTable">
                    <thead>
                    <tr>
                        @if ($canWrite)<th class="ws-check"><input type="checkbox" class="form-check-input" data-check-all></th>@endif
                        <th>Name</th>
                        <th>Status</th>
                        <th>Execute cycle</th>
                        <th>Type</th>
                        <th>Number of save</th>
                        <th>Backup to</th>
                        <th>Last execute time</th>
                        <th class="text-end">Operate</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($jobs as $job)
                        <tr data-id="{{ $job->id }}" data-type="{{ $job->type }}" data-name="{{ $job->name }}" data-search="{{ strtolower($job->name.' '.$job->command.' '.$job->notes) }}">
                            @if ($canWrite)<td class="ws-check"><input type="checkbox" class="form-check-input cron-check" value="{{ $job->id }}"></td>@endif
                            <td>
                                <div class="cell-strong">{{ $job->name }}</div>
                                <div class="cell-sub text-truncate cron-sub" title="{{ $job->command }}">{{ $job->type === 'shell' ? \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $job->command), 70) : $job->command }}{{ $job->run_as !== 'root' ? ' - as '.$job->run_as : '' }}</div>
                            </td>
                            <td>
                                @if ($canWrite)
                                    <a href="#" class="cron-toggle {{ $job->is_active ? 'text-success' : 'text-danger' }}" title="Click to {{ $job->is_active ? 'disable' : 'enable' }}"><span class="status-dot {{ $job->is_active ? 'on' : 'off' }}"></span> {{ $job->is_active ? 'Running' : 'Stopped' }}</a>
                                @else
                                    <span class="{{ $job->is_active ? 'text-success' : 'text-danger' }}"><span class="status-dot {{ $job->is_active ? 'on' : 'off' }}"></span> {{ $job->is_active ? 'Running' : 'Stopped' }}</span>
                                @endif
                            </td>
                            <td class="cron-cycle-cell">
                                @foreach (\App\Services\CronManager::cyclesOf($job) as $cycle)
                                    <div title="{{ $cycle['cron'] ?? '' }}">{{ \App\Services\CronManager::describeCycle($cycle) }}</div>
                                @endforeach
                            </td>
                            <td><span class="badge badge-soft">{{ \App\Services\CronManager::TYPES[$job->type] ?? $job->type }}</span></td>
                            <td>{{ $job->keep ? $job->keep : '-' }}</td>
                            <td>{{ in_array($job->type, ['site_backup', 'db_backup', 'path_backup'], true) ? ($storages->firstWhere('id', (int) ($job->params['storage'] ?? 0))?->name ?? 'Local disk') : '-' }}</td>
                            <td class="text-nowrap">{!! $lastRun($job) !!}</td>
                            <td class="text-end text-nowrap db-ops">
                                @if ($canWrite)
                                    <a href="#" class="cron-run">Execute</a>
                                    <a href="#" class="cron-edit">Edit</a>
                                @endif
                                <a href="#" class="cron-log">Log</a>
                                @if ($canWrite)<a href="#" class="cron-delete text-danger">Delete</a>@endif
                            </td>
                        </tr>
                    @empty
                        <tr class="cron-empty"><td colspan="9" class="sm-empty"><i class="bi bi-inbox"></i> No cron jobs yet. Schedule backups, scripts, URL checks or log cutting with Add Task.</td></tr>
                    @endforelse
                    <tr class="cron-nomatch" hidden><td colspan="9" class="sm-empty"><i class="bi bi-search"></i> No matching tasks</td></tr>
                    </tbody>
                </table>
            </div>
            @if ($canWrite && $jobs->isNotEmpty())
                <div class="ws-bulk">
                    <span class="cell-sub" data-selected>0 selected</span>
                    <select class="form-select form-select-sm w-auto" data-bulk-action>
                        <option value="">Please choose</option>
                        <option value="run">Execute</option>
                        <option value="enable">Enable</option>
                        <option value="disable">Disable</option>
                        <option value="export">Export</option>
                        <option value="delete">Delete</option>
                    </select>
                    <button class="btn btn-sm btn-outline-secondary" data-bulk-run disabled>Execute</button>
                </div>
            @endif
        </div>
    @elseif ($tab === 'flows')
        <div class="db-notice cron-notice">
            <i class="bi bi-info-circle-fill"></i>
            <span>Task Scheduling runs a script from the library on a cycle and, when its output matches a condition, runs another script or calls a webhook. Example: check a service every 5 minutes and restart it when it is stopped.</span>
        </div>
        <div class="gbx-card">
            <div class="db-toolbar">
                @if ($canWrite)
                    <button class="btn btn-primary" data-cron-add="flow" @disabled($scripts->isEmpty())><i class="bi bi-plus-lg"></i> Add task scheduling</button>
                @endif
                <div class="db-toolbar-right">
                    <div class="db-search"><input type="search" class="form-control" id="cronSearch" placeholder="Search"><i class="bi bi-search"></i></div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover db-table cron-table" id="cronTable">
                    <thead>
                    <tr>
                        @if ($canWrite)<th class="ws-check"><input type="checkbox" class="form-check-input" data-check-all></th>@endif
                        <th>Name</th>
                        <th>Status</th>
                        <th>Execute cycle</th>
                        <th>Flow</th>
                        <th>Remark</th>
                        <th>Creation time</th>
                        <th>Last execute time</th>
                        <th class="text-end">Operate</th>
                    </tr>
                    </thead>
                    <tbody>
                    @php
                        $conditions = ['always' => 'always', 'contains' => 'output contains', 'not_contains' => 'output does not contain', 'failed' => 'the script fails'];
                    @endphp
                    @forelse ($flows as $job)
                        @php $p = $job->params ?? []; @endphp
                        <tr data-id="{{ $job->id }}" data-type="flow" data-name="{{ $job->name }}" data-search="{{ strtolower($job->name.' '.$job->notes.' '.($scriptNames[$p['script_id'] ?? 0] ?? '')) }}">
                            @if ($canWrite)<td class="ws-check"><input type="checkbox" class="form-check-input cron-check" value="{{ $job->id }}"></td>@endif
                            <td><div class="cell-strong">{{ $job->name }}</div>@if ($job->run_as !== 'root')<div class="cell-sub">as {{ $job->run_as }}</div>@endif</td>
                            <td>
                                @if ($canWrite)
                                    <a href="#" class="cron-toggle {{ $job->is_active ? 'text-success' : 'text-danger' }}"><span class="status-dot {{ $job->is_active ? 'on' : 'off' }}"></span> {{ $job->is_active ? 'Running' : 'Stopped' }}</a>
                                @else
                                    <span class="{{ $job->is_active ? 'text-success' : 'text-danger' }}">{{ $job->is_active ? 'Running' : 'Stopped' }}</span>
                                @endif
                            </td>
                            <td class="cron-cycle-cell">
                                @foreach (\App\Services\CronManager::cyclesOf($job) as $cycle)
                                    <div title="{{ $cycle['cron'] ?? '' }}">{{ \App\Services\CronManager::describeCycle($cycle) }}</div>
                                @endforeach
                            </td>
                            <td class="small cron-flow">
                                <div><span class="cell-sub">Run</span> {{ $scriptNames[$p['script_id'] ?? 0] ?? 'deleted script' }}</div>
                                <div><span class="cell-sub">When</span> {{ $conditions[$p['condition'] ?? 'always'] ?? '' }}@if (in_array($p['condition'] ?? '', ['contains', 'not_contains'], true)) <code>{{ $p['match'] ?? '' }}</code>@endif</div>
                                <div><span class="cell-sub">Then</span> {{ collect([! empty($p['then_script_id']) ? ($scriptNames[$p['then_script_id']] ?? 'deleted script') : null, ! empty($p['webhook']) ? 'webhook' : null])->filter()->implode(' + ') }}</div>
                            </td>
                            <td class="small">{{ $job->notes ?: '-' }}</td>
                            <td class="text-nowrap small">{{ $job->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="text-nowrap">{!! $lastRun($job) !!}</td>
                            <td class="text-end text-nowrap db-ops">
                                @if ($canWrite)
                                    <a href="#" class="cron-run">Execute</a>
                                    <a href="#" class="cron-edit">Edit</a>
                                @endif
                                <a href="#" class="cron-log">Log</a>
                                @if ($canWrite)<a href="#" class="cron-delete text-danger">Delete</a>@endif
                            </td>
                        </tr>
                    @empty
                        <tr class="cron-empty"><td colspan="9" class="sm-empty"><i class="bi bi-inbox"></i> No task scheduling yet</td></tr>
                    @endforelse
                    <tr class="cron-nomatch" hidden><td colspan="9" class="sm-empty"><i class="bi bi-search"></i> No matching tasks</td></tr>
                    </tbody>
                </table>
            </div>
            @if ($canWrite && $flows->isNotEmpty())
                <div class="ws-bulk">
                    <span class="cell-sub" data-selected>0 selected</span>
                    <select class="form-select form-select-sm w-auto" data-bulk-action>
                        <option value="">Please choose</option>
                        <option value="run">Execute</option>
                        <option value="enable">Enable</option>
                        <option value="disable">Disable</option>
                        <option value="delete">Delete</option>
                    </select>
                    <button class="btn btn-sm btn-outline-secondary" data-bulk-run disabled>Execute</button>
                </div>
            @endif
        </div>
    @else
        <div class="gbx-card">
            <div class="db-toolbar">
                @if ($canWrite)
                    <button class="btn btn-primary" id="scriptAdd"><i class="bi bi-plus-lg"></i> Create Script</button>
                @endif
                <nav class="cron-cats" id="scriptCats">
                    <a href="#" class="active" data-cat="">All <span>{{ $scripts->count() }}</span></a>
                    @foreach ($categories as $key => $label)
                        <a href="#" data-cat="{{ $key }}">{{ $label }} <span>{{ $scripts->where('category', $key)->count() }}</span></a>
                    @endforeach
                </nav>
                <div class="db-toolbar-right">
                    <div class="db-search"><input type="search" class="form-control" id="cronSearch" placeholder="Search scripts"><i class="bi bi-search"></i></div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover db-table cron-table" id="cronTable">
                    <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Success output</th>
                        <th>Remark</th>
                        <th>Create time</th>
                        <th>Last execute time</th>
                        <th class="text-end">Operate</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($scripts as $script)
                        <tr data-id="{{ $script->id }}" data-name="{{ $script->name }}" data-type="{{ $script->category }}" data-hint="{{ $script->args_hint }}" data-search="{{ strtolower($script->name.' '.$script->remark) }}">
                            <td>
                                <div class="cell-strong">{{ $script->name }}</div>
                                <div class="cell-sub">{{ $script->language }}{{ $script->is_builtin ? ' - built-in' : '' }}{{ $script->args_hint ? ' - argument: '.$script->args_hint : '' }}</div>
                            </td>
                            <td class="text-nowrap">{{ $categories[$script->category] ?? $script->category }}</td>
                            <td>@if ($script->success_match)<code>{{ $script->success_match }}</code>@else<span class="cell-sub">-</span>@endif</td>
                            <td class="small cron-remark">{{ $script->remark ?: '-' }}</td>
                            <td class="text-nowrap small">{{ $script->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="text-nowrap small">{{ $script->last_run_at?->format('Y-m-d H:i') ?? 'Never' }}</td>
                            <td class="text-end text-nowrap db-ops">
                                @if ($canWrite)
                                    <a href="#" class="script-run">Execute</a>
                                @endif
                                <a href="#" class="script-edit">{{ $canWrite ? 'Edit' : 'View' }}</a>
                                <a href="#" class="script-log">Log</a>
                                @if ($canWrite)<a href="#" class="script-delete text-danger">Delete</a>@endif
                            </td>
                        </tr>
                    @empty
                        <tr class="cron-empty"><td colspan="7" class="sm-empty"><i class="bi bi-inbox"></i> The library is empty</td></tr>
                    @endforelse
                    <tr class="cron-nomatch" hidden><td colspan="7" class="sm-empty"><i class="bi bi-search"></i> No matching scripts</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection

@push('modals')
    {{-- ===================================================== job / task scheduling --}}
    <div class="modal fade" id="cronModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <form class="modal-content" id="cronForm" autocomplete="off">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-calendar2-plus"></i> <span id="cronTitle">Add Task</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body cron-form">
                    <div class="cron-row" data-hide-flow>
                        <label>Task type</label>
                        <div>
                            <select name="type" class="form-select">
                                @foreach ($types as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="cron-row">
                        <label>Task name</label>
                        <div><input type="text" name="name" class="form-control" maxlength="100" required placeholder="Please enter the task name"></div>
                    </div>
                    <div class="cron-row">
                        <label>Execute cycle</label>
                        <div>
                            <div id="cronCycles"></div>
                            <button type="button" class="btn btn-sm btn-outline-secondary mt-1" id="cronAddCycle"><i class="bi bi-plus-lg"></i> Multiple executions</button>
                        </div>
                    </div>

                    {{-- shell --}}
                    <div data-section="shell">
                        <div class="cron-row">
                            <label>Script content</label>
                            <div>
                                <div class="d-flex gap-2 mb-2 flex-wrap">
                                    <select class="form-select form-select-sm w-auto" id="cronInsertScript">
                                        <option value="">Select script</option>
                                        @foreach ($categories as $cat => $catLabel)
                                            @if ($scripts->where('category', $cat)->where('language', 'bash')->isNotEmpty())
                                                <optgroup label="{{ $catLabel }}">
                                                    @foreach ($scripts->where('category', $cat)->where('language', 'bash') as $s)
                                                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                                                    @endforeach
                                                </optgroup>
                                            @endif
                                        @endforeach
                                    </select>
                                    <span class="cell-sub align-self-center">Inserts the script content into the editor</span>
                                </div>
                                <textarea name="command" class="form-control font-mono cron-code" rows="9" spellcheck="false" placeholder="Please enter the script content"></textarea>
                                <div class="cron-warning"><i class="bi bi-exclamation-triangle"></i> Forbidden commands: {{ $forbidden }}. Scripts run with bash; the output is written to the task log.</div>
                            </div>
                        </div>
                    </div>

                    {{-- website backup / log cutting --}}
                    <div data-section="site_backup log_cut">
                        <div class="cron-row">
                            <label>Website</label>
                            <div>
                                <select name="website" class="form-select">
                                    <option value="all">All websites</option>
                                    @foreach ($websites as $site)
                                        <option value="{{ $site->id }}">{{ $site->domain }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                    <div data-section="site_backup">
                        <div class="cron-row">
                            <label>Options</label>
                            <div>
                                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="databases" id="cronSiteDbs" checked><label class="form-check-label" for="cronSiteDbs">Also back up the databases linked to the website</label></div>
                            </div>
                        </div>
                    </div>

                    {{-- database backup --}}
                    <div data-section="db_backup">
                        <div class="cron-row">
                            <label>Database</label>
                            <div class="d-flex gap-2 flex-wrap">
                                <select name="engine" class="form-select w-auto">
                                    <option value="all">All engines</option>
                                    <option value="mysql">MySQL</option>
                                    <option value="pgsql">PostgreSQL</option>
                                    <option value="mongodb">MongoDB</option>
                                </select>
                                <select name="database" class="form-select flex-grow-1 w-auto">
                                    <option value="all" data-engine="">All databases</option>
                                    @foreach ($databases as $db)
                                        <option value="{{ $db->id }}" data-engine="{{ $db->engine }}">{{ $db->name }} ({{ $db->engine }})</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>

                    {{-- directory backup --}}
                    <div data-section="path_backup">
                        <div class="cron-row">
                            <label>Backup directory</label>
                            <div><input type="text" name="path" class="form-control font-mono" placeholder="/www/wwwroot/example.com/storage"></div>
                        </div>
                    </div>
                    <div data-section="site_backup path_backup">
                        <div class="cron-row">
                            <label>Exclude</label>
                            <div><input type="text" name="exclude" class="form-control font-mono" placeholder="node_modules, *.log, storage/cache"><div class="form-text">Comma separated patterns</div></div>
                        </div>
                    </div>
                    <div data-section="site_backup db_backup path_backup">
                        <div class="cron-row">
                            <label>Backup to</label>
                            <div>
                                <select name="storage" class="form-select" id="cronStorage">
                                    <option value="">Local disk only</option>
                                    @foreach ($storages as $storage)
                                        <option value="{{ $storage->id }}">{{ $storage->name }}</option>
                                    @endforeach
                                </select>
                                <div class="form-text">
                                    @if ($storages->isEmpty())
                                        <a href="{{ route('backup.index', ['tab' => 'storage']) }}">Add a storage</a> to send these backups to object storage, Google Drive, FTP or SFTP.
                                    @else
                                        The backup is made on this server first and then sent to the destination. Large archives are sent in parts and repeated transfers continue where they stopped.
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                    <div data-section="site_backup db_backup path_backup" class="cron-remote" hidden>
                        <div class="cron-row">
                            <label>Local copy</label>
                            <div>
                                <select name="storage_move" class="form-select">
                                    <option value="0">Keep it on this server</option>
                                    <option value="1">Delete it after a successful upload</option>
                                </select>
                            </div>
                        </div>
                        <div class="cron-row">
                            <label>Remote copies</label>
                            <div class="d-flex align-items-center gap-2"><input type="number" name="remote_keep" class="form-control cron-num" min="1" max="365" value="30"> <span class="cell-sub">newest copies are kept at the destination</span></div>
                        </div>
                    </div>
                    <div data-section="site_backup db_backup path_backup log_cut">
                        <div class="cron-row">
                            <label>Number of save</label>
                            <div class="d-flex align-items-center gap-2"><input type="number" name="keep" class="form-control cron-num" min="1" max="365" value="3"> <span class="cell-sub">newest copies are kept, older ones are deleted. Backups are stored in {{ rtrim(config('gbx.paths.backup'), '/') }}</span></div>
                        </div>
                    </div>

                    {{-- access URL --}}
                    <div data-section="url">
                        <div class="cron-row">
                            <label>URL address</label>
                            <div class="d-flex gap-2">
                                <select name="method" class="form-select w-auto"><option>GET</option><option>POST</option></select>
                                <input type="text" name="url" class="form-control font-mono" placeholder="https://example.com/cron.php">
                            </div>
                        </div>
                        <div class="cron-row">
                            <label>Timeout</label>
                            <div class="d-flex align-items-center gap-2"><input type="number" name="timeout" class="form-control cron-num" min="5" max="600" value="60"> <span class="cell-sub">seconds. The task fails when the status is not 2xx or 3xx.</span></div>
                        </div>
                    </div>

                    <div data-section="free_memory">
                        <div class="cron-row"><label></label><div class="cron-warning"><i class="bi bi-info-circle"></i> Writes pending data to disk and drops the page cache. Applications are not affected, but disk reads are slower until the cache warms up.</div></div>
                    </div>

                    {{-- library script / flow --}}
                    <div data-section="script flow">
                        <div class="cron-row">
                            <label>Script</label>
                            <div class="d-flex gap-2 flex-wrap">
                                <select name="script_id" class="form-select flex-grow-1 w-auto">
                                    @foreach ($categories as $cat => $catLabel)
                                        @if ($scripts->where('category', $cat)->isNotEmpty())
                                            <optgroup label="{{ $catLabel }}">
                                                @foreach ($scripts->where('category', $cat) as $s)
                                                    <option value="{{ $s->id }}" data-hint="{{ $s->args_hint }}" data-match="{{ $s->success_match }}">{{ $s->name }}</option>
                                                @endforeach
                                            </optgroup>
                                        @endif
                                    @endforeach
                                </select>
                                <input type="text" name="args" class="form-control w-auto flex-grow-1" placeholder="Arguments">
                            </div>
                        </div>
                    </div>
                    <div data-section="flow">
                        <div class="cron-row">
                            <label>Condition</label>
                            <div class="d-flex gap-2 flex-wrap">
                                <select name="condition" class="form-select w-auto">
                                    <option value="not_contains">Output does not contain</option>
                                    <option value="contains">Output contains</option>
                                    <option value="failed">Script fails (exit code not 0)</option>
                                    <option value="always">Always</option>
                                </select>
                                <input type="text" name="match" class="form-control w-auto flex-grow-1 font-mono" placeholder="Text to look for, e.g. running">
                            </div>
                        </div>
                        <div class="cron-row">
                            <label>Then run</label>
                            <div class="d-flex gap-2 flex-wrap">
                                <select name="then_script_id" class="form-select flex-grow-1 w-auto">
                                    <option value="">No script</option>
                                    @foreach ($categories as $cat => $catLabel)
                                        @if ($scripts->where('category', $cat)->isNotEmpty())
                                            <optgroup label="{{ $catLabel }}">
                                                @foreach ($scripts->where('category', $cat) as $s)
                                                    <option value="{{ $s->id }}" data-hint="{{ $s->args_hint }}">{{ $s->name }}</option>
                                                @endforeach
                                            </optgroup>
                                        @endif
                                    @endforeach
                                </select>
                                <input type="text" name="then_args" class="form-control w-auto flex-grow-1" placeholder="Arguments">
                            </div>
                        </div>
                        <div class="cron-row">
                            <label>Webhook</label>
                            <div><input type="text" name="webhook" class="form-control font-mono" placeholder="https://hooks.example.com/alert (optional)"><div class="form-text">Receives a JSON POST with task, host, exit_code and output when the condition is met.</div></div>
                        </div>
                    </div>

                    {{-- laravel scheduler --}}
                    <div data-section="laravel">
                        <div class="cron-row">
                            <label>Project path</label>
                            <div><input type="text" name="path" class="form-control font-mono" placeholder="/www/wwwroot/example.com" data-laravel-path></div>
                        </div>
                        <div class="cron-row">
                            <label>PHP version</label>
                            <div class="d-flex align-items-center gap-2"><input type="text" name="php" class="form-control cron-num" placeholder="8.4"> <span class="cell-sub">Leave empty for the default php binary. Use Every minute as the cycle.</span></div>
                        </div>
                    </div>

                    <div class="cron-row" data-user-row>
                        <label>Execute user</label>
                        <div>
                            <select name="run_as" class="form-select w-auto">
                                @foreach ($users as $user)
                                    <option value="{{ $user }}">{{ $user }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="cron-row">
                        <label>Remark</label>
                        <div><input type="text" name="notes" class="form-control" maxlength="255" placeholder="Optional"></div>
                    </div>
                    <div class="cron-row">
                        <label>Status</label>
                        <div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" id="cronActive" checked><label class="form-check-label" for="cronActive">Enabled</label></div></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Confirm</button>
                </div>
            </form>
        </div>
    </div>

    {{-- ================================================================ script --}}
    <div class="modal fade" id="scriptModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <form class="modal-content" id="scriptForm" autocomplete="off">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-code-square"></i> <span id="scriptTitle">Create Script</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body cron-form">
                    <div class="cron-row"><label>Name</label><div><input type="text" name="name" class="form-control" maxlength="100" required></div></div>
                    <div class="cron-row">
                        <label>Type</label>
                        <div class="d-flex gap-2 flex-wrap">
                            <select name="category" class="form-select w-auto">
                                @foreach ($categories as $key => $label)
                                    <option value="{{ $key }}" @selected($key === 'custom')>{{ $label }}</option>
                                @endforeach
                            </select>
                            <select name="language" class="form-select w-auto">
                                <option value="bash">bash</option>
                                <option value="python3">python3</option>
                                <option value="php">php</option>
                            </select>
                        </div>
                    </div>
                    <div class="cron-row">
                        <label>Script content</label>
                        <div>
                            <textarea name="content" class="form-control font-mono cron-code" rows="12" spellcheck="false" required></textarea>
                            <div class="form-text">Arguments are available as $1, $2 (bash), sys.argv (python3) or $argv (php).</div>
                        </div>
                    </div>
                    <div class="cron-row"><label>Success output</label><div><input type="text" name="success_match" class="form-control font-mono" maxlength="255" placeholder="Text found in the output when the script succeeds"></div></div>
                    <div class="cron-row"><label>Argument</label><div><input type="text" name="args_hint" class="form-control" maxlength="255" placeholder="Describe the argument, e.g. Service name"></div></div>
                    <div class="cron-row"><label>Remark</label><div><input type="text" name="remark" class="form-control" maxlength="255"></div></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Confirm</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="cronLogModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-journal-text"></i> <span id="cronLogTitle">Log</span></h5>
                    <button class="btn btn-sm btn-outline-secondary ms-auto me-2" id="cronLogRefresh"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                    @if ($canWrite)<button class="btn btn-sm btn-outline-secondary me-2" id="cronLogClear"><i class="bi bi-eraser"></i> Clear</button>@endif
                    <button type="button" class="btn-close ms-0" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body"><pre class="gbx-console" id="cronLogOut" style="height:60vh"></pre></div>
            </div>
        </div>
    </div>
@endpush

@push('scripts')
<script>
    window.GBX_CRON = {
        tab: @json($tab),
        canWrite: @json($canWrite),
        base: @json(url('/home/cron')),
        weekdays: @json(\App\Services\CronManager::WEEKDAYS),
        rootOnly: ['site_backup', 'db_backup', 'path_backup', 'log_cut', 'free_memory'],
        routes: {
            store: @json(route('home.cron.store')),
            bulk: @json(route('home.cron.bulk')),
            export: @json(route('home.cron.export')),
            import: @json(route('home.cron.import')),
            scripts: @json(route('home.cron.scripts.store'))
        }
    };
</script>
<script src="{{ asset('assets/js/cron.js') }}?v={{ config('gbx.version') }}"></script>
@endpush
