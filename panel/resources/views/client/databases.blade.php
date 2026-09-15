@extends('client.layout')

@section('title', 'Database')

@section('content')
    <div class="page-head">
        <div>
            <h2>Databases</h2>
            <p>{{ $databases->count() }} of {{ $client->limit('max_databases') === 0 ? 'unlimited' : max(0, $client->limit('max_databases')) }} MySQL databases. Host for your applications: <span class="font-mono">localhost</span>.</p>
        </div>
        <div class="actions">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#dbModal" @disabled(! $canAdd || ! $installed)><i class="bi bi-plus-lg"></i> Add database</button>
        </div>
    </div>

    <div class="gbx-card">
        <div class="gbx-card-body p-0">
            @if ($databases->isEmpty())
                <div class="empty-state">
                    <div class="icon"><i class="bi bi-database"></i></div>
                    <h3>No databases</h3>
                    <p>Create a MySQL database for WordPress, Laravel or any application.</p>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover db-table">
                        <thead><tr><th>Database name</th><th>Username</th><th>Size</th><th>Website</th><th>Notes</th><th class="text-end">Operate</th></tr></thead>
                        <tbody>
                        @foreach ($databases as $db)
                            <tr data-id="{{ $db->id }}" data-name="{{ $db->name }}">
                                <td class="cell-strong font-mono">{{ $db->name }}</td>
                                <td class="font-mono small">{{ $db->username }}</td>
                                <td class="small">{{ isset($sizes[$db->name]['size']) ? \App\Services\SystemStats::bytes((int) $sizes[$db->name]['size'], 1) : '-' }}</td>
                                <td>{{ $db->website?->domain ?? '-' }}</td>
                                <td class="small">{{ $db->notes ?: '-' }}</td>
                                <td class="text-end text-nowrap db-ops">
                                    <a href="#" class="db-creds">Connection</a>
                                    <a href="#" class="db-password">Password</a>
                                    @if ($db->engine === 'mysql' && ! $db->server_id)<a href="#" class="db-delete text-danger">Delete</a>@endif
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
    <div class="modal fade" id="dbModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" data-ajax data-success="dbCreated" action="{{ route('client.databases.store') }}" autocomplete="off">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-database"></i> Add database</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Database name</label>
                        <div class="input-group"><span class="input-group-text font-mono">{{ $prefix }}</span><input type="text" name="name" class="form-control font-mono" required pattern="[a-zA-Z0-9_]+" maxlength="{{ 32 - strlen($prefix) }}"></div>
                        <div class="form-text">The user has the same name as the database.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <div class="input-group">
                            <input type="text" name="password" id="dbPass" class="form-control font-mono" minlength="10" placeholder="Generated when empty">
                            <button type="button" class="btn btn-outline-secondary" data-generate="#dbPass" title="Generate"><i class="bi bi-shuffle"></i></button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Website</label>
                        <select name="website_id" class="form-select">
                            <option value="">None</option>
                            @foreach ($sites as $s)
                                <option value="{{ $s->id }}">{{ $s->domain }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div><label class="form-label">Notes</label><input type="text" name="notes" class="form-control" maxlength="255"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create</button>
                </div>
            </form>
        </div>
    </div>
@endpush

@push('scripts')
<script>
var showDbCredentials = function (d, title) {
    Swal.fire({
        title: title, icon: null, buttonsStyling: false, customClass: { confirmButton: 'btn btn-primary' },
        html: '<div class="kv text-start"><dt>Host</dt><dd class="font-mono">' + GBX.escape(d.host) + '</dd><dt>Database</dt><dd class="font-mono">' + GBX.escape(d.name) + '</dd>' +
            '<dt>User</dt><dd class="font-mono">' + GBX.escape(d.username) + '</dd><dt>Password</dt><dd class="font-mono">' + GBX.escape(d.password) + '</dd></div>'
    });
};
function dbCreated(res) {
    showDbCredentials(res.database, 'Database created');
    $(document).one('click', '.swal2-confirm', function () { setTimeout(function () { location.reload(); }, 300); });
}
$(function () {
    var base = @json(url('/client/databases'));
    var row = function (el) { return $(el).closest('tr'); };
    $('.db-creds').on('click', function (e) {
        e.preventDefault();
        GBX.get(base + '/' + row(this).data('id') + '/credentials').done(function (r) { showDbCredentials(r.database, 'Connection'); });
    });
    $('.db-password').on('click', function (e) {
        e.preventDefault();
        var $r = row(this);
        GBX.confirm({ title: 'New password for ' + $r.data('name'), input: 'text', inputValue: GBX.password(18), icon: null, confirmText: 'Change',
            inputValidator: function (v) { if (!v || v.length < 10) return 'At least 10 characters with letters and numbers'; } }).then(function (res) {
            if (res.isConfirmed) GBX.post(base + '/' + $r.data('id') + '/password', { password: res.value }).done(function (r) { toastr.success(r.message); });
        });
    });
    $('.db-delete').on('click', function (e) {
        e.preventDefault();
        var $r = row(this);
        GBX.confirm({ title: 'Delete ' + $r.data('name') + '?', text: 'The database, its tables and its user are deleted permanently.', danger: true, confirmText: 'Delete' }).then(function (res) {
            if (res.isConfirmed) GBX.del(base + '/' + $r.data('id')).done(function (r) { toastr.success(r.message); setTimeout(function () { location.reload(); }, 600); });
        });
    });
});
</script>
@endpush
