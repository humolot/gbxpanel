@extends('client.layout')

@section('title', 'Website')

@section('content')
    <div class="page-head">
        <div>
            <h2>Websites</h2>
            <p>{{ $sites->count() }} of {{ $client->limit('max_websites') === 0 ? 'unlimited' : max(0, $client->limit('max_websites')) }} websites in your package.</p>
        </div>
        <div class="actions">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#siteModal" @disabled(! $canAdd)><i class="bi bi-plus-lg"></i> Add website</button>
        </div>
    </div>

    <div class="gbx-card">
        <div class="gbx-card-body p-0">
            @if ($sites->isEmpty())
                <div class="empty-state">
                    <div class="icon"><i class="bi bi-globe2"></i></div>
                    <h3>No websites yet</h3>
                    <p>Create your first website and upload the files with FTP.</p>
                    @if ($canAdd)<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#siteModal"><i class="bi bi-plus-lg"></i> Add website</button>@endif
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover db-table">
                        <thead><tr><th>Site name</th><th>Status</th><th>Requests (24h)</th><th>PHP</th><th>SSL</th><th>Databases / FTP</th><th class="text-end">Operate</th></tr></thead>
                        <tbody>
                        @foreach ($sites as $site)
                            @php $days = $site->ssl_expires_at ? (int) now()->diffInDays($site->ssl_expires_at, false) : null; @endphp
                            <tr data-id="{{ $site->id }}" data-domain="{{ $site->domain }}">
                                <td>
                                    <a href="http{{ $site->ssl_enabled ? 's' : '' }}://{{ $site->domain }}" target="_blank" rel="noopener" class="cell-strong text-decoration-none">{{ $site->domain }}</a>
                                    <div class="cell-sub font-mono">{{ $site->root_path }}</div>
                                </td>
                                <td>
                                    <a href="#" class="site-status text-decoration-none {{ $site->status === 'active' ? 'text-success' : 'text-danger' }}" title="Click to {{ $site->status === 'active' ? 'stop' : 'start' }}">
                                        <span class="status-dot {{ $site->status === 'active' ? 'on' : 'off' }}"></span> {{ $site->status === 'active' ? 'Running' : 'Stopped' }}
                                    </a>
                                </td>
                                <td>{{ number_format($traffic[$site->id]['total'] ?? 0) }}</td>
                                <td>
                                    <select class="form-select form-select-sm w-auto site-php">
                                        <option value="" @selected(! $site->php_version)>Static</option>
                                        @foreach ($phpVersions as $v)
                                            <option value="{{ $v }}" @selected($site->php_version === $v)>PHP {{ $v }}</option>
                                        @endforeach
                                        @if ($site->php_version && ! in_array($site->php_version, $phpVersions, true))
                                            <option value="{{ $site->php_version }}" selected disabled>PHP {{ $site->php_version }}</option>
                                        @endif
                                    </select>
                                </td>
                                <td>
                                    @if ($site->ssl_enabled)
                                        <span class="badge {{ $days !== null && $days < 15 ? 'badge-warning' : 'badge-success' }}">{{ $days !== null ? $days.' days' : 'Enabled' }}</span>
                                    @else
                                        <span class="badge badge-soft">Not set</span>
                                    @endif
                                </td>
                                <td class="small">{{ $site->databases_count }} / {{ $site->ftp_accounts_count }}</td>
                                <td class="text-end text-nowrap db-ops">
                                    @if ($client->package?->allow_ssl)<a href="#" class="site-ssl">SSL</a>@endif
                                    <a href="#" class="site-logs">Logs</a>
                                    <a href="#" class="site-delete text-danger">Delete</a>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection

@push('modals')
    <div class="modal fade" id="siteModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" data-ajax data-reload action="{{ route('client.websites.store') }}">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-globe2"></i> Add website</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Domain</label>
                        <input type="text" name="domain" class="form-control font-mono" placeholder="example.com" required autocomplete="off" autocapitalize="off">
                        <div class="form-text">Point the domain (A record) to this server.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Additional domains (aliases)</label>
                        <textarea name="aliases" class="form-control font-mono" rows="2" placeholder="one per line"></textarea>
                    </div>
                    <div class="row g-3">
                        <div class="col-7">
                            <label class="form-label">PHP version</label>
                            <select name="php_version" class="form-select">
                                @foreach ($phpVersions as $v)
                                    <option value="{{ $v }}" @selected($v === $defaultPhp)>PHP {{ $v }}</option>
                                @endforeach
                                <option value="">Static (no PHP)</option>
                            </select>
                        </div>
                        <div class="col-5 d-flex align-items-end">
                            <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="add_www" id="addWww" checked><label class="form-check-label" for="addWww">Add www</label></div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label">Notes</label>
                        <input type="text" name="notes" class="form-control" maxlength="255">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" data-busy="Creating">Create website</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="logModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-journal-text"></i> <span id="logTitle"></span></h5>
                    <div class="btn-group btn-group-sm ms-auto me-2">
                        <button class="btn btn-outline-secondary active" data-log-type="access">Access log</button>
                        <button class="btn btn-outline-secondary" data-log-type="error">Error log</button>
                    </div>
                    <button type="button" class="btn-close ms-0" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body"><pre class="gbx-console" id="logBody" style="height:60vh"></pre></div>
            </div>
        </div>
    </div>
