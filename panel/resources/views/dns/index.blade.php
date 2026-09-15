@extends('layouts.app')

@section('title', 'DNS')

@php
    $user = auth()->user();
    $canWrite = $user->canWrite();
    $isAdmin = $user->isAdmin();
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/css/dns.css') }}?v={{ config('gbx.version') }}">
@endpush

@section('content')
    <div class="db-tabs">
        <nav class="db-tabs-nav">
            <a href="{{ route('dns.index') }}" class="{{ $tab === 'domains' ? 'active' : '' }}"><i class="bi bi-globe2"></i> Domains</a>
            <a href="{{ route('dns.index', ['tab' => 'providers']) }}" class="{{ $tab === 'providers' ? 'active' : '' }}"><i class="bi bi-plug"></i> DNS API</a>
        </nav>
        <div class="db-tabs-meta">
            <span class="gbx-chip" title="Public IPv4 of this server"><i class="bi bi-hdd-network"></i> Server IP <span class="font-mono">{{ $serverIp }}</span></span>
        </div>
    </div>

    @if ($tab === 'domains')
        @if ($providersCount === 0)
            <div class="gbx-card">
                <div class="empty-state">
                    <div class="icon"><i class="bi bi-plug"></i></div>
                    <h3>Connect a DNS provider</h3>
                    <p>Manage the DNS records of your domains in Cloudflare, Namecheap, GoDaddy, DigitalOcean, Hetzner, Linode, Vultr or Porkbun through their APIs, create records for new websites automatically and issue wildcard SSL certificates.</p>
                    @if ($isAdmin)
                        <a class="btn btn-primary" href="{{ route('dns.index', ['tab' => 'providers', 'add' => 1]) }}"><i class="bi bi-plus-lg"></i> Add DNS API</a>
                    @else
                        <p class="cell-sub mb-0">Ask an administrator to add a DNS API account.</p>
                    @endif
                </div>
            </div>
        @else
            <div class="gbx-card">
                <div class="db-toolbar">
                    @if ($canWrite)
                        <button class="btn btn-outline-secondary" id="dnsSyncAll"><i class="bi bi-arrow-repeat"></i> Sync domains</button>
                    @endif
                    <div class="db-toolbar-right">
                        <select class="form-select" id="dnsProviderFilter"><option value="">All providers</option></select>
                        <div class="db-search"><input type="search" class="form-control" id="dnsSearch" placeholder="Search domain"><i class="bi bi-search"></i></div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover db-table dns-table">
                        <thead><tr><th>Domain</th><th>DNS provider</th><th>Records</th><th>Websites</th><th>Points to</th><th>Synced</th><th class="text-end">Operate</th></tr></thead>
                        <tbody id="dnsZones"><tr><td colspan="7" class="sm-empty"><i class="bi bi-arrow-repeat spin"></i> Loading</td></tr></tbody>
                    </table>
                </div>
            </div>
        @endif
    @else
        <div class="db-notice cron-notice">
            <i class="bi bi-info-circle-fill"></i>
            <span>API keys are stored encrypted and are only used by this server. Records are read and changed live at the provider; removing an account here does not change any record. Namecheap, Vultr and GoDaddy need the IPv4 <span class="font-mono">{{ $serverIp }}</span> allowed in their API settings.</span>
        </div>
        <div class="gbx-card">
            <div class="db-toolbar">
                @if ($isAdmin)
                    <button class="btn btn-primary" id="dnsAddProvider"><i class="bi bi-plus-lg"></i> Add DNS API</button>
                @endif
            </div>
            <div class="table-responsive">
                <table class="table table-hover db-table dns-table">
                    <thead><tr><th>Status</th><th>Name</th><th>Alias</th><th>Account</th><th>Domains</th><th>API-Limit</th><th>Last check</th><th class="text-end">Operate</th></tr></thead>
                    <tbody id="dnsProviders"><tr><td colspan="8" class="sm-empty"><i class="bi bi-arrow-repeat spin"></i> Loading</td></tr></tbody>
                </table>
            </div>
        </div>
        <div class="dns-provider-grid">
            @foreach ($types as $type)
                <div class="dns-provider-card">
                    <span class="dns-logo" style="background: {{ $type['color'] }}"><i class="bi {{ $type['icon'] }}"></i></span>
                    <div class="min-w-0">
                        <div class="fw-semibold">{{ $type['name'] }}</div>
                        <div class="cell-sub">{{ implode(', ', $type['record_types']) }}{{ $type['proxy'] ? ' + proxy' : '' }}</div>
                    </div>
                    <a href="{{ $type['docs'] }}" target="_blank" rel="noopener noreferrer" class="ms-auto small">API key</a>
                </div>
            @endforeach
        </div>
    @endif
@endsection

