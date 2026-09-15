@extends('layouts.app')

@section('title', 'Software')

@section('content')
    <div class="page-head">
        <div>
            <h2>Software</h2>
            <p>Install PHP versions, databases, Node.js, Docker and other server components.</p>
        </div>
        <div class="actions">
            <ul class="nav nav-pills gbx-pills bg-surface-2 rounded-3 p-1" id="filterPills">
                <li class="nav-item"><button class="nav-link active" data-filter="all">All</button></li>
                <li class="nav-item"><button class="nav-link" data-filter="installed">Installed</button></li>
                <li class="nav-item"><button class="nav-link" data-filter="available">Available</button></li>
            </ul>
            <button class="btn btn-outline-secondary" id="refreshBtn"><i class="bi bi-arrow-clockwise"></i> Re-detect</button>
        </div>
    </div>

    <div id="softwareGroups"><div class="text-center text-muted py-5"><i class="bi bi-arrow-repeat spin"></i> Detecting software...</div></div>
@endsection

@push('modals')
    <div class="modal fade" id="phpModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-filetype-php"></i> PHP <span class="php-version"></span> settings</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <ul class="nav nav-tabs gbx-tabs mb-3">
                        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#phpTabSettings">Configuration</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#phpTabExt">Extensions</button></li>
                    </ul>
                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="phpTabSettings">
                            <form id="phpForm" class="row g-3"></form>
                            <div class="form-text mt-3">Saved to <code class="php-ini"></code>. PHP-FPM is reloaded after validation.</div>
                        </div>
                        <div class="tab-pane fade" id="phpTabExt">
                            <div class="d-flex gap-2 mb-3">
                                <input type="text" class="form-control" id="extName" placeholder="Extension package, e.g. imagick, redis, soap, pgsql, memcached">
                                <button class="btn btn-secondary text-nowrap" id="extInstall"><i class="bi bi-download"></i> Install</button>
                            </div>
                            <div class="d-flex flex-wrap gap-2" id="extList"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="phpSave"><i class="bi bi-check2"></i> Save configuration</button>
                </div>
            </div>
        </div>
    </div>
@endpush

