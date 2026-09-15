@php $policies = \App\Services\DockerManager::RESTART_POLICIES; @endphp

{{-- ============================================================ create container --}}
<div class="modal fade" id="dkCreateModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content" id="dkCreateForm" autocomplete="off">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-box"></i> Create Container</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body cron-form">
                <div class="cron-row"><label>Image</label><div><input type="text" name="image" class="form-control font-mono" list="dkImageList" placeholder="nginx:alpine" required><datalist id="dkImageList"></datalist><div class="form-text">Local images are suggested; other images are pulled from Docker Hub.</div></div></div>
                <div class="cron-row"><label>Container name</label><div><input type="text" name="name" class="form-control font-mono" placeholder="my-app"></div></div>
                <div class="cron-row">
                    <label>Network</label>
                    <div class="d-flex flex-wrap gap-2">
                        <select name="network" class="form-select w-auto flex-grow-1" id="dkCreateNetwork"><option value="">bridge (default)</option></select>
                        <select name="restart" class="form-select w-auto">
                            @foreach ($policies as $p)
                                <option value="{{ $p }}" @selected($p === 'unless-stopped')>restart: {{ $p }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="cron-row"><label>Ports</label><div><textarea name="ports" class="form-control font-mono" rows="2" placeholder="8080:80&#10;127.0.0.1:6379:6379"></textarea><div class="form-text">host:container per line. Bind to 127.0.0.1 when a website reverse proxy publishes the app.</div></div></div>
                <div class="cron-row"><label>Volumes</label><div><textarea name="volumes" class="form-control font-mono" rows="2" placeholder="/www/docker/app/data:/data&#10;app_data:/var/lib/app"></textarea></div></div>
                <div class="cron-row"><label>Environment</label><div><textarea name="env" class="form-control font-mono" rows="3" placeholder="TZ=America/Sao_Paulo&#10;APP_ENV=production"></textarea></div></div>
                <div class="cron-row"><label>Labels</label><div><textarea name="labels" class="form-control font-mono" rows="2" placeholder="com.example.team=web"></textarea></div></div>
                <div class="cron-row">
                    <label>Limits</label>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <span class="cell-sub">CPUs</span><input type="number" name="cpus" class="form-control cron-num" min="0" step="0.1" placeholder="1.5">
                        <span class="cell-sub">Memory</span><input type="text" name="memory" class="form-control cron-num" placeholder="512m">
                    </div>
                </div>
                <div class="cron-row"><label>Command</label><div><input type="text" name="command" class="form-control font-mono" placeholder="Optional arguments, e.g. --port 3000"></div></div>
                <div class="cron-row"><label>Note</label><div><input type="text" name="note" class="form-control" maxlength="255"></div></div>
                <div class="cron-row"><label></label><div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="pull" id="dkCreatePull" checked><label class="form-check-label" for="dkCreatePull">Pull the latest image before creating</label></div></div></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Create</button>
            </div>
        </form>
    </div>
</div>

{{-- ============================================================ manage container --}}
<div class="modal fade" id="dkManageModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-box"></i> <span id="dkManageTitle">Container</span> <span id="dkManageState" class="ms-2"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="dk-manage">
                    <nav class="dk-manage-nav">
                        <a href="#" class="active" data-pane="info"><i class="bi bi-info-circle"></i> Overview</a>
                        <a href="#" data-pane="logs"><i class="bi bi-journal-text"></i> Logs</a>
                        <a href="#" data-pane="env"><i class="bi bi-list-ul"></i> Environment</a>
                        <a href="#" data-pane="mounts"><i class="bi bi-hdd"></i> Mounts</a>
                        @if ($canWrite)<a href="#" data-pane="settings"><i class="bi bi-sliders"></i> Settings</a>@endif
                    </nav>
                    <div class="dk-manage-body">
                        <div data-pane-body="info"></div>
                        <div data-pane-body="logs" hidden>
                            <div class="d-flex flex-wrap gap-2 mb-2">
                                <select class="form-select form-select-sm w-auto" data-log-lines><option value="100">Last 100 lines</option><option value="300" selected>Last 300 lines</option><option value="1000">Last 1000 lines</option><option value="5000">Last 5000 lines</option></select>
                                <select class="form-select form-select-sm w-auto" data-log-since><option value="">Any time</option><option value="10m">Last 10 minutes</option><option value="1h">Last hour</option><option value="24h">Last 24 hours</option></select>
                                <button class="btn btn-sm btn-outline-secondary" data-log-refresh><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                                @if ($canWrite)<button class="btn btn-sm btn-outline-secondary ms-auto" data-log-clear><i class="bi bi-eraser"></i> Clear log</button>@endif
                            </div>
                            <pre class="gbx-console" data-log-out style="height:52vh"></pre>
                        </div>
                        <div data-pane-body="env" hidden></div>
                        <div data-pane-body="mounts" hidden></div>
                        @if ($canWrite)
                            <div data-pane-body="settings" hidden>
                                <form class="cron-form" id="dkUpdateForm">
                                    <div class="cron-row"><label>Restart policy</label><div><select name="restart" class="form-select w-auto">@foreach ($policies as $p)<option value="{{ $p }}">{{ $p }}</option>@endforeach</select></div></div>
                                    <div class="cron-row"><label>Limits</label><div class="d-flex flex-wrap gap-2 align-items-center"><span class="cell-sub">CPUs</span><input type="number" name="cpus" class="form-control cron-num" min="0" step="0.1"><span class="cell-sub">Memory</span><input type="text" name="memory" class="form-control cron-num" placeholder="512m"></div></div>
                                    <div class="cron-row"><label></label><div><button type="submit" class="btn btn-primary btn-sm">Apply</button></div></div>
                                </form>
                                <hr>
                                <form class="cron-form" id="dkRenameForm">
                                    <div class="cron-row"><label>Rename</label><div class="d-flex gap-2"><input type="text" name="name" class="form-control font-mono" required><button type="submit" class="btn btn-outline-secondary btn-sm text-nowrap">Rename</button></div></div>
                                </form>
                                <form class="cron-form" id="dkCommitForm">
                                    <div class="cron-row"><label>Save as image</label><div class="d-flex gap-2"><input type="text" name="image" class="form-control font-mono" placeholder="my-app:snapshot" required><button type="submit" class="btn btn-outline-secondary btn-sm text-nowrap">Create image</button></div></div>
                                </form>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
            <div class="modal-footer" id="dkManageFooter"></div>
        </div>
    </div>
</div>

{{-- ============================================================ generic log/output --}}
<div class="modal fade" id="dkOutputModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-terminal"></i> <span id="dkOutputTitle"></span></h5>
                <button class="btn btn-sm btn-outline-secondary ms-auto me-2" id="dkOutputRefresh"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                <button type="button" class="btn-close ms-0" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body"><pre class="gbx-console" id="dkOutputBody" style="height:62vh"></pre></div>
        </div>
    </div>
</div>

{{-- ================================================================== log manage --}}
<div class="modal fade" id="dkLogManageModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-journal-text"></i> Log Manage</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="db-notice cron-notice"><i class="bi bi-info-circle-fill"></i><span>Container logs grow until they are cleared. Limit their size for new containers in Settings (log max size and files).</span></div>
                <table class="table db-table"><thead><tr><th>Container</th><th class="text-end">Log size</th><th class="text-end">Operate</th></tr></thead><tbody id="dkLogSizes"></tbody></table>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-danger" id="dkClearAllLogs"><i class="bi bi-eraser"></i> Clear all logs</button>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

{{-- ======================================================================== pull --}}
<div class="modal fade" id="dkPullModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="dkPullForm" autocomplete="off">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cloud-download"></i> Pull image</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label">Repository</label>
                <select name="registry_id" class="form-select mb-3">
                    <option value="">Docker Hub (public)</option>
                    @foreach ($registries as $r)
                        <option value="{{ $r->id }}">{{ $r->name }} ({{ $r->url }})</option>
                    @endforeach
                </select>
                <label class="form-label">Image</label>
                <input type="text" name="image" class="form-control font-mono" placeholder="nginx:alpine" required>
                <div class="d-flex gap-2 mt-2" id="dkPullTagsWrap" hidden>
                    <select class="form-select form-select-sm" id="dkPullTags"></select>
                </div>
                <div class="form-text">name:tag. With a repository namespace, a name without a slash is placed in it.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Pull</button>
            </div>
        </form>
    </div>
</div>

{{-- ====================================================================== import --}}
<div class="modal fade" id="dkImportModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="dkImportForm" autocomplete="off">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload"></i> Import image</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label">Upload archive</label>
                <input type="file" name="file" class="form-control" accept=".tar,.gz,.tgz,.xz">
                <div class="text-center cell-sub my-2">or</div>
                <label class="form-label">Archive on the server</label>
                <input type="text" name="path" class="form-control font-mono" placeholder="/www/backup/docker/nginx_20260915_101010.tar.gz">
                <div class="form-text">Archives created with docker save (.tar, .tar.gz, .tgz, .tar.xz). Large files are better copied to the server first.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Import</button>
            </div>
        </form>
    </div>
</div>

{{-- ======================================================================= build --}}
<div class="modal fade" id="dkBuildModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form class="modal-content" id="dkBuildForm" autocomplete="off">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-hammer"></i> Build image</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body cron-form">
                <div class="cron-row"><label>Image tag</label><div><input type="text" name="tag" class="form-control font-mono" placeholder="my-app:1.0" required></div></div>
                <div class="cron-row"><label>Build context</label><div><input type="text" name="context" class="form-control font-mono" placeholder="/www/wwwroot/my-app (optional)"><div class="form-text">Directory whose files COPY can use. Empty: build without files.</div></div></div>
                <div class="cron-row"><label>Dockerfile</label><div><textarea name="dockerfile" class="form-control font-mono cron-code" rows="12" spellcheck="false" required placeholder="FROM node:22-alpine&#10;WORKDIR /app&#10;COPY . .&#10;RUN npm ci --omit=dev&#10;CMD [&quot;node&quot;, &quot;server.js&quot;]"></textarea></div></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Build</button>
            </div>
        </form>
    </div>
</div>

{{-- ======================================================================== push --}}
<div class="modal fade" id="dkPushModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="dkPushForm" autocomplete="off">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cloud-upload"></i> Push image</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="image">
                <label class="form-label">Repository</label>
                <select name="registry_id" class="form-select mb-3" required>
                    @forelse ($registries as $r)
                        <option value="{{ $r->id }}">{{ $r->name }} ({{ $r->url }}{{ $r->namespace ? '/'.$r->namespace : '' }})</option>
                    @empty
                        <option value="">Add a repository first</option>
                    @endforelse
                </select>
                <label class="form-label">Target name</label>
                <input type="text" name="target" class="form-control font-mono" required>
                <div class="form-text">name:tag in the repository (the namespace is added when the name has no slash).</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" @disabled($registries->isEmpty())>Push</button>
            </div>
        </form>
    </div>
</div>

{{-- ===================================================================== network --}}
<div class="modal fade" id="dkNetworkModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form class="modal-content" id="dkNetworkForm" autocomplete="off">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-diagram-3"></i> Add network</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body cron-form">
                <div class="cron-row"><label>Network name</label><div><input type="text" name="name" class="form-control font-mono" required placeholder="app_net"></div></div>
                <div class="cron-row"><label>Driver</label><div class="d-flex gap-2 flex-wrap"><select name="driver" class="form-select w-auto">@foreach (\App\Services\DockerManager::NETWORK_DRIVERS as $d)<option value="{{ $d }}">{{ $d }}</option>@endforeach</select><input type="text" name="parent" class="form-control font-mono w-auto" placeholder="Parent interface, e.g. eth0" data-parent hidden></div></div>
                <div class="cron-row"><label>IPv4 subnet</label><div class="d-flex gap-2 flex-wrap"><input type="text" name="subnet" class="form-control font-mono w-auto flex-grow-1" placeholder="172.30.0.0/16"><input type="text" name="gateway" class="form-control font-mono w-auto flex-grow-1" placeholder="Gateway 172.30.0.1"><input type="text" name="ip_range" class="form-control font-mono w-auto flex-grow-1" placeholder="IP range (optional)"></div></div>
                <div class="cron-row"><label>IPv6</label><div class="d-flex gap-2 flex-wrap align-items-center"><div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" name="ipv6" id="dkNetIpv6"><label class="form-check-label" for="dkNetIpv6">Enable</label></div><input type="text" name="subnet6" class="form-control font-mono w-auto flex-grow-1" placeholder="fd00:30::/64"><input type="text" name="gateway6" class="form-control font-mono w-auto flex-grow-1" placeholder="Gateway fd00:30::1"></div></div>
                <div class="cron-row"><label>Options</label><div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="internal" id="dkNetInternal"><label class="form-check-label" for="dkNetInternal">Internal (no access to external networks)</label></div></div></div>
                <div class="cron-row"><label>Tag</label><div><textarea name="labels" class="form-control font-mono" rows="2" placeholder="key=value per line"></textarea></div></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Create</button>
            </div>
        </form>
    </div>
</div>

{{-- ====================================================================== volume --}}
<div class="modal fade" id="dkVolumeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="dkVolumeForm" autocomplete="off">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-hdd"></i> Add volume</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label">Volume name</label>
                <input type="text" name="name" class="form-control font-mono mb-3" required placeholder="app_data">
                <label class="form-label">Driver</label>
                <input type="text" name="driver" class="form-control font-mono mb-3" value="local">
                <label class="form-label">Driver options</label>
                <textarea name="options" class="form-control font-mono mb-1" rows="3" placeholder="type=nfs&#10;o=addr=10.0.0.5,rw&#10;device=:/exports/data"></textarea>
                <div class="form-text mb-3">key=value per line. Leave empty for a normal local volume.</div>
                <label class="form-label">Tag</label>
                <textarea name="labels" class="form-control font-mono" rows="2" placeholder="key=value per line"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Create</button>
            </div>
        </form>
    </div>
</div>

{{-- ==================================================================== registry --}}
<div class="modal fade" id="dkRegistryModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="dkRegistryForm" autocomplete="off">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-archive"></i> <span id="dkRegistryTitle">Add repository</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label">Name</label>
                <input type="text" name="name" class="form-control mb-3" required placeholder="GitHub Container Registry">
                <label class="form-label">Address</label>
                <input type="text" name="url" class="form-control font-mono mb-1" required placeholder="ghcr.io">
                <div class="form-text mb-3">docker.io, ghcr.io, registry.gitlab.com, registry.example.com:5000</div>
                <div class="row g-2 mb-3">
                    <div class="col-6"><label class="form-label">Username</label><input type="text" name="username" class="form-control"></div>
                    <div class="col-6"><label class="form-label">Password or token</label><input type="password" name="password" class="form-control" autocomplete="new-password"></div>
                </div>
                <label class="form-label">Namespace</label>
                <input type="text" name="namespace" class="form-control font-mono mb-3" placeholder="my-org (optional)">
                <label class="form-label">Remark</label>
                <input type="text" name="remark" class="form-control">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

{{-- ============================================================= compose project --}}
<div class="modal fade" id="dkComposeModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content" id="dkComposeForm" autocomplete="off">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-stack"></i> Add Compose</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label">Project name</label><input type="text" name="name" class="form-control font-mono" required pattern="[a-z0-9][a-z0-9_\-]{0,62}" placeholder="my-stack"></div>
                    <div class="col-md-4"><label class="form-label">Template</label><select class="form-select" id="dkComposeTemplate"><option value="">No template</option></select></div>
                    <div class="col-md-4"><label class="form-label">Note</label><input type="text" name="note" class="form-control" maxlength="255"></div>
                    <div class="col-lg-7"><label class="form-label">compose.yaml</label><textarea name="content" class="form-control font-mono cron-code" rows="18" spellcheck="false" required placeholder="services:&#10;  web:&#10;    image: nginx:alpine&#10;    restart: unless-stopped&#10;    ports:&#10;      - &quot;127.0.0.1:8080:80&quot;"></textarea></div>
                    <div class="col-lg-5"><label class="form-label">.env</label><textarea name="env" class="form-control font-mono cron-code" rows="18" spellcheck="false" placeholder="KEY=value"></textarea></div>
                </div>
                <div class="form-check form-switch mt-3"><input class="form-check-input" type="checkbox" name="start" id="dkComposeStart" checked><label class="form-check-label" for="dkComposeStart">Pull images and start after creating</label></div>
                <div class="form-text">Stored in {{ $projectsRoot }}/&lt;project name&gt;. The file is validated with docker compose config before it is saved.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Confirm</button>
            </div>
        </form>
    </div>
</div>

{{-- =================================================================== templates --}}
<div class="modal fade" id="dkTemplatesModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-files"></i> Template List</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-lg-5">
                        <table class="table db-table"><thead><tr><th>Name</th><th>Remark</th><th class="text-end">Operate</th></tr></thead><tbody id="dkTemplateRows"></tbody></table>
                    </div>
                    <div class="col-lg-7">
                        <form id="dkTemplateForm" autocomplete="off">
                            <div class="row g-2">
                                <div class="col-6"><label class="form-label">Name</label><input type="text" name="name" class="form-control" required maxlength="100"></div>
                                <div class="col-6"><label class="form-label">Remark</label><input type="text" name="remark" class="form-control" maxlength="255"></div>
                                <div class="col-12"><label class="form-label">compose.yaml</label><textarea name="content" class="form-control font-mono cron-code" rows="12" spellcheck="false" required></textarea></div>
                                <div class="col-12"><label class="form-label">.env</label><textarea name="env" class="form-control font-mono cron-code" rows="4" spellcheck="false"></textarea></div>
                            </div>
                            @if ($canWrite)
                                <div class="d-flex justify-content-end gap-2 mt-3">
                                    <button type="button" class="btn btn-outline-secondary" id="dkTemplateNew">New</button>
                                    <button type="submit" class="btn btn-primary">Save template</button>
                                </div>
                            @endif
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ================================================================= app install --}}
<div class="modal fade" id="dkAppModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content" id="dkAppForm" autocomplete="off">
            <div class="modal-header">
                <h5 class="modal-title" id="dkAppTitle">Install</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body cron-form">
                <div class="cron-row"><label>Project name</label><div><input type="text" name="project" class="form-control font-mono" required pattern="[a-z0-9][a-z0-9_\-]{0,62}"><div class="form-text">Folder in {{ $projectsRoot }} and prefix of the container names.</div></div></div>
                <div id="dkAppFields"></div>
                <div class="cron-row"><label>Access</label><div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="external" id="dkAppExternal"><label class="form-check-label" for="dkAppExternal">Allow external access (bind to 0.0.0.0 and open the ports in the firewall)</label></div><div class="form-text">Off: the ports listen on 127.0.0.1 only; publish the app with a website reverse proxy and SSL.</div></div></div>
                <div class="cron-row"><label>Compose</label><div><a href="#" class="small" data-bs-toggle="collapse" data-bs-target="#dkAppCompose">Show compose file</a><pre class="gbx-console collapse mt-2" id="dkAppCompose" style="max-height:40vh"></pre></div></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Install</button>
            </div>
        </form>
    </div>
</div>
