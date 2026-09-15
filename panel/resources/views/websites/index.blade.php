@extends('layouts.app')

@section('title', 'Websites')

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/vendor/datatables/dataTables.bootstrap5.min.css') }}">
@endpush

@section('content')
    <div class="page-head">
        <div>
            <h2>Websites</h2>
            <p>Apache virtual hosts with PHP-FPM, SSL certificates and logs.</p>
        </div>
        <div class="actions">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#siteModal"><i class="bi bi-plus-lg"></i> Add website</button>
        </div>
    </div>

    @if (empty($phpVersions))
        <div class="alert alert-warning d-flex align-items-center gap-2"><i class="bi bi-exclamation-triangle"></i> No PHP-FPM version detected. Websites will be created as static sites. <a href="{{ route('home.software') }}" class="ms-auto btn btn-sm btn-outline-secondary">Install PHP</a></div>
    @endif

    <div class="gbx-card">
        <div class="gbx-card-body p-0">
            @if ($websites->isEmpty())
                <div class="empty-state">
                    <div class="icon"><i class="bi bi-globe2"></i></div>
                    <h3>No websites yet</h3>
                    <p>Create your first website. Point the domain DNS (A record) to this server before requesting SSL.</p>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#siteModal"><i class="bi bi-plus-lg"></i> Add website</button>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover" id="sitesTable">
                        <thead><tr><th>Domain</th><th>Status</th><th>PHP</th><th>SSL</th><th>Root</th><th>Resources</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                        @foreach ($websites as $site)
                            <tr>
                                <td>
                                    <a href="{{ route('websites.show', $site) }}" class="cell-strong">{{ $site->domain }}</a>
                                    <a href="http{{ $site->ssl_enabled ? 's' : '' }}://{{ $site->domain }}" target="_blank" rel="noopener" class="text-muted ms-1" title="Open website"><i class="bi bi-box-arrow-up-right small"></i></a>
                                    <div class="cell-sub text-truncate" style="max-width:260px">{{ $site->aliases ?: 'No aliases' }}</div>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-ghost px-1" data-post="{{ route('websites.status', $site) }}" data-confirm="{{ $site->status === 'active' ? 'Stop' : 'Start' }} {{ $site->domain }}?" data-reload title="Toggle">
                                        <span class="status-dot {{ $site->status === 'active' ? 'on' : 'off' }}"></span> {{ $site->status === 'active' ? 'Running' : 'Stopped' }}
                                    </button>
                                </td>
                                <td>{!! $site->php_version ? '<span class="badge badge-soft font-mono">PHP '.$site->php_version.'</span>' : '<span class="badge badge-soft">Static</span>' !!}</td>
                                <td>
                                    @if ($site->ssl_enabled)
                                        @php $days = $site->ssl_expires_at ? (int) now()->diffInDays($site->ssl_expires_at, false) : null; @endphp
                                        <span class="badge {{ $days !== null && $days < 15 ? 'badge-warning' : 'badge-success' }}"><i class="bi bi-lock-fill"></i> {{ $days !== null ? $days.' days' : 'Enabled' }}</span>
                                    @else
                                        <a href="{{ route('websites.show', $site) }}#ssl" class="badge badge-soft"><i class="bi bi-unlock"></i> Not set</a>
                                    @endif
                                </td>
                                <td><a href="{{ route('files.index', ['path' => $site->root_path]) }}" class="font-mono small text-truncate d-inline-block" style="max-width:240px">{{ $site->root_path }}</a></td>
                                <td class="cell-sub text-nowrap"><i class="bi bi-database"></i> {{ $site->databases_count }} &nbsp; <i class="bi bi-folder-symlink"></i> {{ $site->ftp_accounts_count }}</td>
                                <td class="table-actions">
                                    <a href="{{ route('websites.show', $site) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-gear"></i> Manage</a>
                                    <button class="btn btn-sm btn-ghost btn-icon text-danger delete-site" data-url="{{ route('websites.destroy', $site) }}" data-domain="{{ $site->domain }}" title="Delete"><i class="bi bi-trash"></i></button>
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
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <form class="modal-content" data-ajax data-success="siteCreated" action="{{ route('websites.store') }}" id="siteForm">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-globe2"></i> Add website</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label">Domain</label>
                            <input type="text" name="domain" class="form-control font-mono" placeholder="example.com" required autocomplete="off" autocapitalize="off">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">PHP version</label>
                            <select name="php_version" class="form-select">
                                @foreach ($phpVersions as $v)
                                    <option value="{{ $v }}" @selected($v === $defaultPhp)>PHP {{ $v }}</option>
                                @endforeach
                                <option value="">Static (no PHP)</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Additional domains (aliases)</label>
                            <textarea name="aliases" class="form-control font-mono" rows="2" placeholder="one per line or separated by spaces"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Document root</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-folder2"></i></span>
                                <input type="text" name="root_path" class="form-control font-mono" placeholder="{{ $wwwRoot }}/example.com">
                            </div>
                            <div class="form-text">Leave empty to use {{ $wwwRoot }}/&lt;domain&gt;. For Laravel point it to the <code>public</code> folder.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Reverse proxy target <span class="cell-sub">(optional, for Node.js / Docker apps)</span></label>
                            <input type="text" name="proxy_target" class="form-control font-mono" placeholder="http://127.0.0.1:3000">
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="add_www" id="addWww" checked>
                                <label class="form-check-label" for="addWww">Add www alias</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="create_database" id="createDb" @disabled(! $mysql)>
                                <label class="form-check-label" for="createDb">Create database</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="create_ftp" id="createFtp">
                                <label class="form-check-label" for="createFtp">Create FTP account</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <input type="text" name="notes" class="form-control" maxlength="255">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" data-busy="Creating"><i class="bi bi-check2"></i> Create website</button>
                </div>
            </form>
        </div>
    </div>
