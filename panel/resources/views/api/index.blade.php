@extends('layouts.app')

@section('title', 'API')

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/css/api.css') }}?v={{ config('gbx.version') }}">
@endpush

@section('content')
    <div class="db-tabs">
        <nav class="db-tabs-nav">
            <a href="{{ route('api.index') }}" class="{{ $tab === 'keys' ? 'active' : '' }}"><i class="bi bi-key"></i> Keys</a>
            <a href="{{ route('api.index', ['tab' => 'webhooks']) }}" class="{{ $tab === 'webhooks' ? 'active' : '' }}"><i class="bi bi-broadcast"></i> Webhooks</a>
            <a href="{{ route('api.index', ['tab' => 'logs']) }}" class="{{ $tab === 'logs' ? 'active' : '' }}"><i class="bi bi-list-columns"></i> Log</a>
            <a href="{{ route('api.index', ['tab' => 'docs']) }}" class="{{ $tab === 'docs' ? 'active' : '' }}"><i class="bi bi-book"></i> Documentation</a>
        </nav>
        <div class="db-tabs-meta">
            <span class="gbx-chip"><i class="bi bi-activity"></i> {{ $callsToday }} calls today</span>
            @if ($errorsToday)
                <span class="gbx-chip chip-danger"><i class="bi bi-exclamation-triangle"></i> {{ $errorsToday }} failed</span>
            @endif
            <span class="gbx-chip"><i class="bi bi-hdd-network"></i> <span class="font-mono">{{ $baseUrl }}</span> <a href="#" data-copy="{{ $baseUrl }}">copy</a></span>
        </div>
    </div>

    @if ($tab === 'keys')
        <div class="db-notice cron-notice">
            <i class="bi bi-shield-lock-fill"></i>
            <span>A key is shown only once, when it is created: the panel keeps just its hash. Give every integration its own key with the smallest set of permissions, and add an address list whenever the caller has a fixed IP.</span>
        </div>
        <div class="gbx-card">
            <div class="db-toolbar">
                <button class="btn btn-primary" id="apiAddKey"><i class="bi bi-plus-lg"></i> Create key</button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover db-table api-table">
                    <thead><tr><th>Status</th><th>Name</th><th>Permissions</th><th>Limits</th><th>Last use</th><th class="text-end">Operate</th></tr></thead>
                    <tbody id="apiKeys"><tr><td colspan="6" class="sm-empty"><i class="bi bi-arrow-repeat spin"></i> Loading</td></tr></tbody>
                </table>
            </div>
        </div>
    @elseif ($tab === 'webhooks')
        <div class="db-notice cron-notice">
            <i class="bi bi-info-circle-fill"></i>
            <span>The panel calls your URL when something happens, so your system does not have to ask all the time. Every message is signed: the header <span class="font-mono">X-GBX-Signature</span> carries <span class="font-mono">sha256=HMAC(secret, body)</span>. A delivery that fails is repeated five times with growing pauses.</span>
        </div>
        <div class="gbx-card">
            <div class="db-toolbar">
                <button class="btn btn-primary" id="apiAddHook"><i class="bi bi-plus-lg"></i> Add webhook</button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover db-table api-table">
                    <thead><tr><th>Status</th><th>Name</th><th>URL</th><th>Events</th><th>Last call</th><th class="text-end">Operate</th></tr></thead>
                    <tbody id="apiHooks"><tr><td colspan="6" class="sm-empty"><i class="bi bi-arrow-repeat spin"></i> Loading</td></tr></tbody>
                </table>
            </div>
        </div>
    @elseif ($tab === 'logs')
        <div class="gbx-card">
            <div class="db-toolbar">
                <button class="btn btn-outline-secondary" id="apiLogRefresh"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                <button class="btn btn-outline-danger" id="apiLogClear"><i class="bi bi-trash"></i> Clear</button>
                <div class="db-toolbar-right">
                    <select class="form-select" id="apiLogKey"><option value="">All keys</option></select>
                    <select class="form-select" id="apiLogOnly"><option value="">All calls</option><option value="errors">Only failures</option></select>
                    <div class="db-search"><input type="search" class="form-control" id="apiLogSearch" placeholder="Search path"><i class="bi bi-search"></i></div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover db-table api-table">
                    <thead><tr><th>When</th><th>Key</th><th>Call</th><th>Status</th><th>Time</th><th>Address</th></tr></thead>
                    <tbody id="apiLog"><tr><td colspan="6" class="sm-empty"><i class="bi bi-arrow-repeat spin"></i> Loading</td></tr></tbody>
                </table>
            </div>
        </div>
    @else
        <div class="gbx-card">
            <div class="gbx-card-body">
                <h5 class="mb-2">How to call the API</h5>
                <p class="cell-sub mb-3">Every call carries the key in the <span class="font-mono">Authorization</span> header. Answers are JSON and always have <span class="font-mono">ok</span>; failures add <span class="font-mono">error.code</span> and <span class="font-mono">error.message</span>. Actions that take longer answer with a task you can follow on <span class="font-mono">GET /tasks/{id}</span>.</p>
                <pre class="api-code">curl -s {{ $baseUrl }}/websites \
  -H "Authorization: Bearer YOUR_KEY"

