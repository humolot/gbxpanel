@extends('layouts.app')

@section('title', 'Backup')

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/css/backup.css') }}?v={{ config('gbx.version') }}">
@endpush

@section('content')
    <div class="db-tabs">
        <nav class="db-tabs-nav">
            <a href="{{ route('backup.index') }}" class="{{ $tab === 'transfers' ? 'active' : '' }}"><i class="bi bi-arrow-left-right"></i> Transfers</a>
            <a href="{{ route('backup.index', ['tab' => 'local']) }}" class="{{ $tab === 'local' ? 'active' : '' }}"><i class="bi bi-hdd"></i> Local backups</a>
            <a href="{{ route('backup.index', ['tab' => 'storage']) }}" class="{{ $tab === 'storage' ? 'active' : '' }}"><i class="bi bi-cloud"></i> Storage</a>
            <a href="{{ route('backup.index', ['tab' => 'settings']) }}" class="{{ $tab === 'settings' ? 'active' : '' }}"><i class="bi bi-sliders"></i> Settings</a>
        </nav>
        <div class="db-tabs-meta">
            @if ($failed24h)
                <a href="{{ route('backup.index') }}" class="gbx-chip chip-danger"><i class="bi bi-exclamation-triangle"></i> {{ $failed24h }} failed in 24 h</a>
            @endif
            <span class="gbx-chip" title="rclone moves the backups to the destinations">
                <i class="bi bi-box-seam"></i> rclone
                @if ($rclone['installed'])
                    <span class="font-mono">{{ $rclone['version'] ?? 'installed' }}</span>
                @else
                    <a href="#" id="bkInstall">install</a>
                @endif
            </span>
        </div>
    </div>

    @if (! $rclone['installed'])
        <div class="db-notice cron-notice">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span>rclone is not installed on this server, so backups cannot be sent anywhere yet. <a href="#" id="bkInstall2">Install it now</a> (about 20 MB, from the official rclone release).</span>
        </div>
    @endif

    @if ($tab === 'transfers')
        <div class="gbx-card">
            <div class="db-toolbar">
                <button class="btn btn-outline-secondary" id="bkRefresh"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                <div class="db-toolbar-right">
                    <select class="form-select" id="bkStatus">
                        <option value="">All transfers</option>
                        <option value="active">Running and queued</option>
                        <option value="failed">Failed</option>
                        <option value="success">Finished</option>
                    </select>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover db-table bk-table">
                    <thead><tr><th>Status</th><th>Backup</th><th>Destination</th><th>Size</th><th>Progress</th><th>Finished</th><th class="text-end">Operate</th></tr></thead>
                    <tbody id="bkTransfers"><tr><td colspan="7" class="sm-empty"><i class="bi bi-arrow-repeat spin"></i> Loading</td></tr></tbody>
                </table>
            </div>
            <div class="gbx-card-body py-2 cell-sub border-top border-soft">
                Transfers run outside the panel, so they continue while you work and after you close the browser. A failed upload can be started again: parts that already arrived are not sent twice.
            </div>
        </div>
    @elseif ($tab === 'local')
        <div class="gbx-card">
            <div class="db-toolbar">
                <div class="db-toolbar-right">
                    <select class="form-select" id="bkLocalType">
                        <option value="">All backups</option>
                        <option value="site">Websites</option>
                        <option value="database">Databases</option>
                        <option value="path">Directories</option>
                    </select>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover db-table bk-table">
                    <thead><tr><th>File</th><th>Type</th><th>Size</th><th>Created</th><th class="text-end">Operate</th></tr></thead>
                    <tbody id="bkLocal"><tr><td colspan="5" class="sm-empty"><i class="bi bi-arrow-repeat spin"></i> Loading</td></tr></tbody>
                </table>
            </div>
            <div class="gbx-card-body py-2 cell-sub border-top border-soft">Backups on this server, in <span class="font-mono">{{ $backupRoot }}</span>. Send one to a storage to keep a copy off this machine.</div>
        </div>
    @elseif ($tab === 'storage')
        <div class="db-notice cron-notice">
            <i class="bi bi-info-circle-fill"></i>
            <span>Credentials are stored encrypted and are only used by this server. Removing a destination here never deletes the backups already stored in it.</span>
        </div>
        <div class="gbx-card">
            <div class="db-toolbar">
                <button class="btn btn-primary" id="bkAddStorage"><i class="bi bi-plus-lg"></i> Add storage</button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover db-table bk-table">
                    <thead><tr><th>Status</th><th>Name</th><th>Destination</th><th>Used</th><th>Last check</th><th class="text-end">Operate</th></tr></thead>
                    <tbody id="bkStorages"><tr><td colspan="6" class="sm-empty"><i class="bi bi-arrow-repeat spin"></i> Loading</td></tr></tbody>
                </table>
            </div>
        </div>
        @foreach ($groups as $group => $groupName)
            <h6 class="bk-group-title">{{ $groupName }}</h6>
            <div class="dns-provider-grid">
                @foreach (collect($types)->where('group', $group) as $type)
                    <div class="dns-provider-card bk-type" data-type="{{ $type['key'] }}" role="button">
                        <span class="dns-logo" style="background: {{ $type['color'] }}"><i class="bi {{ $type['icon'] }}"></i></span>
                        <div class="min-w-0">
                            <div class="fw-semibold text-truncate">{{ $type['name'] }}</div>
                            <div class="cell-sub">{{ $type['bucket'] ? 'Bucket' : ($type['split'] ? 'Sent in parts' : 'Resumable upload') }}</div>
                        </div>
                        <i class="bi bi-plus-lg ms-auto"></i>
                    </div>
                @endforeach
            </div>
        @endforeach
    @else
        <div class="gbx-card">
            <form class="gbx-card-body" id="bkSettingsForm" autocomplete="off">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label">Webhook on failure</label>
                        <input type="url" name="webhook" class="form-control font-mono" maxlength="1000" value="{{ $settings['webhook'] }}" placeholder="https://hooks.example.com/backup (optional)">
                        <div class="form-text">The panel sends a JSON message when a transfer fails. Failures are always written to the activity log.</div>
                    </div>
                    <div class="col-md-4 d-flex align-items-center">
                        <div class="form-check form-switch mt-4">
                            <input class="form-check-input" type="checkbox" name="webhook_success" id="bkWebhookOk" @checked($settings['webhook_success'])>
                            <label class="form-check-label" for="bkWebhookOk">Also call it after a successful transfer</label>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">Bandwidth limit</label>
                        <input type="text" name="bwlimit" class="form-control" maxlength="20" value="{{ $settings['bwlimit'] }}" placeholder="10M">
                        <div class="form-text">Per transfer. Empty: no limit.</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">Part size (MB)</label>
                        <input type="number" name="split_mb" class="form-control" min="64" max="20480" value="{{ $settings['split_mb'] }}">
                        <div class="form-text">FTP, SFTP and WebDAV only.</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">Transfers at the same time</label>
                        <input type="number" name="max_parallel" class="form-control" min="1" max="10" value="{{ $settings['max_parallel'] }}">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">Keep history (days)</label>
                        <input type="number" name="history_days" class="form-control" min="1" max="365" value="{{ $settings['history_days'] }}">
                    </div>
                </div>
                <div class="mt-3"><button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> Save</button></div>
            </form>
        </div>
    @endif
