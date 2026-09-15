@extends('layouts.app')

@section('title', 'Clients')

@section('content')
    <div class="db-tabs">
        <nav class="db-tabs-nav">
            @foreach ($tabs as $key => [$label, $icon])
                <a href="{{ route('clients.index', ['tab' => $key]) }}" class="{{ $key === $tab ? 'active' : '' }}"><i class="bi {{ $icon }}"></i> {{ $label }}</a>
            @endforeach
        </nav>
        <div class="db-tabs-meta">
            <a href="{{ $portalUrl }}" target="_blank" rel="noopener" class="gbx-chip" title="Client sub-panel address"><span class="status-dot {{ $settings['client_portal'] ? 'on' : 'off' }}"></span> <span class="font-mono">{{ $portalUrl }}</span></a>
        </div>
    </div>

    @if ($tab === 'accounts')
        @if ($packages->isEmpty())
            <div class="db-notice cron-notice"><i class="bi bi-info-circle-fill"></i><span>Create a package first: it defines how many websites, databases and FTP accounts a client can have, and the disk and bandwidth limits. <a href="{{ route('clients.index', ['tab' => 'packages']) }}">Packages</a></span></div>
        @endif
        <div class="gbx-card">
            <div class="db-toolbar">
                <button class="btn btn-primary" id="clAdd"><i class="bi bi-plus-lg"></i> Add Account</button>
                <div class="db-toolbar-right">
                    <select class="form-select" id="clPackageFilter">
                        <option value="">All packages</option>
                        @foreach ($packages as $p)
                            <option value="{{ $p->id }}">{{ $p->name }}</option>
                        @endforeach
                    </select>
                    <select class="form-select" id="clStatusFilter"><option value="">All status</option><option value="active">Normal</option><option value="suspended">Suspended</option></select>
                    <div class="db-search"><input type="search" class="form-control" id="clSearch" placeholder="Please enter the username"><i class="bi bi-search"></i></div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover db-table">
                    <thead><tr><th>Username</th><th>Package</th><th>Email Address</th><th>Disk Quota</th><th>Bandwidth</th><th>Resources</th><th>Status</th><th>Expiration Date</th><th>Remarks</th><th class="text-end">Operate</th></tr></thead>
                    <tbody id="clRows"><tr><td colspan="10" class="sm-empty"><i class="bi bi-arrow-repeat spin"></i> Loading</td></tr></tbody>
                </table>
            </div>
        </div>
    @elseif ($tab === 'packages')
        <div class="gbx-card">
            <div class="db-toolbar"><button class="btn btn-primary" data-package="new"><i class="bi bi-plus-lg"></i> Add Package</button></div>
            <div class="table-responsive">
                <table class="table table-hover db-table">
                    <thead><tr><th>Package name</th><th>Websites</th><th>Databases</th><th>FTP</th><th>Disk</th><th>Bandwidth / month</th><th>PHP</th><th>SSL</th><th>Clients</th><th>Remarks</th><th class="text-end">Operate</th></tr></thead>
                    <tbody>
                    @php $lim = fn ($v, $unit = '') => $v === 0 ? 'Unlimited' : $v.$unit; @endphp
                    @forelse ($packages as $p)
                        <tr>
                            <td class="cell-strong">{{ $p->name }}</td>
                            <td>{{ $lim($p->max_websites) }}</td>
                            <td>{{ $lim($p->max_databases) }}</td>
                            <td>{{ $lim($p->max_ftp) }}</td>
                            <td>{{ $p->disk_mb === 0 ? 'Unlimited' : \App\Services\SystemStats::bytes($p->disk_mb * 1048576, 0) }}</td>
                            <td>{{ $p->bandwidth_mb === 0 ? 'Unlimited' : \App\Services\SystemStats::bytes($p->bandwidth_mb * 1048576, 0) }}</td>
                            <td class="small">{{ $p->php_versions ? implode(', ', $p->php_versions) : 'All' }}</td>
                            <td>{!! $p->allow_ssl ? '<span class="badge badge-success">Yes</span>' : '<span class="badge badge-soft">No</span>' !!}</td>
                            <td>{{ $p->clients_count }}</td>
                            <td class="small">{{ $p->notes ?: '-' }}</td>
                            <td class="text-end text-nowrap db-ops">
                                <a href="#" data-package="{{ json_encode($p->only(['id', 'name', 'max_websites', 'max_databases', 'max_ftp', 'disk_mb', 'bandwidth_mb', 'php_versions', 'allow_ssl', 'notes'])) }}">Edit</a>
                                <a href="#" class="text-danger" data-post="{{ route('clients.packages.destroy', $p) }}" data-method="DELETE" data-confirm="Delete package {{ $p->name }}?" data-danger data-reload>Delete</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="11" class="sm-empty"><i class="bi bi-box-seam"></i> No packages yet</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @elseif ($tab === 'storage')
        <div class="gbx-card">
            <div class="db-toolbar">
                <button class="btn btn-outline-secondary" id="clRefreshUsage"><i class="bi bi-arrow-repeat"></i> Measure usage now</button>
                <span class="cell-sub">Disk is measured daily (website folders and MySQL databases); bandwidth and requests hourly from the access logs.</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover db-table">
                    <thead><tr><th>Client</th><th>Package</th><th style="min-width:220px">Disk</th><th style="min-width:220px">Bandwidth (month)</th><th>Website folders</th><th>Measured</th></tr></thead>
                    <tbody id="clStorage"><tr><td colspan="6" class="sm-empty"><i class="bi bi-arrow-repeat spin"></i> Loading</td></tr></tbody>
                </table>
            </div>
        </div>
    @elseif ($tab === 'logs')
        <div class="gbx-card">
            <div class="db-toolbar">
                <div class="db-toolbar-right">
                    <select class="form-select" id="clLogClient"><option value="">All clients</option></select>
                    <div class="db-search"><input type="search" class="form-control" id="clLogSearch" placeholder="Search action"><i class="bi bi-search"></i></div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover db-table">
                    <thead><tr><th>Time</th><th>Client</th><th>Action</th><th>Details</th><th>IP</th></tr></thead>
                    <tbody id="clLogs"><tr><td colspan="5" class="sm-empty"><i class="bi bi-arrow-repeat spin"></i> Loading</td></tr></tbody>
                </table>
            </div>
        </div>
    @else
        <div class="gbx-card">
            <div class="gbx-card-header"><h2><i class="bi bi-gear"></i> Client panel settings</h2></div>
            <div class="gbx-card-body">
                <form data-ajax data-no-reset action="{{ route('clients.settings') }}" class="cron-form" style="max-width: 820px">
                    <div class="cron-row"><label>Client panel</label><div><div class="form-check form-switch pt-2"><input class="form-check-input" type="checkbox" name="client_portal" id="clPortal" @checked($settings['client_portal'])><label class="form-check-label" for="clPortal">Clients can sign in at <span class="font-mono">{{ $portalUrl }}</span></label></div><div class="form-text">The address does not need the security entrance of the administrator panel. It answers only while at least one client exists.</div></div></div>
                    <div class="cron-row"><label>Panel name</label><div><input type="text" name="client_portal_title" class="form-control" maxlength="60" value="{{ $settings['client_portal_title'] }}" required></div></div>
                    <div class="cron-row"><label>When expired</label><div><select name="client_on_expire" class="form-select w-auto"><option value="suspend" @selected($settings['client_on_expire'] === 'suspend')>Suspend the account (stop websites, disable FTP)</option><option value="none" @selected($settings['client_on_expire'] === 'none')>Do nothing</option></select></div></div>
                    <div class="cron-row"><label>Disk quota full</label><div><select name="client_over_disk" class="form-select w-auto"><option value="block" @selected($settings['client_over_disk'] === 'block')>Block new websites, databases and FTP</option><option value="suspend" @selected($settings['client_over_disk'] === 'suspend')>Suspend the account</option><option value="none" @selected($settings['client_over_disk'] === 'none')>Only show the usage</option></select></div></div>
                    <div class="cron-row"><label>Bandwidth used up</label><div><select name="client_over_bandwidth" class="form-select w-auto"><option value="block" @selected($settings['client_over_bandwidth'] === 'block')>Block new websites, databases and FTP</option><option value="suspend" @selected($settings['client_over_bandwidth'] === 'suspend')>Suspend the account</option><option value="none" @selected($settings['client_over_bandwidth'] === 'none')>Only show the usage</option></select></div></div>
                    <div class="cron-warning"><i class="bi bi-shield-exclamation"></i> Isolation: client websites currently run with the shared web user. Only give accounts to trusted clients until per-client isolation (own Linux user and PHP-FPM pool) is enabled.</div>
                    <div class="text-end mt-3"><button type="submit" class="btn btn-primary">Save</button></div>
                </form>
            </div>
        </div>
    @endif
