@extends('layouts.app')

@section('title', 'Websites')

@php $canWrite = auth()->user()->canWrite(); @endphp

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/vendor/datatables/dataTables.bootstrap5.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vendor/codemirror/codemirror.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vendor/codemirror/theme/material-darker.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vendor/codemirror/addon/dialog.css') }}">
@endpush

@section('content')
    <div class="page-head">
        <div>
            <h2>Websites</h2>
            <p>Apache virtual hosts with PHP-FPM, SSL certificates, Git deployments, backups and usage.</p>
        </div>
        <div class="actions">
            @if ($canWrite)
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#siteModal"><i class="bi bi-plus-lg"></i> Add website</button>
            @endif
        </div>
    </div>

    @if (empty($phpVersions))
        <div class="alert alert-warning d-flex align-items-center gap-2"><i class="bi bi-exclamation-triangle"></i> No PHP-FPM version detected. Websites will be created as static sites. <a href="{{ route('home.software') }}" class="ms-auto btn btn-sm btn-outline-secondary">Install PHP</a></div>
    @endif

    <div class="gbx-card">
        <div class="gbx-card-body p-0">
            @if ($websites->isEmpty())
                <div class="empty-state">
                    <div class="icon"><i class="bi bi-globe2"></i></div>
                    <h3>No websites yet</h3>
                    <p>Create your first website. Point the domain DNS (A record) to this server before requesting SSL.</p>
                    @if ($canWrite)
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#siteModal"><i class="bi bi-plus-lg"></i> Add website</button>
                    @endif
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover ws-table" id="sitesTable">
                        <thead>
                        <tr>
                            <th class="ws-check"><input type="checkbox" class="form-check-input" id="wsAll"></th>
                            <th>Site name</th>
                            <th>Status</th>
                            <th>Backup</th>
                            <th>Quick action</th>
                            <th>Expiration</th>
                            <th>SSL</th>
                            <th>Requests <i class="bi bi-question-circle cell-sub" title="Requests in the last 24 hours, read from the access log"></i></th>
                            <th class="text-end">Operate</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($websites as $site)
                            @php
                                $days = $site->ssl_expires_at ? (int) now()->diffInDays($site->ssl_expires_at, false) : null;
                                $scheme = $site->ssl_enabled ? 'https' : 'http';
                                $proxy = $site->proxies();
                            @endphp
                            <tr data-id="{{ $site->id }}" data-domain="{{ $site->domain }}">
                                <td class="ws-check"><input type="checkbox" class="form-check-input ws-row-check" value="{{ $site->id }}"></td>
                                <td data-order="{{ $site->domain }}" data-search="{{ $site->domain }} {{ $site->aliases }} {{ $site->notes }}">
                                    <div class="ws-name">
                                        <a href="#" class="cell-strong ws-conf" data-tab="domains">{{ $site->domain }}</a>
                                        <a href="{{ $scheme }}://{{ $site->domain }}" target="_blank" rel="noopener" class="ws-visit" title="Open website"><i class="bi bi-box-arrow-up-right"></i></a>
                                    </div>
                                    <a href="#" class="ws-remark {{ $site->notes ? '' : 'empty' }}" data-notes="{{ $site->notes }}" @if (! $canWrite) aria-disabled="true" @endif>{{ $site->notes ?: ($canWrite ? 'Add remark' : '') }}</a>
                                </td>
                                <td data-order="{{ $site->status }}">
                                    @if ($site->status === 'active')
                                        <button class="ws-status on" data-post="{{ route('websites.status', $site) }}" data-confirm="Stop {{ $site->domain }}? Visitors will see a stopped page." data-reload title="Running, click to stop" @disabled(! $canWrite)><i class="bi bi-play-circle-fill"></i><span>Running</span></button>
                                    @else
                                        <button class="ws-status off" data-post="{{ route('websites.status', $site) }}" data-confirm="Start {{ $site->domain }}?" data-reload title="Stopped, click to start" @disabled(! $canWrite)><i class="bi bi-pause-circle-fill"></i><span>{{ $site->isExpired() ? 'Expired' : 'Stopped' }}</span></button>
                                    @endif
                                </td>
                                <td data-order="{{ $backupCounts[$site->id] ?? 0 }}">
                                    <a href="#" class="ws-backup" title="Backups">
                                        <i class="bi bi-cloud-arrow-up"></i>
                                        <span><strong class="{{ ($backupCounts[$site->id] ?? 0) ? 'text-success' : 'text-warning' }}">{{ $backupCounts[$site->id] ?? 0 }} Backup</strong><small>{{ ($backupCounts[$site->id] ?? 0) ? 'Manage backups' : 'Click to backup' }}</small></span>
                                    </a>
                                </td>
                                <td>
                                    <div class="ws-quick">
                                        <a href="#" data-edit-root="{{ $site->root_path }}" title="Open in code editor"><i class="bi bi-code-slash"></i></a>
                                        <a href="{{ route('files.index', ['path' => $site->root_path]) }}" title="File manager: {{ $site->root_path }}"><i class="bi bi-folder2-open"></i></a>
                                        <a href="#" class="ws-conf" data-tab="git" title="Git deployment"><i class="bi bi-git {{ $site->setting('git.repo') ? 'text-success' : '' }}"></i></a>
                                        @if ($proxy)
                                            <a href="#" class="ws-conf ws-php" data-tab="proxy" title="Reverse proxy {{ $proxy[0]['target'] }}">Proxy</a>
                                        @else
                                            <a href="#" class="ws-conf ws-php" data-tab="php" title="PHP version">{{ $site->php_version ?: 'Static' }}</a>
                                        @endif
                                    </div>
                                </td>
                                <td data-order="{{ $site->expires_at?->toDateString() ?? '9999-12-31' }}">
                                    <a href="#" class="ws-expire {{ $site->isExpired() ? 'text-danger' : '' }}" data-date="{{ $site->expires_at?->toDateString() }}" @if (! $canWrite) aria-disabled="true" @endif>{{ $site->expires_at ? $site->expires_at->toDateString() : 'Perpetual' }}</a>
                                </td>
                                <td data-order="{{ $site->ssl_enabled ? ($days ?? 999) : -1 }}">
                                    @if ($site->ssl_enabled)
                                        <a href="#" class="ws-conf ws-ssl {{ $days !== null && $days < 0 ? 'text-danger' : ($days !== null && $days < 15 ? 'text-warning' : 'text-success') }}" data-tab="ssl">{{ $days !== null ? ($days < 0 ? 'Expired' : $days.' Days') : 'Enabled' }}</a>
                                    @else
                                        <a href="#" class="ws-conf ws-ssl text-warning" data-tab="ssl">Not Set</a>
                                    @endif
                                </td>
                                <td class="ws-requests" data-order="0">
                                    <span class="ws-req-count">-</span>
                                    <svg class="ws-spark" viewBox="0 0 120 26" preserveAspectRatio="none"></svg>
                                </td>
                                <td class="text-end text-nowrap">
                                    <a href="#" class="ws-op ws-conf" data-tab="domains">Conf</a>
                                    <a href="#" class="ws-op ws-log">Log</a>
                                    <div class="dropdown d-inline-block">
                                        <button class="btn btn-sm btn-ghost btn-icon" data-bs-toggle="dropdown" title="More"><i class="bi bi-three-dots-vertical"></i></button>
                                        <div class="dropdown-menu dropdown-menu-end">
                                            <a class="dropdown-item" href="{{ $scheme }}://{{ $site->domain }}" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Visit website</a>
                                            <a class="dropdown-item" href="{{ route('files.index', ['path' => $site->root_path]) }}"><i class="bi bi-folder2-open"></i> File manager</a>
                                            <a class="dropdown-item ws-conf" href="#" data-tab="rewrite"><i class="bi bi-arrow-repeat"></i> URL rewrite</a>
                                            <a class="dropdown-item ws-conf" href="#" data-tab="config"><i class="bi bi-file-earmark-code"></i> Virtual host config</a>
                                            @if ($canWrite)
                                                <a class="dropdown-item" href="#" data-post="{{ route('security.antivirus.scan.website', $site) }}" data-confirm="Scan {{ $site->root_path }} for malware with ClamAV?"><i class="bi bi-bug"></i> Scan for malware</a>
                                                <div class="dropdown-divider"></div>
                                                <a class="dropdown-item text-danger delete-site" href="#" data-url="{{ route('websites.destroy', $site) }}" data-domain="{{ $site->domain }}"><i class="bi bi-trash"></i> Delete</a>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($canWrite)
                    <div class="ws-bulk">
                        <span class="cell-sub" id="wsSelected">0 selected</span>
                        <select class="form-select form-select-sm w-auto" id="wsBulkAction">
                            <option value="">Please choose</option>
                            <option value="start">Start</option>
                            <option value="stop">Stop</option>
                            <option value="backup">Backup</option>
                        </select>
                        <button class="btn btn-sm btn-outline-secondary" id="wsBulkRun" disabled>Execute</button>
                    </div>
                @endif
            @endif
        </div>
    </div>
