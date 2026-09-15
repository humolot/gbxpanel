@extends('layouts.app')

@section('title', 'Docker')

@section('content')
    <div class="page-head">
        <div>
            <h2>Docker</h2>
            <p>Containers, images, volumes and compose projects.</p>
        </div>
        @if ($running)
            <div class="actions">
                <button class="btn btn-outline-secondary" data-post="{{ route('docker.prune') }}" data-confirm="Remove stopped containers, unused networks, dangling images and build cache?" data-danger><i class="bi bi-trash3"></i> Prune</button>
                <button class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#composeModal"><i class="bi bi-stack"></i> Compose</button>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#containerModal"><i class="bi bi-plus-lg"></i> Create container</button>
            </div>
        @endif
    </div>

    @if (! $installed)
        <div class="gbx-card">
            <div class="empty-state">
                <div class="icon"><i class="bi bi-boxes"></i></div>
                <h3>Docker is not installed</h3>
                <p>Install Docker Engine with the compose plugin from the official repository.</p>
                <button class="btn btn-primary" data-post="{{ route('home.software.install') }}" data-payload='{"key":"docker"}' data-confirm="Install Docker Engine now?"><i class="bi bi-download"></i> Install Docker</button>
            </div>
        </div>
    @elseif (! $running)
        <div class="gbx-card">
            <div class="empty-state">
                <div class="icon"><i class="bi bi-exclamation-octagon"></i></div>
                <h3>Docker daemon is not running</h3>
                <button class="btn btn-primary" data-post="{{ route('home.services.action') }}" data-payload='{"service":"docker","action":"start"}' data-reload><i class="bi bi-play-fill"></i> Start Docker</button>
            </div>
        </div>
    @else
        <div class="row g-3 mb-3">
            @foreach ([['bi-box', 'Containers', $info['containers']], ['bi-play-circle', 'Running', $info['running']], ['bi-layers', 'Images', $info['images']], ['bi-gear', 'Engine', $info['version'].' · '.$info['storage_driver']]] as [$icon, $label, $value])
                <div class="col-6 col-lg-3"><div class="stat-tile"><div class="icon"><i class="bi {{ $icon }}"></i></div><div class="min-w-0"><div class="label">{{ $label }}</div><div class="value text-truncate" style="font-size:1.15rem">{{ $value }}</div></div></div></div>
            @endforeach
        </div>

        <div class="gbx-card">
            <div class="gbx-card-header py-0">
                <ul class="nav nav-tabs gbx-tabs border-0" id="dockerTabs">
                    <li class="nav-item"><button class="nav-link active" data-type="containers"><i class="bi bi-box"></i> Containers</button></li>
                    <li class="nav-item"><button class="nav-link" data-type="images"><i class="bi bi-layers"></i> Images</button></li>
                    <li class="nav-item"><button class="nav-link" data-type="volumes"><i class="bi bi-hdd"></i> Volumes</button></li>
                    <li class="nav-item"><button class="nav-link" data-type="networks"><i class="bi bi-diagram-3"></i> Networks</button></li>
                </ul>
                <div class="actions">
                    <button class="btn btn-sm btn-outline-secondary d-none" id="pullBtn"><i class="bi bi-cloud-download"></i> Pull image</button>
                    <button class="btn btn-sm btn-ghost" id="dockerRefresh"><i class="bi bi-arrow-clockwise"></i></button>
                </div>
            </div>
            <div class="gbx-card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover"><thead id="dockerHead"></thead><tbody id="dockerBody"></tbody></table>
                </div>
            </div>
        </div>
    @endif
@endsection