curl -s -X POST {{ $baseUrl }}/websites \
  -H "Authorization: Bearer YOUR_KEY" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"domain":"example.com","php_version":"8.3","add_www":true,"create_database":true}'</pre>
                <div class="d-flex flex-wrap gap-3 mt-3">
                    <a class="btn btn-outline-secondary btn-sm" href="{{ $baseUrl }}/openapi.json" target="_blank" rel="noopener"><i class="bi bi-filetype-json"></i> OpenAPI document</a>
                    <span class="cell-sub align-self-center">Import it in Postman, Insomnia or any client generator.</span>
                </div>
                <ul class="sm-hints">
                    <li><span class="font-mono">Idempotency-Key</span>: a repeated call with the same value answers the first result instead of acting twice.</li>
                    <li>Rate limit per key; the answer carries <span class="font-mono">X-RateLimit-Limit</span> and <span class="font-mono">X-RateLimit-Remaining</span>.</li>
                    <li>Every answer carries <span class="font-mono">X-Request-Id</span>, the same value shown in the Log tab.</li>
                    <li>A key bound to a client only ever sees and changes the resources of that client.</li>
                </ul>
            </div>
        </div>

        @foreach ($endpoints as $tag => $group)
            <h6 class="api-group-title">{{ $tag }}</h6>
            <div class="gbx-card mb-3">
                <div class="table-responsive">
                    <table class="table db-table api-table api-endpoints">
                        <tbody>
                        @foreach ($group as $endpoint)
                            <tr>
                                <td style="width: 86px"><span class="api-method api-{{ strtolower($endpoint['method']) }}">{{ $endpoint['method'] }}</span></td>
                                <td class="font-mono small">{{ '/api/'.$version.rtrim($endpoint['path'], '/') }}</td>
                                <td>
                                    <div>{{ $endpoint['summary'] }}@if ($endpoint['task'])<span class="badge badge-soft ms-2">task</span>@endif</div>
                                    @if ($endpoint['query'] || $endpoint['body'])
                                        <div class="cell-sub api-params">
                                            @foreach ($endpoint['query'] as $name => $description)
                                                <span><span class="font-mono">?{{ $name }}</span> {{ $description }}</span>
                                            @endforeach
                                            @foreach ($endpoint['body'] as $name => $description)
                                                <span><span class="font-mono">{{ $name }}</span> {{ $description }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap"><span class="cell-sub font-mono">{{ $endpoint['scope'] ?? 'any key' }}</span></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    @endif
@endsection

@push('modals')
    {{-- ============================================================== key --}}
    <div class="modal fade" id="apiKeyModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <form class="modal-content" id="apiKeyForm" autocomplete="off">
                <div class="modal-header">
                    <h5 class="modal-title" id="apiKeyTitle">Create API key</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body cron-form dns-form">
                    <div class="cron-row"><label>Status</label><div><div class="form-check form-switch m-0 pt-2"><input class="form-check-input" type="checkbox" name="is_active" checked></div></div></div>
                    <div class="cron-row"><label>Name</label><div><input type="text" name="name" class="form-control" maxlength="60" required placeholder="Billing system, monitoring, deploy script..."></div></div>
                    <div class="cron-row">
                        <label>Permissions</label>
                        <div>
                            <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" id="apiScopeAll" name="scopes[]" value="*"><label class="form-check-label" for="apiScopeAll">Full access (everything below)</label></div>
                            <div class="api-scopes" id="apiScopes">
                                @foreach ($scopes as $group => $items)
                                    <div class="api-scope-group">
                                        <div class="api-scope-title">{{ ucfirst($group) }}</div>
                                        @foreach ($items as $scope => $label)
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="scopes[]" value="{{ $scope }}" id="scope_{{ str_replace(':', '_', $scope) }}">
                                                <label class="form-check-label" for="scope_{{ str_replace(':', '_', $scope) }}"><span class="font-mono">{{ $scope }}</span> <span class="cell-sub">{{ $label }}</span></label>
                                            </div>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    <div class="cron-row"><label>Allowed addresses</label><div><input type="text" name="allowed_ips" class="form-control font-mono" maxlength="500" placeholder="203.0.113.10, 10.0.0.0/8 (empty: any address)"></div></div>
                    <div class="cron-row"><label>Requests per minute</label><div><input type="number" name="rate_limit" class="form-control cron-num" min="1" max="10000" value="120"></div></div>
                    <div class="cron-row"><label>Expires on</label><div><input type="date" name="expires_at" class="form-control"><div class="form-text">Empty: the key never expires.</div></div></div>
                    <div class="cron-row"><label>Client</label><div><select name="client_id" class="form-select" id="apiKeyClient">
                        <option value="">Whole server (administrator)</option>
                        @foreach ($clients as $client)
                            <option value="{{ $client->id }}">{{ $client->username }}</option>
                        @endforeach
                    </select><div class="form-text">A key bound to a client only reaches the resources of that client.</div></div></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    {{-- ========================================================== webhook --}}
    <div class="modal fade" id="apiHookModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <form class="modal-content" id="apiHookForm" autocomplete="off">
                <div class="modal-header">
                    <h5 class="modal-title" id="apiHookTitle">Add webhook</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body cron-form dns-form">
                    <div class="cron-row"><label>Status</label><div><div class="form-check form-switch m-0 pt-2"><input class="form-check-input" type="checkbox" name="is_active" checked></div></div></div>
                    <div class="cron-row"><label>Name</label><div><input type="text" name="name" class="form-control" maxlength="60" required></div></div>
                    <div class="cron-row"><label>URL</label><div><input type="url" name="url" class="form-control font-mono" maxlength="1000" required placeholder="https://billing.example.com/hooks/gbx"></div></div>
                    <div class="cron-row">
                        <label>Events</label>
                        <div>
                            <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" id="apiEventAll" name="events[]" value="*"><label class="form-check-label" for="apiEventAll">Everything that happens</label></div>
                            <div class="api-scopes" id="apiEvents">
                                @foreach ($events as $event => $label)
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="events[]" value="{{ $event }}" id="event_{{ str_replace('.', '_', $event) }}">
                                        <label class="form-check-label" for="event_{{ str_replace('.', '_', $event) }}"><span class="font-mono">{{ $event }}</span> <span class="cell-sub">{{ $label }}</span></label>
                                    </div>
                                @endforeach
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

    {{-- ======================================================= deliveries --}}
    <div class="modal fade" id="apiDeliveriesModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-send"></i> Deliveries <span class="cell-sub ms-2" id="apiDeliveriesTitle"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <table class="table db-table api-table mb-0">
                        <thead><tr><th>When</th><th>Event</th><th>Status</th><th>Attempts</th><th>Answer</th></tr></thead>
                        <tbody id="apiDeliveries"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endpush

@push('scripts')
<script>
    window.GBX_API = {
        tab: @json($tab),
        base: @json(url('/api-access')),
        apiBase: @json($baseUrl)
    };
</script>
<script src="{{ asset('assets/js/api.js') }}?v={{ config('gbx.version') }}"></script>
@endpush