@endsection

@push('modals')
    @include('websites.partials.create-modal')

    {{-- site settings --}}
    <div class="modal fade site-modal" id="confModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-truncate">Site modification [<span id="confDomain"></span>] <span class="cell-sub d-none d-md-inline">-- Time added [<span id="confCreated"></span>]</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0" id="confBody"></div>
            </div>
        </div>
    </div>

    {{-- usage and logs --}}
    <div class="modal fade site-modal" id="usageModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-truncate"><i class="bi bi-activity"></i> Usage [<span id="usageDomain"></span>]</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                        <ul class="nav nav-pills gbx-pills" id="usageTabs">
                            <li class="nav-item"><button class="nav-link active" data-view="overview" type="button"><i class="bi bi-bar-chart"></i> Overview</button></li>
                            <li class="nav-item"><button class="nav-link" data-view="access" type="button"><i class="bi bi-list-ul"></i> Access log</button></li>
                            <li class="nav-item"><button class="nav-link" data-view="error" type="button"><i class="bi bi-exclamation-octagon"></i> Error log</button></li>
                        </ul>
                        <div class="ms-auto d-flex gap-2 align-items-center" data-usage-tools="overview">
                            <select class="form-select form-select-sm w-auto" id="usageRange">
                                <option value="today">Today</option>
                                <option value="24h" selected>Last 24 hours</option>
                                <option value="7d">Last 7 days</option>
                            </select>
                            <button class="btn btn-sm btn-outline-secondary" data-usage-refresh title="Refresh"><i class="bi bi-arrow-clockwise"></i></button>
                        </div>
                        <div class="ms-auto d-flex gap-2 align-items-center d-none" data-usage-tools="log">
                            <input type="search" class="form-control form-control-sm" id="logFilter" placeholder="Filter lines" style="width: 180px">
                            <select class="form-select form-select-sm w-auto" id="logLines">
                                <option value="200">200 lines</option>
                                <option value="500" selected>500 lines</option>
                                <option value="2000">2000 lines</option>
                            </select>
                            <button class="btn btn-sm btn-outline-secondary" data-usage-refresh title="Refresh"><i class="bi bi-arrow-clockwise"></i></button>
                            @if ($canWrite)
                                <button class="btn btn-sm btn-outline-danger" id="logClear" title="Clear log"><i class="bi bi-eraser"></i></button>
                            @endif
                        </div>
                    </div>

                    <div data-usage-view="overview">
                        <div class="alert alert-warning small d-none" id="usageNoLog"><i class="bi bi-exclamation-triangle me-1"></i> The access log is disabled for this website (Conf &gt; Directory), so new requests are not counted.</div>
                        <div class="row g-3 mb-3">
                            <div class="col-6 col-lg-3"><div class="us-stat"><span>Requests</span><strong id="usTotal">-</strong></div></div>
                            <div class="col-6 col-lg-3"><div class="us-stat"><span>Unique visitors (IP)</span><strong id="usIps">-</strong></div></div>
                            <div class="col-6 col-lg-3"><div class="us-stat"><span>Bandwidth</span><strong id="usBytes">-</strong></div></div>
                            <div class="col-6 col-lg-3"><div class="us-stat"><span>Errors (4xx / 5xx)</span><strong id="usErrors">-</strong></div></div>
                        </div>
                        <div class="us-panel mb-3">
                            <div class="d-flex align-items-center mb-2"><div class="small-caps">Requests over time</div><div class="ms-auto us-status" id="usStatus"></div></div>
                            <div class="us-chart"><canvas id="usChart"></canvas></div>
                        </div>
                        <div class="row g-3">
                            <div class="col-lg-6"><div class="us-panel"><div class="small-caps mb-2">Top pages</div><table class="table table-sm us-top mb-0" id="usUrls"></table></div></div>
                            <div class="col-lg-6"><div class="us-panel"><div class="small-caps mb-2">Top visitors (IP)</div><table class="table table-sm us-top mb-0" id="usIpList"></table></div></div>
                            <div class="col-lg-4"><div class="us-panel"><div class="small-caps mb-2">Not found (404)</div><table class="table table-sm us-top mb-0" id="us404"></table></div></div>
                            <div class="col-lg-4"><div class="us-panel"><div class="small-caps mb-2">Browsers and bots</div><table class="table table-sm us-top mb-0" id="usAgents"></table></div></div>
                            <div class="col-lg-4"><div class="us-panel"><div class="small-caps mb-2">Referrers</div><table class="table table-sm us-top mb-0" id="usRefs"></table></div></div>
                        </div>
                        <div class="cell-sub mt-2" id="usSince"></div>
                    </div>

                    <div data-usage-view="log" class="d-none">
                        <div class="cell-sub font-mono mb-2" id="logPath"></div>
                        <pre class="gbx-console us-log" id="logOut"></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- backups --}}
    <div class="modal fade" id="backupModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-truncate"><i class="bi bi-cloud-arrow-up"></i> Backups [<span id="backupDomain"></span>]</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    @if ($canWrite)
                        <div class="d-flex flex-wrap gap-3 align-items-center mb-3">
                            <button class="btn btn-primary" id="backupNow"><i class="bi bi-cloud-arrow-up"></i> Backup now</button>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" id="backupDbs" checked>
                                <label class="form-check-label" for="backupDbs">Include linked databases <span class="cell-sub" id="backupDbNames"></span></label>
                            </div>
                        </div>
                    @endif
                    <div class="table-responsive sm-table">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>File</th><th>Size</th><th>Created</th><th class="text-end">Operate</th></tr></thead>
                            <tbody id="backupList"></tbody>
                        </table>
                    </div>
                    <ul class="sm-hints">
                        <li>Archives are stored in {{ config('gbx.paths.backup') }}/site. Restoring extracts the archive over the current files; files that are not in the backup are kept.</li>
                        <li>Database dumps are stored in {{ config('gbx.paths.backup') }}/database and can be restored in Databases.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    {{-- expiration --}}
    <div class="modal fade" id="expireModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <form class="modal-content" id="expireForm">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-calendar-event"></i> Expiration</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">Stop the website after</label>
                    <input type="date" class="form-control" id="expireDate">
                    <div class="d-flex flex-wrap gap-1 mt-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-expire-add="1">+1 month</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-expire-add="3">+3 months</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-expire-add="12">+1 year</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-expire-add="0">Perpetual</button>
                    </div>
                    <div class="form-text mt-2">The website is stopped automatically on the day after this date. Leave empty for no expiration.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
@endpush

@push('vendor')
    <script src="{{ asset('assets/vendor/datatables/dataTables.min.js') }}"></script>
    <script src="{{ asset('assets/vendor/datatables/dataTables.bootstrap5.min.js') }}"></script>
    <script src="{{ asset('assets/vendor/chartjs/chart.umd.min.js') }}"></script>
    <script src="{{ asset('assets/vendor/codemirror/codemirror.js') }}"></script>
    <script src="{{ asset('assets/vendor/codemirror/mode/nginx/nginx.js') }}"></script>
    <script src="{{ asset('assets/vendor/codemirror/addon/searchcursor.js') }}"></script>
    <script src="{{ asset('assets/vendor/codemirror/addon/dialog.js') }}"></script>
    <script src="{{ asset('assets/vendor/codemirror/addon/search.js') }}"></script>
@endpush

@push('scripts')
<script>
    window.GBX_SITES = {
        canWrite: @json($canWrite),
        wwwRoot: @json($wwwRoot),
        routes: {
            stats: @json(route('websites.stats')),
            bulk: @json(route('websites.bulk')),
            base: @json(url('/websites'))
        }
    };
</script>
<script src="{{ asset('assets/js/websites.js') }}?v={{ config('gbx.version') }}"></script>
@endpush
