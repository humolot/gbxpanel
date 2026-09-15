@extends('layouts.app')

@section('title', 'Databases')

@section('content')
    <div class="page-head">
        <div>
            <h2>Databases</h2>
            <p>MySQL / MariaDB databases, users, backups and imports.</p>
        </div>
        @if ($installed)
            <div class="actions">
                <span class="gbx-chip"><span class="status-dot {{ $service['active'] ? 'on' : 'off' }}"></span> {{ $service['name'] }} {{ $version }}</span>
                @if ($phpmyadmin)
                    <a href="{{ url('/phpmyadmin/') }}" target="_blank" rel="noopener" class="btn btn-outline-secondary"><i class="bi bi-table"></i> phpMyAdmin</a>
                @else
                    <button class="btn btn-outline-secondary" data-post="{{ route('home.software.install') }}" data-payload='{"key":"phpmyadmin"}' data-confirm="Install phpMyAdmin on the panel port?"><i class="bi bi-download"></i> phpMyAdmin</button>
                @endif
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#dbModal"><i class="bi bi-plus-lg"></i> Add database</button>
            </div>
        @endif
    </div>

    @if (! $installed)
        <div class="gbx-card">
            <div class="empty-state">
                <div class="icon"><i class="bi bi-database"></i></div>
                <h3>No database server installed</h3>
                <p>Install MySQL or MariaDB to create databases.</p>
                <div class="d-flex gap-2 justify-content-center">
                    <button class="btn btn-primary" data-post="{{ route('home.software.install') }}" data-payload='{"key":"mysql"}' data-confirm="Install MySQL Server?"><i class="bi bi-download"></i> Install MySQL</button>
                    <button class="btn btn-secondary" data-post="{{ route('home.software.install') }}" data-payload='{"key":"mariadb"}' data-confirm="Install MariaDB Server?"><i class="bi bi-download"></i> Install MariaDB</button>
                </div>
            </div>
        </div>
    @else
        @if (count($unmanaged))
            <div class="alert alert-secondary d-flex align-items-center gap-2 flex-wrap">
                <i class="bi bi-info-circle"></i> {{ count($unmanaged) }} database(s) exist on the server but are not managed by the panel: <span class="font-mono small">{{ implode(', ', array_slice($unmanaged, 0, 6)) }}</span>
                <button class="btn btn-sm btn-outline-secondary ms-auto" data-post="{{ route('databases.sync') }}" data-reload><i class="bi bi-arrow-down-circle"></i> Import list</button>
            </div>
        @endif

        <div class="gbx-card mb-3">
            <div class="gbx-card-body p-0">
                @if ($databases->isEmpty())
                    <div class="empty-state">
                        <div class="icon"><i class="bi bi-database-add"></i></div>
                        <h3>No databases yet</h3>
                        <p>Create a database with its own user and password.</p>
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead><tr><th>Database</th><th>User</th><th>Size</th><th>Tables</th><th>Website</th><th class="text-end">Actions</th></tr></thead>
                            <tbody>
                            @foreach ($databases as $db)
                                @php $info = $live[$db->name] ?? null; @endphp
                                <tr>
                                    <td><span class="cell-strong font-mono">{{ $db->name }}</span>@if(! $info)<span class="badge badge-danger ms-2">missing</span>@endif<div class="cell-sub">{{ $db->charset }}{{ $db->notes ? ' · '.$db->notes : '' }}</div></td>
                                    <td class="font-mono small">{{ $db->username.'@'.$db->host }}</td>
                                    <td class="font-mono small">{{ $info ? \App\Services\SystemStats::bytes($info['size'], 1) : '-' }}</td>
                                    <td>{{ $info['tables'] ?? '-' }}</td>
                                    <td>{{ $db->website?->domain ?? '-' }}</td>
                                    <td class="table-actions">
                                        <button class="btn btn-sm btn-outline-secondary show-creds" data-url="{{ route('databases.credentials', $db) }}"><i class="bi bi-key"></i> Credentials</button>
                                        <button class="btn btn-sm btn-outline-secondary" data-post="{{ route('databases.backup', $db) }}" data-confirm="Create a compressed backup of {{ $db->name }}?"><i class="bi bi-archive"></i> Backup</button>
                                        <div class="dropdown d-inline-block">
                                            <button class="btn btn-sm btn-ghost btn-icon" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
                                            <div class="dropdown-menu dropdown-menu-end">
                                                <a href="#" class="dropdown-item change-pass" data-url="{{ route('databases.password', $db) }}" data-user="{{ $db->username }}"><i class="bi bi-shield-lock"></i> Change password</a>
                                                <a href="#" class="dropdown-item import-db" data-url="{{ route('databases.import', $db) }}" data-name="{{ $db->name }}"><i class="bi bi-upload"></i> Import SQL</a>
                                                <div class="dropdown-divider"></div>
                                                <a href="#" class="dropdown-item text-danger" data-post="{{ route('databases.destroy', $db) }}" data-method="DELETE" data-confirm="Drop database {{ $db->name }} and user {{ $db->username }}? This cannot be undone." data-danger data-reload data-confirm-text="Drop database"><i class="bi bi-trash"></i> Delete</a>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        <div class="gbx-card">
            <div class="gbx-card-header"><h2><i class="bi bi-archive"></i> Backups</h2><div class="actions"><span class="cell-sub font-mono">{{ rtrim(config('gbx.paths.backup'), '/') }}/database</span></div></div>
            <div class="gbx-card-body p-0">
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>File</th><th>Size</th><th>Date</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                        @forelse ($backups as $b)
                            <tr>
                                <td class="font-mono small">{{ $b['name'] }}</td>
                                <td class="font-mono small">{{ \App\Services\SystemStats::bytes($b['size'], 1) }}</td>
                                <td class="cell-sub">{{ date('Y-m-d H:i', $b['time']) }}</td>
                                <td class="table-actions">
                                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('databases.backups.download', $b['name']) }}"><i class="bi bi-download"></i> Download</a>
                                    <button class="btn btn-sm btn-ghost btn-icon text-danger" data-post="{{ route('databases.backups.delete', $b['name']) }}" data-method="DELETE" data-confirm="Delete this backup?" data-danger data-reload><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-4">No backups yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