@push('modals')
    {{-- ========================================================= provider --}}
    <div class="modal fade" id="dnsProviderModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" id="dnsProviderForm" autocomplete="off">
                <div class="modal-header">
                    <h5 class="modal-title" id="dnsProviderTitle">Integrate DNS Provider API</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body cron-form dns-form">
                    <div class="cron-row"><label>Status</label><div><div class="form-check form-switch m-0 pt-2"><input class="form-check-input" type="checkbox" name="is_active" checked></div></div></div>
                    <div class="cron-row">
                        <label>Name</label>
                        <div>
                            <select name="type" class="form-select">
                                @foreach ($types as $type)
                                    <option value="{{ $type['key'] }}">{{ $type['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div id="dnsFields"></div>
                    <div class="cron-row"><label>Alias</label><div><input type="text" name="alias" class="form-control" maxlength="60" placeholder="Please enter an alias"></div></div>
                    <div class="cron-row"><label>API-Limit</label><div><div class="form-check form-switch m-0 pt-2"><input class="form-check-input" type="checkbox" name="rate_limit" id="dnsRateLimit"><label class="form-check-label cell-sub" for="dnsRateLimit">Space requests to stay under the provider rate limit</label></div></div></div>
                    <ul class="dns-notes" id="dnsNotes"></ul>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Confirm</button>
                </div>
            </form>
        </div>
    </div>

    {{-- ========================================================== records --}}
    <div class="modal fade" id="dnsRecordsModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-globe2"></i> <span id="dnsZoneTitle"></span> <span class="cell-sub ms-2" id="dnsZoneProvider"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <div class="db-toolbar">
                        @if ($canWrite)
                            <button class="btn btn-primary btn-sm" id="dnsAddRecord"><i class="bi bi-plus-lg"></i> Add record</button>
                            <button class="btn btn-outline-secondary btn-sm" id="dnsPoint"><i class="bi bi-hdd-network"></i> Point to this server</button>
                        @endif
                        <button class="btn btn-outline-secondary btn-sm btn-icon" id="dnsReload" title="Reload"><i class="bi bi-arrow-clockwise"></i></button>
                        <div class="db-toolbar-right">
                            <select class="form-select form-select-sm" id="dnsTypeFilter"><option value="">All types</option></select>
                            <div class="db-search"><input type="search" class="form-control form-control-sm" id="dnsRecordSearch" placeholder="Search host or value"><i class="bi bi-search"></i></div>
                        </div>
                    </div>

                    @if ($canWrite)
                        <form class="dns-record-form" id="dnsRecordForm" autocomplete="off" hidden>
                            <div class="dns-record-grid">
                                <div><label class="form-label">Type</label><select name="type" class="form-select form-select-sm"></select></div>
                                <div><label class="form-label">Host</label><div class="input-group input-group-sm"><input type="text" name="name" class="form-control font-mono" placeholder="@"><span class="input-group-text font-mono dns-suffix"></span></div></div>
                                <div class="dns-col-value"><label class="form-label">Value</label><input type="text" name="content" class="form-control form-control-sm font-mono" required></div>
                                <div data-for-type="MX"><label class="form-label">Priority</label><input type="number" name="priority" class="form-control form-control-sm" value="10" min="0" max="65535"></div>
                                <div><label class="form-label">TTL</label><select name="ttl" class="form-select form-select-sm">@foreach ($ttls as $seconds => $label)<option value="{{ $seconds }}">{{ $label }}</option>@endforeach</select></div>
                                <div data-proxy><label class="form-label">Proxy</label><div class="form-check form-switch pt-1"><input class="form-check-input" type="checkbox" name="proxied" title="Cloudflare proxy (orange cloud)"></div></div>
                            </div>
                            <div class="d-flex align-items-center gap-2 mt-2">
                                <span class="cell-sub" id="dnsValueHint"></span>
                                <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" id="dnsCancelRecord">Cancel</button>
                                <button type="submit" class="btn btn-sm btn-primary">Save record</button>
                            </div>
                        </form>
                    @endif

                    <div class="table-responsive">
                        <table class="table table-hover db-table dns-table mb-0">
                            <thead><tr><th>Type</th><th>Host</th><th>Value</th><th>TTL</th><th>Priority</th><th data-proxy>Proxy</th><th class="text-end">Operate</th></tr></thead>
                            <tbody id="dnsRecords"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endpush

@push('scripts')
<script>
    window.GBX_DNS = {
        tab: @json($tab),
        canWrite: @json($canWrite),
        isAdmin: @json($isAdmin),
        base: @json(url('/dns')),
        websites: @json(route('websites.index')),
        serverIp: @json($serverIp),
        types: @json($types),
        ttls: @json($ttls),
        openAdd: @json(request()->boolean('add'))
    };
</script>
<script src="{{ asset('assets/js/dns.js') }}?v={{ config('gbx.version') }}"></script>
@endpush