@push('modals')
    <div class="modal fade" id="containerModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <form class="modal-content" data-ajax data-no-reset action="{{ route('docker.create') }}">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-box"></i> Create container</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label">Image</label>
                            <input type="text" name="image" class="form-control font-mono" placeholder="nginx:alpine" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Container name</label>
                            <input type="text" name="name" class="form-control font-mono" placeholder="my-app">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Port mappings</label>
                            <textarea name="ports" class="form-control font-mono" rows="3" placeholder="8080:80&#10;127.0.0.1:6379:6379"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Volumes</label>
                            <textarea name="volumes" class="form-control font-mono" rows="3" placeholder="/www/docker/app:/data&#10;app_data:/var/lib/app"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Environment variables</label>
                            <textarea name="env" class="form-control font-mono" rows="3" placeholder="TZ=UTC&#10;APP_ENV=production"></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Restart policy</label>
                            <select name="restart" class="form-select">
                                <option value="unless-stopped">unless-stopped</option>
                                <option value="always">always</option>
                                <option value="on-failure">on-failure</option>
                                <option value="no">no</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Memory limit</label>
                            <input type="text" name="memory" class="form-control font-mono" placeholder="512m">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Command (optional)</label>
                            <input type="text" name="command" class="form-control font-mono">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-play-fill"></i> Create &amp; start</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="composeModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <form class="modal-content" data-ajax data-no-reset action="{{ route('docker.compose') }}">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-stack"></i> Deploy compose project</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Project name</label>
                        <input type="text" name="name" class="form-control font-mono" placeholder="uptime" required pattern="[a-z0-9][a-z0-9_\-]{1,40}">
                        <div class="form-text">Stored in <code>/www/docker/&lt;name&gt;/docker-compose.yml</code>. Deploying again updates the project.</div>
                    </div>
                    <label class="form-label">docker-compose.yml</label>
                    <textarea name="content" class="form-control font-mono small" rows="14" required placeholder="services:&#10;  web:&#10;    image: nginx:alpine&#10;    ports:&#10;      - &quot;8080:80&quot;&#10;    restart: unless-stopped"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-rocket-takeoff"></i> Deploy</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="outputModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-terminal"></i> <span id="outputTitle"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body"><pre class="gbx-console" id="outputBody" style="height:62vh"></pre></div>
            </div>
        </div>
    </div>
@endpush

