@extends('layouts.app')

@section('title', 'FTP')

@section('content')
    <div class="page-head">
        <div>
            <h2>FTP</h2>
            <p>Virtual FTP accounts served by Pure-FTPd. Files are owned by <code>{{ config('gbx.web_user') }}</code>.</p>
        </div>
        @if ($installed)
            <div class="actions">
                <span class="gbx-chip"><span class="status-dot {{ $status['active'] ? 'on' : 'off' }}"></span> Pure-FTPd {{ $status['active'] ? 'running' : 'stopped' }}</span>
                <button class="btn btn-outline-secondary" data-post="{{ route('home.services.action') }}" data-payload='{"service":"pure-ftpd","action":"restart"}'><i class="bi bi-arrow-clockwise"></i> Restart</button>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#ftpModal"><i class="bi bi-plus-lg"></i> Add account</button>
            </div>
        @endif
    </div>

    @if (! $installed)
        <div class="gbx-card">
            <div class="empty-state">
                <div class="icon"><i class="bi bi-folder-symlink"></i></div>
                <h3>Pure-FTPd is not installed</h3>
                <p>Install the FTP server to create accounts. Ports 21 and 39000-40000 (passive) are opened in the firewall.</p>
                <button class="btn btn-primary" data-post="{{ route('home.software.install') }}" data-payload='{"key":"pureftpd"}' data-confirm="Install Pure-FTPd now?"><i class="bi bi-download"></i> Install Pure-FTPd</button>
            </div>
        </div>
    @else
        <div class="row g-3 mb-3">
            <div class="col-md-4"><div class="stat-tile"><div class="icon"><i class="bi bi-hdd-network"></i></div><div><div class="label">Host</div><div class="fw-semibold font-mono">{{ $host }}</div></div></div></div>
            <div class="col-md-4"><div class="stat-tile"><div class="icon"><i class="bi bi-plug"></i></div><div><div class="label">Port / Passive range</div><div class="fw-semibold font-mono">21 &middot; 39000-40000</div></div></div></div>
            <div class="col-md-4"><div class="stat-tile"><div class="icon"><i class="bi bi-people"></i></div><div><div class="label">Accounts</div><div class="value">{{ $accounts->count() }}</div></div></div></div>
        </div>

        <div class="gbx-card">
            <div class="gbx-card-body p-0">
                @if ($accounts->isEmpty())
                    <div class="empty-state">
                        <div class="icon"><i class="bi bi-person-plus"></i></div>
                        <h3>No FTP accounts</h3>
                        <p>Create an account and restrict it to a website directory.</p>
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead><tr><th>Username</th><th>Directory</th><th>Website</th><th>Status</th><th>Created</th><th class="text-end">Actions</th></tr></thead>
                            <tbody>
                            @foreach ($accounts as $a)
                                <tr>
                                    <td><span class="cell-strong font-mono">{{ $a->username }}</span>@if($a->notes)<div class="cell-sub">{{ $a->notes }}</div>@endif</td>
                                    <td><a class="font-mono small" href="{{ route('files.index', ['path' => $a->path]) }}">{{ $a->path }}</a></td>
                                    <td>{{ $a->website?->domain ?? '-' }}</td>
                                    <td>
                                        <button class="btn btn-sm btn-ghost px-1" data-post="{{ route('ftp.toggle', $a) }}" data-reload>
                                            <span class="status-dot {{ $a->is_active ? 'on' : 'off' }}"></span> {{ $a->is_active ? 'Enabled' : 'Disabled' }}
                                        </button>
                                    </td>
                                    <td class="cell-sub">{{ $a->created_at->format('Y-m-d') }}</td>
                                    <td class="table-actions">
                                        <button class="btn btn-sm btn-outline-secondary edit-ftp" data-url="{{ route('ftp.update', $a) }}" data-username="{{ $a->username }}" data-path="{{ $a->path }}" data-notes="{{ $a->notes }}"><i class="bi bi-pencil"></i> Edit</button>
                                        <button class="btn btn-sm btn-ghost btn-icon text-danger" data-post="{{ route('ftp.destroy', $a) }}" data-method="DELETE" data-confirm="Delete FTP account {{ $a->username }}? Files are kept." data-danger data-reload><i class="bi bi-trash"></i></button>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    @endif
@endsection

@push('modals')
    <div class="modal fade" id="ftpModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" data-ajax data-reload action="{{ route('ftp.store') }}">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-plus"></i> Add FTP account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Website (optional)</label>
                        <select name="website_id" class="form-select" id="ftpWebsite">
                            <option value="">None</option>
                            @foreach ($websites as $w)
                                <option value="{{ $w->id }}" data-root="{{ $w->root_path }}">{{ $w->domain }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control font-mono" required pattern="[a-zA-Z0-9_.\-]{3,32}" autocomplete="off">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <div class="input-group">
                            <input type="password" name="password" id="ftpPassword" class="form-control font-mono" required minlength="8" autocomplete="new-password">
                            <button type="button" class="btn btn-secondary" data-generate="#ftpPassword" title="Generate"><i class="bi bi-magic"></i></button>
                            <button type="button" class="btn btn-secondary" data-copy-target="#ftpPassword" data-copy="" title="Copy"><i class="bi bi-clipboard"></i></button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Directory</label>
                        <input type="text" name="path" id="ftpPath" class="form-control font-mono" value="{{ $wwwRoot }}/" required>
                    </div>
                    <div>
                        <label class="form-label">Notes</label>
                        <input type="text" name="notes" class="form-control" maxlength="255">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create account</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="ftpEditModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" data-ajax data-method="PUT" data-reload id="ftpEditForm">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil"></i> Edit <span class="font-mono" id="ftpEditName"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">New password</label>
                        <div class="input-group">
                            <input type="password" name="password" id="ftpEditPassword" class="form-control font-mono" minlength="8" placeholder="Leave empty to keep" autocomplete="new-password">
                            <button type="button" class="btn btn-secondary" data-generate="#ftpEditPassword"><i class="bi bi-magic"></i></button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Directory</label>
                        <input type="text" name="path" class="form-control font-mono" required>
                    </div>
                    <div>
                        <label class="form-label">Notes</label>
                        <input type="text" name="notes" class="form-control" maxlength="255">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
@endpush

@push('scripts')
<script>
$(function () {
    $('#ftpWebsite').on('change', function () {
        var root = $(this).find(':selected').data('root');
        if (root) {
            $('#ftpPath').val(root);
            var $u = $('[name=username]', '#ftpModal');
            if (!$u.val()) $u.val($(this).find(':selected').text().replace(/[^a-z0-9]/gi, '_').substring(0, 32));
        }
    });

    $('.edit-ftp').on('click', function () {
        var $f = $('#ftpEditForm'), d = $(this).data();
        $f.attr('action', d.url);
        $('#ftpEditName').text(d.username);
        $f.find('[name=password]').val('');
        $f.find('[name=path]').val(d.path);
        $f.find('[name=notes]').val(d.notes);
        bootstrap.Modal.getOrCreateInstance('#ftpEditModal').show();
    });
});
</script>
@endpush