@endpush

@push('vendor')
    <script src="{{ asset('assets/vendor/datatables/dataTables.min.js') }}"></script>
    <script src="{{ asset('assets/vendor/datatables/dataTables.bootstrap5.min.js') }}"></script>
@endpush

@push('scripts')
<script>
function siteCreated(res) {
    var html = '<p>The website is online.</p>';
    if (res.database) html += '<div class="text-start mb-2"><div class="small-caps mb-1">Database</div><div class="kv"><dt>Name</dt><dd class="font-mono">' + GBX.escape(res.database.name) + '</dd><dt>User</dt><dd class="font-mono">' + GBX.escape(res.database.username) + '</dd><dt>Password</dt><dd class="font-mono">' + GBX.escape(res.database.password) + '</dd></div></div>';
    if (res.ftp) html += '<div class="text-start mb-2"><div class="small-caps mb-1">FTP</div><div class="kv"><dt>User</dt><dd class="font-mono">' + GBX.escape(res.ftp.username) + '</dd><dt>Password</dt><dd class="font-mono">' + GBX.escape(res.ftp.password) + '</dd></div></div>';
    if (res.warnings) html += '<div class="alert alert-warning text-start small">' + res.warnings.map(GBX.escape).join('<br>') + '</div>';
    if (!res.database && !res.ftp && !res.warnings) { window.location.reload(); return; }
    Swal.fire({ title: 'Website created', html: html + '<p class="small text-muted mt-2">Save these credentials, passwords are not shown again in plain text here.</p>', icon: 'success', buttonsStyling: false, customClass: { confirmButton: 'btn btn-primary' } })
        .then(function () { window.location.reload(); });
}

$(function () {
    if ($('#sitesTable').length) {
        $('#sitesTable').DataTable({ order: [[0, 'asc']], columnDefs: [{ orderable: false, targets: [6] }] });
    }

    $('#siteForm [name=domain]').on('input', function () {
        var d = this.value.trim().toLowerCase();
        $('#siteForm [name=root_path]').attr('placeholder', @json($wwwRoot) + '/' + (d || 'example.com'));
    });

    $(document).on('click', '.delete-site', function () {
        var url = $(this).data('url'), domain = $(this).data('domain');
        GBX.confirm({
            title: 'Delete ' + domain + '?',
            danger: true,
            confirmText: 'Delete website',
            html: '<p>The virtual host is removed. Choose what else to delete:</p><div class="text-start d-inline-block">' +
                '<div class="form-check"><input class="form-check-input" type="checkbox" id="delFiles"><label class="form-check-label" for="delFiles">Website files (document root)</label></div>' +
                '<div class="form-check"><input class="form-check-input" type="checkbox" id="delDb"><label class="form-check-label" for="delDb">Linked databases</label></div>' +
                '<div class="form-check"><input class="form-check-input" type="checkbox" id="delFtp"><label class="form-check-label" for="delFtp">Linked FTP accounts</label></div></div>',
            preConfirm: function () { return { delete_files: $('#delFiles').is(':checked') ? 1 : 0, delete_databases: $('#delDb').is(':checked') ? 1 : 0, delete_ftp: $('#delFtp').is(':checked') ? 1 : 0 }; }
        }).then(function (r) {
            if (!r.isConfirmed) return;
            GBX.del(url, r.value).done(function (res) { toastr.success(res.message); setTimeout(function () { location.reload(); }, 600); });
        });
    });
});
</script>
@endpush
