@extends('layouts.app')

@section('title', 'Settings')

@section('content')
    <div class="page-head">
        <div>
            <h2>Settings</h2>
            <p>Panel access, security, AI assistant and server configuration.</p>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-3">
            <div class="gbx-card p-2 position-sticky" style="top: 76px">
                <nav class="nav flex-column gbx-nav p-0" id="settingsNav">
                    <a class="nav-link active" href="#panel" data-bs-toggle="tab" data-bs-target="#s-panel"><i class="bi bi-window"></i> Panel</a>
                    <a class="nav-link" href="#security" data-bs-toggle="tab" data-bs-target="#s-security"><i class="bi bi-shield-lock"></i> Access &amp; security</a>
                    <a class="nav-link" href="#ai" data-bs-toggle="tab" data-bs-target="#s-ai"><i class="bi bi-stars"></i> AI assistant</a>
                    <a class="nav-link" href="#system" data-bs-toggle="tab" data-bs-target="#s-system"><i class="bi bi-server"></i> System</a>
                    <a class="nav-link" href="#about" data-bs-toggle="tab" data-bs-target="#s-about"><i class="bi bi-info-circle"></i> About</a>
                </nav>
            </div>
        </div>
        <div class="col-lg-9">
            <div class="tab-content">
                {{-- Panel --}}
                <div class="tab-pane fade show active" id="s-panel">
                    <div class="gbx-card">
                        <div class="gbx-card-header"><h2><i class="bi bi-window"></i> Panel</h2></div>
                        <div class="gbx-card-body">
                            <form data-ajax data-no-reset data-success="panelSaved" action="{{ route('settings.panel') }}" class="row g-3" style="max-width:720px">
                                <div class="col-12">
                                    <label class="form-label">Panel name</label>
                                    <input type="text" name="panel_title" class="form-control" value="{{ $title }}" maxlength="40" required>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label">Panel port</label>
                                    <input type="number" name="port" class="form-control font-mono" value="{{ $port }}" min="1024" max="65535" required>
                                    <div class="form-text">The firewall rule is updated automatically.</div>
                                </div>
                                <div class="col-md-7">
                                    <label class="form-label">Security entrance</label>
                                    <div class="input-group">
                                        <span class="input-group-text font-mono">/</span>
                                        <input type="text" name="entry" id="entryInput" class="form-control font-mono" value="{{ $entry }}" maxlength="32" placeholder="disabled">
                                        <button type="button" class="btn btn-secondary" id="entryGen" title="Generate"><i class="bi bi-magic"></i></button>
                                    </div>
                                    <div class="form-text">Visitors must open this path before the login page is shown. Leave empty to disable (not recommended).</div>
                                </div>
                                <div class="col-12">
                                    <div class="alert alert-secondary mb-0 small">
                                        <div class="small-caps mb-1">Panel address</div>
                                        <span class="font-mono" id="panelUrl">{{ request()->getScheme() }}://{{ $publicIp }}:{{ $port }}{{ $entry ? '/'.$entry : '' }}</span>
                                        <button type="button" class="btn btn-sm btn-ghost btn-icon" data-copy-target="#panelUrlVal" data-copy=""><i class="bi bi-clipboard"></i></button>
                                        <input type="hidden" id="panelUrlVal" value="{{ request()->getScheme() }}://{{ $publicIp }}:{{ $port }}{{ $entry ? '/'.$entry : '' }}">
                                    </div>
                                </div>
                                <div class="col-12"><button class="btn btn-primary" type="submit"><i class="bi bi-check2"></i> Save panel settings</button></div>
                            </form>
                        </div>
                    </div>

                    <div class="gbx-card">
                        <div class="gbx-card-header"><h2><i class="bi bi-shield-lock"></i> Panel SSL &amp; domain</h2></div>
                        <div class="gbx-card-body" style="max-width:760px">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="soft-mini">
                                        <div class="soft-icon"><i class="bi {{ $panelSsl ? 'bi-lock-fill' : 'bi-unlock' }}"></i></div>
                                        <div class="min-w-0 flex-grow-1">
                                            <div class="name">HTTPS {!! $panelSsl ? '<span class="badge badge-success ms-1">On</span>' : '<span class="badge badge-warning ms-1">Off</span>' !!}</div>
                                            <div class="cell-sub">{{ $panelSsl ? $panelCert.' certificate' : 'Login travels unencrypted' }}</div>
                                        </div>
                                        @if ($panelSsl)
                                            <button class="btn btn-sm btn-outline-secondary panel-access" data-action="ssl_off" data-confirm="Disable HTTPS on the panel? Passwords will travel unencrypted.">Disable</button>
                                        @else
                                            <button class="btn btn-sm btn-primary panel-access" data-action="ssl_on" data-confirm="Enable HTTPS with a self-signed certificate?">Enable</button>
                                        @endif
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="soft-mini">
                                        <div class="soft-icon"><i class="bi bi-globe2"></i></div>
                                        <div class="min-w-0 flex-grow-1">
                                            <div class="name">Bound domain</div>
                                            <div class="cell-sub font-mono text-truncate">{{ $panelDomain ?: 'None, access by IP' }}</div>
                                        </div>
                                        @if ($panelDomain)
                                            <button class="btn btn-sm btn-outline-secondary panel-access" data-action="domain_off" data-confirm="Remove the domain binding? The panel will answer on the server IP.">Unbind</button>
                                        @endif
                                    </div>
                                </div>
                                <div class="col-md-7">
                                    <label class="form-label">Bind panel to a domain</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control font-mono" id="panelDomainInput" placeholder="panel.example.com" value="{{ $panelDomain }}">
                                        <button class="btn btn-secondary panel-access" data-action="domain" data-confirm="After binding, the panel only answers on this domain (not on the IP). Make sure the DNS A record points to {{ $publicIp }}. Continue?">Bind</button>
                                    </div>
                                    <div class="form-text">If you get locked out run <code>gbx domain off</code> on the server.</div>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label">Trusted certificate</label>
                                    <div class="input-group">
                                        <input type="email" class="form-control" id="panelLeEmail" placeholder="you@example.com" @disabled(! $panelDomain)>
                                        <button class="btn btn-secondary panel-access" data-action="letsencrypt" @disabled(! $panelDomain) data-confirm="Request a Let's Encrypt certificate for {{ $panelDomain }}? Port 80 must be open.">Issue</button>
                                    </div>
                                    <div class="form-text">{{ $panelDomain ? "Let's Encrypt, renewed automatically." : 'Bind a domain first.' }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Security --}}
                <div class="tab-pane fade" id="s-security">
                    <div class="gbx-card">
                        <div class="gbx-card-header"><h2><i class="bi bi-shield-lock"></i> Access &amp; security</h2></div>
                        <div class="gbx-card-body">
                            <form data-ajax data-no-reset action="{{ route('settings.security') }}" class="row g-3" style="max-width:720px">
                                <div class="col-md-5">
                                    <label class="form-label">Session lifetime (minutes)</label>
                                    <input type="number" name="session_lifetime" class="form-control" value="{{ $sessionLifetime }}" min="5" max="10080" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">IP allow list</label>
                                    <textarea name="allowed_ips" class="form-control font-mono" rows="3" placeholder="Empty = allow any IP">{{ str_replace(',', "\n", $allowedIps) }}</textarea>
                                    <div class="form-text">Only these IPs can open the panel. Your current IP: <code>{{ request()->ip() }}</code>. If you get locked out run <code>gbx</code> on the server.</div>
                                </div>
                                <div class="col-12">
                                    <div class="alert alert-secondary small mb-0">
                                        <i class="bi bi-lock me-1"></i> Brute-force protection is always on: 5 failed logins lock the username/IP pair for 5 minutes. Failed attempts appear under Accounts.
                                    </div>
                                </div>
                                <div class="col-12"><button class="btn btn-primary" type="submit"><i class="bi bi-check2"></i> Save security settings</button></div>
                            </form>
                        </div>
                    </div>
                </div>

                {{-- AI --}}
                <div class="tab-pane fade" id="s-ai">
                    <div class="gbx-card">
                        <div class="gbx-card-header"><h2><i class="bi bi-stars"></i> AI assistant</h2><div class="actions">{!! $aiConfigured ? '<span class="badge badge-success">Connected</span>' : '<span class="badge badge-soft">Not configured</span>' !!}</div></div>
                        <div class="gbx-card-body">
                            <form data-ajax data-reload action="{{ route('settings.ai') }}" class="row g-3" style="max-width:720px">
                                <div class="col-12">
                                    <label class="form-label">Venice AI API key</label>
                                    <div class="input-group">
                                        <input type="password" name="api_key" id="aiKey" class="form-control font-mono" placeholder="{{ $aiConfigured ? 'Stored encrypted. Type to replace.' : 'VENICE-INFERENCE-KEY-...' }}" autocomplete="off">
                                        <button type="button" class="btn btn-secondary" data-toggle-password="#aiKey"><i class="bi bi-eye"></i></button>
                                    </div>
                                    <div class="form-text">Create an inference key at venice.ai/settings/api. The key is encrypted with the application key and never shown again.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Default chat model</label>
                                    <select name="model" class="form-select">
                                        @foreach ($models as $id => $label)
                                            <option value="{{ $id }}" @selected($aiModel === $id)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Reasoning effort</label>
                                    <select name="effort" class="form-select">
                                        @foreach ($efforts as $id => $label)
                                            <option value="{{ $id }}" @selected($aiEffort === $id)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <div class="form-text">Higher effort gives deeper answers but is slower and costs more.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Vision model (images)</label>
                                    <input type="text" name="vision_model" class="form-control font-mono" value="{{ $aiVision }}">
                                    <div class="form-text">Reads screenshots attached to a message.</div>
                                </div>
                                <div class="col-12">
                                    <div class="alert alert-secondary small mb-2">
                                        <i class="bi bi-tools me-1"></i> The assistant uses function calling: read tools run automatically, action tools wait for your approval in the chat.
                                    </div>
                                    <div class="accordion" id="aiToolsList">
                                        @foreach ($aiTools as $group => $list)
                                            <div class="border border-soft rounded-3 mb-2">
                                                <button type="button" class="btn btn-ghost w-100 justify-content-start px-3 py-2" data-bs-toggle="collapse" data-bs-target="#tools-{{ $group }}">
                                                    <span class="text-capitalize fw-semibold">{{ $group }}</span>
                                                    <span class="ms-auto d-flex gap-1"><span class="badge badge-soft">{{ count($list['read']) }} read</span><span class="badge badge-warning">{{ count($list['write']) }} actions</span></span>
                                                </button>
                                                <div class="collapse" id="tools-{{ $group }}" data-bs-parent="#aiToolsList">
                                                    <div class="px-3 pb-2 d-flex flex-wrap gap-1">
                                                        @foreach ($list['read'] as $tool)<code>{{ $tool }}</code>@endforeach
                                                        @foreach ($list['write'] as $tool)<code class="text-warning">{{ $tool }}</code>@endforeach
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="auto_approve" id="aiAuto" @checked($aiAutoApprove)>
                                        <label class="form-check-label" for="aiAuto">Auto-approve actions for administrators <span class="text-warning">(not recommended: the AI will change the server without asking)</span></label>
                                    </div>
                                </div>
                                <div class="col-12 d-flex gap-2">
                                    <button class="btn btn-primary" type="submit"><i class="bi bi-check2"></i> Save AI settings</button>
                                    @if ($aiConfigured)
                                        <button class="btn btn-outline-danger" type="button" data-post="{{ route('settings.ai') }}" data-payload='{"remove_key":1,"model":"{{ $aiModel }}","effort":"{{ $aiEffort }}","vision_model":"{{ $aiVision }}","auto_approve":{{ $aiAutoApprove ? 1 : 0 }}}' data-confirm="Remove the stored API key?" data-danger data-reload><i class="bi bi-trash"></i> Remove key</button>
                                    @endif
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                {{-- System --}}
                <div class="tab-pane fade" id="s-system">
                    <div class="gbx-card mb-3">
                        <div class="gbx-card-header"><h2><i class="bi bi-server"></i> System</h2></div>
                        <div class="gbx-card-body">
                            <form data-ajax data-no-reset action="{{ route('settings.system') }}" class="row g-3" style="max-width:720px">
                                <div class="col-md-6">
                                    <label class="form-label">Hostname</label>
                                    <input type="text" name="hostname" class="form-control font-mono" value="{{ $info['hostname'] }}" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Timezone</label>
                                    <select name="timezone" class="form-select">
                                        @foreach ($timezones as $tz)
                                            <option value="{{ $tz }}" @selected($tz === $timezone)>{{ $tz }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-12"><button class="btn btn-primary" type="submit"><i class="bi bi-check2"></i> Save system settings</button></div>
                            </form>
                        </div>
                    </div>

                    <div class="gbx-card">
                        <div class="gbx-card-header"><h2><i class="bi bi-lightning"></i> Maintenance</h2></div>
                        <div class="gbx-card-body">
                            <div class="row g-3">
                                @foreach ([
                                    ['update_system', 'bi-cloud-arrow-down', 'Update system', 'apt update and upgrade all packages.', 'Run a full system package upgrade now?', false],
                                    ['restart_panel', 'bi-arrow-clockwise', 'Restart panel', 'Reload Apache, PHP-FPM and the queue worker.', 'Restart panel services?', false],
                                    ['clear_cache', 'bi-eraser', 'Clear panel cache', 'Clear cached settings, software detection and views.', null, false],
                                    ['reboot', 'bi-power', 'Reboot server', 'All services are unavailable until the server is back.', 'Reboot the server now?', true],
                                ] as [$action, $icon, $label, $desc, $confirm, $danger])
                                    <div class="col-md-6">
                                        <div class="soft-mini">
                                            <div class="soft-icon"><i class="bi {{ $icon }}"></i></div>
                                            <div class="min-w-0 flex-grow-1"><div class="name">{{ $label }}</div><div class="cell-sub">{{ $desc }}</div></div>
                                            <button class="btn btn-sm {{ $danger ? 'btn-outline-danger' : 'btn-outline-secondary' }}" data-post="{{ route('settings.action') }}" data-payload='{"action":"{{ $action }}"}' @if($confirm) data-confirm="{{ $confirm }}" @endif @if($danger) data-danger @endif>Run</button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                {{-- About --}}
                <div class="tab-pane fade" id="s-about">
                    <div class="gbx-card">
                        <div class="gbx-card-header"><h2><i class="bi bi-info-circle"></i> About</h2></div>
                        <div class="gbx-card-body">
                            <div class="d-flex align-items-center gap-3 mb-4">
                                <div class="gbx-logo" style="width:52px;height:52px;font-size:1rem">GBX</div>
                                <div><div class="h5 mb-0">GBX Panel {{ config('gbx.version') }}</div><div class="text-muted">Laravel {{ app()->version() }} &middot; PHP {{ $info['php'] }} &middot; SQLite</div></div>
                            </div>
                            <dl class="kv">
                                <dt>Hostname</dt><dd>{{ $info['hostname'] }}</dd>
                                <dt>Operating system</dt><dd>{{ $info['os'] }}</dd>
                                <dt>Kernel</dt><dd class="font-mono">{{ $info['kernel'] }} ({{ $info['arch'] }})</dd>
                                <dt>CPU</dt><dd>{{ $info['cpu_model'] }} &middot; {{ $info['cores'] }} cores</dd>
                                <dt>Uptime</dt><dd>{{ $info['uptime']['human'] }}</dd>
                                <dt>Install path</dt><dd class="font-mono">{{ base_path() }}</dd>
                                <dt>CLI</dt><dd><code>gbx</code> (panel info, reset password, change port / entrance)</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
function panelSaved(res) {
    if (res.redirect) {
        Swal.fire({ title: 'Panel is moving', html: 'Open the new address in a few seconds:<br><code>' + GBX.escape(res.redirect) + '</code>', icon: 'info', buttonsStyling: false, confirmButtonText: 'Go now', customClass: { confirmButton: 'btn btn-primary' } })
            .then(function () { window.location.href = res.redirect; });
    }
}

$(function () {
    $('.panel-access').on('click', function () {
        var $b = $(this), action = $b.data('action');
        GBX.confirm({ text: $b.data('confirm'), danger: action === 'ssl_off' || action === 'domain' }).then(function (r) {
            if (!r.isConfirmed) return;
            var redirect = null;
            GBX.post(@json(route('settings.access')), { action: action, domain: $('#panelDomainInput').val().trim(), email: $('#panelLeEmail').val().trim() }, {
                onTaskDone: function (task) {
                    if (task.status !== 'success' || !redirect) return;
                    Swal.fire({ title: 'Panel address', html: 'The panel configuration changed. Continue at:<br><code>' + GBX.escape(redirect) + '</code>', icon: 'success', buttonsStyling: false, confirmButtonText: 'Open', customClass: { confirmButton: 'btn btn-primary' } })
                        .then(function () { window.location.href = redirect; });
                }
            }).done(function (res) { redirect = res.redirect; });
        });
    });

    $('#entryGen').on('click', function () { $('#entryInput').val(GBX.password(8).toLowerCase().replace(/[^a-z0-9]/g, 'x')); });

    var hash = location.hash.substring(1);
    if (hash) {
        var el = $('#settingsNav a[href="#' + hash + '"]')[0];
        if (el) bootstrap.Tab.getOrCreateInstance(el).show();
    }
    $('#settingsNav a').on('shown.bs.tab', function () { history.replaceState(null, '', $(this).attr('href')); });
});
</script>
@endpush
