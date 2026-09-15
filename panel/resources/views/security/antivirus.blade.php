@extends('layouts.app')

@section('title', 'Antivirus')

@section('content')
    <div class="page-head">
        <div>
            <h2>Antivirus</h2>
            <p>ClamAV malware scanning for uploads and website folders, with quarantine.</p>
        </div>
        <div class="actions">@include('security._nav')</div>
    </div>

    @if (! $status['installed'])
        <div class="gbx-card">
            <div class="empty-state">
                <div class="icon"><i class="bi bi-bug"></i></div>
                <h3>ClamAV is not installed</h3>
                <p>Install the ClamAV engine, the clamd daemon and freshclam signature updates.<br>The daemon keeps signatures in memory and needs about 1.5 GB of RAM.</p>
                <button class="btn btn-primary" data-post="{{ route('home.software.install') }}" data-payload='{"key":"clamav"}' data-confirm="Install ClamAV now? The first signature download can take a few minutes."><i class="bi bi-download"></i> Install ClamAV</button>
            </div>
        </div>
    @else
        <div class="row g-3 mb-3">
            <div class="col-md-6 col-xl-3">
                <div class="stat-tile">
                    <div class="icon"><i class="bi bi-cpu"></i></div>
                    <div class="min-w-0">
                        <div class="label">Engine</div>
                        <div class="fw-semibold text-truncate">{{ $status['engine'] ?? 'Unknown' }}</div>
                        <div class="cell-sub"><span class="status-dot {{ $status['daemon'] ? 'on' : 'off' }} me-1"></span>{{ $status['daemon'] ? 'clamd running' : 'clamd not running (clamscan fallback)' }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="stat-tile">
                    <div class="icon"><i class="bi bi-database-check"></i></div>
                    <div class="min-w-0">
                        <div class="label">Signatures</div>
                        <div class="fw-semibold text-truncate">{{ $status['signatures_version'] ? 'Version '.$status['signatures_version'] : 'Unknown' }}</div>
                        <div class="cell-sub text-truncate">{{ $status['signatures_date'] ?? '-' }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="stat-tile">
                    <div class="icon"><i class="bi bi-shield-exclamation"></i></div>
                    <div class="min-w-0">
                        <div class="label">Open detections</div>
                        <div class="value {{ $status['open_detections'] ? 'text-danger' : '' }}">{{ $status['open_detections'] }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="stat-tile">
                    <div class="icon"><i class="bi bi-cloud-arrow-up"></i></div>
                    <div class="min-w-0">
                        <div class="label">Upload scanning</div>
                        <div class="fw-semibold">{!! $settings['scan_uploads'] ? '<span class="text-success">Enabled</span>' : '<span class="text-warning">Disabled</span>' !!}</div>
                        <div class="cell-sub">Schedule: {{ $settings['schedule'] }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-xxl-8">
                {{-- Scan --}}
                <div class="gbx-card mb-3">
                    <div class="gbx-card-header">
                        <h2><i class="bi bi-search"></i> Scan</h2>
                        <div class="actions">
                            <button class="btn btn-sm btn-outline-secondary" data-post="{{ route('security.antivirus.signatures') }}" data-confirm="Download the latest virus signatures now?"><i class="bi bi-arrow-repeat"></i> Update signatures</button>
                        </div>
                    </div>
                    <div class="gbx-card-body">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label">Website</label>
                                <div class="input-group">
                                    <select class="form-select" id="avWebsite">
                                        @foreach ($websites as $w)
                                            <option value="{{ $w->id }}">{{ $w->domain }}</option>
                                        @endforeach
                                    </select>
                                    <button class="btn btn-secondary" id="avScanWebsite" @disabled($websites->isEmpty())><i class="bi bi-play-fill"></i> Scan</button>
                                </div>
                            </div>
                            <div class="col-md-7">
                                <label class="form-label">Any path</label>
                                <div class="input-group">
                                    <input type="text" class="form-control font-mono" id="avPath" value="{{ config('gbx.paths.www') }}">
                                    <button class="btn btn-primary" id="avScanPath"><i class="bi bi-search"></i> Scan path</button>
                                </div>
                            </div>
                        </div>
                        <div class="form-text mt-2">Directories are scanned in the background with <code>clamdscan --multiscan --fdpass</code>. Files uploaded through the file manager are streamed to clamd before they are saved.</div>
                    </div>
                </div>

                {{-- Detections --}}
                <div class="gbx-card mb-3">
                    <div class="gbx-card-header">
                        <h2><i class="bi bi-bug"></i> Detections</h2>
                        <div class="actions">
                            <ul class="nav nav-pills gbx-pills" id="detFilter">
                                <li class="nav-item"><button class="nav-link active" data-filter="open">Open</button></li>
                                <li class="nav-item"><button class="nav-link" data-filter="all">All</button></li>
                            </ul>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown" id="bulkBtn" disabled><i class="bi bi-check2-square"></i> Selected</button>
                                <div class="dropdown-menu dropdown-menu-end">
                                    <a class="dropdown-item bulk" href="#" data-action="quarantine"><i class="bi bi-lock"></i> Quarantine</a>
                                    <a class="dropdown-item bulk" href="#" data-action="ignore"><i class="bi bi-eye-slash"></i> Ignore (false positive)</a>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-danger bulk" href="#" data-action="delete"><i class="bi bi-trash"></i> Delete files</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="gbx-card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead><tr><th style="width:34px"><input type="checkbox" class="form-check-input" id="detAll"></th><th>File</th><th>Signature</th><th>Source</th><th>Status</th><th>Date</th><th class="text-end"></th></tr></thead>
                                <tbody>
                                @forelse ($detections as $d)
                                    @php
                                        $badge = ['detected' => 'badge-danger', 'quarantined' => 'badge-warning', 'blocked' => 'badge-info', 'deleted' => 'badge-soft', 'restored' => 'badge-soft', 'ignored' => 'badge-soft'][$d->status] ?? 'badge-soft';
                                    @endphp
                                    <tr class="det-row" data-status="{{ $d->status }}" @if($d->status !== 'detected') style="display:none" @endif>
                                        <td>@if($d->status === 'detected')<input type="checkbox" class="form-check-input det-check" value="{{ $d->id }}">@endif</td>
                                        <td><div class="font-mono small text-break" style="max-width:360px">{{ $d->path }}</div>@if($d->quarantine_path)<div class="cell-sub font-mono text-truncate" style="max-width:360px">{{ $d->quarantine_path }}</div>@endif</td>
                                        <td><span class="badge badge-danger font-mono">{{ $d->signature }}</span></td>
                                        <td class="cell-sub">{{ $d->source }}</td>
                                        <td><span class="badge {{ $badge }}">{{ $d->status }}</span></td>
                                        <td class="cell-sub text-nowrap">{{ $d->created_at->format('Y-m-d H:i') }}</td>
                                        <td class="table-actions">
                                            @if ($d->status === 'detected')
                                                <button class="btn btn-sm btn-outline-secondary det-action" data-id="{{ $d->id }}" data-action="quarantine"><i class="bi bi-lock"></i> Quarantine</button>
                                                <div class="dropdown d-inline-block">
                                                    <button class="btn btn-sm btn-ghost btn-icon" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
                                                    <div class="dropdown-menu dropdown-menu-end">
                                                        <a href="{{ route('files.index', ['path' => dirname($d->path)]) }}" class="dropdown-item"><i class="bi bi-folder2-open"></i> Open folder</a>
                                                        <a href="#" class="dropdown-item det-action" data-id="{{ $d->id }}" data-action="ignore"><i class="bi bi-eye-slash"></i> Ignore (false positive)</a>
                                                        <div class="dropdown-divider"></div>
                                                        <a href="#" class="dropdown-item text-danger det-action" data-id="{{ $d->id }}" data-action="delete"><i class="bi bi-trash"></i> Delete file</a>
                                                    </div>
                                                </div>
                                            @elseif ($d->status === 'quarantined')
                                                <button class="btn btn-sm btn-outline-secondary det-action" data-id="{{ $d->id }}" data-action="restore"><i class="bi bi-arrow-counterclockwise"></i> Restore</button>
                                                <button class="btn btn-sm btn-ghost btn-icon text-danger det-action" data-id="{{ $d->id }}" data-action="delete" title="Delete permanently"><i class="bi bi-trash"></i></button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="text-center text-muted py-4">No malware detected.</td></tr>
                                @endforelse
                                <tr id="detEmptyOpen" style="display:none"><td colspan="7" class="text-center text-muted py-4"><i class="bi bi-shield-check text-success me-1"></i> No open detections.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                {{-- Scan history --}}
                <div class="gbx-card">
                    <div class="gbx-card-header"><h2><i class="bi bi-clock-history"></i> Scan history</h2></div>
                    <div class="gbx-card-body p-0">
                        <div class="table-responsive">
                            <table class="table">
                                <thead><tr><th>Path</th><th>Result</th><th>Engine</th><th>Trigger</th><th>Started</th><th class="text-end"></th></tr></thead>
                                <tbody>
                                @forelse ($scans as $s)
                                    @php
                                        $badge = ['clean' => 'badge-success', 'infected' => 'badge-danger', 'error' => 'badge-warning', 'running' => 'badge-info', 'queued' => 'badge-soft'][$s->status] ?? 'badge-soft';
                                    @endphp
                                    <tr>
                                        <td class="font-mono small text-break">{{ $s->path }}</td>
                                        <td><span class="badge {{ $badge }}">{{ $s->status }}{{ $s->infected_count ? ' · '.$s->infected_count : '' }}</span></td>
                                        <td class="cell-sub">{{ $s->engine }}</td>
                                        <td class="cell-sub">{{ $s->trigger }}{{ $s->user ? ' · '.$s->user->username : '' }}</td>
                                        <td class="cell-sub text-nowrap">{{ $s->created_at->format('Y-m-d H:i') }}</td>
                                        <td class="table-actions">@if($s->task_id)<button class="btn btn-sm btn-ghost task-open" data-id="{{ $s->task_id }}" data-title="Malware scan {{ $s->path }}"><i class="bi bi-terminal"></i> Output</button>@endif</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted py-4">No scans yet.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xxl-4">
                <div class="gbx-card mb-3">
                    <div class="gbx-card-header"><h2><i class="bi bi-sliders"></i> Protection settings</h2></div>
                    <div class="gbx-card-body">
                        <form data-ajax data-no-reset data-reload action="{{ route('security.antivirus.settings') }}">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="scan_uploads" id="avUploads" @checked($settings['scan_uploads'])>
                                <label class="form-check-label" for="avUploads">Scan file manager uploads</label>
                                <div class="form-text">Infected uploads are rejected before they reach the website folder.</div>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="block_on_error" id="avBlockErr" @checked($settings['block_on_error'])>
                                <label class="form-check-label" for="avBlockErr">Block uploads when the scan fails</label>
                            </div>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="auto_quarantine" id="avAutoQ" @checked($settings['auto_quarantine'])>
                                <label class="form-check-label" for="avAutoQ">Quarantine detections automatically</label>
                                <div class="form-text">Files are moved to <code>{{ $status['quarantine'] }}</code> with mode 000.</div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-5">
                                    <label class="form-label">Scheduled scan</label>
                                    <select name="schedule" class="form-select">
                                        <option value="off" @selected($settings['schedule'] === 'off')>Off</option>
                                        <option value="daily" @selected($settings['schedule'] === 'daily')>Daily 03:30</option>
                                        <option value="weekly" @selected($settings['schedule'] === 'weekly')>Weekly (Sun 04:00)</option>
                                    </select>
                                </div>
                                <div class="col-7">
                                    <label class="form-label">Path</label>
                                    <input type="text" name="schedule_path" class="form-control font-mono" value="{{ $settings['schedule_path'] }}">
                                </div>
                            </div>
                            <button class="btn btn-primary w-100" type="submit"><i class="bi bi-check2"></i> Save settings</button>
                        </form>
                    </div>
                </div>

                <div class="gbx-card">
                    <div class="gbx-card-header"><h2><i class="bi bi-info-circle"></i> Engine</h2></div>
                    <div class="gbx-card-body">
                        <dl class="kv mb-3">
                            <dt>clamd</dt><dd><span class="status-dot {{ ($status['daemon_service']['active'] ?? false) ? 'on' : 'off' }} me-1"></span>{{ $status['daemon_service']['state'] ?? '-' }}</dd>
                            <dt>freshclam</dt><dd><span class="status-dot {{ ($status['freshclam_service']['active'] ?? false) ? 'on' : 'off' }} me-1"></span>{{ $status['freshclam_service']['state'] ?? '-' }}</dd>
                            <dt>Socket</dt><dd class="font-mono small">{{ $status['socket'] }}</dd>
                        </dl>
                        <div class="d-flex gap-2 flex-wrap">
                            <button class="btn btn-sm btn-outline-secondary" data-post="{{ route('home.services.action') }}" data-payload='{"service":"clamav-daemon","action":"restart"}' data-reload><i class="bi bi-arrow-clockwise"></i> Restart clamd</button>
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('files.index', ['path' => '/etc/clamav', 'edit' => '/etc/clamav/clamd.conf']) }}"><i class="bi bi-file-earmark-code"></i> clamd.conf</a>
                        </div>
                        <div class="alert alert-secondary small mt-3 mb-0">
                            Test the integration by uploading the <a href="https://www.eicar.org/download-anti-malware-testfile/" target="_blank" rel="noopener">EICAR test file</a> with the file manager: the upload must be blocked.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
<script>
$(function () {
    var R = { scan: @json(route('security.antivirus.scan')), website: @json(url('security/antivirus/scan/website')), action: @json(route('security.antivirus.action')) };
    var reloadOnFinish = function () { setTimeout(function () { location.reload(); }, 1200); };

    $('#avScanWebsite').on('click', function () {
        GBX.post(R.website + '/' + $('#avWebsite').val(), {}, { onTaskDone: reloadOnFinish });
    });
    $('#avScanPath').on('click', function () {
        var path = $('#avPath').val().trim();
        if (!path) return;
        GBX.confirm({ text: 'Scan ' + path + ' recursively? Large folders can take a while and use CPU.', confirmText: 'Scan' }).then(function (r) {
            if (r.isConfirmed) GBX.post(R.scan, { path: path }, { onTaskDone: reloadOnFinish }).done(function (res) { if (res.result) { (res.result.status === 'infected' ? toastr.error : toastr.success)(res.message); reloadOnFinish(); } });
        });
    });

    function applyFilter(filter) {
        var visible = 0;
        $('.det-row').each(function () {
            var show = filter === 'all' || $(this).data('status') === 'detected';
            $(this).toggle(show);
            if (show) visible++;
        });
        $('#detEmptyOpen').toggle(filter === 'open' && visible === 0 && $('.det-row').length > 0);
    }
    $('#detFilter').on('click', '[data-filter]', function () {
        $('#detFilter .nav-link').removeClass('active');
        applyFilter($(this).addClass('active').data('filter'));
    });
    applyFilter('open');

    function act(ids, action) {
        var go = function () {
            GBX.post(R.action, { ids: ids, action: action }).done(function (r) { toastr.success(r.message); reloadOnFinish(); });
        };
        if (action === 'delete') {
            GBX.confirm({ text: 'Permanently delete ' + ids.length + ' infected file(s)?', danger: true, confirmText: 'Delete' }).then(function (r) { if (r.isConfirmed) go(); });
        } else {
            go();
        }
    }

    $(document).on('click', '.det-action', function (e) { e.preventDefault(); act([$(this).data('id')], $(this).data('action')); });
    $('#detAll').on('change', function () { $('.det-row:visible .det-check').prop('checked', this.checked).trigger('change'); });
    $(document).on('change', '.det-check', function () { $('#bulkBtn').prop('disabled', $('.det-check:checked').length === 0); });
    $('.bulk').on('click', function (e) {
        e.preventDefault();
        act($('.det-check:checked').map(function () { return this.value; }).get(), $(this).data('action'));
    });
});
</script>
@endpush