@endpush

@push('scripts')
<script>
$(function () {
    var base = @json(url('/client/websites')), sslEmail = @json($sslEmail);
    var row = function (el) { return $(el).closest('tr'); };

    $('.site-status').on('click', function (e) {
        e.preventDefault();
        var $r = row(this);
        GBX.post(base + '/' + $r.data('id') + '/status').done(function (res) { toastr.success(res.message); setTimeout(function () { location.reload(); }, 600); });
    });
    $('.site-php').on('change', function () {
        var $r = row(this), $s = $(this);
        GBX.post(base + '/' + $r.data('id') + '/php', { php_version: $s.val() }).done(function (res) { toastr.success(res.message); }).fail(function () { setTimeout(function () { location.reload(); }, 1200); });
    });
    $('.site-ssl').on('click', function (e) {
        e.preventDefault();
        var $r = row(this);
        GBX.confirm({ title: "Let's Encrypt SSL", html: '<p class="small">Issue a free certificate for <strong>' + GBX.escape($r.data('domain')) + '</strong> and its aliases. The domain must point to this server.</p>',
            input: 'email', inputValue: sslEmail || '', inputPlaceholder: 'E-mail for expiry notices', icon: null, confirmText: 'Issue certificate',
            inputValidator: function (v) { if (!v) return 'Enter an e-mail'; } }).then(function (r) {
            if (r.isConfirmed) GBX.post(base + '/' + $r.data('id') + '/ssl', { email: r.value }, { onTaskDone: function () { $(document).one('hidden.bs.modal', '#taskModal', function () { location.reload(); }); } });
        });
    });

    var logSite = null;
    var loadLog = function (type) {
        $('#logBody').text('Loading...');
        GBX.get(base + '/' + logSite + '/logs', { type: type, lines: 500 }).done(function (r) { var $b = $('#logBody').text(r.content || 'The log is empty.'); $b.scrollTop($b[0].scrollHeight); });
    };
    $('.site-logs').on('click', function (e) {
        e.preventDefault();
        var $r = row(this);
        logSite = $r.data('id');
        $('#logTitle').text($r.data('domain'));
        $('[data-log-type]').removeClass('active').first().addClass('active');
        bootstrap.Modal.getOrCreateInstance('#logModal').show();
        loadLog('access');
    });
    $('[data-log-type]').on('click', function () { $(this).addClass('active').siblings().removeClass('active'); loadLog($(this).data('log-type')); });

    $('.site-delete').on('click', function (e) {
        e.preventDefault();
        var $r = row(this);
        GBX.confirm({
            title: 'Delete ' + $r.data('domain') + '?', danger: true, confirmText: 'Delete website',
            html: '<p class="small">The website stops answering. Choose what else to delete:</p><div class="text-start d-inline-block small">' +
                '<div class="form-check"><input class="form-check-input" type="checkbox" id="delFiles"><label class="form-check-label" for="delFiles">Website files</label></div>' +
                '<div class="form-check"><input class="form-check-input" type="checkbox" id="delDbs"><label class="form-check-label" for="delDbs">Linked databases</label></div>' +
                '<div class="form-check"><input class="form-check-input" type="checkbox" id="delFtp"><label class="form-check-label" for="delFtp">Linked FTP accounts</label></div></div>',
            preConfirm: function () { return { delete_files: $('#delFiles').is(':checked') ? 1 : 0, delete_databases: $('#delDbs').is(':checked') ? 1 : 0, delete_ftp: $('#delFtp').is(':checked') ? 1 : 0 }; }
        }).then(function (r) {
            if (r.isConfirmed) GBX.del(base + '/' + $r.data('id'), r.value).done(function (res) { toastr.success(res.message); setTimeout(function () { location.reload(); }, 600); });
        });
    });
});
</script>
@endpush