@endsection

@push('modals')
    <div class="modal fade" id="dbModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <form class="modal-content" data-ajax data-reload action="{{ route('databases.store') }}">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-database-add"></i> Add database</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Database name</label>
                            <input type="text" name="name" id="dbName" class="form-control font-mono" required pattern="[a-zA-Z0-9_]{1,64}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" id="dbUser" class="form-control font-mono" required pattern="[a-zA-Z0-9_]{1,32}">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Password</label>
                            <div class="input-group">
                                <input type="text" name="password" id="dbPassword" class="form-control font-mono" required minlength="8">
                                <button type="button" class="btn btn-secondary" data-generate="#dbPassword" title="Generate"><i class="bi bi-magic"></i></button>
                                <button type="button" class="btn btn-secondary" data-copy-target="#dbPassword" data-copy="" title="Copy"><i class="bi bi-clipboard"></i></button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Access</label>
                            <select name="host" class="form-select">
                                <option value="localhost">Local only</option>
                                <option value="127.0.0.1">127.0.0.1</option>
                                <option value="%">Everywhere (%)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Charset</label>
                            <select name="charset" class="form-select">
                                <option value="utf8mb4">utf8mb4</option>
                                <option value="utf8">utf8</option>
                                <option value="latin1">latin1</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Website</label>
                            <select name="website_id" class="form-select">
                                <option value="">None</option>
                                @foreach ($websites as $w)
                                    <option value="{{ $w->id }}">{{ $w->domain }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <input type="text" name="notes" class="form-control" maxlength="255">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create database</button>
                </div>
            </form>
        </div>
    </div>
@endpush

@push('scripts')
<script>
$(function () {
    $('#dbModal').on('show.bs.modal', function () { if (!$('#dbPassword').val()) $('#dbPassword').val(GBX.password(18)); });
    $('#dbName').on('input', function () { $('#dbUser').val(this.value.substring(0, 32)); });

    $('.show-creds').on('click', function () {
        GBX.get($(this).data('url')).done(function (r) {
            var row = function (k, v) { return '<dt>' + k + '</dt><dd class="font-mono d-flex align-items-center gap-2"><span class="text-break">' + GBX.escape(v || '(not stored)') + '</span>' + (v ? '<button class="btn btn-sm btn-ghost btn-icon" data-copy="' + GBX.escape(v) + '"><i class="bi bi-clipboard"></i></button>' : '') + '</dd>'; };
            Swal.fire({ title: 'Credentials', html: '<dl class="kv text-start">' + row('Host', r.host) + row('Database', r.name) + row('Username', r.username) + row('Password', r.password) + '</dl>', buttonsStyling: false, customClass: { confirmButton: 'btn btn-primary' } });
        });
    });

    $('.change-pass').on('click', function (e) {
        e.preventDefault();
        var url = $(this).data('url');
        GBX.confirm({ title: 'New password for ' + $(this).data('user'), input: 'text', inputValue: GBX.password(18), icon: null, confirmText: 'Change password',
            inputValidator: function (v) { if (!v || v.length < 8) return 'At least 8 characters'; } })
            .then(function (r) { if (r.isConfirmed) GBX.post(url, { password: r.value }).done(function (res) { toastr.success(res.message); }); });
    });

    $('.import-db').on('click', function (e) {
        e.preventDefault();
        var url = $(this).data('url'), name = $(this).data('name');
        GBX.confirm({ title: 'Import into ' + name, input: 'file', icon: null, confirmText: 'Upload & import',
            html: '<p class="small">Accepted: .sql, .sql.gz, .zip. Existing tables with the same name are overwritten.</p>',
            inputValidator: function (f) { if (!f) return 'Choose a file'; } })
            .then(function (r) {
                if (!r.isConfirmed) return;
                var fd = new FormData();
                fd.append('file', r.value);
                toastr.info('Uploading...');
                GBX.post(url, fd);
            });
    });
});
</script>
@endpush