@endsection

@push('modals')
    {{-- ========================================================== storage --}}
    <div class="modal fade" id="bkStorageModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <form class="modal-content" id="bkStorageForm" autocomplete="off">
                <div class="modal-header">
                    <h5 class="modal-title" id="bkStorageTitle">Add storage</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body cron-form dns-form">
                    <div class="cron-row"><label>Status</label><div><div class="form-check form-switch m-0 pt-2"><input class="form-check-input" type="checkbox" name="is_active" checked></div></div></div>
                    <div class="cron-row">
                        <label>Type</label>
                        <div>
                            <select name="type" class="form-select">
                                @foreach ($groups as $group => $groupName)
                                    <optgroup label="{{ $groupName }}">
                                        @foreach (collect($types)->where('group', $group) as $type)
                                            <option value="{{ $type['key'] }}">{{ $type['name'] }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="cron-row"><label>Name</label><div><input type="text" name="name" class="form-control" maxlength="60" required placeholder="How this destination is shown in the panel"></div></div>
                    <div id="bkFields"></div>
                    <div class="cron-row">
                        <label>Folder</label>
                        <div><input type="text" name="folder" class="form-control font-mono" maxlength="255" placeholder="backups/server1"><div class="form-text" id="bkFolderHint">Backups are stored under this folder, in site/, database/ and path/ subfolders.</div></div>
                    </div>
                    <div class="cron-row"><label>Bandwidth limit</label><div><input type="text" name="bwlimit" class="form-control" maxlength="20" placeholder="Empty: the value from Settings"></div></div>
                    <div id="bkOauth" hidden>
                        <div class="bk-oauth">
                            <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="bkGoogleConnect" hidden><i class="bi bi-google"></i> Connect with Google</button>
                                <span class="cell-sub" id="bkOauthHint"></span>
                            </div>
                            <div class="mb-2" id="bkRedirect" hidden>
                                <div class="cell-sub">Redirect URI of the OAuth client:</div>
                                <div class="d-flex gap-2 align-items-center"><code class="bk-code flex-grow-1">{{ $redirectUri }}</code><a href="#" data-copy="{{ $redirectUri }}" class="small">copy</a></div>
                                @unless ($secureRequest)
                                    <div class="cell-sub text-warning mt-1"><i class="bi bi-exclamation-triangle"></i> This panel is open without HTTPS. Google refuses such a redirect: use the paste code method below.</div>
                                @endunless
                            </div>
                            <div>
                                <div class="cell-sub">Paste code: run this on a computer with a browser, then paste the answer in the Token field.</div>
                                <div class="d-flex gap-2 align-items-center"><code class="bk-code flex-grow-1" id="bkAuthorize"></code><a href="#" class="small" id="bkAuthorizeCopy">copy</a></div>
                            </div>
                        </div>
                    </div>
                    <ul class="dns-notes" id="bkNotes"></ul>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save and test</button>
                </div>
            </form>
        </div>
    </div>

    {{-- =========================================================== browse --}}
    <div class="modal fade" id="bkBrowseModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-cloud"></i> <span id="bkBrowseTitle"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="fm-path mb-2"><div class="crumbs" id="bkCrumbs"></div></div>
                    <div class="table-responsive">
                        <table class="table table-hover db-table bk-table mb-0">
                            <thead><tr><th>Name</th><th>Size</th><th>Date</th><th class="text-end">Operate</th></tr></thead>
                            <tbody id="bkBrowseList"></tbody>
                        </table>
                    </div>
                    <ul class="sm-hints">
                        <li>Download sends the backup straight to your browser through this server; Download to server puts it back in {{ $backupRoot }}.</li>
                        <li>Restore downloads the backup first and then restores it over the website or database it came from.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    {{-- ============================================================== log --}}
    <div class="modal fade" id="bkLogModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-journal-text"></i> Transfer log <span class="cell-sub ms-2" id="bkLogTitle"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0"><pre class="bk-log" id="bkLog"></pre></div>
            </div>
        </div>
    </div>

    {{-- =========================================================== upload --}}
    <div class="modal fade" id="bkUploadModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" id="bkUploadForm">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-cloud-arrow-up"></i> Send backup to a storage</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="font-mono small text-break" id="bkUploadFile"></p>
                    <div class="mb-3">
                        <label class="form-label">Storage</label>
                        <select name="storage_id" class="form-select" required>
                            @foreach ($storages->where('is_active', true) as $storage)
                                <option value="{{ $storage->id }}">{{ $storage->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3"><label class="form-label">Copies to keep at the destination</label><input type="number" name="keep" class="form-control" min="1" max="365" value="30"></div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="delete_local" id="bkDeleteLocal">
                        <label class="form-check-label" for="bkDeleteLocal">Delete the local copy after a successful upload</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Send</button>
                </div>
            </form>
        </div>
    </div>
@endpush

@push('scripts')
<script>
    window.GBX_BACKUP = {
        tab: @json($tab),
        base: @json(url('/backup')),
        types: @json($types),
        redirectUri: @json($redirectUri),
        hasStorages: @json($storages->where('is_active', true)->count() > 0),
        openAdd: @json($openAdd)
    };
</script>
<script src="{{ asset('assets/js/backup.js') }}?v={{ config('gbx.version') }}"></script>
@endpush