@endsection

@push('modals')
    <div class="modal fade" id="clModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" id="clForm" autocomplete="off">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-badge"></i> <span id="clTitle">Add Account</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-6"><label class="form-label">Username</label><input type="text" name="username" class="form-control font-mono" required pattern="[a-z][a-z0-9_\-]{2,31}"></div>
                        <div class="col-6"><label class="form-label">Name</label><input type="text" name="name" class="form-control" required maxlength="100"></div>
                        <div class="col-12"><label class="form-label">Email Address</label><input type="email" name="email" class="form-control"></div>
                        <div class="col-12">
                            <label class="form-label">Password</label>
                            <div class="input-group">
                                <input type="text" name="password" id="clPass" class="form-control font-mono" minlength="10">
                                <button type="button" class="btn btn-outline-secondary" data-generate="#clPass" title="Generate"><i class="bi bi-shuffle"></i></button>
                            </div>
                            <div class="form-text" id="clPassHint">At least 10 characters with letters and numbers.</div>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Package</label>
                            <select name="package_id" class="form-select">
                                <option value="">No package</option>
                                @foreach ($packages as $p)
                                    <option value="{{ $p->id }}">{{ $p->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6"><label class="form-label">Expiration Date</label><input type="date" name="expires_at" class="form-control"><div class="form-text">Empty: perpetual</div></div>
                        <div class="col-12"><label class="form-label">Remarks</label><input type="text" name="notes" class="form-control" maxlength="255"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Confirm</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="clResourcesModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-diagram-2"></i> Resources of <span id="clResTitle"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <ul class="nav nav-pills mb-3" id="clResTabs">
                        @foreach ($resources as $key => $label)
                            <li class="nav-item"><button class="nav-link {{ $loop->first ? 'active' : '' }}" data-res="{{ $key }}">{{ $label }}</button></li>
                        @endforeach
                    </ul>
                    <div class="cell-sub mb-2">Checked items belong to this client. Databases and FTP accounts of an assigned website follow it. Items of other clients must be removed from them first.</div>
                    <div id="clResList" class="cl-res-list"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="packageModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" id="packageForm" autocomplete="off">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-box-seam"></i> <span id="packageTitle">Add Package</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12"><label class="form-label">Package name</label><input type="text" name="name" class="form-control" required maxlength="100"></div>
                        <div class="col-4"><label class="form-label">Websites</label><input type="number" name="max_websites" class="form-control" min="0" value="1" required></div>
                        <div class="col-4"><label class="form-label">Databases</label><input type="number" name="max_databases" class="form-control" min="0" value="1" required></div>
                        <div class="col-4"><label class="form-label">FTP</label><input type="number" name="max_ftp" class="form-control" min="0" value="1" required></div>
                        <div class="col-6"><label class="form-label">Disk (MB)</label><input type="number" name="disk_mb" class="form-control" min="0" value="1024" required></div>
                        <div class="col-6"><label class="form-label">Bandwidth per month (MB)</label><input type="number" name="bandwidth_mb" class="form-control" min="0" value="10240" required></div>
                        <div class="col-12 cell-sub">0 means unlimited.</div>
                        <div class="col-12">
                            <label class="form-label">PHP versions</label>
                            <div class="d-flex flex-wrap gap-3">
                                @forelse ($phpVersions as $v)
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="php_versions[]" value="{{ $v }}" id="pv{{ str_replace('.', '', $v) }}"><label class="form-check-label" for="pv{{ str_replace('.', '', $v) }}">PHP {{ $v }}</label></div>
                                @empty
                                    <span class="cell-sub">No PHP installed</span>
                                @endforelse
                            </div>
                            <div class="form-text">None checked: every installed version.</div>
                        </div>
                        <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="allow_ssl" id="pkSsl" checked><label class="form-check-label" for="pkSsl">Let's Encrypt SSL</label></div></div>
                        <div class="col-12"><label class="form-label">Remarks</label><input type="text" name="notes" class="form-control" maxlength="255"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Confirm</button>
                </div>
            </form>
        </div>
    </div>
@endpush

@push('styles')
    <style>
        .cl-res-list { max-height: 50vh; overflow-y: auto; border: 1px solid var(--gbx-border); border-radius: var(--gbx-radius); }
        .cl-res-list label { display: flex; align-items: center; gap: .6rem; padding: .5rem .8rem; border-bottom: 1px solid var(--gbx-border); margin: 0; cursor: pointer; }
        .cl-res-list label:last-child { border-bottom: 0; }
        .cl-res-list label.taken { opacity: .55; cursor: not-allowed; }
        .cl-bar { height: 5px; border-radius: 4px; background: var(--gbx-surface-3); overflow: hidden; margin-top: .3rem; }
        .cl-bar i { display: block; height: 100%; background: var(--gbx-success); }
        .cl-bar i.warn { background: var(--gbx-warning); }
        .cl-bar i.crit { background: var(--gbx-danger); }
    </style>
@endpush

@push('scripts')
<script>
    window.GBX_CLIENTS = { tab: @json($tab), base: @json(url('/clients')) };
</script>
<script src="{{ asset('assets/js/clients.js') }}?v={{ config('gbx.version') }}"></script>
@endpush
