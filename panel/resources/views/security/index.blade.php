@extends('layouts.app')

@section('title', 'Security')

@section('content')
    <div class="page-head">
        <div>
            <h2>Security</h2>
            <p>Firewall rules (UFW), SSH hardening, Fail2ban and login attempts.</p>
        </div>
        <div class="actions">@include('security._nav')</div>
    </div>

    @if (! $installed)
        <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i> UFW is not installed. Run <code>apt install ufw</code> or re-run the installer.</div>
    @endif

    <div class="row g-3">
        <div class="col-xxl-8">
            <div class="gbx-card mb-3">
                <div class="gbx-card-header">
                    <h2><i class="bi bi-bricks"></i> Firewall</h2>
                    <span class="gbx-chip" id="fwStatus"><span class="status-dot"></span> Loading</span>
                    <div class="actions">
                        <div class="form-check form-switch mb-0 d-flex align-items-center gap-2">
                            <input class="form-check-input" type="checkbox" id="fwToggle">
                            <label class="form-check-label small" for="fwToggle">Enabled</label>
                        </div>
                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#blockModal"><i class="bi bi-slash-circle"></i> Block IP</button>
                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#ruleModal"><i class="bi bi-plus-lg"></i> Add rule</button>
                    </div>
                </div>
                <div class="px-3 pt-3">
                    <div class="d-flex flex-wrap gap-2" id="quickPorts">
                        <span class="cell-sub me-1 align-self-center">Quick allow:</span>
                        @foreach (['80/tcp' => 'HTTP', '443/tcp' => 'HTTPS', '21/tcp' => 'FTP', '3306/tcp' => 'MySQL', '6379/tcp' => 'Redis', '8080/tcp' => '8080'] as $p => $label)
                            <button class="btn btn-sm btn-outline-secondary quick-port" data-port="{{ explode('/', $p)[0] }}" data-label="{{ $label }}">{{ $label }} <span class="cell-sub font-mono">{{ $p }}</span></button>
                        @endforeach
                    </div>
                </div>
                <div class="gbx-card-body p-0 mt-2">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead><tr><th>#</th><th>Port / Target</th><th>Action</th><th>Source</th><th>Comment</th><th class="text-end"></th></tr></thead>
                            <tbody id="rulesBody"><tr class="loading-row"><td colspan="6"><i class="bi bi-arrow-repeat spin"></i> Loading rules...</td></tr></tbody>
                        </table>
                    </div>
                </div>
                <div class="gbx-card-body border-top border-soft py-2 cell-sub" id="fwDefaults"></div>
            </div>

            <div class="gbx-card">
                <div class="gbx-card-header">
                    <h2><i class="bi bi-person-x"></i> Failed SSH logins (24h)</h2>
                    <div class="actions"><button class="btn btn-sm btn-ghost" id="failedLoad"><i class="bi bi-arrow-clockwise"></i> Load</button></div>
                </div>
                <div class="gbx-card-body p-0">
                    <div class="table-responsive">
                        <table class="table">
                            <thead><tr><th>IP address</th><th>Attempts</th><th>Last attempt</th><th class="text-end"></th></tr></thead>
                            <tbody id="failedBody"><tr class="loading-row"><td colspan="4">Click Load to scan the authentication log.</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xxl-4">
            <div class="gbx-card mb-3">
                <div class="gbx-card-header">
                    <h2><i class="bi bi-key"></i> SSH</h2>
                    <span class="gbx-chip ms-auto"><span class="status-dot {{ $ssh['active'] ? 'on' : 'off' }}"></span> {{ $ssh['active'] ? 'Running' : 'Stopped' }}</span>
                </div>
                <div class="gbx-card-body">
                    <form data-ajax data-no-reset action="{{ route('security.ssh') }}" id="sshForm">
                        <div class="mb-3">
                            <label class="form-label">SSH port</label>
                            <input type="number" name="port" class="form-control font-mono" value="{{ $ssh['port'] }}" min="1" max="65535" required>
                            <div class="form-text">The new port is allowed in UFW automatically before SSH reloads.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Root login</label>
                            <select name="permit_root_login" class="form-select">
                                <option value="prohibit-password" @selected($ssh['permit_root_login'] === 'prohibit-password' || $ssh['permit_root_login'] === 'without-password')>Keys only (recommended)</option>
                                <option value="yes" @selected($ssh['permit_root_login'] === 'yes')>Allowed</option>
                                <option value="no" @selected($ssh['permit_root_login'] === 'no')>Disabled</option>
                            </select>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label">Password auth</label>
                                <select name="password_authentication" class="form-select">
                                    <option value="yes" @selected($ssh['password_authentication'] === 'yes')>Enabled</option>
                                    <option value="no" @selected($ssh['password_authentication'] === 'no')>Disabled</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Public key auth</label>
                                <select name="pubkey_authentication" class="form-select">
                                    <option value="yes" @selected($ssh['pubkey_authentication'] === 'yes')>Enabled</option>
                                    <option value="no" @selected($ssh['pubkey_authentication'] === 'no')>Disabled</option>
                                </select>
                            </div>
                        </div>
                        <div class="alert alert-warning small py-2"><i class="bi bi-exclamation-triangle me-1"></i> Keep your current SSH session open and test a new connection before closing it.</div>
                        <button class="btn btn-primary w-100" type="submit"><i class="bi bi-check2"></i> Apply SSH settings</button>
                    </form>
                </div>
            </div>

            <div class="gbx-card">
                <div class="gbx-card-header"><h2><i class="bi bi-shield-exclamation"></i> Fail2ban</h2></div>
                <div class="gbx-card-body" id="f2bBody"><div class="text-muted small">Loading...</div></div>
            </div>
        </div>
    </div>
