@extends('layouts.app')

@section('title', 'Accounts')

@section('content')
    <div class="page-head">
        <div>
            <h2>Accounts</h2>
            <p>Panel users and roles. Administrators have full access, operators can manage resources, read-only users can only view.</p>
        </div>
        <div class="actions">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userModal" data-mode="create"><i class="bi bi-person-plus"></i> Add account</button>
        </div>
    </div>

    <div class="db-notice cron-notice">
        <i class="bi bi-shield-lock"></i>
        <div class="flex-grow-1">
            <div class="fw-semibold">Require two-factor authentication</div>
            <div class="cell-sub">Accounts without an authenticator app must enable it before using the panel. Enable it on your own account first.</div>
        </div>
        <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" id="tfaPolicy" @checked($twoFactorRequired)></div>
    </div>

    <div class="gbx-card mb-3">
        <div class="gbx-card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead><tr><th>User</th><th>Role</th><th>Status</th><th>Two-factor</th><th>Last login</th><th>Created</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                    @foreach ($users as $u)
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="gbx-avatar">{{ $u->initials }}</span>
                                    <div><div class="cell-strong">{{ $u->name }} @if($u->is(auth()->user()))<span class="badge badge-soft ms-1">you</span>@endif</div><div class="cell-sub font-mono">{{ $u->username }}{{ $u->email ? ' · '.$u->email : '' }}</div></div>
                                </div>
                            </td>
                            <td><span class="badge {{ $u->role === 'admin' ? 'badge-info' : 'badge-soft' }}">{{ $roles[$u->role] ?? $u->role }}</span></td>
                            <td>{!! $u->is_active ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-danger">Disabled</span>' !!}</td>
                            <td>
                                @if ($u->hasTwoFactor())
                                    <span class="badge badge-success" title="Enabled {{ $u->two_factor_confirmed_at->format('Y-m-d H:i') }}"><i class="bi bi-shield-check"></i> Enabled</span>
                                    <div class="cell-sub">{{ count($u->two_factor_recovery_codes ?? []) }} recovery codes</div>
                                @else
                                    <span class="badge {{ $twoFactorRequired ? 'badge-warning' : 'badge-soft' }}">{{ $twoFactorRequired ? 'Pending' : 'Off' }}</span>
                                @endif
                            </td>
                            <td>{{ $u->last_login_at?->diffForHumans() ?? 'Never' }}<div class="cell-sub font-mono">{{ $u->last_login_ip }}</div></td>
                            <td class="cell-sub">{{ $u->created_at->format('Y-m-d') }}</td>
                            <td class="table-actions">
                                <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#userModal" data-mode="edit" data-user="{{ json_encode($u->only(['id', 'name', 'username', 'email', 'role', 'is_active'])) }}"><i class="bi bi-pencil"></i> Edit</button>
                                @if ($u->hasTwoFactor())
                                    <button class="btn btn-sm btn-outline-secondary" data-post="{{ route('accounts.2fa.reset', $u) }}" data-confirm="Reset two-factor authentication of {{ $u->username }}? The account signs in with the password only until it enables it again." data-danger data-reload title="Reset two-factor authentication"><i class="bi bi-shield-x"></i> Reset 2FA</button>
                                @endif
                                @unless ($u->is(auth()->user()))
                                    <button class="btn btn-sm btn-ghost btn-icon text-danger" data-post="{{ route('accounts.destroy', $u) }}" data-method="DELETE" data-confirm="Delete account {{ $u->username }}?" data-danger data-reload><i class="bi bi-trash"></i></button>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-xl-7">
            <div class="gbx-card h-100">
                <div class="gbx-card-header"><h2><i class="bi bi-door-open"></i> Login history</h2></div>
                <div class="gbx-card-body p-0">
                    <div class="table-responsive">
                        <table class="table">
                            <thead><tr><th>Time</th><th>Event</th><th>IP</th></tr></thead>
                            <tbody>
                            @forelse ($logins as $l)
                                <tr>
                                    <td class="cell-sub text-nowrap">{{ $l->created_at?->format('Y-m-d H:i:s') }}</td>
                                    <td>@if(str_starts_with($l->action, 'Failed'))<i class="bi bi-x-circle text-danger me-1"></i>@else<i class="bi bi-check-circle text-success me-1"></i>@endif {{ $l->user?->username ? $l->user->username.': ' : '' }}{{ $l->action }}</td>
                                    <td class="font-mono small">{{ $l->ip }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-center text-muted py-4">No logins recorded.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="gbx-card h-100">
                <div class="gbx-card-header"><h2><i class="bi bi-laptop"></i> Active sessions</h2></div>
                <div class="gbx-card-body p-0">
                    <div class="table-responsive">
                        <table class="table">
                            <thead><tr><th>User</th><th>IP</th><th>Last activity</th></tr></thead>
                            <tbody>
                            @foreach ($sessions as $s)
                                <tr>
                                    <td>{{ $users->firstWhere('id', $s->user_id)?->username ?? '#'.$s->user_id }} @if($s->id === session()->getId())<span class="badge badge-soft ms-1">this</span>@endif
                                        <div class="cell-sub text-truncate" style="max-width:220px" title="{{ $s->user_agent }}">{{ $s->user_agent }}</div></td>
                                    <td class="font-mono small">{{ $s->ip_address }}</td>
                                    <td class="cell-sub text-nowrap">{{ \Carbon\Carbon::createFromTimestamp($s->last_activity)->diffForHumans() }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('modals')
    <div class="modal fade" id="userModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" data-ajax data-reload id="userForm" action="{{ route('accounts.store') }}">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person"></i> <span id="userTitle">Add account</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Full name</label>
                            <input type="text" name="name" class="form-control" required maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control font-mono" required pattern="[a-zA-Z0-9_.\-]{3,32}" autocomplete="off">
                        </div>
                        <div class="col-12">
                            <label class="form-label">E-mail (optional)</label>
                            <input type="email" name="email" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Password</label>
                            <div class="input-group">
                                <input type="password" name="password" id="userPassword" class="form-control font-mono" minlength="10" autocomplete="new-password">
                                <button type="button" class="btn btn-secondary" data-generate="#userPassword" title="Generate"><i class="bi bi-magic"></i></button>
                                <button type="button" class="btn btn-secondary" data-copy-target="#userPassword" data-copy="" title="Copy"><i class="bi bi-clipboard"></i></button>
                            </div>
                            <div class="form-text" id="passHint">At least 10 characters with letters and numbers.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Role</label>
                            <select name="role" class="form-select">
                                @foreach ($roles as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check form-switch mb-2" id="activeWrap">
                                <input class="form-check-input" type="checkbox" name="is_active" id="userActive" checked>
                                <label class="form-check-label" for="userActive">Active</label>
                            </div>
                        </div>
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
    var storeUrl = @json(route('accounts.store')), base = @json(url('accounts'));
    $('#tfaPolicy').on('change', function () {
        var $c = $(this), on = this.checked;
        GBX.post(@json(route('accounts.2fa.policy')), { required: on ? 1 : 0 }).done(function (r) { toastr.success(r.message); setTimeout(function () { location.reload(); }, 900); }).fail(function () { $c.prop('checked', !on); });
    });
    $('#userModal').on('show.bs.modal', function (e) {
        var $t = $(e.relatedTarget), $f = $('#userForm');
        $f[0].reset();
        if ($t.data('mode') === 'edit') {
            var u = $t.data('user');
            $('#userTitle').text('Edit ' + u.username);
            $f.attr('action', base + '/' + u.id).data('method', 'PUT');
            $f.find('[name=name]').val(u.name);
            $f.find('[name=username]').val(u.username).prop('disabled', true);
            $f.find('[name=email]').val(u.email);
            $f.find('[name=role]').val(u.role);
            $f.find('[name=is_active]').prop('checked', !!u.is_active);
            $f.find('[name=password]').prop('required', false);
            $('#passHint').text('Leave empty to keep the current password.');
            $('#activeWrap').show();
        } else {
            $('#userTitle').text('Add account');
            $f.attr('action', storeUrl).removeData('method');
            $f.find('[name=username]').prop('disabled', false);
            $f.find('[name=password]').prop('required', true);
            $('#passHint').text('At least 10 characters with letters and numbers.');
            $('#activeWrap').hide();
        }
    });
});
</script>
@endpush
