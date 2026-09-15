@extends('layouts.app')

@section('title', $site->domain)

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/vendor/codemirror/codemirror.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vendor/codemirror/theme/material-darker.css') }}">
@endpush

@section('content')
    <div class="page-head">
        <div class="min-w-0">
            <div class="small mb-1"><a href="{{ route('websites.index') }}" class="text-muted"><i class="bi bi-arrow-left"></i> Websites</a></div>
            <h2 class="d-flex align-items-center gap-2 text-break">
                <span class="status-dot {{ $site->status === 'active' ? 'on' : 'off' }}"></span> {{ $site->domain }}
            </h2>
            <p>{{ $site->php_version ? 'PHP '.$site->php_version : 'Static site' }} &middot; <span class="font-mono">{{ $site->root_path }}</span></p>
        </div>
        <div class="actions">
            <a href="http{{ $site->ssl_enabled ? 's' : '' }}://{{ $site->domain }}" target="_blank" rel="noopener" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-up-right"></i> Visit</a>
            <a href="{{ route('files.index', ['path' => $site->root_path]) }}" class="btn btn-outline-secondary"><i class="bi bi-folder2-open"></i> Files</a>
            <button class="btn btn-outline-secondary" data-post="{{ route('security.antivirus.scan.website', $site) }}" data-confirm="Scan {{ $site->root_path }} for malware with ClamAV?"><i class="bi bi-bug"></i> Scan for malware</button>
            <button class="btn {{ $site->status === 'active' ? 'btn-outline-danger' : 'btn-success' }}" data-post="{{ route('websites.status', $site) }}" data-confirm="{{ $site->status === 'active' ? 'Stop' : 'Start' }} this website?" data-reload>
                <i class="bi {{ $site->status === 'active' ? 'bi-stop-fill' : 'bi-play-fill' }}"></i> {{ $site->status === 'active' ? 'Stop' : 'Start' }}
            </button>
        </div>
    </div>

    <div class="gbx-card">
        <div class="gbx-card-header py-0">
            <ul class="nav nav-tabs gbx-tabs border-0 w-100" id="siteTabs">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-general"><i class="bi bi-sliders"></i> General</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-ssl" data-hash="ssl"><i class="bi bi-shield-lock"></i> SSL</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-config" data-hash="config"><i class="bi bi-file-earmark-code"></i> Vhost config</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-logs" data-hash="logs"><i class="bi bi-journal-text"></i> Logs</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-resources" data-hash="resources"><i class="bi bi-database"></i> Databases &amp; FTP</button></li>
            </ul>
        </div>
        <div class="gbx-card-body">
            <div class="tab-content">
                {{-- General --}}
                <div class="tab-pane fade show active" id="tab-general">
                    <form data-ajax data-method="PUT" data-no-reset action="{{ route('websites.update', $site) }}" class="row g-3" style="max-width: 820px">
                        <div class="col-md-6">
                            <label class="form-label">Primary domain</label>
                            <input type="text" class="form-control font-mono" value="{{ $site->domain }}" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">PHP version</label>
                            <select name="php_version" class="form-select">
                                @foreach ($phpVersions as $v)
                                    <option value="{{ $v }}" @selected($site->php_version === $v)>PHP {{ $v }}</option>
                                @endforeach
                                <option value="" @selected(! $site->php_version)>Static (no PHP)</option>
                                @if ($site->php_version && ! in_array($site->php_version, $phpVersions, true))
                                    <option value="{{ $site->php_version }}" selected>PHP {{ $site->php_version }} (not installed)</option>
                                @endif
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Aliases</label>
                            <textarea name="aliases" class="form-control font-mono" rows="3">{{ implode("\n", $site->aliasList()) }}</textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Document root</label>
                            <input type="text" name="root_path" class="form-control font-mono" value="{{ $site->root_path }}" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Reverse proxy target <span class="cell-sub">(optional)</span></label>
                            <input type="text" name="proxy_target" class="form-control font-mono" value="{{ $site->proxy_target }}" placeholder="http://127.0.0.1:3000">
                            <div class="form-text">Send all requests (and WebSockets) to a local app such as Node.js/PM2 or a Docker container. PHP is not used when set.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <input type="text" name="notes" class="form-control" value="{{ $site->notes }}" maxlength="255">
                        </div>
                        <div class="col-12 d-flex justify-content-between align-items-center">
                            <span class="cell-sub">Created {{ $site->created_at->format('Y-m-d H:i') }}</span>
                            <button class="btn btn-primary" type="submit"><i class="bi bi-check2"></i> Save changes</button>
                        </div>
                    </form>
                </div>

                {{-- SSL --}}
                <div class="tab-pane fade" id="tab-ssl">
                    <div class="row g-4">
                        <div class="col-lg-5">
                            <div class="small-caps mb-2">Current certificate</div>
                            @if ($certificate)
                                <dl class="kv mb-3">
                                    <dt>Provider</dt><dd>{{ $site->ssl_provider === 'letsencrypt' ? "Let's Encrypt" : 'Custom' }}</dd>
                                    <dt>Subject</dt><dd class="font-mono small">{{ $certificate['subject'] }}</dd>
                                    <dt>Issuer</dt><dd class="small">{{ $certificate['issuer'] }}</dd>
                                    <dt>Expires</dt><dd>{{ $certificate['expires'] }}</dd>
                                    <dt>Domains</dt><dd class="font-mono small">{{ $certificate['san'] }}</dd>
                                </dl>
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="forceHttps" @checked($site->force_https)>
                                    <label class="form-check-label" for="forceHttps">Force HTTPS (redirect HTTP to HTTPS)</label>
                                </div>
                                <button class="btn btn-outline-danger btn-sm" data-post="{{ route('websites.ssl.disable', $site) }}" data-confirm="Disable SSL for this website? Visitors will use plain HTTP." data-danger data-reload><i class="bi bi-unlock"></i> Disable SSL</button>
                            @else
                                <div class="empty-state py-4 border rounded-3 border-soft">
                                    <div class="icon"><i class="bi bi-unlock"></i></div>
                                    <h3>No certificate installed</h3>
                                    <p class="mb-0">Issue a free Let's Encrypt certificate or paste your own.</p>
                                </div>
                            @endif
                        </div>
                        <div class="col-lg-7">
                            <ul class="nav nav-pills gbx-pills mb-3">
                                <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#ssl-le">Let's Encrypt</button></li>
                                <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#ssl-custom">Custom certificate</button></li>
                            </ul>
                            <div class="tab-content">
                                <div class="tab-pane fade show active" id="ssl-le">
                                    <form data-ajax data-no-reset data-keep-open action="{{ route('websites.ssl.issue', $site) }}" class="row g-3">
                                        <div class="col-12">
                                            <div class="alert alert-secondary small mb-0"><i class="bi bi-info-circle me-1"></i> The domain must resolve to this server (DNS A/AAAA record) and port 80 must be open. Aliases without a DNS record are skipped and listed in the task log. Certificates renew automatically.</div>
                                        </div>
                                        <div class="col-md-8">
                                            <label class="form-label">E-mail for expiry notices</label>
                                            <input type="email" name="email" class="form-control" value="{{ $sslEmail }}" required>
                                        </div>
                                        <div class="col-md-4 d-flex align-items-end">
                                            <div class="form-check form-switch mb-2">
                                                <input class="form-check-input" type="checkbox" name="include_aliases" id="inclAliases" checked>
                                                <label class="form-check-label" for="inclAliases">Include aliases</label>
                                            </div>
                                        </div>
                                        <div class="col-12 cell-sub font-mono">{{ implode(', ', array_merge([$site->domain], $site->aliasList())) }}</div>
                                        <div class="col-12"><button class="btn btn-primary" type="submit"><i class="bi bi-shield-check"></i> Issue certificate</button></div>
                                    </form>
                                </div>
                                <div class="tab-pane fade" id="ssl-custom">
                                    <form data-ajax data-reload action="{{ route('websites.ssl.custom', $site) }}" class="row g-3">
                                        <div class="col-12">
                                            <label class="form-label">Certificate (PEM, full chain)</label>
                                            <textarea name="certificate" class="form-control font-mono small" rows="6" placeholder="-----BEGIN CERTIFICATE-----" required></textarea>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Private key (PEM)</label>
                                            <textarea name="private_key" class="form-control font-mono small" rows="6" placeholder="-----BEGIN PRIVATE KEY-----" required></textarea>
                                        </div>
                                        <div class="col-12"><button class="btn btn-primary" type="submit"><i class="bi bi-upload"></i> Install certificate</button></div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Vhost --}}
                <div class="tab-pane fade" id="tab-config">
                    <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                        <span class="font-mono cell-sub" id="configPath"></span>
                        <div class="ms-auto d-flex gap-2">
                            <button class="btn btn-sm btn-outline-secondary" id="configReload"><i class="bi bi-arrow-counterclockwise"></i> Reload</button>
                            <button class="btn btn-sm btn-primary" id="configSave"><i class="bi bi-check2"></i> Save &amp; test</button>
                        </div>
                    </div>
                    <textarea id="configEditor"></textarea>
                    <div class="form-text mt-2">The configuration is validated with <code>apache2ctl configtest</code> and rolled back if invalid. Saving General or SSL settings regenerates this file.</div>
                </div>

                {{-- Logs --}}
                <div class="tab-pane fade" id="tab-logs">
                    <div class="d-flex gap-2 mb-2 flex-wrap">
                        <ul class="nav nav-pills gbx-pills" id="logType">
                            <li class="nav-item"><button class="nav-link active" data-type="error">Error log</button></li>
                            <li class="nav-item"><button class="nav-link" data-type="access">Access log</button></li>
                        </ul>
                        <button class="btn btn-sm btn-outline-secondary ms-auto" id="logRefresh"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                    </div>
                    <pre class="gbx-console" id="logOut" style="height: 60vh">Select a log.</pre>
                </div>

                {{-- Resources --}}
                <div class="tab-pane fade" id="tab-resources">
                    <div class="row g-4">
                        <div class="col-lg-6">
                            <div class="d-flex align-items-center mb-2"><div class="small-caps">Databases</div><a href="{{ route('databases.index') }}" class="btn btn-sm btn-ghost ms-auto">Manage</a></div>
                            <ul class="list-group">
                                @forelse ($site->databases as $db)
                                    <li class="list-group-item bg-transparent d-flex justify-content-between"><span class="font-mono">{{ $db->name }}</span><span class="cell-sub">{{ $db->username.'@'.$db->host }}</span></li>
                                @empty
                                    <li class="list-group-item bg-transparent text-muted">No linked databases.</li>
                                @endforelse
                            </ul>
                        </div>
                        <div class="col-lg-6">
                            <div class="d-flex align-items-center mb-2"><div class="small-caps">FTP accounts</div><a href="{{ route('ftp.index') }}" class="btn btn-sm btn-ghost ms-auto">Manage</a></div>
                            <ul class="list-group">
                                @forelse ($site->ftpAccounts as $ftp)
                                    <li class="list-group-item bg-transparent d-flex justify-content-between"><span class="font-mono">{{ $ftp->username }}</span><span class="cell-sub font-mono">{{ $ftp->path }}</span></li>
                                @empty
                                    <li class="list-group-item bg-transparent text-muted">No linked FTP accounts.</li>
                                @endforelse
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('vendor')
    <script src="{{ asset('assets/vendor/codemirror/codemirror.js') }}"></script>
    <script src="{{ asset('assets/vendor/codemirror/mode/nginx/nginx.js') }}"></script>