@push('scripts')
<script>
$(function () {
    var items = [], filter = 'all', phpVersion = null;
    var canWrite = @json(auth()->user()->canWrite());

    function card(s) {
        var status = s.installed
            ? (s.running === null ? '<span class="badge badge-success">Installed</span>' : (s.running ? '<span class="badge badge-success">Running</span>' : '<span class="badge badge-danger">Stopped</span>'))
            : '<span class="badge badge-soft">Not installed</span>';
        var actions = '';
        if (canWrite) {
            if (!s.installed) {
                actions = '<button class="btn btn-sm btn-primary w-100 act" data-act="install"><i class="bi bi-download"></i> Install</button>';
            } else {
                actions = '<div class="d-flex gap-1 w-100">';
                if (s.key === 'php') actions += '<button class="btn btn-sm btn-secondary flex-grow-1 act" data-act="php"><i class="bi bi-gear"></i> Settings</button>';
                if (s.service) actions += '<button class="btn btn-sm btn-outline-secondary flex-grow-1 act" data-act="restart"><i class="bi bi-arrow-clockwise"></i> Restart</button>';
                if (s.config_file) actions += '<a class="btn btn-sm btn-outline-secondary btn-icon" title="Edit config" href="{{ route('files.index') }}?edit=' + encodeURIComponent(s.config_file) + '&path=' + encodeURIComponent(s.config_file.replace(/\/[^\/]+$/, '')) + '"><i class="bi bi-file-earmark-code"></i></a>';
                if (s.can_uninstall) actions += '<button class="btn btn-sm btn-outline-danger btn-icon act" data-act="uninstall" title="Uninstall"><i class="bi bi-trash"></i></button>';
                actions += '</div>';
            }
        }
        return '<div class="col-sm-6 col-lg-4 col-xxl-3 sw-item" data-installed="' + (s.installed ? 1 : 0) + '"><div class="soft-card" data-id="' + GBX.escape(s.id) + '">' +
            '<div class="d-flex align-items-center gap-2"><div class="soft-icon"><i class="bi ' + s.icon + '"></i></div><div class="min-w-0 flex-grow-1"><h4 class="text-truncate">' + GBX.escape(s.name) + '</h4>' +
            '<div class="cell-sub font-mono text-truncate">' + GBX.escape(s.installed_version || '—') + '</div></div>' + status + '</div>' +
            '<p>' + GBX.escape(s.description) + '</p>' + actions + '</div></div>';
    }

    function render() {
        var groups = {};
        items.forEach(function (s) {
            if (filter === 'installed' && !s.installed) return;
            if (filter === 'available' && s.installed) return;
            (groups[s.category] = groups[s.category] || []).push(s);
        });
        var html = Object.keys(groups).map(function (g) {
            return '<div class="mb-4"><div class="small-caps mb-2">' + GBX.escape(g) + '</div><div class="row g-3">' + groups[g].map(card).join('') + '</div></div>';
        }).join('');
        $('#softwareGroups').html(html || '<div class="empty-state"><div class="icon"><i class="bi bi-box-seam"></i></div><h3>Nothing to show</h3></div>');
    }

    function load(fresh) {
        GBX.get(@json(route('home.software.data')), { fresh: fresh ? 1 : 0 }).done(function (r) { items = r.data; render(); });
    }
    load(false);

    $('#refreshBtn').on('click', function () { load(true); });
    $('#filterPills').on('click', '[data-filter]', function () {
        $('#filterPills .nav-link').removeClass('active');
        filter = $(this).addClass('active').data('filter');
        render();
    });

    $(document).on('gbx:task-finished', function () { load(true); });

    $('#softwareGroups').on('click', '.act', function () {
        var $b = $(this), id = $b.closest('.soft-card').data('id'), s = items.find(function (i) { return i.id === id; }), act = $b.data('act');
        if (act === 'install') {
            GBX.confirm({ title: 'Install ' + s.name + '?', text: 'The installation runs in the background. You can follow the output live.', confirmText: 'Install' }).then(function (r) {
                if (r.isConfirmed) GBX.post(@json(route('home.software.install')), { key: s.key, version: s.version });
            });
        } else if (act === 'uninstall') {
            GBX.confirm({ title: 'Uninstall ' + s.name + '?', text: 'Packages are purged. Websites or apps depending on it will stop working.', danger: true, confirmText: 'Uninstall' }).then(function (r) {
                if (r.isConfirmed) GBX.post(@json(route('home.software.uninstall')), { key: s.key, version: s.version });
            });
        } else if (act === 'restart') {
            GBX.busy($b, true);
            GBX.post(@json(route('home.services.action')), { service: s.service, action: 'restart' }).done(function (r) { toastr.success(r.message); load(true); }).always(function () { GBX.busy($b, false); });
        } else if (act === 'php') {
            openPhp(s.version);
        }
    });

    function openPhp(version) {
        phpVersion = version;
        $('.php-version').text(version);
        $('#phpForm').html('<div class="text-muted">Loading...</div>');
        bootstrap.Modal.getOrCreateInstance('#phpModal').show();
        GBX.get(@json(url('home/software/php')) + '/' + version).done(function (r) {
            $('.php-ini').text(r.ini);
            $('#phpForm').html(Object.keys(r.definitions).map(function (k) {
                var v = r.settings[k] || '', input;
                if (k === 'display_errors' || k === 'short_open_tag') {
                    input = '<select class="form-select" name="' + k + '"><option' + (v === 'On' ? ' selected' : '') + '>On</option><option' + (v !== 'On' ? ' selected' : '') + '>Off</option></select>';
                } else {
                    input = '<input class="form-control font-mono" name="' + k + '" value="' + GBX.escape(v) + '">';
                }
                return '<div class="col-md-6"><label class="form-label">' + GBX.escape(r.definitions[k]) + ' <span class="cell-sub font-mono">' + k + '</span></label>' + input + '</div>';
            }).join(''));
            $('#extList').html(r.extensions.map(function (e) { return '<span class="badge badge-soft font-mono">' + GBX.escape(e) + '</span>'; }).join(''));
        });
    }

    $('#phpSave').on('click', function () {
        var $b = $(this), settings = {};
        $('#phpForm').find('[name]').each(function () { settings[this.name] = $(this).val(); });
        GBX.busy($b, true);
        GBX.post(@json(url('home/software/php')) + '/' + phpVersion, { settings: settings }).done(function (r) { toastr.success(r.message); }).always(function () { GBX.busy($b, false); });
    });

    $('#extInstall').on('click', function () {
        var ext = $('#extName').val().trim();
        if (!ext) return;
        bootstrap.Modal.getOrCreateInstance('#phpModal').hide();
        GBX.post(@json(url('home/software/php')) + '/' + phpVersion + '/extension', { extension: ext });
    });
});
</script>
@endpush