@push('scripts')
<script>
$(function () {
    if (!$('#dockerTabs').length) return;
    var type = 'containers', dataUrl = @json(route('docker.data'));

    var heads = {
        containers: ['Name', 'Image', 'State', 'Ports', 'CPU / Memory', ''],
        images: ['Repository', 'Tag', 'Image ID', 'Size', 'Created', ''],
        volumes: ['Name', 'Driver', 'Mountpoint', ''],
        networks: ['Name', 'Driver', 'Scope', 'ID']
    };

    function stateBadge(s) {
        var cls = s === 'running' ? 'badge-success' : (s === 'exited' || s === 'dead' ? 'badge-danger' : 'badge-warning');
        return '<span class="badge ' + cls + '">' + GBX.escape(s) + '</span>';
    }

    function row(item, stats) {
        var e = GBX.escape;
        if (type === 'containers') {
            var st = stats[item.Names] || {}, running = item.State === 'running';
            return '<tr><td><div class="cell-strong">' + e(item.Names) + '</div><div class="cell-sub font-mono">' + e(String(item.ID).substring(0, 12)) + '</div></td>' +
                '<td class="font-mono small">' + e(item.Image) + '</td>' +
                '<td>' + stateBadge(item.State) + '<div class="cell-sub">' + e(item.Status) + '</div></td>' +
                '<td class="font-mono small text-break" style="max-width:220px">' + e(item.Ports || '-') + '</td>' +
                '<td class="font-mono small">' + e(st.cpu || '-') + '<div class="cell-sub">' + e(st.mem || '') + '</div></td>' +
                '<td class="table-actions">' +
                (running ? '<button class="btn btn-sm btn-outline-secondary c-act" data-a="restart" title="Restart"><i class="bi bi-arrow-clockwise"></i></button><button class="btn btn-sm btn-outline-secondary c-act" data-a="stop" title="Stop"><i class="bi bi-stop-fill"></i></button>'
                    : '<button class="btn btn-sm btn-success c-act" data-a="start" title="Start"><i class="bi bi-play-fill"></i></button>') +
                '<div class="dropdown d-inline-block"><button class="btn btn-sm btn-ghost btn-icon" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button><div class="dropdown-menu dropdown-menu-end">' +
                '<a href="#" class="dropdown-item c-logs"><i class="bi bi-journal-text"></i> Logs</a><a href="#" class="dropdown-item c-inspect"><i class="bi bi-code-square"></i> Inspect</a>' +
                (running ? '<a href="#" class="dropdown-item c-act" data-a="pause"><i class="bi bi-pause"></i> Pause</a>' : '') +
                '<div class="dropdown-divider"></div><a href="#" class="dropdown-item text-danger c-act" data-a="rm"><i class="bi bi-trash"></i> Remove</a></div></div>' +
                '</td></tr>';
        }
        if (type === 'images') {
            return '<tr><td class="cell-strong font-mono">' + e(item.Repository) + '</td><td><span class="badge badge-soft">' + e(item.Tag) + '</span></td><td class="font-mono small">' + e(String(item.ID).replace('sha256:', '').substring(0, 12)) + '</td><td>' + e(item.Size) + '</td><td class="cell-sub">' + e(item.CreatedSince) + '</td>' +
                '<td class="table-actions"><button class="btn btn-sm btn-ghost btn-icon text-danger i-rm" data-id="' + e(item.ID) + '"><i class="bi bi-trash"></i></button></td></tr>';
        }
        if (type === 'volumes') {
            return '<tr><td class="cell-strong font-mono">' + e(item.Name) + '</td><td>' + e(item.Driver) + '</td><td class="font-mono small text-break">' + e(item.Mountpoint || '') + '</td>' +
                '<td class="table-actions"><button class="btn btn-sm btn-ghost btn-icon text-danger v-rm" data-name="' + e(item.Name) + '"><i class="bi bi-trash"></i></button></td></tr>';
        }
        return '<tr><td class="cell-strong">' + e(item.Name) + '</td><td>' + e(item.Driver) + '</td><td>' + e(item.Scope) + '</td><td class="font-mono small">' + e(String(item.ID).substring(0, 12)) + '</td></tr>';
    }

    function load() {
        $('#dockerHead').html('<tr>' + heads[type].map(function (h, i) { return '<th' + (i === heads[type].length - 1 && h === '' ? ' class="text-end"' : '') + '>' + h + '</th>'; }).join('') + '</tr>');
        $('#dockerBody').html('<tr class="loading-row"><td colspan="6"><i class="bi bi-arrow-repeat spin"></i> Loading...</td></tr>');
        $('#pullBtn').toggleClass('d-none', type !== 'images');
        GBX.get(dataUrl, { type: type }).done(function (r) {
            var stats = r.stats || {};
            $('#dockerBody').html(r.data.map(function (i) { return $(row(i, stats)).attr('data-id', i.ID || i.Name).prop('outerHTML'); }).join('') || '<tr class="loading-row"><td colspan="6">Nothing here.</td></tr>');
        });
    }
    load();

    $('#dockerTabs').on('click', '[data-type]', function () {
        $('#dockerTabs .nav-link').removeClass('active');
        type = $(this).addClass('active').data('type');
        load();
    });
    $('#dockerRefresh').on('click', load);
    $(document).on('gbx:task-finished', load);

    $('#dockerBody').on('click', '.c-act', function (e) {
        e.preventDefault();
        var $b = $(this), id = $b.closest('tr').data('id'), a = $b.data('a');
        var go = function () { GBX.post(@json(route('docker.action')), { id: id, action: a }).done(function (r) { toastr.success(r.message); load(); }); };
        if (a === 'rm' || a === 'stop') GBX.confirm({ text: (a === 'rm' ? 'Remove' : 'Stop') + ' this container?', danger: true }).then(function (r) { if (r.isConfirmed) go(); });
        else go();
    });

    function output(title, url, id) {
        $('#outputTitle').text(title);
        $('#outputBody').text('Loading...');
        bootstrap.Modal.getOrCreateInstance('#outputModal').show();
        GBX.get(url, { id: id }).done(function (r) { $('#outputBody').text(r.content || '(empty)').scrollTop(title.indexOf('Logs') === 0 ? 1e9 : 0); });
    }
    $('#dockerBody').on('click', '.c-logs', function (e) { e.preventDefault(); output('Logs', @json(route('docker.logs')), $(this).closest('tr').data('id')); });
    $('#dockerBody').on('click', '.c-inspect', function (e) { e.preventDefault(); output('Inspect', @json(route('docker.inspect')), $(this).closest('tr').data('id')); });

    $('#dockerBody').on('click', '.i-rm', function () {
        var id = $(this).data('id');
        GBX.confirm({ text: 'Remove this image?', danger: true }).then(function (r) { if (r.isConfirmed) GBX.post(@json(route('docker.images.remove')), { id: id }).done(function (res) { toastr.success(res.message); load(); }); });
    });
    $('#dockerBody').on('click', '.v-rm', function () {
        var name = $(this).data('name');
        GBX.confirm({ text: 'Remove volume ' + name + '? Data inside is lost.', danger: true }).then(function (r) { if (r.isConfirmed) GBX.post(@json(route('docker.volumes.remove')), { name: name }).done(function (res) { toastr.success(res.message); load(); }); });
    });
    $('#pullBtn').on('click', function () {
        GBX.prompt('Pull image', '', 'nginx:alpine').then(function (r) { if (r.isConfirmed) GBX.post(@json(route('docker.pull')), { image: r.value }); });
    });
});
</script>
@endpush