@endsection

@push('modals')
    <div class="modal fade" id="ruleModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" data-ajax data-success="reloadSecurity" action="{{ route('security.rules.store') }}">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-lg"></i> Add firewall rule</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label">Action</label>
                            <select name="action" class="form-select">
                                <option value="allow">Allow</option>
                                <option value="deny">Deny</option>
                                <option value="reject">Reject</option>
                                <option value="limit">Limit (rate limit)</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Protocol</label>
                            <select name="protocol" class="form-select">
                                <option value="tcp">TCP</option>
                                <option value="udp">UDP</option>
                                <option value="both">TCP + UDP</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Port or range</label>
                            <input type="text" name="port" class="form-control font-mono" placeholder="8080 or 30000:30100" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Source IP / CIDR</label>
                            <input type="text" name="source" class="form-control font-mono" placeholder="Anywhere">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Comment</label>
                            <input type="text" name="comment" class="form-control" maxlength="60">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add rule</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="blockModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" data-ajax data-success="reloadSecurity" action="{{ route('security.block') }}">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-slash-circle"></i> Block IP address</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">IP address or CIDR</label>
                        <input type="text" name="ip" class="form-control font-mono" placeholder="203.0.113.10 or 203.0.113.0/24" required>
                    </div>
                    <div>
                        <label class="form-label">Comment</label>
                        <input type="text" name="comment" class="form-control" maxlength="60" placeholder="Brute force">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Block</button>
                </div>
            </form>
        </div>
    </div>
@endpush

@push('scripts')
<script>
function reloadSecurity() { window.loadSecurity(); }

