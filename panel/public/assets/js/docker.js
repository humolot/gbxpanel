/*
 * GBX Panel - Docker: overview, containers, one-click apps, Docker Hub, images, compose,
 * networks, volumes, registries and settings.
 */
(function ($) {
    'use strict';

    var D = window.GBX_DOCKER, esc = GBX.escape, base = D.base;
    var url = function (path) { return base + '/' + path; };
    var pad = function (n) { return ('0' + n).slice(-2); };
    var fmtDate = function (ts) {
        if (!ts) return '-';
        var d = new Date(ts * 1000);
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
    };
    var bytes = function (n) { return GBX.bytes(n || 0, 2); };
    var shortNum = function (n) { return n >= 1e9 ? (n / 1e9).toFixed(1) + 'B' : n >= 1e6 ? (n / 1e6).toFixed(1) + 'M' : n >= 1e3 ? (n / 1e3).toFixed(1) + 'K' : String(n); };
    var loading = function (cols) { return '<tr><td colspan="' + cols + '" class="sm-empty"><i class="bi bi-arrow-repeat spin"></i> Loading</td></tr>'; };
    var reloadSoon = function (fn) { setTimeout(fn, 700); };
    var stateLabel = { running: 'Running', exited: 'Stopped', paused: 'Paused', created: 'Created', restarting: 'Restarting', dead: 'Dead', removing: 'Removing' };
    var stateClass = function (s) { return s === 'running' ? 'on' : (s === 'paused' || s === 'restarting' || s === 'created' ? 'warn' : 'off'); };
    var modal = function (id) { return bootstrap.Modal.getOrCreateInstance(document.getElementById(id)); };
    var confirmDo = function (opts, fn) { GBX.confirm(opts).then(function (r) { if (r.isConfirmed) fn(r); }); };

    var portsHtml = function (ports) {
        if (!ports || !ports.length) return '<span class="cell-sub">--</span>';
        return ports.map(function (p) {
            var host = p.host_port ? ((p.host_ip && p.host_ip !== '0.0.0.0' && p.host_ip !== '::' ? p.host_ip + ':' : '') + p.host_port) : '';
            return '<span class="dk-port' + (p.host_port ? '' : ' internal') + '" title="' + esc((p.host_ip || '') + (host ? ' published' : ' not published')) + '">' + esc(host) + ' --&gt; ' + p.port + '/' + p.proto + '</span>';
        }).join('');
    };
    var labelsText = function (labels) {
        var keys = Object.keys(labels || {});
        return keys.length ? keys.map(function (k) { return k + ': ' + labels[k]; }).join(', ') : '--';
    };

    var terminal = function (name) {
        if (!D.terminal) return;
        var cmd = 'docker exec -it ' + name + ' sh -c "command -v bash >/dev/null 2>&1 && exec bash || exec sh"\r';
        window.open(D.terminal + '?cmd=' + encodeURIComponent(cmd), '_blank', 'noopener');
    };

    /* ================================================================ pager */
    function Pager(opts) {
        var self = this;
        this.page = 1;
        this.size = 10;
        this.opts = opts;
        this.$pager = opts.$pager;
        this.$pager.on('click', '[data-page]', function () {
            var p = $(this).data('page');
            if (p === 'prev') self.page--; else if (p === 'next') self.page++; else self.page = +p;
            self.render();
        });
        this.$pager.on('change', '[data-size]', function () { self.size = +this.value; self.page = 1; self.render(); });
        this.$pager.on('keydown', '[data-goto]', function (e) { if (e.key === 'Enter') { self.page = +this.value || 1; self.render(); } });
    }
    Pager.prototype.render = function () {
        var rows = this.opts.rows(), total = rows.length, pages = Math.max(1, Math.ceil(total / this.size));
        this.page = Math.min(Math.max(1, this.page), pages);
        var slice = rows.slice((this.page - 1) * this.size, this.page * this.size);
        this.opts.$tbody.html(slice.length ? slice.map(this.opts.render).join('') : '<tr><td colspan="' + this.opts.cols + '" class="sm-empty"><i class="bi bi-inbox"></i> ' + (this.opts.empty || 'No Data') + '</td></tr>');
        var nums = '', from = Math.max(1, this.page - 2), to = Math.min(pages, from + 4);
        for (var i = from; i <= to; i++) nums += '<button class="btn btn-sm ' + (i === this.page ? 'btn-outline-success active' : 'btn-outline-secondary') + ' dk-page-btn" data-page="' + i + '">' + i + '</button>';
        this.$pager.html(
            '<button class="btn btn-sm btn-outline-secondary btn-icon" data-page="prev"' + (this.page <= 1 ? ' disabled' : '') + '><i class="bi bi-chevron-left"></i></button>' + nums +
            '<button class="btn btn-sm btn-outline-secondary btn-icon" data-page="next"' + (this.page >= pages ? ' disabled' : '') + '><i class="bi bi-chevron-right"></i></button>' +
            '<select class="form-select form-select-sm w-auto" data-size>' + [10, 20, 50, 100].map(function (s) { return '<option value="' + s + '"' + (s === this.size ? ' selected' : '') + '>' + s + ' / page</option>'; }, this).join('') + '</select>' +
            '<span class="cell-sub">Goto</span><input type="number" class="form-control form-control-sm dk-goto" data-goto min="1" max="' + pages + '" value="' + this.page + '">' +
            '<span class="cell-sub">Total ' + total + '</span>'
        );
        if (this.opts.after) this.opts.after(slice);
    };

    /* selection helpers for tables with [data-dk-all] and .dk-check */
    var selection = function () { return $('.dk-check:checked').map(function () { return $(this).val(); }).get(); };
    var bindSelection = function () {
        var update = function () {
            var n = selection().length;
            $('[data-dk-selected]').text(n + ' selected');
            $('[data-dk-bulk-run]').prop('disabled', !n || !$('[data-dk-bulk]').val());
            $('[data-dk-bulk-delete]').prop('disabled', !n);
        };
        $(document).on('change', '.dk-check, [data-dk-bulk]', update);
        $('[data-dk-all]').on('change', function () { $('.dk-check').prop('checked', this.checked); update(); });
        return function () { $('[data-dk-all]').prop('checked', false); update(); };
    };

    var submitForm = function ($form, route, opts) {
        opts = opts || {};
        $form.on('submit', function (e) {
            e.preventDefault();
            var $btn = $form.find('[type=submit]').first(), data = opts.data ? opts.data($form) : GBX.serialize($form);
            if (data === false) return;
            GBX.busy($btn, true);
            GBX.post(typeof route === 'function' ? route($form) : route, data, { silent: true, onTaskDone: opts.onTaskDone }).done(function (res) {
                if (!res.task && res.message) toastr.success(res.message);
                var $m = $form.closest('.modal');
                if ($m.length && !opts.keepOpen) modal($m.attr('id')).hide();
                if (opts.done) opts.done(res);
            }).fail(function (xhr) { toastr.error(GBX.errorMessage(xhr)); }).always(function () { GBX.busy($btn, false); });
        });
    };

    /* ============================================================ container manage */
    var current = null;
    var openManage = function (ref, pane) {
        $('#dkManageTitle').text(ref);
        $('#dkManageState').html('');
        $('[data-pane-body=info]').html('<div class="sm-empty"><i class="bi bi-arrow-repeat spin"></i> Loading</div>');
        showPane(pane || 'info');
        modal('dkManageModal').show();
        GBX.get(url('containers/detail/' + encodeURIComponent(ref))).done(function (r) {
            current = r.container;
            renderManage();
            if ((pane || 'info') === 'logs') loadManageLogs();
        });
    };
    var showPane = function (pane) {
        $('.dk-manage-nav a').removeClass('active').filter('[data-pane=' + pane + ']').addClass('active');
        $('[data-pane-body]').prop('hidden', true).filter('[data-pane-body=' + pane + ']').prop('hidden', false);
    };
    $(document).on('click', '.dk-manage-nav a', function (e) {
        e.preventDefault();
        var pane = $(this).data('pane');
        showPane(pane);
        if (pane === 'logs' && current) loadManageLogs();
    });
    var kv = function (rows) {
        return '<div class="dk-kv">' + rows.map(function (r) { return '<div><span>' + r[0] + '</span><strong>' + r[1] + '</strong></div>'; }).join('') + '</div>';
    };
    var renderManage = function () {
        var c = current;
        $('#dkManageTitle').text(c.name);
        $('#dkManageState').html('<span class="dk-state"><span class="status-dot ' + stateClass(c.state) + '"></span> ' + (stateLabel[c.state] || c.state) + (c.health ? ' (' + esc(c.health) + ')' : '') + '</span>');
        var nets = Object.keys(c.ips || {}).map(function (n) { return esc(n) + ' <span class="cell-sub">' + esc(c.ips[n] || '-') + '</span>'; }).join('<br>') || '-';
        $('[data-pane-body=info]').html(kv([
            ['Container ID', '<span class="font-mono">' + esc(c.full_id) + '</span>'],
            ['Image', '<span class="font-mono">' + esc(c.image) + '</span>'],
            ['Created', fmtDate(c.created)],
            ['Started', fmtDate(c.started)],
            ['Restart policy', esc(c.restart)],
            ['Limits', (c.cpus ? c.cpus + ' CPUs' : 'no CPU limit') + ', ' + (c.memory_limit ? bytes(c.memory_limit) : 'no memory limit')],
            ['Networks', nets],
            ['IPv6', esc(c.ipv6 || '-')],
            ['Ports', portsHtml(c.ports)],
            ['Compose', c.project ? esc(c.project) + ' / ' + esc(c.service || '') : '-'],
            ['Command', '<span class="font-mono small">' + esc(c.command || '-') + '</span>'],
            ['Entrypoint', '<span class="font-mono small">' + esc(c.entrypoint || '-') + '</span>'],
            ['Working dir', '<span class="font-mono small">' + esc(c.working_dir || '-') + '</span>'],
            ['Note', esc(c.note || '-')]
        ]) + '<div id="dkManageStats" class="dk-manage-stats"></div>');
        GBX.get(url('stats'), {}, { silent: true }).done(function (r) {
            var s = r.stats[c.id];
            if (!s) return;
            $('#dkManageStats').html(kv([['CPU', s.cpu.toFixed(2) + '%'], ['Memory', esc(s.mem_used + ' / ' + s.mem_limit) + ' (' + s.mem_percent.toFixed(1) + '%)'], ['Network I/O', esc(s.net)], ['Block I/O', esc(s.block)], ['PIDs', esc(s.pids)]]));
        });

        $('[data-pane-body=env]').html(c.env.length ? '<table class="table db-table"><tbody>' + c.env.map(function (e) {
            var i = e.indexOf('=');
            return '<tr><td class="font-mono small text-nowrap">' + esc(e.slice(0, i)) + '</td><td class="font-mono small dk-break">' + esc(e.slice(i + 1)) + '</td></tr>';
        }).join('') + '</tbody></table>' : '<div class="sm-empty">No environment variables</div>');
        var labels = Object.keys(c.labels || {});
        if (labels.length) {
            $('[data-pane-body=env]').append('<h6 class="mt-3">Labels</h6><table class="table db-table"><tbody>' + labels.map(function (k) { return '<tr><td class="font-mono small">' + esc(k) + '</td><td class="font-mono small dk-break">' + esc(c.labels[k]) + '</td></tr>'; }).join('') + '</tbody></table>');
        }
        $('[data-pane-body=mounts]').html(c.mounts.length ? '<table class="table db-table"><thead><tr><th>Type</th><th>Source</th><th>Destination</th><th>Mode</th></tr></thead><tbody>' + c.mounts.map(function (m) {
            var src = m.type === 'bind' ? '<a class="font-mono small" href="' + D.files + '?path=' + encodeURIComponent(m.source) + '">' + esc(m.source) + '</a>' : '<span class="font-mono small">' + esc(m.name || m.source) + '</span>';
            return '<tr><td>' + esc(m.type) + '</td><td class="dk-break">' + src + '</td><td class="font-mono small">' + esc(m.destination) + '</td><td>' + (m.rw ? 'rw' : 'ro') + '</td></tr>';
        }).join('') + '</tbody></table>' : '<div class="sm-empty">No mounts</div>');

        var $u = $('#dkUpdateForm');
        $u.find('[name=restart]').val(c.restart);
        $u.find('[name=cpus]').val(c.cpus || '');
        $u.find('[name=memory]').val(c.memory_limit ? Math.round(c.memory_limit / 1048576) + 'm' : '');
        $('#dkRenameForm [name=name]').val(c.name);
        $('#dkCommitForm [name=image]').val(c.name + ':snapshot');

        var running = c.state === 'running', html = '';
        if (D.canWrite) {
            html += running ? '<button class="btn btn-outline-secondary" data-manage-action="stop"><i class="bi bi-stop-fill"></i> Stop</button>' : '<button class="btn btn-outline-secondary" data-manage-action="start"><i class="bi bi-play-fill"></i> Start</button>';
            html += '<button class="btn btn-outline-secondary" data-manage-action="restart"><i class="bi bi-arrow-repeat"></i> Restart</button>';
            if (c.state === 'paused') html += '<button class="btn btn-outline-secondary" data-manage-action="unpause">Unpause</button>';
            else if (running) html += '<button class="btn btn-outline-secondary" data-manage-action="pause">Pause</button>';
        }
        if (D.terminal && running) html += '<button class="btn btn-outline-secondary" data-manage-terminal><i class="bi bi-terminal"></i> Terminal</button>';
        if (D.canWrite) html += '<button class="btn btn-outline-danger ms-auto" data-manage-action="rm"><i class="bi bi-trash"></i> Delete</button>';
        $('#dkManageFooter').html(html);
    };
    var loadManageLogs = function () {
        var $p = $('[data-pane-body=logs]'), $out = $p.find('[data-log-out]').text('Loading...');
        GBX.get(url('containers/logs'), { id: current.name, lines: $p.find('[data-log-lines]').val(), since: $p.find('[data-log-since]').val() }).done(function (r) {
            $out.text(r.content || 'No log output.');
            $out.scrollTop($out[0].scrollHeight);
        });
    };
    $(document).on('change', '[data-log-lines], [data-log-since]', loadManageLogs);
    $(document).on('click', '[data-log-refresh]', loadManageLogs);
    $(document).on('click', '[data-log-clear]', function () {
        confirmDo({ text: 'Clear the log file of ' + current.name + '?', danger: true, confirmText: 'Clear' }, function () {
            GBX.post(url('containers/clear-logs'), { id: current.name }).done(function (r) { toastr.success(r.message); loadManageLogs(); });
        });
    });
    $(document).on('click', '[data-manage-terminal]', function () { terminal(current.name); });
    $(document).on('click', '[data-manage-action]', function () {
        var action = $(this).data('manage-action'), $btn = $(this);
        var run = function () {
            GBX.busy($btn, true);
            GBX.post(url('containers/action'), { id: current.name, action: action }).done(function (r) {
                toastr.success(r.message);
                if (action === 'rm') { modal('dkManageModal').hide(); } else { openManage(current.name); }
                $(document).trigger('dk:changed');
            }).always(function () { GBX.busy($btn, false); });
        };
        if (action === 'rm' || action === 'stop') confirmDo({ text: (action === 'rm' ? 'Delete' : 'Stop') + ' container ' + current.name + '?', danger: action === 'rm', confirmText: action === 'rm' ? 'Delete' : 'Stop' }, run);
        else run();
    });
    submitForm($('#dkUpdateForm'), url('containers/update'), { keepOpen: true, data: function ($f) { return $.extend(GBX.serialize($f), { id: current.name }); }, done: function () { openManage(current.name, 'settings'); } });
    submitForm($('#dkRenameForm'), url('containers/rename'), { keepOpen: true, data: function ($f) { return $.extend(GBX.serialize($f), { id: current.name }); }, done: function () { openManage($('#dkRenameForm [name=name]').val(), 'settings'); $(document).trigger('dk:changed'); } });
    submitForm($('#dkCommitForm'), url('containers/commit'), { keepOpen: true, data: function ($f) { return $.extend(GBX.serialize($f), { id: current.name }); } });

    /* ======================================================== create container */
    var openCreate = function (image) {
        var $f = $('#dkCreateForm');
        $f[0].reset();
        if (image) $f.find('[name=image]').val(image);
        GBX.get(url('images'), {}, { silent: true }).done(function (r) {
            $('#dkImageList').html(r.data.filter(function (i) { return i.name !== '<none>'; }).map(function (i) { return '<option value="' + esc(i.name) + '">'; }).join(''));
        });
        GBX.get(url('networks'), {}, { silent: true }).done(function (r) {
            $('#dkCreateNetwork').html('<option value="">bridge (default)</option>' + r.data.filter(function (n) { return n.name !== 'bridge' && n.name !== 'none'; }).map(function (n) { return '<option value="' + esc(n.name) + '">' + esc(n.name) + ' (' + esc(n.driver) + ')</option>'; }).join(''));
        });
        modal('dkCreateModal').show();
    };
    $(document).on('click', '[data-dk-create]', function () { openCreate($(this).data('image')); });
    submitForm($('#dkCreateForm'), url('containers'), { onTaskDone: function () { $(document).trigger('dk:changed'); } });

    /* ================================================================ overview */
    function overviewTab() {
        var data = null, stats = {};
        var render = function () {
            if (!data) return;
            var filter = $('#dkCardFilter').val(), q = ($('#dkCardSearch').val() || '').toLowerCase();
            var rows = data.containers.filter(function (c) {
                if (filter === 'running' && c.state !== 'running') return false;
                if (filter === 'stopped' && c.state === 'running') return false;
                return !q || (c.name + ' ' + c.image).toLowerCase().indexOf(q) !== -1;
            });
            $('#dkCards').html(rows.length ? rows.map(function (c) {
                var s = stats[c.id], cpu = s ? s.cpu : 0, mem = s ? s.mem_percent : 0;
                return '<div class="dk-card" data-ref="' + esc(c.name) + '">' +
                    '<div class="dk-card-head"><i class="bi bi-display"></i><div class="min-w-0 flex-grow-1">' +
                    '<a href="#" class="dk-card-name">' + esc(c.name) + '</a><div class="dk-card-image text-truncate" title="' + esc(c.image) + '">' + esc(c.image) + '</div>' +
                    '<div class="cell-sub">Create at: ' + fmtDate(c.created) + '</div></div><span class="status-dot ' + stateClass(c.state) + '" title="' + esc(stateLabel[c.state] || c.state) + '"></span></div>' +
                    '<div class="dk-card-body">' +
                    '<div class="dk-meter"><span>CPU</span><strong>' + (s ? cpu.toFixed(2) + '%' : (c.state === 'running' ? '...' : '-')) + '</strong></div><div class="dk-bar"><i style="width:' + Math.min(100, cpu) + '%"></i></div>' +
                    '<div class="dk-meter"><span>RAM</span><strong>' + (s ? esc(s.mem_used + '/' + s.mem_limit) : (c.state === 'running' ? '...' : '-')) + '</strong></div><div class="dk-bar"><i style="width:' + Math.min(100, mem) + '%"></i></div>' +
                    '</div></div>';
            }).join('') : '<div class="sm-empty w-100"><i class="bi bi-inbox"></i> No containers</div>');
        };
        var load = function () {
            GBX.get(url('overview')).done(function (r) {
                data = r;
                var c = r.counts, disk = r.disk || {};
                var set = function (key, value, sub) { var $r = $('[data-resource=' + key + ']'); $r.find('.dk-resource-value').text(value); $r.find('.dk-resource-sub').html(sub); };
                set('containers', c.containers, 'Space used <b>' + esc((disk.Containers || {}).size || '0B') + '</b> <span class="cell-sub">(' + c.running + ' running)</span>');
                set('compose', c.compose, 'Docker Compose Projects');
                set('images', c.images, 'Space used <b>' + esc((disk.Images || {}).size || '0B') + '</b>');
                set('networks', c.networks, 'Created networks');
                set('volumes', c.volumes, 'Space used <b>' + esc((disk['Local Volumes'] || {}).size || '0B') + '</b>');
                set('registries', c.registries, 'Configured repositories');
                render();
                GBX.get(url('stats'), {}, { silent: true }).done(function (s) { stats = s.stats; render(); });
            });
        };
        $('#dkCardFilter').on('change', render);
        $('#dkCardSearch').on('input', render);
        $('#dkCardRefresh').on('click', load);
        $(document).on('click', '.dk-card', function (e) { e.preventDefault(); openManage($(this).data('ref')); });
        $(document).on('dk:changed', load);
        load();
        setInterval(function () { if (!document.hidden && data) GBX.get(url('stats'), {}, { silent: true }).done(function (s) { stats = s.stats; render(); }); }, 10000);
    }

    /* ============================================================== containers */
    function containersTab() {
        var data = [];
        var pager = new Pager({
            $tbody: $('#dkRows'), $pager: $('#dkPager'), cols: 11, empty: 'No containers',
            rows: function () {
                var q = ($('#dkSearch').val() || '').toLowerCase(), st = $('#dkStateFilter').val();
                return data.filter(function (c) {
                    if (st && c.state !== st) return false;
                    return !q || (c.name + ' ' + c.full_id + ' ' + c.image + ' ' + (c.note || '')).toLowerCase().indexOf(q) !== -1;
                });
            },
            render: function (c) {
                var running = c.state === 'running';
                var more = D.canWrite ? '<div class="dropdown d-inline-block"><a href="#" data-bs-toggle="dropdown">More <i class="bi bi-chevron-down small"></i></a><div class="dropdown-menu dropdown-menu-end">' +
                    (running ? '<a class="dropdown-item" href="#" data-act="stop">Stop</a><a class="dropdown-item" href="#" data-act="pause">Pause</a>' : '<a class="dropdown-item" href="#" data-act="start">Start</a>') +
                    (c.state === 'paused' ? '<a class="dropdown-item" href="#" data-act="unpause">Unpause</a>' : '') +
                    '<a class="dropdown-item" href="#" data-act="restart">Restart</a>' + (running ? '<a class="dropdown-item" href="#" data-act="kill">Kill</a>' : '') +
                    '<div class="dropdown-divider"></div><a class="dropdown-item" href="#" data-open="logs">Logs</a><a class="dropdown-item" href="#" data-open="settings">Rename / limits</a><a class="dropdown-item" href="#" data-open="settings">Save as image</a>' +
                    '</div></div>' : '<a href="#" data-open="logs">Logs</a>';
                return '<tr data-ref="' + esc(c.name) + '">' +
                    (D.canWrite ? '<td class="ws-check"><input type="checkbox" class="form-check-input dk-check" value="' + esc(c.name) + '"></td>' : '') +
                    '<td><a href="#" class="dk-link" data-open="info">' + esc(c.name) + '</a>' + (c.project ? '<div class="cell-sub">compose: ' + esc(c.project) + '</div>' : '') + '</td>' +
                    '<td class="font-mono small" title="' + esc(c.full_id) + '">' + esc(c.id) + '</td>' +
                    '<td class="text-nowrap">' + (D.canWrite ? '<a href="#" class="dk-state-toggle ' + (running ? 'text-success' : 'text-danger') + '" data-act="' + (running ? 'stop' : 'start') + '" title="Click to ' + (running ? 'stop' : 'start') + '">' : '<span>') +
                    (stateLabel[c.state] || esc(c.state)) + ' <i class="bi ' + (running ? 'bi-play-fill' : 'bi-pause-fill') + '"></i>' + (D.canWrite ? '</a>' : '</span>') +
                    (c.health ? '<div class="cell-sub">' + esc(c.health) + '</div>' : '') + '</td>' +
                    '<td class="dk-trunc" title="' + esc(c.image) + '">' + esc(c.image) + '</td>' +
                    '<td class="font-mono small">' + esc(c.ip || '--') + '</td>' +
                    '<td class="font-mono small">' + esc(c.ipv6 || '--') + '</td>' +
                    '<td class="dk-ports">' + portsHtml(c.ports) + '</td>' +
                    '<td class="text-nowrap small">' + fmtDate(c.created) + '</td>' +
                    '<td>' + (D.canWrite ? '<a href="#" class="ws-remark dk-note ' + (c.note ? '' : 'empty') + '">' + esc(c.note || 'Add note') + '</a>' : esc(c.note || '')) + '</td>' +
                    '<td class="text-end text-nowrap db-ops"><a href="#" data-open="info">Manage</a>' +
                    (D.terminal && running ? '<a href="#" data-terminal>Terminal</a>' : '') +
                    (D.canWrite ? '<a href="#" class="text-danger" data-act="rm">Delete</a>' : '') + more + '</td></tr>';
            },
            after: function () { resetSel(); }
        });
        var resetSel = bindSelection();
        var load = function () {
            GBX.get(url('containers')).done(function (r) { data = r.data; pager.render(); });
        };
        var act = function (ref, action, $btn) {
            var run = function () {
                GBX.busy($btn, true);
                GBX.post(url('containers/action'), { id: ref, action: action }).done(function (r) { toastr.success(r.message); load(); }).always(function () { GBX.busy($btn, false); });
            };
            if (['rm', 'stop', 'kill'].indexOf(action) !== -1) confirmDo({ text: { rm: 'Delete', stop: 'Stop', kill: 'Kill' }[action] + ' container ' + ref + '?' + (action === 'rm' ? ' Anonymous volumes of the container are kept.' : ''), danger: action !== 'stop', confirmText: { rm: 'Delete', stop: 'Stop', kill: 'Kill' }[action] }, run);
            else run();
        };

        $('#dkSearch').on('input', function () { pager.page = 1; pager.render(); });
        $('#dkStateFilter').on('change', function () { pager.page = 1; pager.render(); });
        $('#dkRows').on('click', '[data-act]', function (e) { e.preventDefault(); act($(this).closest('tr').data('ref'), $(this).data('act'), $(this)); });
        $('#dkRows').on('click', '[data-open]', function (e) { e.preventDefault(); openManage($(this).closest('tr').data('ref'), $(this).data('open')); });
        $('#dkRows').on('click', '[data-terminal]', function (e) { e.preventDefault(); terminal($(this).closest('tr').data('ref')); });
        $('#dkRows').on('click', '.dk-note', function (e) {
            e.preventDefault();
            var ref = $(this).closest('tr').data('ref'), row = data.find(function (c) { return c.name === ref; });
            GBX.confirm({ title: 'Note', input: 'text', inputValue: row.note || '', icon: null, confirmText: 'Save' }).then(function (r) {
                if (!r.isConfirmed) return;
                GBX.post(url('notes'), { type: 'container', ref: ref, note: r.value }).done(function () { row.note = r.value; pager.render(); });
            });
        });
        $('[data-dk-bulk-run]').on('click', function () {
            var ids = selection(), action = $('[data-dk-bulk]').val(), $btn = $(this);
            confirmDo({ text: $('[data-dk-bulk] option:selected').text() + ' ' + ids.length + ' container(s)?', danger: action === 'rm', confirmText: 'Execute' }, function () {
                GBX.busy($btn, true);
                GBX.post(url('containers/bulk'), { ids: ids, action: action }).done(function (r) { toastr.success(r.message); }).always(function () { GBX.busy($btn, false); load(); });
            });
        });
        $('#dkPruneContainers').on('click', function () {
            confirmDo({ title: 'Clear Container', text: 'Remove all stopped containers?', danger: true, confirmText: 'Remove' }, function () {
                GBX.post(url('containers/prune')).done(function (r) { toastr.success(r.message); load(); });
            });
        });
        $('#dkLogManage').on('click', function () {
            var fill = function () {
                $('#dkLogSizes').html(loading(3));
                GBX.get(url('containers/log-sizes')).done(function (r) {
                    $('#dkLogSizes').html(r.data.length ? r.data.map(function (l) {
                        return '<tr><td>' + esc(l.name) + '</td><td class="text-end font-mono small ' + (l.size > 104857600 ? 'text-warning' : '') + '">' + esc(l.human) + '</td><td class="text-end db-ops"><a href="#" data-log-view="' + esc(l.name) + '">View</a><a href="#" class="text-danger" data-log-clear-one="' + esc(l.name) + '">Clear</a></td></tr>';
                    }).join('') : '<tr><td colspan="3" class="sm-empty">No containers</td></tr>');
                });
            };
            modal('dkLogManageModal').show();
            fill();
            $('#dkLogSizes').off('click').on('click', '[data-log-clear-one]', function (e) {
                e.preventDefault();
                GBX.post(url('containers/clear-logs'), { id: $(this).data('log-clear-one') }).done(function (r) { toastr.success(r.message); fill(); });
            }).on('click', '[data-log-view]', function (e) {
                e.preventDefault();
                modal('dkLogManageModal').hide();
                openManage($(this).data('log-view'), 'logs');
            });
            $('#dkClearAllLogs').off('click').on('click', function () {
                confirmDo({ text: 'Truncate the log files of every container?', danger: true, confirmText: 'Clear all' }, function () {
                    GBX.post(url('containers/clear-logs')).done(function (r) { toastr.success(r.message); fill(); });
                });
            });
        });
        $(document).on('dk:changed', load);
        load();
    }

    /* =========================================================== one-click apps */
    function appsTab() {
        var apps = [], cat = '', selected = null;
        var render = function () {
            var q = ($('#dkAppSearch').val() || '').toLowerCase();
            var rows = apps.filter(function (a) {
                if (cat === 'installed' && !a.installed.length) return false;
                if (cat && cat !== 'installed' && a.category !== cat) return false;
                return !q || (a.name + ' ' + a.description + ' ' + a.slug).toLowerCase().indexOf(q) !== -1;
            });
            $('#dkApps').html(rows.length ? rows.map(function (a) {
                return '<div class="dk-app" data-slug="' + esc(a.slug) + '">' +
                    '<div class="dk-app-icon" style="background:' + esc(a.color) + '"><i class="bi ' + esc(a.icon) + '"></i></div>' +
                    '<div class="dk-app-body"><div class="dk-app-title">' + esc(a.name) + (a.website ? ' <a href="' + esc(a.website) + '" target="_blank" rel="noopener noreferrer" class="dk-app-help">&gt;&gt;Help</a>' : '') + '</div>' +
                    '<p>' + esc(a.description) + '</p><div class="dk-app-foot"><span class="dk-tag">' + esc(D.categories[a.category] || a.category) + '</span>' +
                    (a.installed.length ? '<span class="dk-tag installed" title="' + esc(a.installed.join(', ')) + '"><i class="bi bi-check2"></i> Installed' + (a.installed.length > 1 ? ' (' + a.installed.length + ')' : '') + '</span>' : '') + '</div></div>' +
                    (D.canWrite ? '<button class="btn btn-sm dk-install">Install</button>' : '') + '</div>';
            }).join('') : '<div class="sm-empty w-100"><i class="bi bi-inbox"></i> No apps found</div>');
        };
        GBX.get(url('apps')).done(function (r) { apps = r.apps; render(); });
        $('#dkAppSearchForm').on('submit', function (e) { e.preventDefault(); render(); });
        $('#dkAppSearch').on('input', render);
        $('#dkAppCats').on('click', 'button', function () { $(this).addClass('active').siblings().removeClass('active'); cat = $(this).data('cat'); render(); });

        $('#dkApps').on('click', '.dk-install', function () {
            selected = apps.find(function (a) { return a.slug === $(this).closest('.dk-app').data('slug'); }, this);
            var a = selected, $f = $('#dkAppForm');
            $f[0].reset();
            $('#dkAppTitle').html('<span class="dk-app-icon sm" style="background:' + esc(a.color) + '"><i class="bi ' + esc(a.icon) + '"></i></span> Install ' + esc(a.name));
            var project = a.slug, n = 2;
            while (a.installed.indexOf(project) !== -1) project = a.slug + '-' + (n++);
            $f.find('[name=project]').val(project);
            $('#dkAppFields').html(a.fields.map(function (field) {
                var name = 'fields[' + field.key + ']', input;
                if (field.type === 'password') {
                    input = '<div class="input-group"><input type="text" name="' + name + '" id="dkf_' + field.key + '" class="form-control font-mono" placeholder="Generated when empty">' +
                        '<button type="button" class="btn btn-outline-secondary" data-generate="#dkf_' + field.key + '" title="Generate"><i class="bi bi-shuffle"></i></button></div>';
                } else if (field.type === 'select') {
                    input = '<select name="' + name + '" class="form-select">' + Object.keys(field.options || {}).map(function (k) { return '<option value="' + esc(k) + '"' + (k === field.default ? ' selected' : '') + '>' + esc(field.options[k]) + '</option>'; }).join('') + '</select>';
                } else {
                    input = '<input type="' + (field.type === 'port' ? 'number' : 'text') + '" name="' + name + '" class="form-control' + (field.type === 'port' ? ' cron-num' : '') + '" value="' + esc(field.default === null ? '' : String(field.default)) + '"' + (field.type === 'port' ? ' min="1" max="65535"' : '') + '>';
                }
                return '<div class="cron-row"><label>' + esc(field.label) + '</label><div>' + input + '</div></div>';
            }).join(''));
            $('#dkAppCompose').text(a.compose).removeClass('show');
            modal('dkAppModal').show();
        });
        submitForm($('#dkAppForm'), function () { return url('apps/' + selected.slug + '/install'); }, {
            onTaskDone: function () { GBX.get(url('apps')).done(function (r) { apps = r.apps; render(); }); }
        });
    }

    /* ================================================================ docker hub */
    function hubTab() {
        var page = 1, total = 0, size = 25;
        var load = function () {
            $('#dkHubRows').html(loading(5));
            GBX.get(url('hub/search'), { q: $('#dkHubQuery').val(), page: page }).done(function (r) {
                total = r.total;
                $('#dkHubRows').html(r.results.length ? r.results.map(function (i) {
                    return '<tr><td class="text-nowrap"><span class="fw-semibold">' + esc(i.name) + '</span>' + (i.official ? ' <span class="badge badge-success">Official</span>' : '') + '</td>' +
                        '<td class="small dk-desc">' + esc(i.description || '-') + '</td><td class="text-end small"><i class="bi bi-star"></i> ' + shortNum(i.stars) + '</td><td class="text-end small">' + shortNum(i.pulls) + '</td>' +
                        '<td class="text-end db-ops">' + (D.canWrite ? '<a href="#" data-hub-pull="' + esc(i.name) + '">Pull</a>' : '') + '<a href="https://hub.docker.com/' + (i.name.indexOf('/') === -1 ? '_/' : 'r/') + esc(i.name) + '" target="_blank" rel="noopener noreferrer">Details</a></td></tr>';
                }).join('') : '<tr><td colspan="5" class="sm-empty"><i class="bi bi-inbox"></i> No images found</td></tr>');
                $('#dkHubTotal').text(total + ' results');
                $('#dkHubPage').text(page);
                $('#dkHubPrev').prop('disabled', page <= 1);
                $('#dkHubNext').prop('disabled', page * size >= total);
            }).fail(function () { $('#dkHubRows').html('<tr><td colspan="5" class="sm-empty">Docker Hub is not reachable</td></tr>'); });
        };
        $('#dkHubForm').on('submit', function (e) { e.preventDefault(); page = 1; load(); });
        $('#dkHubPrev').on('click', function () { page--; load(); });
        $('#dkHubNext').on('click', function () { page++; load(); });
        $('#dkHubRows').on('click', '[data-hub-pull]', function (e) { e.preventDefault(); openPull($(this).data('hub-pull'), true); });
        load();
    }

    /* ===================================================================== pull */
    var openPull = function (repo, fromHub) {
        var $f = $('#dkPullForm');
        $f[0].reset();
        $('#dkPullTagsWrap').prop('hidden', true);
        if (repo) {
            $f.find('[name=image]').val(repo + ':latest');
            if (fromHub) {
                GBX.get(url('hub/tags'), { repo: repo }, { silent: true }).done(function (r) {
                    if (!r.tags.length) return;
                    $('#dkPullTags').html('<option value="">Choose a tag</option>' + r.tags.map(function (t) { return '<option>' + esc(t) + '</option>'; }).join(''));
                    $('#dkPullTagsWrap').prop('hidden', false);
                });
            }
        }
        modal('dkPullModal').show();
    };
    $('#dkPullTags').on('change', function () {
        if (!this.value) return;
        var $i = $('#dkPullForm [name=image]');
        $i.val($i.val().replace(/:[^:\/]*$/, '') + ':' + this.value);
    });
    $(document).on('click', '[data-dk-pull]', function () { openPull(); });
    submitForm($('#dkPullForm'), url('images/pull'), { onTaskDone: function () { $(document).trigger('dk:changed'); } });

    /* =================================================================== images */
    function imagesTab() {
        var data = [];
        var pager = new Pager({
            $tbody: $('#dkRows'), $pager: $('#dkPager'), cols: 7, empty: 'No images',
            rows: function () {
                var q = ($('#dkSearch').val() || '').toLowerCase();
                return data.filter(function (i) { return !q || (i.id + ' ' + i.tags.join(' ') + ' ' + i.containers.join(' ')).toLowerCase().indexOf(q) !== -1; });
            },
            render: function (i) {
                return '<tr data-id="' + esc(i.id) + '" data-name="' + esc(i.name) + '">' +
                    (D.canWrite ? '<td class="ws-check"><input type="checkbox" class="form-check-input dk-check" value="' + esc(i.id) + '"></td>' : '') +
                    '<td class="font-mono small dk-trunc" title="' + esc(i.id) + '">' + esc(i.id.slice(0, 40)) + '...</td>' +
                    '<td class="dk-trunc" title="' + esc(i.tags.join(', ')) + '">' + esc(i.name) + (i.tags.length > 1 ? ' <span class="cell-sub">+' + (i.tags.length - 1) + '</span>' : '') + '</td>' +
                    '<td class="text-nowrap">' + bytes(i.size) + '</td>' +
                    '<td class="text-nowrap small">' + fmtDate(i.created) + '</td>' +
                    '<td>' + (i.containers.length ? i.containers.map(function (c) { return '<span class="dk-port">' + esc(c) + '</span>'; }).join('') : '<span class="cell-sub">--</span>') + '</td>' +
                    '<td class="text-end text-nowrap db-ops">' + (D.canWrite ? '<a href="#" data-dk-create data-image="' + esc(i.name === '<none>' ? i.id : i.name) + '">Create Container</a><a href="#" data-push>Push</a><a href="#" data-export>Export</a><a href="#" class="text-danger" data-delete>Delete</a>' : '<span class="cell-sub">-</span>') + '</td></tr>';
            },
            after: function () { resetSel(); }
        });
        var resetSel = bindSelection();
        var load = function () { GBX.get(url('images')).done(function (r) { data = r.data; pager.render(); }); };
        $('#dkSearch').on('input', function () { pager.page = 1; pager.render(); });

        $('#dkRows').on('click', '[data-delete]', function (e) {
            e.preventDefault();
            var $tr = $(this).closest('tr'), img = data.find(function (i) { return i.id === $tr.data('id'); });
            GBX.confirm({ title: 'Delete image', html: 'Delete <strong>' + esc(img.name) + '</strong>?' + (img.containers.length ? '<br><span class="small text-warning">Used by: ' + esc(img.containers.join(', ')) + '. Force removal is required.</span>' : ''), danger: true, confirmText: 'Delete',
                input: img.containers.length || img.tags.length > 1 ? 'checkbox' : undefined, inputPlaceholder: 'Force (remove every tag, containers keep running)' }).then(function (r) {
                if (!r.isConfirmed) return;
                GBX.post(url('images/remove'), { id: img.tags.length === 1 && !r.value ? img.name : img.id, force: r.value ? 1 : 0 }).done(function (res) { toastr.success(res.message); load(); });
            });
        });
        $('#dkRows').on('click', '[data-export]', function (e) {
            e.preventDefault();
            var $tr = $(this).closest('tr');
            GBX.post(url('images/export'), { image: $tr.data('name') === '<none>' ? $tr.data('id') : $tr.data('name') });
        });
        $('#dkRows').on('click', '[data-push]', function (e) {
            e.preventDefault();
            var name = $(this).closest('tr').data('name'), $f = $('#dkPushForm');
            if (name === '<none>') return toastr.warning('Tag the image before pushing it.');
            $f.find('[name=image]').val(name);
            $f.find('[name=target]').val(name.replace(/^[^\/]+\.[^\/]+\//, ''));
            modal('dkPushModal').show();
        });
        $('[data-dk-bulk-delete]').on('click', function () {
            var ids = selection();
            confirmDo({ text: 'Delete ' + ids.length + ' image(s)? Images used by containers are skipped.', danger: true, confirmText: 'Delete' }, function () {
                GBX.post(url('images/bulk-remove'), { ids: ids }).done(function (r) { toastr.success(r.message); }).always(load);
            });
        });
        $('#dkPruneImages').on('click', function () {
            GBX.confirm({ title: 'Clear image', text: 'Remove dangling images (untagged layers left by builds and pulls)?', danger: true, confirmText: 'Remove', input: 'checkbox', inputPlaceholder: 'Also remove every image not used by a container' }).then(function (r) {
                if (r.isConfirmed) GBX.post(url('images/prune'), { all: r.value ? 1 : 0 }).done(function (res) { toastr.success(res.message); load(); });
            });
        });
        submitForm($('#dkPushForm'), url('images/push'));
        submitForm($('#dkBuildForm'), url('images/build'), { onTaskDone: load });
        $('#dkImportForm').on('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(this), $btn = $(this).find('[type=submit]');
            if (!this.file.files.length) fd.delete('file');
            if (!this.file.files.length && !this.path.value) return toastr.warning('Choose a file or enter a path');
            GBX.busy($btn, true);
            GBX.post(url('images/import'), fd, { onTaskDone: load }).done(function () { modal('dkImportModal').hide(); }).always(function () { GBX.busy($btn, false); });
        });
        $(document).on('dk:changed', load);
        load();
    }

    /* ================================================================== compose */
    function composeTab() {
        var projects = [], active = null, templates = [];
        var statusBadge = function (p) {
            var map = { running: ['Running', 'badge-success'], partial: ['Partial', 'badge-warning'], exited: ['Stopped', 'badge-danger'], stopped: ['Not started', 'badge-soft'] };
            var s = map[p.status] || [p.status, 'badge-soft'];
            return '<span class="badge ' + s[1] + '">' + s[0] + '</span>';
        };
        var renderList = function () {
            var q = ($('#dkProjectSearch').val() || '').toLowerCase();
            var rows = projects.filter(function (p) { return !q || (p.name + ' ' + (p.note || '') + ' ' + (p.app_name || '')).toLowerCase().indexOf(q) !== -1; });
            $('#dkProjects').html(rows.length ? rows.map(function (p) {
                return '<div class="dk-project' + (active === p.name ? ' active' : '') + '" data-name="' + esc(p.name) + '">' +
                    (D.canWrite ? '<input type="checkbox" class="form-check-input dk-project-check" value="' + esc(p.name) + '">' : '') +
                    statusBadge(p) + '<div class="min-w-0"><div class="dk-project-name text-truncate">' + esc(p.name) + '</div>' +
                    '<a href="#" class="cell-sub dk-project-note">' + esc(p.note || (p.app_name ? p.app_name : (D.canWrite ? 'Click to edit notes' : ''))) + '</a></div></div>';
            }).join('') : '<div class="sm-empty">No projects</div>');
        };
        var loadList = function (select) {
            GBX.get(url('compose')).done(function (r) {
                projects = r.data;
                renderList();
                var want = select || active || (projects[0] && projects[0].name);
                if (want && projects.some(function (p) { return p.name === want; })) show(want);
                else if (!projects.length) active = null;
            });
        };
        var show = function (name) {
            active = name;
            renderList();
            GBX.get(url('compose/' + encodeURIComponent(name))).done(function (r) {
                var p = r.project, f = r.files, can = D.canWrite;
                var containers = r.containers.map(function (c) {
                    return '<div class="dk-cc"><div class="d-flex align-items-center gap-2 flex-wrap"><a href="#" class="dk-link" data-manage="' + esc(c.name) + '">' + esc(c.name) + '</a>' +
                        '<span class="badge ' + (c.state === 'running' ? 'badge-success' : 'badge-danger') + '">' + esc(stateLabel[c.state] || c.state) + '</span>' +
                        '<div class="ms-auto d-flex gap-2">' + (D.terminal && c.state === 'running' ? '<button class="btn btn-sm btn-outline-secondary" data-term="' + esc(c.name) + '"><i class="bi bi-terminal"></i> Terminal</button>' : '') +
                        '<button class="btn btn-sm btn-outline-secondary" data-clogs="' + esc(c.name) + '"><i class="bi bi-journal-text"></i> Logs</button></div></div>' +
                        '<div class="cell-sub font-mono">' + esc(c.id) + '</div><div class="mt-1">' + portsHtml(c.ports) + '</div></div>';
                }).join('') || '<div class="sm-empty">No containers. Start the project to create them.</div>';
                var dir = p.dir ? '<a href="' + D.files + '?path=' + encodeURIComponent(p.dir) + '" class="cell-sub">Jump to Directory</a>' : '';

                $('#dkComposeMain').html(
                    '<div class="gbx-card dk-ph"><div><h3>' + esc(p.name) + ' ' + statusBadge(p) + '</h3><div class="cell-sub">Creation Time: ' + fmtDate(p.created) + ' &nbsp; Capacity Quantity: ' + p.total + (p.app ? ' &nbsp; App: ' + esc(p.app) : '') + (p.managed ? '' : ' &nbsp; <span class="text-warning">external project</span>') + '</div></div>' +
                    (can ? '<div class="btn-group dk-ph-actions">' + (p.running ? '<button class="btn btn-outline-secondary" data-caction="stop">Stop</button>' : '<button class="btn btn-outline-secondary" data-caction="start">Start</button>') +
                        '<button class="btn btn-outline-secondary" data-caction="restart">Restart</button><button class="btn btn-outline-secondary" data-caction="update">Update Image</button><button class="btn btn-outline-secondary" data-cdelete>Delete</button></div>' : '') + '</div>' +
                    '<div class="dk-compose-grid"><div class="dk-compose-left">' +
                    '<div class="gbx-card"><div class="gbx-card-header"><h3>Container List</h3></div><div class="dk-cc-list">' + containers + '</div></div>' +
                    '<div class="gbx-card"><div class="gbx-card-header"><h3>Compose Logs</h3><div class="actions"><button class="btn btn-sm btn-ghost btn-icon" data-plogs title="Refresh"><i class="bi bi-arrow-clockwise"></i></button></div></div><div class="gbx-card-body"><pre class="gbx-console" id="dkComposeLogs" style="height:320px">Loading...</pre></div></div>' +
                    '</div><div class="gbx-card dk-compose-files"><div class="gbx-card-header"><h3>Configuration File</h3></div><div class="gbx-card-body">' +
                    '<div class="d-flex align-items-center gap-2 mb-1"><strong class="small">' + esc(f.compose_path ? f.compose_path.split('/').pop() : 'compose file') + '</strong>' + dir + '</div>' +
                    '<textarea class="form-control font-mono cron-code" id="dkComposeFile" rows="16" spellcheck="false"' + (can ? '' : ' readonly') + '></textarea>' +
                    (can ? '<div class="d-flex gap-2 mt-2"><button class="btn btn-sm btn-primary" data-save="compose" data-apply="1">Save and apply</button><button class="btn btn-sm btn-outline-secondary" data-save="compose">Save</button></div>' : '') +
                    '<div class="mt-3 mb-1"><strong class="small">.env</strong></div>' +
                    '<textarea class="form-control font-mono cron-code" id="dkComposeEnv" rows="9" spellcheck="false"' + (can ? '' : ' readonly') + '></textarea>' +
                    (can ? '<div class="d-flex gap-2 mt-2"><button class="btn btn-sm btn-primary" data-save="env" data-apply="1">Save and apply</button><button class="btn btn-sm btn-outline-secondary" data-save="env">Save</button></div>' : '') +
                    '</div></div></div>'
                );
                $('#dkComposeFile').val(f.compose);
                $('#dkComposeEnv').val(f.env);
                loadLogs();
            });
        };
        var loadLogs = function () {
            if (!active) return;
            GBX.get(url('compose/' + encodeURIComponent(active) + '/logs'), {}, { silent: true }).done(function (r) {
                var $o = $('#dkComposeLogs').text(r.content || 'No log output.');
                if ($o.length) $o.scrollTop($o[0].scrollHeight);
            });
        };

        $('#dkProjectSearch').on('input', renderList);
        $('#dkProjects').on('click', '.dk-project', function (e) {
            if ($(e.target).is('input, .dk-project-note')) return;
            show($(this).data('name'));
        });
        $('#dkProjects').on('click', '.dk-project-note', function (e) {
            e.preventDefault();
            if (!D.canWrite) return;
            var name = $(this).closest('.dk-project').data('name'), p = projects.find(function (x) { return x.name === name; });
            GBX.confirm({ title: 'Note', input: 'text', inputValue: p.note || '', icon: null, confirmText: 'Save' }).then(function (r) {
                if (r.isConfirmed) GBX.post(url('notes'), { type: 'project', ref: name, note: r.value }).done(function () { p.note = r.value; renderList(); });
            });
        });
        var updateBulk = function () { $('#dkProjectBulkDelete').prop('disabled', !$('.dk-project-check:checked').length); };
        $('#dkProjects').on('change', '.dk-project-check', updateBulk);
        $('#dkProjectAll').on('change', function () { $('.dk-project-check').prop('checked', this.checked); updateBulk(); });

        var askDelete = function (names) {
            GBX.confirm({
                title: 'Delete compose project', danger: true, confirmText: 'Delete',
                html: 'Stop and remove <strong>' + esc(names.join(', ')) + '</strong>?<div class="text-start mt-3 small">' +
                    '<div class="form-check"><input class="form-check-input" type="checkbox" id="dkDelFiles" checked><label class="form-check-label" for="dkDelFiles">Delete the project folder (projects in ' + esc(D.projectsRoot) + ')</label></div>' +
                    '<div class="form-check"><input class="form-check-input" type="checkbox" id="dkDelVolumes"><label class="form-check-label text-warning" for="dkDelVolumes">Delete volumes (data is lost)</label></div></div>',
                preConfirm: function () { return { files: $('#dkDelFiles').is(':checked') ? 1 : 0, volumes: $('#dkDelVolumes').is(':checked') ? 1 : 0 }; }
            }).then(function (r) {
                if (!r.isConfirmed) return;
                GBX.post(url('compose/remove'), $.extend({ names: names }, r.value), { onTaskDone: function () { active = null; loadList(); } }).done(function (res) { if (!res.task) toastr.success(res.message); });
            });
        };
        $('#dkProjectBulkDelete').on('click', function () { askDelete($('.dk-project-check:checked').map(function () { return this.value; }).get()); });

        $('#dkComposeMain').on('click', '[data-caction]', function () {
            var action = $(this).data('caction');
            var run = function () { GBX.post(url('compose/' + encodeURIComponent(active) + '/action'), { action: action }, { onTaskDone: function () { loadList(active); } }); };
            if (action === 'stop' || action === 'update') confirmDo({ text: (action === 'stop' ? 'Stop' : 'Pull the latest images and recreate') + ' ' + active + '?', confirmText: 'Confirm' }, run); else run();
        });
        $('#dkComposeMain').on('click', '[data-cdelete]', function () { askDelete([active]); });
        $('#dkComposeMain').on('click', '[data-plogs]', loadLogs);
        $('#dkComposeMain').on('click', '[data-term]', function () { terminal($(this).data('term')); });
        $('#dkComposeMain').on('click', '[data-clogs]', function () { openManage($(this).data('clogs'), 'logs'); });
        $('#dkComposeMain').on('click', '[data-manage]', function (e) { e.preventDefault(); openManage($(this).data('manage')); });
        $('#dkComposeMain').on('click', '[data-save]', function () {
            var which = $(this).data('save'), apply = !!$(this).data('apply'), $btn = $(this);
            GBX.busy($btn, true);
            GBX.post(url('compose/' + encodeURIComponent(active) + '/file'), { which: which, content: $(which === 'env' ? '#dkComposeEnv' : '#dkComposeFile').val(), apply: apply ? 1 : 0 }, { onTaskDone: function () { loadList(active); } })
                .done(function (r) { if (!r.task) toastr.success(r.message); }).always(function () { GBX.busy($btn, false); });
        });

        /* add compose */
        var loadTemplates = function (cb) {
            GBX.get(url('compose/templates'), {}, { silent: true }).done(function (r) {
                templates = r.data;
                $('#dkComposeTemplate').html('<option value="">No template</option>' + templates.map(function (t) { return '<option value="' + t.id + '">' + esc(t.name) + '</option>'; }).join(''));
                if (cb) cb();
            });
        };
        $('[data-dk-compose-add]').on('click', function () {
            $('#dkComposeForm')[0].reset();
            loadTemplates();
            modal('dkComposeModal').show();
        });
        $('#dkComposeTemplate').on('change', function () {
            var t = templates.find(function (x) { return String(x.id) === this.value; }, this);
            if (!t) return;
            $('#dkComposeForm [name=content]').val(t.content);
            $('#dkComposeForm [name=env]').val(t.env || '');
        });
        submitForm($('#dkComposeForm'), url('compose'), {
            done: function (r) { var name = $('#dkComposeForm [name=name]').val(); if (!r.task) loadList(name); },
            onTaskDone: function () { loadList($('#dkComposeForm [name=name]').val()); }
        });

        /* templates */
        var editingTemplate = null;
        var renderTemplates = function () {
            $('#dkTemplateRows').html(templates.length ? templates.map(function (t) {
                return '<tr data-id="' + t.id + '"><td>' + esc(t.name) + '</td><td class="small">' + esc(t.remark || '-') + '</td><td class="text-end db-ops"><a href="#" data-tedit>Edit</a>' +
                    (D.canWrite ? '<a href="#" data-tuse>Use</a><a href="#" class="text-danger" data-tdel>Delete</a>' : '') + '</td></tr>';
            }).join('') : '<tr><td colspan="3" class="sm-empty">No templates yet</td></tr>');
        };
        var resetTemplate = function () { editingTemplate = null; $('#dkTemplateForm')[0].reset(); };
        $('#dkTemplates').on('click', function () { resetTemplate(); loadTemplates(renderTemplates); modal('dkTemplatesModal').show(); });
        $('#dkTemplateNew').on('click', resetTemplate);
        $('#dkTemplateRows').on('click', '[data-tedit]', function (e) {
            e.preventDefault();
            var t = templates.find(function (x) { return x.id === $(this).closest('tr').data('id'); }, this), $f = $('#dkTemplateForm');
            editingTemplate = t.id;
            $f.find('[name=name]').val(t.name); $f.find('[name=remark]').val(t.remark || ''); $f.find('[name=content]').val(t.content); $f.find('[name=env]').val(t.env || '');
        }).on('click', '[data-tuse]', function (e) {
            e.preventDefault();
            var id = $(this).closest('tr').data('id');
            modal('dkTemplatesModal').hide();
            $('#dkComposeForm')[0].reset();
            loadTemplates(function () { $('#dkComposeTemplate').val(id).trigger('change'); });
            modal('dkComposeModal').show();
        }).on('click', '[data-tdel]', function (e) {
            e.preventDefault();
            var id = $(this).closest('tr').data('id');
            confirmDo({ text: 'Delete this template?', danger: true, confirmText: 'Delete' }, function () {
                GBX.del(url('compose/templates/' + id)).done(function () { loadTemplates(renderTemplates); resetTemplate(); });
            });
        });
        $('#dkTemplateForm').on('submit', function (e) {
            e.preventDefault();
            var data = GBX.serialize($(this));
            if (editingTemplate) data._method = 'PUT';
            GBX.post(url('compose/templates' + (editingTemplate ? '/' + editingTemplate : '')), data).done(function (r) { toastr.success(r.message); editingTemplate = r.template.id; loadTemplates(renderTemplates); });
        });

        $(document).on('dk:changed', function () { loadList(active); });
        loadList(new URLSearchParams(location.search).get('project'));
    }

    /* ================================================================= networks */
    function networksTab() {
        var data = [];
        var pager = new Pager({
            $tbody: $('#dkRows'), $pager: $('#dkPager'), cols: 11, empty: 'No networks',
            rows: function () {
                var q = ($('#dkSearch').val() || '').toLowerCase();
                return data.filter(function (n) { return !q || (n.name + ' ' + n.driver + ' ' + (n.subnet || '') + ' ' + (n.subnet6 || '')).toLowerCase().indexOf(q) !== -1; });
            },
            render: function (n) {
                return '<tr data-name="' + esc(n.name) + '">' +
                    (D.canWrite ? '<td class="ws-check">' + (n.builtin ? '' : '<input type="checkbox" class="form-check-input dk-check" value="' + esc(n.name) + '">') + '</td>' : '') +
                    '<td>' + esc(n.name) + (n.internal ? ' <span class="badge badge-soft">internal</span>' : '') + '</td><td>' + esc(n.driver) + '</td>' +
                    '<td class="font-mono small">' + esc(n.subnet || '--') + '</td><td class="font-mono small">' + esc(n.gateway || '--') + '</td>' +
                    '<td class="font-mono small">' + esc(n.subnet6 || '--') + '</td><td class="font-mono small">' + esc(n.gateway6 || '--') + '</td>' +
                    '<td>' + n.containers + '</td>' +
                    '<td class="small dk-trunc" title="' + esc(labelsText(n.labels)) + '">' + esc(labelsText(n.labels)) + '</td>' +
                    '<td class="text-nowrap small">' + fmtDate(n.created) + '</td>' +
                    '<td class="text-end db-ops">' + (D.canWrite && !n.builtin ? '<a href="#" class="text-danger" data-delete>Delete</a>' : '<span class="cell-sub">-</span>') + '</td></tr>';
            },
            after: function () { resetSel(); }
        });
        var resetSel = bindSelection();
        var load = function () { GBX.get(url('networks')).done(function (r) { data = r.data; pager.render(); }); };
        var remove = function (names) {
            confirmDo({ text: 'Delete network(s) ' + names.join(', ') + '? Networks with connected containers cannot be removed.', danger: true, confirmText: 'Delete' }, function () {
                GBX.post(url('networks/remove'), { names: names }).done(function (r) { toastr.success(r.message); }).always(load);
            });
        };
        $('#dkSearch').on('input', function () { pager.page = 1; pager.render(); });
        $('#dkRows').on('click', '[data-delete]', function (e) { e.preventDefault(); remove([$(this).closest('tr').data('name')]); });
        $('[data-dk-bulk-delete]').on('click', function () { remove(selection()); });
        $('#dkPruneNetworks').on('click', function () {
            confirmDo({ title: 'Clear network', text: 'Remove all networks not used by any container?', danger: true, confirmText: 'Remove' }, function () {
                GBX.post(url('networks/prune')).done(function (r) { toastr.success(r.message); load(); });
            });
        });
        $('#dkNetworkForm [name=driver]').on('change', function () { $('#dkNetworkForm [data-parent]').prop('hidden', this.value === 'bridge'); });
        submitForm($('#dkNetworkForm'), url('networks'), { done: function () { $('#dkNetworkForm')[0].reset(); load(); } });
        load();
    }

    /* ================================================================== volumes */
    function volumesTab() {
        var data = [];
        var pager = new Pager({
            $tbody: $('#dkRows'), $pager: $('#dkPager'), cols: 8, empty: 'No volumes',
            rows: function () {
                var q = ($('#dkSearch').val() || '').toLowerCase();
                return data.filter(function (v) { return !q || (v.name + ' ' + v.mountpoint + ' ' + v.containers.join(' ')).toLowerCase().indexOf(q) !== -1; });
            },
            render: function (v) {
                return '<tr data-name="' + esc(v.name) + '">' +
                    (D.canWrite ? '<td class="ws-check"><input type="checkbox" class="form-check-input dk-check" value="' + esc(v.name) + '"></td>' : '') +
                    '<td class="dk-trunc" title="' + esc(v.name) + '">' + esc(v.name) + '</td>' +
                    '<td class="font-mono small dk-trunc" title="' + esc(v.mountpoint) + '"><a href="' + D.files + '?path=' + encodeURIComponent(v.mountpoint) + '">' + esc(v.mountpoint) + '</a></td>' +
                    '<td>' + (v.containers.length ? v.containers.map(esc).join(', ') : '<span class="cell-sub">--</span>') + '</td>' +
                    '<td>' + esc(v.driver) + '</td><td class="text-nowrap small">' + fmtDate(v.created) + '</td>' +
                    '<td class="small dk-trunc" title="' + esc(labelsText(v.labels)) + '">' + esc(labelsText(v.labels)) + '</td>' +
                    '<td class="text-end db-ops">' + (D.canWrite ? '<a href="#" class="text-danger" data-delete>Delete</a>' : '<span class="cell-sub">-</span>') + '</td></tr>';
            },
            after: function () { resetSel(); }
        });
        var resetSel = bindSelection();
        var load = function () { GBX.get(url('volumes')).done(function (r) { data = r.data; pager.render(); }); };
        var remove = function (names) {
            confirmDo({ text: 'Delete volume(s) ' + names.join(', ') + '? The data stored in them is lost. Volumes in use cannot be removed.', danger: true, confirmText: 'Delete' }, function () {
                GBX.post(url('volumes/remove'), { names: names }).done(function (r) { toastr.success(r.message); }).always(load);
            });
        };
        $('#dkSearch').on('input', function () { pager.page = 1; pager.render(); });
        $('#dkRows').on('click', '[data-delete]', function (e) { e.preventDefault(); remove([$(this).closest('tr').data('name')]); });
        $('[data-dk-bulk-delete]').on('click', function () { remove(selection()); });
        $('#dkPruneVolumes').on('click', function () {
            GBX.confirm({ title: 'Clear volume', text: 'Remove anonymous volumes not used by any container? Their data is lost.', danger: true, confirmText: 'Remove', input: 'checkbox', inputPlaceholder: 'Also remove named volumes not used by a container' }).then(function (r) {
                if (r.isConfirmed) GBX.post(url('volumes/prune'), { all: r.value ? 1 : 0 }).done(function (res) { toastr.success(res.message); load(); });
            });
        });
        submitForm($('#dkVolumeForm'), url('volumes'), { done: function () { $('#dkVolumeForm')[0].reset(); load(); } });
        load();
    }

    /* =============================================================== registries */
    function registriesTab() {
        var data = [], editing = null;
        var load = function () {
            GBX.get(url('registries')).done(function (r) {
                data = r.data;
                $('#dkRows').html(data.length ? data.map(function (g) {
                    return '<tr data-id="' + g.id + '"><td class="fw-semibold">' + esc(g.name) + '</td><td class="font-mono small">' + esc(g.url) + '</td><td>' + esc(g.username || 'anonymous') + '</td>' +
                        '<td class="font-mono small">' + esc(g.namespace || '--') + '</td><td class="small">' + esc(g.remark || '-') + '</td><td class="small text-nowrap">' + esc((g.created_at || '').replace('T', ' ').slice(0, 19)) + '</td>' +
                        '<td class="text-end db-ops">' + (D.canWrite ? '<a href="#" data-test>Test</a><a href="#" data-edit>Edit</a><a href="#" class="text-danger" data-delete>Delete</a>' : '-') + '</td></tr>';
                }).join('') : '<tr><td colspan="7" class="sm-empty"><i class="bi bi-inbox"></i> No repositories. Docker Hub public images work without one.</td></tr>');
            });
        };
        var open = function (g) {
            editing = g ? g.id : null;
            var $f = $('#dkRegistryForm');
            $f[0].reset();
            $('#dkRegistryTitle').text(g ? 'Edit repository' : 'Add repository');
            if (g) {
                $.each(['name', 'url', 'username', 'namespace', 'remark'], function (_, k) { $f.find('[name=' + k + ']').val(g[k] || ''); });
                $f.find('[name=password]').attr('placeholder', g.has_password ? 'Unchanged' : '');
            }
            modal('dkRegistryModal').show();
        };
        $('[data-dk-registry-add]').on('click', function () { open(null); });
        $('#dkRows').on('click', '[data-edit]', function (e) { e.preventDefault(); open(data.find(function (g) { return g.id === $(this).closest('tr').data('id'); }, this)); });
        $('#dkRows').on('click', '[data-test]', function (e) {
            e.preventDefault();
            var $a = $(this);
            $a.text('Testing...');
            GBX.post(url('registries/' + $a.closest('tr').data('id') + '/test')).done(function (r) { toastr.success(r.message); }).always(function () { $a.text('Test'); });
        });
        $('#dkRows').on('click', '[data-delete]', function (e) {
            e.preventDefault();
            var id = $(this).closest('tr').data('id');
            confirmDo({ text: 'Remove this repository?', danger: true, confirmText: 'Remove' }, function () { GBX.del(url('registries/' + id)).done(function (r) { toastr.success(r.message); load(); }); });
        });
        submitForm($('#dkRegistryForm'), function () { return url('registries' + (editing ? '/' + editing : '')); }, {
            data: function ($f) { var d = GBX.serialize($f); if (editing) d._method = 'PUT'; return d; },
            done: load
        });
        load();
    }

    /* ================================================================= settings */
    function settingsTab() {
        var load = function () {
            GBX.get(url('settings')).done(function (r) {
                var i = r.info || {};
                $('#dkServiceInfo').html(
                    '<div><span>Status</span><strong><span class="status-dot ' + (r.running ? 'on' : 'off') + '"></span> ' + (r.running ? 'Running' : (r.installed ? 'Stopped' : 'Not installed')) + '</strong></div>' +
                    '<div><span>Docker</span><strong>' + esc(i.version || '-') + '</strong></div>' +
                    '<div><span>Compose</span><strong>' + esc(i.compose || '-') + '</strong></div>' +
                    '<div><span>Storage driver</span><strong>' + esc(i.storage_driver || '-') + '</strong></div>' +
                    '<div><span>Data root</span><strong class="font-mono small">' + esc(i.root_dir || '-') + '</strong></div>' +
                    '<div><span>Resources</span><strong>' + (i.cpus ? i.cpus + ' CPUs, ' + bytes(i.memory) : '-') + '</strong></div>' +
                    '<div><span>Containers</span><strong>' + (i.containers !== undefined ? i.running + ' running of ' + i.containers : '-') + '</strong></div>' +
                    '<div><span>Compose projects</span><strong class="font-mono small">' + esc(r.projects_root) + '</strong></div>' +
                    '<div><span>Image exports</span><strong class="font-mono small">' + esc(r.export_dir) + '</strong></div>'
                );
                $('#dkServiceEnabled').prop('checked', !!r.enabled);
                var $f = $('#dkDaemonForm'), form = r.form;
                $.each(['registry_mirrors', 'insecure_registries', 'log_driver', 'log_max_size', 'log_max_file', 'fixed_cidr_v6'], function (_, k) { $f.find('[name=' + k + ']').val(form[k]); });
                $f.find('[name=live_restore]').prop('checked', form.live_restore);
                $f.find('[name=ipv6]').prop('checked', form.ipv6);
                $f.find('[name=iptables]').prop('checked', form.iptables);
                $('#dkDaemonRaw [name=raw]').val(r.raw);
            });
        };
        $('[data-dk-mode]').on('click', function () {
            $(this).addClass('active').siblings().removeClass('active');
            var raw = $(this).data('dk-mode') === 'raw';
            $('#dkDaemonForm').prop('hidden', raw);
            $('#dkDaemonRaw').prop('hidden', !raw);
        });
        var save = function (data, $btn) {
            confirmDo({ text: 'Save the configuration and restart Docker now?', confirmText: 'Save and restart' }, function () {
                GBX.busy($btn, true);
                GBX.post(url('settings'), data, { timeout: 400000 }).done(function (r) { toastr.success(r.message); load(); }).always(function () { GBX.busy($btn, false); });
            });
        };
        $('#dkDaemonForm').on('submit', function (e) { e.preventDefault(); save($.extend(GBX.serialize($(this)), { mode: 'form' }), $(this).find('[type=submit]')); });
        $('#dkDaemonRaw').on('submit', function (e) { e.preventDefault(); save({ mode: 'raw', raw: $(this).find('[name=raw]').val() }, $(this).find('[type=submit]')); });
        $('[data-dk-service]').on('click', function () {
            var action = $(this).data('dk-service'), $btn = $(this);
            var run = function () {
                GBX.busy($btn, true);
                GBX.post(url('service'), { action: action }, { timeout: 200000 }).done(function (r) { toastr.success(r.message); load(); }).always(function () { GBX.busy($btn, false); });
            };
            if (action === 'stop') confirmDo({ text: 'Stop Docker? Every container stops.', danger: true, confirmText: 'Stop' }, run); else run();
        });
        $('#dkServiceEnabled').on('change', function () {
            GBX.post(url('service'), { action: this.checked ? 'enable' : 'disable' }).done(function (r) { toastr.success(r.message); });
        });
        load();
    }

    var tabs = { overview: overviewTab, containers: containersTab, apps: appsTab, hub: hubTab, images: imagesTab, compose: composeTab, networks: networksTab, volumes: volumesTab, registries: registriesTab, settings: settingsTab };
    if (tabs[D.tab] && ($('#dkRows, #dkCards, #dkApps, #dkHubRows, #dkProjects, #dkSettings').length)) tabs[D.tab]();
})(jQuery);
