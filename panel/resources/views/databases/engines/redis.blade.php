<div class="gbx-card">
    @if (! $installed && $servers->isEmpty())
        <div class="empty-state">
            <div class="icon"><i class="bi bi-lightning-charge"></i></div>
            <h3>Redis is not installed</h3>
            <p>Install Redis on this server or connect a remote Redis to browse keys and manage its settings.</p>
            @if ($canWrite)
                <div class="d-flex gap-2 justify-content-center flex-wrap">
                    <button class="btn btn-primary" data-post="{{ route('home.software.install') }}" data-payload='{"key":"redis"}' data-confirm="Install Redis on this server?"><i class="bi bi-download"></i> Click install</button>
                    <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#serversModal"><i class="bi bi-hdd-network"></i> Add Remote DB</button>
                </div>
            @endif
        </div>
    @else
        <div class="db-toolbar">
            <select class="form-select w-auto" id="rdLocation">
                @if ($installed)<option value="">Localhost</option>@endif
                @foreach ($servers as $server)
                    <option value="{{ $server->id }}">{{ $server->label() }}</option>
                @endforeach
            </select>
            <select class="form-select w-auto" id="rdDb"></select>
            @if ($canWrite)
                <button class="btn btn-primary" id="rdAdd"><i class="bi bi-plus-lg"></i> Add key</button>
                <button class="btn btn-outline-secondary" id="rdFlush">Flush DB</button>
                <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#rdSettingsModal">Settings</button>
                <button class="btn btn-outline-secondary" id="rdBackups" @if (! $installed) hidden @endif>Backup</button>
                <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#serversModal">Remote DB{{ $servers->count() ? ' ('.$servers->count().')' : '' }}</button>
            @endif
            <div class="db-toolbar-right">
                <div class="db-search"><input type="search" class="form-control" id="rdPattern" placeholder="Key pattern, e.g. session:*"><i class="bi bi-search"></i></div>
                <button class="btn btn-outline-secondary btn-icon" id="rdRefresh" title="Refresh"><i class="bi bi-arrow-clockwise"></i></button>
            </div>
        </div>

        <div class="p-3 d-none" id="rdError">
            <div class="alert alert-danger mb-0">
                <div class="d-flex align-items-start gap-2"><i class="bi bi-exclamation-octagon mt-1"></i><span id="rdErrorText"></span></div>
                <form class="d-flex gap-2 mt-2 d-none" id="rdAuthForm" autocomplete="off">
                    <input type="password" class="form-control form-control-sm" name="password" placeholder="Redis password (requirepass)" autocomplete="new-password" style="max-width: 320px">
                    <button class="btn btn-sm btn-primary" type="submit">Connect</button>
                </form>
            </div>
        </div>

        <div class="db-stats" id="rdStats">
            <div><span>Version</span><strong data-stat="version">-</strong></div>
            <div><span>Memory used</span><strong data-stat="memory">-</strong></div>
            <div><span>Clients</span><strong data-stat="clients">-</strong></div>
            <div><span>Uptime</span><strong data-stat="uptime">-</strong></div>
            <div><span>Hit rate</span><strong data-stat="hits">-</strong></div>
            <div><span>Ops / second</span><strong data-stat="ops">-</strong></div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover db-table mb-0">
                <thead><tr><th class="ws-check"><input type="checkbox" class="form-check-input" id="rdAll"></th><th>Key</th><th>Type</th><th>TTL</th><th class="text-end">Size</th><th class="text-end">Operate</th></tr></thead>
                <tbody id="rdKeys"><tr><td colspan="6" class="sm-empty"><i class="bi bi-arrow-repeat spin"></i> Loading</td></tr></tbody>
            </table>
        </div>
        <div class="ws-bulk">
            @if ($canWrite)<button class="btn btn-sm btn-outline-danger" id="rdDeleteSelected" disabled>Delete selected</button>@endif
            <span class="cell-sub" id="rdSummary"></span>
            <button class="btn btn-sm btn-outline-secondary ms-auto d-none" id="rdMore">Load more</button>
        </div>
    @endif
</div>

@push('modals')
    <div class="modal fade" id="rdKeyModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <form class="modal-content" id="rdKeyForm" autocomplete="off">
                <div class="modal-header">
                    <h5 class="modal-title text-truncate"><i class="bi bi-key"></i> <span id="rdKeyTitle">Add key</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Key</label><input type="text" name="key" class="form-control font-mono" required maxlength="1024"></div>
                        <div class="col-md-3">
                            <label class="form-label">Type</label>
                            <select name="type" class="form-select">
                                @foreach (\App\Services\Databases\RedisManager::TYPES as $type)<option>{{ $type }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-md-3"><label class="form-label">TTL (seconds)</label><input type="number" name="ttl" class="form-control" min="0" placeholder="no expiry"></div>
                        <div class="col-12">
                            <label class="form-label d-flex"><span>Value</span><span class="ms-auto cell-sub" id="rdValueHint">text or JSON</span></label>
                            <textarea name="value" class="form-control font-mono" rows="12"></textarea>
                        </div>
                    </div>
                    <div class="cell-sub mt-2" id="rdKeyMeta"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    @if ($canWrite)<button type="submit" class="btn btn-primary">Save</button>@endif
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="rdSettingsModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" id="rdSettingsForm" autocomplete="off">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-sliders"></i> Redis settings</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-6"><label class="form-label">Max memory</label><input type="text" name="maxmemory" class="form-control font-mono" placeholder="0 = unlimited, 512mb, 2gb"></div>
                        <div class="col-6">
                            <label class="form-label">Eviction policy</label>
                            <select name="maxmemory_policy" class="form-select">
                                @foreach (['noeviction', 'allkeys-lru', 'allkeys-lfu', 'allkeys-random', 'volatile-lru', 'volatile-lfu', 'volatile-random', 'volatile-ttl'] as $policy)<option>{{ $policy }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Append-only file (AOF)</label>
                            <select name="appendonly" class="form-select"><option value="no">Off</option><option value="yes">On</option></select>
                        </div>
                        <div class="col-6"><label class="form-label">Snapshots (save)</label><input type="text" class="form-control font-mono" id="rdSave" readonly></div>
                        <div class="col-12">
                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="change_password" id="rdChangePass"><label class="form-check-label" for="rdChangePass">Change password (requirepass)</label></div>
                            <div class="input-group mt-2 d-none" id="rdPassGroup">
                                <input type="text" name="requirepass" id="rdPass" class="form-control font-mono" placeholder="empty removes the password">
                                <button type="button" class="btn btn-secondary" data-generate="#rdPass"><i class="bi bi-magic"></i></button>
                            </div>
                            <div class="form-text">Applications must use the new password immediately. Settings are written to redis.conf with CONFIG REWRITE.</div>
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

    <div class="modal fade" id="rdBackupsModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-archive"></i> Redis backups</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <button class="btn btn-primary mb-3" id="rdBackupNow"><i class="bi bi-cloud-arrow-up"></i> Backup now</button>
                    <div class="table-responsive sm-table">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>File</th><th>Size</th><th>Created</th><th class="text-end">Operate</th></tr></thead>
                            <tbody id="rdBackupList"></tbody>
                        </table>
                    </div>
                    <ul class="sm-hints"><li>A snapshot is taken with BGSAVE and the RDB file copied to {{ rtrim(config('gbx.paths.backup'), '/') }}/database/redis.</li><li>Restoring stops Redis, replaces the dataset and starts it again. It requires append-only persistence to be off.</li></ul>
                </div>
            </div>
        </div>
    </div>
@endpush
