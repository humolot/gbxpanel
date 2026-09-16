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

    <div class="modal fade" id="tuneModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-speedometer2"></i> Optimization <span class="cell-sub ms-2" id="tuneTitle"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <ul class="nav nav-tabs gbx-tabs mb-3">
                        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tuneTabSettings">Settings</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tuneTabStatus">Status</button></li>
                        <li class="nav-item d-none" id="tuneTabFunctionsNav"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tuneTabFunctions">Disabled functions</button></li>
                    </ul>
                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="tuneTabSettings">
                            <div class="tune-plan">
                                <label class="form-label mb-0">Plan</label>
                                <select class="form-select w-auto" id="tunePlan"></select>
                                <span class="cell-sub" id="tunePlanHint"></span>
                                <button class="btn btn-outline-secondary btn-sm ms-auto" id="tuneReload"><i class="bi bi-arrow-clockwise"></i> Reload values</button>
                            </div>
                            <div id="tuneGroups"></div>
                            <div class="form-text mt-2" id="tuneFile"></div>
                        </div>
                        <div class="tab-pane fade" id="tuneTabStatus">
                            <div id="tuneAdvice"></div>
                            <table class="table table-sm db-table mb-0"><tbody id="tuneStatus"></tbody></table>
                        </div>
                        <div class="tab-pane fade" id="tuneTabFunctions">
                            <p class="cell-sub">Functions PHP refuses to run. Blocking the ones that start programs limits what an invaded website can do on the server.</p>
                            <div class="d-flex gap-2 mb-3">
                                <input type="text" class="form-control font-mono" id="tuneFunctionName" placeholder="Function name, for example exec">
                                <button class="btn btn-secondary text-nowrap" id="tuneFunctionAdd"><i class="bi bi-plus-lg"></i> Add</button>
                                <button class="btn btn-outline-secondary text-nowrap" id="tuneFunctionRecommended">Use the recommended list</button>
                            </div>
                            <div class="d-flex flex-wrap gap-2" id="tuneFunctions"></div>
                            <div class="mt-3"><button class="btn btn-primary btn-sm" id="tuneFunctionSave"><i class="bi bi-check2"></i> Save list</button></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-warning me-auto d-none" id="tuneRestart"><i class="bi bi-arrow-repeat"></i> Restart database</button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="tuneSave"><i class="bi bi-check2"></i> Apply</button>
                </div>
            </div>
        </div>
    </div>
@endpush