$(function () {
    var panelPort = @json((string) $panelPort), sshPort = @json((string) $ssh['port']);

    window.loadSecurity = function () {
        GBX.get(@json(route('security.data'))).done(function (r) {
            var fw = r.firewall;
            $('#fwToggle').prop('checked', fw.active);
            $('#fwStatus').html('<span class="status-dot ' + (fw.active ? 'on' : 'off') + '"></span> ' + (fw.active ? 'Active' : 'Inactive'));
            $('#fwDefaults').html('Default policy: incoming <strong>' + GBX.escape(fw.default_in) + '</strong>, outgoing <strong>' + GBX.escape(fw.default_out) + '</strong>');
            $('#rulesBody').html(fw.rules.map(function (rule) {
                var cls = rule.action === 'ALLOW' ? 'badge-success' : (rule.action === 'LIMIT' ? 'badge-warning' : 'badge-danger');
                var port = rule.to.replace(' (v6)', '').split('/')[0];
                var critical = port === panelPort || port === sshPort;
                return '<tr><td class="cell-sub">' + rule.num + '</td>' +
                    '<td class="font-mono">' + GBX.escape(rule.to) + (critical ? ' <span class="badge badge-soft ms-1">' + (port === panelPort ? 'panel' : 'ssh') + '</span>' : '') + '</td>' +
                    '<td><span class="badge ' + cls + '">' + GBX.escape(rule.action + ' ' + rule.direction) + '</span></td>' +
                    '<td class="font-mono small">' + GBX.escape(rule.from) + '</td>' +
                    '<td class="cell-sub">' + GBX.escape(rule.comment || '') + '</td>' +
                    '<td class="table-actions"><button class="btn btn-sm btn-ghost btn-icon text-danger del-rule" data-num="' + rule.num + '" data-critical="' + (critical ? 1 : 0) + '" data-label="' + GBX.escape(rule.to + ' ' + rule.action) + '"><i class="bi bi-trash"></i></button></td></tr>';
            }).join('') || '<tr class="loading-row"><td colspan="6">No rules defined.</td></tr>');

            var f = r.fail2ban;
            if (!f.installed) {
                $('#f2bBody').html('<p class="text-muted small">Fail2ban bans IPs after repeated failed logins.</p><button class="btn btn-primary btn-sm" data-post="{{ route('home.software.install') }}" data-payload=\'{"key":"fail2ban"}\' data-confirm="Install Fail2ban with an SSH jail?"><i class="bi bi-download"></i> Install Fail2ban</button>');
            } else {
                var html = '<div class="d-flex align-items-center gap-2 mb-3"><span class="status-dot ' + (f.active ? 'on' : 'off') + '"></span> ' + (f.active ? 'Running' : 'Stopped') + '</div>';
                $.each(f.jails, function (jail, j) {
                    html += '<div class="mb-3"><div class="d-flex justify-content-between"><strong class="font-mono">' + GBX.escape(jail) + '</strong><span class="cell-sub">' + j.banned.length + ' banned · ' + j.total + ' total</span></div>' +
                        (j.banned.length ? '<div class="d-flex flex-wrap gap-1 mt-2">' + j.banned.map(function (ip) {
                            return '<span class="badge badge-danger font-mono d-inline-flex align-items-center gap-1">' + GBX.escape(ip) + ' <a href="#" class="text-reset unban" data-jail="' + GBX.escape(jail) + '" data-ip="' + GBX.escape(ip) + '" title="Unban"><i class="bi bi-x"></i></a></span>';
                        }).join('') + '</div>' : '') + '</div>';
                });
                $('#f2bBody').html(html);
            }
        });
    };
    loadSecurity();

    $('#fwToggle').on('change', function () {
        var $t = $(this), enable = $t.is(':checked');
        GBX.confirm({ text: enable ? 'Enable the firewall? SSH (' + sshPort + ') and panel (' + panelPort + ') ports are allowed first.' : 'Disable the firewall? All ports become reachable.', danger: !enable })
            .then(function (r) {
                if (!r.isConfirmed) { $t.prop('checked', !enable); return; }
                GBX.post(@json(route('security.toggle')), { enabled: enable ? 1 : 0 }).done(function (res) { toastr.success(res.message); loadSecurity(); }).fail(function () { $t.prop('checked', !enable); });
            });
    });

    $('#rulesBody').on('click', '.del-rule', function () {
        var d = $(this).data();
        GBX.confirm({ html: 'Delete rule <strong>' + GBX.escape(d.label) + '</strong>?' + (d.critical ? '<br><span class="text-warning">This rule protects SSH or the panel. Deleting it may lock you out.</span>' : ''), danger: true, confirmText: 'Delete rule' })
            .then(function (r) { if (r.isConfirmed) GBX.del(@json(url('security/firewall/rules')) + '/' + d.num).done(function (res) { toastr.success(res.message); loadSecurity(); }); });
    });

    $('.quick-port').on('click', function () {
        var d = $(this).data();
        GBX.post(@json(route('security.rules.store')), { action: 'allow', port: String(d.port), protocol: 'tcp', comment: d.label }).done(function (res) { toastr.success(res.message); loadSecurity(); });
    });

    $('#f2bBody').on('click', '.unban', function (e) {
        e.preventDefault();
        GBX.post(@json(route('security.unban')), $(this).data()).done(function (res) { toastr.success(res.message); loadSecurity(); });
    });

    $('#failedLoad').on('click', function () {
        $('#failedBody').html('<tr class="loading-row"><td colspan="4"><i class="bi bi-arrow-repeat spin"></i> Scanning...</td></tr>');
        GBX.get(@json(route('security.failed'))).done(function (r) {
            $('#failedBody').html(r.data.map(function (x) {
                return '<tr><td class="font-mono">' + GBX.escape(x.ip) + '</td><td><span class="badge ' + (x.count > 20 ? 'badge-danger' : 'badge-warning') + '">' + x.count + '</span></td><td class="cell-sub">' + GBX.escape(x.last) + '</td>' +
                    '<td class="table-actions"><button class="btn btn-sm btn-outline-danger block-ip" data-ip="' + GBX.escape(x.ip) + '"><i class="bi bi-slash-circle"></i> Block</button></td></tr>';
            }).join('') || '<tr class="loading-row"><td colspan="4">No failed attempts in the last 24 hours.</td></tr>');
        });
    });

    $('#failedBody').on('click', '.block-ip', function () {
        var ip = $(this).data('ip');
        GBX.post(@json(route('security.block')), { ip: ip, comment: 'SSH brute force' }).done(function (res) { toastr.success(res.message); loadSecurity(); });
    });
});
</script>
@endpush