@endpush

@push('scripts')
<script>
$(function () {
    var editor = null, logType = 'error';

    function loadConfig() {
        GBX.get(@json(route('websites.config', $site))).done(function (r) {
            $('#configPath').text(r.path);
            if (!editor) {
                editor = CodeMirror.fromTextArea(document.getElementById('configEditor'), { mode: 'nginx', theme: 'material-darker', lineNumbers: true, indentUnit: 4 });
            }
            editor.setValue(r.content);
            setTimeout(function () { editor.refresh(); }, 50);
        });
    }

    function loadLog() {
        $('#logOut').text('Loading...');
        GBX.get(@json(route('websites.logs', $site)), { type: logType, lines: 500 }).done(function (r) {
            $('#logOut').text(r.content || 'The log is empty.').scrollTop(1e9);
        });
    }

    $('#siteTabs [data-bs-toggle=tab]').on('shown.bs.tab', function () {
        var target = $(this).data('bs-target');
        history.replaceState(null, '', $(this).data('hash') ? '#' + $(this).data('hash') : location.pathname);
        if (target === '#tab-config') { editor ? editor.refresh() : loadConfig(); }
        if (target === '#tab-logs') loadLog();
    });

    if (location.hash) {
        var $tab = $('#siteTabs [data-hash="' + location.hash.substring(1) + '"]');
        if ($tab.length) bootstrap.Tab.getOrCreateInstance($tab[0]).show();
    }

    $('#configReload').on('click', loadConfig);
    $('#configSave').on('click', function () {
        var $b = $(this);
        GBX.busy($b, true);
        GBX.post(@json(route('websites.config.save', $site)), { content: editor.getValue() }).done(function (r) { toastr.success(r.message); }).always(function () { GBX.busy($b, false); });
    });

    $('#logType').on('click', '[data-type]', function () {
        $('#logType .nav-link').removeClass('active');
        logType = $(this).addClass('active').data('type');
        loadLog();
    });
    $('#logRefresh').on('click', loadLog);

    $('#forceHttps').on('change', function () {
        var $c = $(this);
        GBX.post(@json(route('websites.ssl.force', $site)), { enabled: $c.is(':checked') ? 1 : 0 })
            .done(function (r) { toastr.success(r.message); })
            .fail(function () { $c.prop('checked', !$c.is(':checked')); });
    });

    $(document).on('gbx:task-finished', function (e, task) { if (task.status === 'success') setTimeout(function () { location.reload(); }, 1500); });
});
</script>
@endpush