@push('styles')
    <style>
        .tune-plan { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; padding: .6rem .8rem; margin-bottom: 1rem; background: var(--gbx-surface-2); border: 1px solid var(--gbx-border); border-radius: var(--gbx-radius); }
        .tune-group-title { margin: 1.1rem 0 .5rem; font-size: .74rem; letter-spacing: .08em; text-transform: uppercase; color: var(--gbx-muted); }
        .tune-hint { font-size: .74rem; color: var(--gbx-muted); }
        .tune-changed input, .tune-changed select { border-color: var(--gbx-success); }
        .tune-advice { padding: .6rem .8rem; margin-bottom: .6rem; border-radius: var(--gbx-radius); border: 1px solid var(--gbx-border); background: var(--gbx-surface-2); font-size: .82rem; }
        .tune-advice.warning { border-color: rgba(245, 180, 84, .4); color: #f5b454; }
        .tune-function { display: inline-flex; align-items: center; gap: .35rem; padding: .15rem .5rem; border-radius: 6px; font-family: var(--gbx-mono); font-size: .74rem; background: var(--gbx-surface-3); border: 1px solid var(--gbx-border-strong); }
        .tune-function a { color: var(--gbx-muted); }
    </style>
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
                actions = '<div class="soft-actions">';
                if (s.key === 'php') actions += '<button class="btn btn-sm btn-secondary act" data-act="php"><i class="bi bi-gear"></i> Settings</button>';
                if (tuningTarget(s)) actions += '<button class="btn btn-sm btn-secondary act" data-act="tune"><i class="bi bi-speedometer2"></i> Optimization</button>';
                if (s.service) actions += '<button class="btn btn-sm btn-outline-secondary act" data-act="restart"><i class="bi bi-arrow-clockwise"></i> Restart</button>';
                if (s.config_file) actions += '<a class="btn btn-sm btn-outline-secondary btn-icon" title="Edit config" href="#" data-edit-file="' + GBX.escape(s.config_file) + '"><i class="bi bi-file-earmark-code"></i></a>';
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
        } else if (act === 'tune') {
            bootstrap.Modal.getOrCreateInstance('#tuneModal').show();
            tuneLoad(tuningTarget(s));
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

    /* ======================================================== optimization */
    var tuning = null;
    var tuningTargets = @json($tuningTargets);
    function tuningTarget(s) {
        if (!s.installed) return null;
        var key = s.key === 'php' ? 'php' + s.version : (s.key === 'apache' ? 'apache' : (s.key === 'mysql' || s.key === 'mariadb' ? 'mysql' : null));
        return key && tuningTargets[key] ? key : null;
    }

    function tuneField(key, f) {
        var value = f.value == null ? '' : String(f.value), input;
        if (f.type === 'select') {
            input = '<select class="form-select" data-key="' + GBX.escape(key) + '">' + Object.keys(f.options).map(function (o) {
                return '<option value="' + GBX.escape(o) + '"' + (value === o ? ' selected' : '') + '>' + GBX.escape(f.options[o]) + '</option>';
            }).join('') + '</select>';
        } else if (f.type === 'bool') {
            input = '<select class="form-select" data-key="' + GBX.escape(key) + '"><option value="1"' + (value === '1' ? ' selected' : '') + '>On</option><option value="0"' + (value !== '1' ? ' selected' : '') + '>Off</option></select>';
        } else {
            input = '<input class="form-control font-mono" data-key="' + GBX.escape(key) + '" value="' + GBX.escape(value) + '">';
        }
        return '<div class="col-md-6 col-xl-4 tune-field"><label class="form-label mb-1">' + GBX.escape(f.label) +
            ' <span class="cell-sub font-mono">' + GBX.escape(key) + '</span></label>' + input +
            (f.hint ? '<div class="tune-hint mt-1">' + GBX.escape(f.hint) + '</div>' : '') + '</div>';
    }

    function tuneRender(r) {
        tuning = r;
        $('#tuneTitle').text(r.label);
        $('#tuneFile').html('Saved to <code>' + GBX.escape(r.file) + '</code>. The panel keeps a copy of the file and puts it back if the service refuses the new values.');
        $('#tunePlan').html('<option value="">Current values</option>' + r.plans.map(function (p) {
            return '<option value="' + GBX.escape(p.key) + '">' + GBX.escape(p.label) + '</option>';
        }).join(''));
        $('#tunePlanHint').text('This server has ' + r.ram_mb + ' MB of memory' + (r.process_mb ? ' and each PHP worker is using about ' + r.process_mb + ' MB' : '') + '.');
        $('#tuneGroups').html(Object.keys(r.groups).map(function (g) {
            return '<div class="tune-group-title">' + GBX.escape(g) + '</div><div class="row g-3">' +
                Object.keys(r.groups[g]).map(function (k) { return tuneField(k, r.groups[g][k]); }).join('') + '</div>';
        }).join(''));
        $('#tuneStatus').html((r.status.rows || []).map(function (row) {
            return '<tr><td class="cell-sub">' + GBX.escape(row.label) + '</td><td class="text-end font-mono">' + GBX.escape(row.value) + '</td></tr>';
        }).join('') || '<tr><td class="sm-empty">No numbers available.</td></tr>');
        $('#tuneAdvice').html((r.status.advice || []).map(function (a) {
            return '<div class="tune-advice ' + GBX.escape(a.level) + '"><i class="bi ' + (a.level === 'warning' ? 'bi-exclamation-triangle' : 'bi-info-circle') + '"></i> ' + GBX.escape(a.text) + '</div>';
        }).join(''));
        $('#tuneRestart').toggleClass('d-none', !r.restartable);
        $('#tuneTabFunctionsNav').toggleClass('d-none', !r.functions);
        if (r.functions) tuneFunctions(r.functions.current);
    }

    function tuneLoad(target) {
        $('#tuneGroups').html('<div class="text-muted py-3"><i class="bi bi-arrow-repeat spin"></i> Reading the current settings...</div>');
        GBX.get(@json(url('/tuning')) + '/' + target).done(tuneRender);
    }

    $('#tunePlan').on('change', function () {
        var plan = this.value;
        if (!plan || !tuning) return;
        var suggested = tuning.suggestions[plan] || {};
        $('#tuneGroups').find('[data-key]').each(function () {
            var key = $(this).data('key');
            if (suggested[key] === undefined) return;
            $(this).val(String(suggested[key])).closest('.tune-field').toggleClass('tune-changed', String(suggested[key]) !== String(tuning.values[key] == null ? '' : tuning.values[key]));
        });
        toastr.info('Values filled in for this plan. Check them and press Apply.');
    });

    $('#tuneReload').on('click', function () { if (tuning) tuneLoad(tuning.target); });

    $('#tuneSave').on('click', function () {
        var $b = $(this), values = {};
        $('#tuneGroups').find('[data-key]').each(function () { values[$(this).data('key')] = $(this).val(); });
        GBX.busy($b, true);
        GBX.post(@json(url('/tuning')) + '/' + tuning.target, { values: values }, { silent: true })
            .done(function (r) { toastr.success(r.message, '', { timeOut: 10000 }); tuneLoad(tuning.target); })
            .fail(function (xhr) { toastr.error(GBX.errorMessage(xhr), '', { timeOut: 15000 }); })
            .always(function () { GBX.busy($b, false); });
    });

    $('#tuneRestart').on('click', function () {
        var $b = $(this);
        GBX.confirm({ title: 'Restart the database', text: 'Open connections are dropped and sites using the database fail for a few seconds. If the new settings are refused, the panel starts it again with the previous ones.', danger: true, confirmText: 'Restart' }).then(function (r) {
            if (!r.isConfirmed) return;
            GBX.busy($b, true);
            GBX.post(@json(url('/tuning')) + '/' + tuning.target + '/restart', {}, { silent: true })
                .done(function (res) { toastr.success(res.message); tuneLoad(tuning.target); })
                .fail(function (xhr) { toastr.error(GBX.errorMessage(xhr), '', { timeOut: 15000 }); })
                .always(function () { GBX.busy($b, false); });
        });
    });

    function tuneFunctions(list) {
        $('#tuneFunctions').html(list.length ? list.map(function (f) {
            return '<span class="tune-function" data-function="' + GBX.escape(f) + '">' + GBX.escape(f) + ' <a href="#" data-remove title="Remove"><i class="bi bi-x"></i></a></span>';
        }).join('') : '<span class="cell-sub">No function is blocked.</span>');
    }
    var tuneFunctionList = function () { return $('#tuneFunctions .tune-function').map(function () { return $(this).data('function') + ''; }).get(); };
    $('#tuneFunctionAdd').on('click', function () {
        var name = ($('#tuneFunctionName').val() || '').trim();
        if (!name) return;
        var list = tuneFunctionList();
        if (list.indexOf(name) === -1) list.push(name);
        tuneFunctions(list);
        $('#tuneFunctionName').val('');
    });
    $('#tuneFunctionRecommended').on('click', function () { if (tuning && tuning.functions) tuneFunctions(tuning.functions.recommended); });
    $('#tuneFunctions').on('click', '[data-remove]', function (e) {
        e.preventDefault();
        $(this).closest('.tune-function').remove();
        if (!tuneFunctionList().length) tuneFunctions([]);
    });
    $('#tuneFunctionSave').on('click', function () {
        var $b = $(this);
        GBX.busy($b, true);
        GBX.post(@json(url('/tuning')) + '/' + tuning.target + '/functions', { functions: tuneFunctionList() }, { silent: true })
            .done(function (r) { toastr.success(r.message); })
            .fail(function (xhr) { toastr.error(GBX.errorMessage(xhr), '', { timeOut: 15000 }); })
            .always(function () { GBX.busy($b, false); });
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
