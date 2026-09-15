@extends('client.layout')

@section('title', 'FTP')

@section('content')
    <div class="page-head">
        <div>
            <h2>FTP accounts</h2>
            <p>{{ $accounts->count() }} of {{ $client->limit('max_ftp') === 0 ? 'unlimited' : max(0, $client->limit('max_ftp')) }} accounts. Host: <span class="font-mono">{{ request()->getHost() }}</span>, port 21.</p>
        </div>
        <div class="actions">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#ftpModal" @disabled(! $canAdd || $sites->isEmpty() || ! $installed)><i class="bi bi-plus-lg"></i> Add FTP</button>
        </div>
    </div>

    @unless ($installed)
        <div class="db-notice cron-notice"><i class="bi bi-info-circle-fill"></i><span>FTP is not available on this server.</span></div>
    @endunless

    <div class="gbx-card">
        <div class="gbx-card-body p-0">
            @if ($accounts->isEmpty())
                <div class="empty-state">
                    <div class="icon"><i class="bi bi-folder-symlink"></i></div>
                    <h3>No FTP accounts</h3>
                    <p>{{ $sites->isEmpty() ? 'Create a website first, then an FTP account to upload its files.' : 'Create an account to upload files to your websites.' }}</p>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover db-table">
                        <thead><tr><th>Username</th><th>Status</th><th>Website</th><th>Folder</th><th>Notes</th><th class="text-end">Operate</th></tr></thead>
                        <tbody>
                        @foreach ($accounts as $a)
                            <tr data-id="{{ $a->id }}" data-user="{{ $a->username }}">
                                <td class="cell-strong font-mono">{{ $a->username }}</td>
                                <td><a href="#" class="ftp-toggle text-decoration-none {{ $a->is_active ? 'text-success' : 'text-danger' }}"><span class="status-dot {{ $a->is_active ? 'on' : 'off' }}"></span> {{ $a->is_active ? 'Enabled' : 'Disabled' }}</a></td>
                                <td>{{ $a->website?->domain ?? '-' }}</td>
                                <td class="font-mono small">{{ $a->path }}</td>
                                <td class="small">{{ $a->notes ?: '-' }}</td>
                                <td class="text-end text-nowrap db-ops">
                                    <a href="#" class="ftp-password">Password</a>
                                    <a href="#" class="ftp-delete text-danger">Delete</a>
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
    <div class="modal fade" id="ftpModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" data-ajax data-reload action="{{ route('client.ftp.store') }}" autocomplete="off">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-folder-symlink"></i> Add FTP account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">Username</label><input type="text" name="username" class="form-control font-mono" required pattern="[a-zA-Z0-9_.\-]{3,32}"></div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <div class="input-group">
                            <input type="text" name="password" id="ftpPass" class="form-control font-mono" required minlength="8">
                            <button type="button" class="btn btn-outline-secondary" data-generate="#ftpPass" title="Generate"><i class="bi bi-shuffle"></i></button>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-7">
                            <label class="form-label">Website</label>
                            <select name="website_id" class="form-select" required>
                                @foreach ($sites as $s)
                                    <option value="{{ $s->id }}">{{ $s->domain }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-5"><label class="form-label">Folder</label><input type="text" name="folder" class="form-control font-mono" placeholder="/ (website root)"></div>
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
$(function () {
    var base = @json(url('/client/ftp'));
    var row = function (el) { return $(el).closest('tr'); };
    $('.ftp-toggle').on('click', function (e) {
        e.preventDefault();
        GBX.post(base + '/' + row(this).data('id') + '/toggle').done(function (r) { toastr.success(r.message); setTimeout(function () { location.reload(); }, 600); });
    });
    $('.ftp-password').on('click', function (e) {
        e.preventDefault();
        var $r = row(this);
        GBX.confirm({ title: 'New password for ' + $r.data('user'), input: 'text', inputValue: GBX.password(16), icon: null, confirmText: 'Change',
            inputValidator: function (v) { if (!v || v.length < 8) return 'At least 8 characters'; } }).then(function (res) {
            if (res.isConfirmed) GBX.post(base + '/' + $r.data('id') + '/password', { password: res.value }).done(function (r) { toastr.success(r.message); });
        });
    });
    $('.ftp-delete').on('click', function (e) {
        e.preventDefault();
        var $r = row(this);
        GBX.confirm({ text: 'Delete FTP account ' + $r.data('user') + '? Files are not deleted.', danger: true, confirmText: 'Delete' }).then(function (res) {
            if (res.isConfirmed) GBX.del(base + '/' + $r.data('id')).done(function (r) { toastr.success(r.message); setTimeout(function () { location.reload(); }, 600); });
        });
    });
});
</script>
@endpush
