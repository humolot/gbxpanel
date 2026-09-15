/*
 * GBX Panel - Websites: list, site settings modal (Conf), usage and logs (Log), backups.
 */
function siteCreated(res) {
    var html = '<p>The website is online.</p>';
    if (res.database) html += '<div class="text-start mb-2"><div class="small-caps mb-1">Database</div><div class="kv"><dt>Name</dt><dd class="font-mono">' + GBX.escape(res.database.name) + '</dd><dt>User</dt><dd class="font-mono">' + GBX.escape(res.database.username) + '</dd><dt>Password</dt><dd class="font-mono">' + GBX.escape(res.database.password) + '</dd></div></div>';
    if (res.ftp) html += '<div class="text-start mb-2"><div class="small-caps mb-1">FTP</div><div class="kv"><dt>User</dt><dd class="font-mono">' + GBX.escape(res.ftp.username) + '</dd><dt>Password</dt><dd class="font-mono">' + GBX.escape(res.ftp.password) + '</dd></div></div>';
    if (res.dns) html += '<div class="text-start mb-2"><div class="small-caps mb-1">DNS</div><ul class="small mb-0 ps-3">' + res.dns.map(function (m) { return '<li>' + GBX.escape(m) + '</li>'; }).join('') + '</ul></div>';
    if (res.warnings) html += '<div class="alert alert-warning text-start small">' + res.warnings.map(GBX.escape).join('<br>') + '</div>';
    if (!res.database && !res.ftp && !res.warnings && !res.dns) { window.location.reload(); return; }
    Swal.fire({ title: 'Website created', html: html + '<p class="small text-muted mt-2">Save these credentials, passwords are not shown again in plain text here.</p>', icon: 'success', buttonsStyling: false, customClass: { confirmButton: 'btn btn-primary' } })
        .then(function () { window.location.reload(); });
}

(function ($) {
    'use strict';

    var S = window.GBX_SITES, esc = GBX.escape;
    var siteUrl = function (id, path) { return S.routes.base + '/' + id + (path ? '/' + path : ''); };
    var rowOf = function (el) { return $(el).closest('tr[data-id]'); };

    /* ================================================================ list */
    var table = null;
    if ($('#sitesTable').length) {
        table = $('#sitesTable').DataTable({
            order: [[1, 'asc']],
            columnDefs: [{ orderable: false, targets: [0, 4, 8] }],
            drawCallback: function () { updateBulk(); }
        });
    }

    // create form: www alias only for apex domains
    var wwwTouched = false;
    $('#addWww').on('change', function () { wwwTouched = true; });
    function isApex(d) {
        var l = d.replace(/^\.+|\.+$/g, '').split('.');
        if (l.length <= 2) return true;
        return l.length === 3 && ['com', 'net', 'org', 'gov', 'edu', 'co', 'ac', 'gob', 'or', 'ne'].indexOf(l[1]) !== -1 && l[2].length === 2;
    }
    $('#siteForm [name=domain]').on('input', function () {
        var d = this.value.trim().toLowerCase();
        $('#siteForm [name=root_path]').attr('placeholder', S.wwwRoot + '/' + (d || 'example.com'));
        if (!wwwTouched) $('#addWww').prop('checked', !d || (d.indexOf('www.') !== 0 && isApex(d)));
        clearTimeout(dnsTimer);
        dnsTimer = setTimeout(function () {
            if (!/^[a-z0-9.-]+\.[a-z]{2,}$/.test(d)) { $('#siteDnsWrap').prop('hidden', true); return; }
            GBX.get(S.routes.dnsMatch, { domain: d }, { silent: true }).done(function (r) {
                $('#siteDnsWrap').prop('hidden', !r.zone);
                $('#createDns').prop('disabled', !r.zone);
                if (r.zone) $('#siteDnsZone').text('(' + r.zone.name + ' at ' + r.zone.provider + ')');
            });
        }, 400);
    });
    var dnsTimer = null;
    $('#createDns').prop('disabled', true);

    $(document).on('click', '.delete-site', function (e) {
        e.preventDefault();
        var url = $(this).data('url'), domain = $(this).data('domain');
        GBX.confirm({
            title: 'Delete ' + domain + '?', danger: true, confirmText: 'Delete website',
            html: '<p>The virtual host is removed. Choose what else to delete:</p><div class="text-start d-inline-block">' +
                '<div class="form-check"><input class="form-check-input" type="checkbox" id="delFiles"><label class="form-check-label" for="delFiles">Website files (document root)</label></div>' +
                '<div class="form-check"><input class="form-check-input" type="checkbox" id="delDb"><label class="form-check-label" for="delDb">Linked databases</label></div>' +
                '<div class="form-check"><input class="form-check-input" type="checkbox" id="delFtp"><label class="form-check-label" for="delFtp">Linked FTP accounts</label></div></div>',
            preConfirm: function () { return { delete_files: $('#delFiles').is(':checked') ? 1 : 0, delete_databases: $('#delDb').is(':checked') ? 1 : 0, delete_ftp: $('#delFtp').is(':checked') ? 1 : 0 }; }
        }).then(function (r) {
            if (!r.isConfirmed) return;
            GBX.del(url, r.value).done(function (res) { toastr.success(res.message); setTimeout(function () { location.reload(); }, 600); });
        });
    });

    // bulk actions
    function selectedIds() { return $('.ws-row-check:checked').map(function () { return this.value; }).get(); }
    function updateBulk() {
        var n = selectedIds().length;
        $('#wsSelected').text(n + ' selected');
        $('#wsBulkRun').prop('disabled', !n || !$('#wsBulkAction').val());
    }
    $(document).on('change', '.ws-row-check, #wsBulkAction', updateBulk);
    $('#wsAll').on('change', function () { $('.ws-row-check').prop('checked', this.checked); updateBulk(); });
    $('#wsBulkRun').on('click', function () {
        var ids = selectedIds(), action = $('#wsBulkAction').val(), $b = $(this);
        GBX.confirm({ text: $('#wsBulkAction option:selected').text() + ' ' + ids.length + ' website(s)?', confirmText: 'Execute' }).then(function (r) {
            if (!r.isConfirmed) return;
            GBX.busy($b, true);
            GBX.post(S.routes.bulk, { ids: ids, action: action }).done(function (res) {
                toastr.success(res.message);
                if (action !== 'backup') setTimeout(function () { location.reload(); }, 700);
            }).always(function () { GBX.busy($b, false); });
        });
    });

    // remark
    $(document).on('click', '.ws-remark', function (e) {
        e.preventDefault();
        if (!S.canWrite) return;
        var $a = $(this), id = rowOf(this).data('id');
        Swal.fire({
            title: 'Remark', input: 'text', inputValue: $a.data('notes') || '', inputAttributes: { maxlength: 255 }, showCancelButton: true, confirmButtonText: 'Save',
            buttonsStyling: false, reverseButtons: true, customClass: { confirmButton: 'btn btn-primary', cancelButton: 'btn btn-outline-secondary' }
        }).then(function (r) {
            if (!r.isConfirmed) return;
            GBX.post(siteUrl(id, 'meta'), { notes: r.value }).done(function (res) {
                toastr.success(res.message);
                $a.data('notes', r.value).text(r.value || 'Add remark').toggleClass('empty', !r.value);
            });
        });
    });

    // expiration
    var expireId = null;
    $(document).on('click', '.ws-expire', function (e) {
        e.preventDefault();
        if (!S.canWrite) return;
        expireId = rowOf(this).data('id');
        $('#expireDate').val($(this).data('date') || '');
        bootstrap.Modal.getOrCreateInstance('#expireModal').show();
    });
    $('[data-expire-add]').on('click', function () {
        var months = +$(this).data('expire-add');
        if (!months) { $('#expireDate').val(''); return; }
        var base = $('#expireDate').val() ? new Date($('#expireDate').val() + 'T12:00:00') : new Date();
        base.setMonth(base.getMonth() + months);
        $('#expireDate').val(base.toISOString().slice(0, 10));
    });
    $('#expireForm').on('submit', function (e) {
        e.preventDefault();
        GBX.post(siteUrl(expireId, 'meta'), { expires_at: $('#expireDate').val() || '' }).done(function (res) {
            toastr.success(res.message);
            bootstrap.Modal.getOrCreateInstance('#expireModal').hide();
            var $a = $('tr[data-id="' + expireId + '"] .ws-expire'), d = $('#expireDate').val();
            $a.data('date', d).text(d || 'Perpetual').removeClass('text-danger');
        });
    });

    /* ================================================================ requests */
    function sparkline(svg, values) {
        var max = Math.max.apply(null, values.concat([1])), w = 120, h = 26, step = w / Math.max(1, values.length - 1);
        var pts = values.map(function (v, i) { return (i * step).toFixed(1) + ',' + (h - 2 - (v / max) * (h - 4)).toFixed(1); });
        svg.innerHTML = '<polyline class="area" points="0,' + h + ' ' + pts.join(' ') + ' ' + w + ',' + h + '"></polyline><polyline class="line" points="' + pts.join(' ') + '"></polyline>';
    }
    function loadStats() {
        if (!table) return;
        GBX.get(S.routes.stats, {}, { silent: true }).done(function (r) {
            $('#sitesTable tbody tr[data-id]').each(function () {
                var s = r.sites[$(this).data('id')];
                if (!s) return;
                var $cell = $(this).find('.ws-requests');
                $cell.attr('data-order', s.total).find('.ws-req-count').text(s.total.toLocaleString());
                sparkline($cell.find('.ws-spark')[0], s.hours);
                table.cell($cell[0]).invalidate('dom');
            });
        });
    }
    loadStats();
    setInterval(function () { if (!document.hidden) loadStats(); }, 300000);

    /* ================================================================ backups */
    var backupSite = null;
    function loadBackups() {
        var $list = $('#backupList').html('<tr><td colspan="4" class="sm-empty"><i class="bi bi-arrow-repeat spin"></i> Loading</td></tr>');
        GBX.get(siteUrl(backupSite.id, 'backups')).done(function (r) {
            $('#backupDbNames').text(r.databases.length ? '(' + r.databases.join(', ') + ')' : '(none linked)');
            if (!r.backups.length) { $list.html('<tr><td colspan="4" class="sm-empty"><i class="bi bi-inbox"></i> No backups yet</td></tr>'); return; }
            $list.html(r.backups.map(function (b) {
                return '<tr data-file="' + esc(b.file) + '"><td class="font-mono small text-break">' + esc(b.file.split('/').pop()) + '</td><td class="text-nowrap">' + esc(b.size) + '</td><td class="text-nowrap">' + esc(b.date) + '</td>' +
                    '<td class="text-end text-nowrap"><a class="btn btn-sm btn-ghost" href="' + siteUrl(backupSite.id, 'backups/download') + '?file=' + encodeURIComponent(b.file) + '">Download</a>' +
                    (S.canWrite ? '<button class="btn btn-sm btn-ghost" data-backup="restore">Restore</button><button class="btn btn-sm btn-ghost text-danger" data-backup="delete">Delete</button>' : '') + '</td></tr>';
            }).join(''));
        });
    }
    $(document).on('click', '.ws-backup', function (e) {
        e.preventDefault();
        var $tr = rowOf(this);
        backupSite = { id: $tr.data('id'), domain: $tr.data('domain') };
        $('#backupDomain').text(backupSite.domain);
        bootstrap.Modal.getOrCreateInstance('#backupModal').show();
        loadBackups();
    });
    $(document).on('change', '#backupStorage', function () { $('#backupMoveWrap').prop('hidden', !this.value); });
    $('#backupNow').on('click', function () {
        GBX.post(siteUrl(backupSite.id, 'backups'), {
            databases: $('#backupDbs').is(':checked') ? 1 : 0,
            storage_id: $('#backupStorage').val() || '',
            delete_local: $('#backupMove').is(':checked') ? 1 : 0
        }, { onTaskDone: loadBackups });
    });
    $('#backupList').on('click', '[data-backup]', function () {
        var file = $(this).closest('tr').data('file'), act = $(this).data('backup');
        if (act === 'restore') {
            GBX.confirm({ title: 'Restore backup?', html: 'Files from <code>' + esc(file.split('/').pop()) + '</code> are extracted over <code>' + esc(backupSite.domain) + '</code>. Current files with the same name are overwritten.', danger: true, confirmText: 'Restore' })
                .then(function (r) { if (r.isConfirmed) GBX.post(siteUrl(backupSite.id, 'backups/restore'), { file: file }); });
        } else {
            GBX.confirm({ text: 'Delete ' + file.split('/').pop() + '?', danger: true, confirmText: 'Delete' })
                .then(function (r) { if (r.isConfirmed) GBX.post(siteUrl(backupSite.id, 'backups/delete'), { file: file }).done(function (res) { toastr.success(res.message); loadBackups(); }); });
        }
    });

    /* ================================================================ usage and logs */
    var usage = { id: null, view: 'overview', chart: null };

    function topTable($el, rows, empty) {
        if (!rows.length) { $el.html('<tr><td class="cell-sub">' + (empty || 'No data') + '</td></tr>'); return; }
        var max = rows[0].count;
        $el.html(rows.map(function (r) {
            return '<tr><td><div class="us-bar" style="--w:' + Math.max(2, Math.round(r.count / max * 100)) + '%"></div><span class="text-break">' + esc(r.name) + '</span></td><td class="text-end font-mono">' + r.count.toLocaleString() + '</td></tr>';
        }).join(''));
    }

    function loadUsage() {
        $('#usTotal, #usIps, #usBytes, #usErrors').html('<i class="bi bi-arrow-repeat spin"></i>');
        GBX.get(siteUrl(usage.id, 'usage'), { range: $('#usageRange').val() }).done(function (r) {
            var u = r.report;
            $('#usageNoLog').toggleClass('d-none', u.access_log);
            $('#usTotal').text(u.total.toLocaleString());
            $('#usIps').text(u.unique_ips.toLocaleString());
            $('#usBytes').text(u.bandwidth);
            $('#usErrors').html('<span class="text-warning">' + u.status['4xx'].toLocaleString() + '</span> / <span class="text-danger">' + u.status['5xx'].toLocaleString() + '</span>');
            $('#usStatus').html(['2xx', '3xx', '4xx', '5xx'].map(function (k) { return '<span class="us-code us-' + k + '">' + k + ' ' + u.status[k].toLocaleString() + '</span>'; }).join(''));
            topTable($('#usUrls'), u.top_urls);
            topTable($('#usIpList'), u.top_ips);
            topTable($('#us404'), u.not_found, 'No 404 errors');
            topTable($('#usAgents'), u.agents);
            topTable($('#usRefs'), u.referrers, 'No external referrers');
            $('#usSince').text(u.since ? 'Based on requests since ' + u.since + ' (most recent 50,000 log lines).' : '');

            var labels = u.timeline.labels.map(function (l) { return l.length > 10 ? l.slice(11) : l.slice(5); });
            if (usage.chart) usage.chart.destroy();
            usage.chart = new Chart(document.getElementById('usChart'), {
                type: 'line',
                data: { labels: labels, datasets: [{ data: u.timeline.values, borderColor: '#3ecf8e', backgroundColor: 'rgba(62, 207, 142, .12)', fill: true, tension: .35, pointRadius: 0, borderWidth: 2 }] },
                options: {
                    maintainAspectRatio: false, animation: false, plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } },
                    scales: { x: { grid: { color: 'rgba(255,255,255,.04)' }, ticks: { color: '#858d97', maxTicksLimit: 12 } }, y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,.05)' }, ticks: { color: '#858d97', precision: 0 } } }
                }
            });
        });
    }

    function loadLog() {
        $('#logOut').text('Loading...');
        GBX.get(siteUrl(usage.id, 'logs'), { type: usage.view, lines: $('#logLines').val(), filter: $('#logFilter').val() }).done(function (r) {
            $('#logPath').text(r.path);
            var $out = $('#logOut').empty();
            if (!r.content) { $out.text($('#logFilter').val() ? 'No lines match the filter.' : 'The log is empty.'); return; }
            // colour status codes and error levels
            var html = esc(r.content)
                .replace(/(&quot; )([45]\d\d)( )/g, '$1<span class="err">$2</span>$3')
                .replace(/\[(\w+:)?(error|crit|alert|emerg)\]/g, '<span class="err">$&</span>')
                .replace(/\[(\w+:)?(warn|notice)\]/g, '<span class="warn">$&</span>');
            $out.html(html).scrollTop(1e9);
        });
    }

    function showUsageView(view) {
        usage.view = view;
        $('#usageTabs .nav-link').removeClass('active').filter('[data-view="' + view + '"]').addClass('active');
        var isLog = view !== 'overview';
        $('[data-usage-view=overview]').toggleClass('d-none', isLog);
        $('[data-usage-view=log]').toggleClass('d-none', !isLog);
        $('[data-usage-tools=overview]').toggleClass('d-none', isLog);
        $('[data-usage-tools=log]').toggleClass('d-none', !isLog);
        isLog ? loadLog() : loadUsage();
    }

    $(document).on('click', '.ws-log', function (e) {
        e.preventDefault();
        var $tr = rowOf(this);
        usage.id = $tr.data('id');
        $('#usageDomain').text($tr.data('domain'));
        bootstrap.Modal.getOrCreateInstance('#usageModal').show();
        showUsageView('overview');
    });
    $('#usageTabs').on('click', '[data-view]', function () { showUsageView($(this).data('view')); });
    $('#usageRange').on('change', loadUsage);
    $('[data-usage-refresh]').on('click', function () { usage.view === 'overview' ? loadUsage() : loadLog(); });
    $('#logLines').on('change', loadLog);
    var filterTimer = null;
    $('#logFilter').on('input', function () { clearTimeout(filterTimer); filterTimer = setTimeout(loadLog, 400); });
    $('#logClear').on('click', function () {
        GBX.confirm({ text: 'Clear the ' + usage.view + ' log of ' + $('#usageDomain').text() + '?', danger: true, confirmText: 'Clear log' }).then(function (r) {
            if (r.isConfirmed) GBX.post(siteUrl(usage.id, 'logs/clear'), { type: usage.view }).done(function (res) { toastr.success(res.message); loadLog(); });
        });
    });

    /* ================================================================ site settings modal */
    var conf = { id: null, tab: 'domains', changed: false, editors: {} };

    function openConf(id, tab) {
        conf.id = id;
        conf.tab = tab || conf.tab || 'domains';
        conf.editors = {};
        $('#confBody').html('<div class="sm-loading"><i class="bi bi-arrow-repeat spin"></i> Loading</div>');
        bootstrap.Modal.getOrCreateInstance('#confModal').show();
        loadConf();
    }

    function loadConf() {
        return GBX.get(siteUrl(conf.id, 'manage')).done(function (r) {
            $('#confDomain').text(r.title);
            $('#confCreated').text(r.created || '');
            conf.editors = {};
            $('#confBody').html(r.html);
            showSection(conf.tab);
        });
    }

    // called by forms and buttons inside the modal (data-success="siteSectionSaved")
    window.siteSectionSaved = function (res) {
        conf.changed = true;
        if (res && res.task) return;
        loadConf();
    };

    function showSection(section) {
        conf.tab = section;
        var $body = $('#confBody');
        $body.find('.sm-nav-link').removeClass('active').filter('[data-section="' + section + '"]').addClass('active');
        $body.find('.sm-pane').removeClass('active').filter('[data-pane="' + section + '"]').addClass('active');
        var $active = $body.find('.sm-nav-link.active');
        if ($active.length && $active[0].scrollIntoView) $active[0].scrollIntoView({ block: 'nearest', inline: 'nearest' });
        if (history.replaceState) history.replaceState(null, '', location.pathname + '?conf=' + conf.id + '#' + section);

        if (section === 'directory') loadSubdirs();
        if (section === 'rewrite') loadRewrite();
        if (section === 'config') loadConfig();
        if (section === 'composer') { loadComposer(); composerCommandChanged(); }
        if (section === 'git') gitAuthChanged();
        if (conf.editors[section]) setTimeout(function () { conf.editors[section].refresh(); }, 10);
    }

    function editor(key, el, value, readOnly) {
        if (!conf.editors[key]) {
            conf.editors[key] = CodeMirror(el, { value: value, mode: 'nginx', theme: 'material-darker', lineNumbers: true, indentUnit: 4, readOnly: !S.canWrite || !!readOnly, lineWrapping: false });
        } else {
            conf.editors[key].setValue(value);
        }
        setTimeout(function () { conf.editors[key].refresh(); }, 10);
        return conf.editors[key];
    }

    $(document).on('click', '.ws-conf', function (e) {
        e.preventDefault();
        openConf(rowOf(this).data('id'), $(this).data('tab'));
    });
    $('#confBody').on('click', '.sm-nav-link', function () { showSection($(this).data('section')); });
    $('#confModal').on('hidden.bs.modal', function () {
        if (history.replaceState) history.replaceState(null, '', location.pathname);
        if (conf.changed) location.reload();
    });

    // switches that post immediately
    $('#confBody').on('change', '.sm-toggle', function () {
        var $c = $(this), payload = { enabled: this.checked ? 1 : 0 };
        if ($c.data('action')) payload.action = $c.data('action');
        GBX.post($c.data('url'), payload).done(function (res) { toastr.success(res.message); conf.changed = true; })
            .fail(function () { $c.prop('checked', !$c.prop('checked')); });
    });
    $('#confBody').on('change', '.sm-rule-toggle', function () {
        var $c = $(this);
        GBX.post($c.data('url'), { action: 'toggle', id: $c.data('id') }).done(function (res) { toastr.success(res.message); conf.changed = true; })
            .fail(function () { $c.prop('checked', !$c.prop('checked')); });
    });

    // directory
    function loadSubdirs() {
        var $sel = $('#confBody [data-subdirs]');
        if (!$sel.length || $sel.data('loaded')) return;
        $sel.data('loaded', true);
        GBX.get(siteUrl(conf.id, 'manage/subdirs'), {}, { silent: true }).done(function (r) {
            var current = String($sel.data('current') || '');
            $sel.html('<option value="">/</option>' + r.dirs.map(function (d) { return '<option value="' + esc(d) + '"' + (d === current ? ' selected' : '') + '>/' + esc(d) + '</option>'; }).join(''));
            if (current && r.dirs.indexOf(current) === -1) $sel.append('<option value="' + esc(current) + '" selected>/' + esc(current) + ' (missing)</option>');
        });
    }

    // URL rewrite
    function loadRewrite() {
        var el = document.getElementById('smRewriteEditor');
        if (!el || conf.editors.rewrite) return;
        GBX.get(siteUrl(conf.id, 'manage/rewrite')).done(function (r) {
            $('#smRewritePath').text(r.path);
            editor('rewrite', el, r.content || '');
            conf.rewriteOriginal = r.content || '';
        });
    }
    $('#confBody').on('change', '#smRewriteTemplate', function () {
        var templates = JSON.parse($('#smTemplates').text()), key = this.value, ed = conf.editors.rewrite;
        if (!ed) return;
        ed.setValue(key ? templates[key].rules : conf.rewriteOriginal);
        var hint = key && templates[key].hint;
        $('#smRewriteHint').toggleClass('d-none', !hint).html(hint ? '<strong>Note:</strong> ' + esc(hint) : '');
    });
    $('#confBody').on('click', '#smRewriteSave', function () {
        var $b = $(this);
        GBX.busy($b, true);
        GBX.post(siteUrl(conf.id, 'manage/rewrite'), { content: conf.editors.rewrite.getValue() }).done(function (res) {
            toastr.success(res.message);
            conf.rewriteOriginal = conf.editors.rewrite.getValue();
            $('#smRewriteTemplate').val('');
        }).always(function () { GBX.busy($b, false); });
    });
    $('#confBody').on('click', '#smRewriteReload', function () { delete conf.editors.rewrite; $('#smRewriteEditor').empty(); loadRewrite(); });

    // default document
    $('#confBody').on('click', '[data-fill-index]', function () { $(this).closest('form').find('[name=files]').val($(this).data('fill-index')); });

    // vhost config
    function loadConfig(force) {
        var el = document.getElementById('smConfigEditor');
        if (!el || (conf.editors.config && !force)) return;
        GBX.get(siteUrl(conf.id, 'config')).done(function (r) {
            $('#smConfigPath').text(r.path);
            var ed = editor('config', el, r.content);
            ed.setOption('extraKeys', { 'Ctrl-S': saveConfig, 'Cmd-S': saveConfig });
        });
    }
    function saveConfig() {
        var $b = $('#smConfigSave');
        GBX.busy($b, true);
        GBX.post(siteUrl(conf.id, 'config'), { content: conf.editors.config.getValue() }).done(function (res) { toastr.success(res.message); })
            .always(function () { GBX.busy($b, false); });
    }
    $('#confBody').on('click', '#smConfigSave', saveConfig);
    $('#confBody').on('click', '#smConfigReload', function () { loadConfig(true); });

    // Git
    function gitAuthChanged() {
        var auth = $('#smGitForm [name=auth]:checked').val();
        $('#smGitForm [data-git-auth]').each(function () { $(this).toggleClass('d-none', $(this).data('git-auth') !== auth); });
        if (auth === 'ssh' && !$('#smGitKey').val()) {
            GBX.get(siteUrl(conf.id, 'manage/git-key')).done(function (r) { $('#smGitKey').val(r.key); });
        }
    }
    $('#confBody').on('change', '#smGitForm [name=auth]', gitAuthChanged);
    $('#confBody').on('click', '#smGitTest', function () {
        var $b = $(this), $f = $('#smGitForm'), data = GBX.serialize($f);
        data.action = 'test';
        GBX.busy($b, true);
        GBX.post(siteUrl(conf.id, 'manage/git'), data).done(function (res) {
            var current = $('#smGitBranch').val();
            $('#smGitBranch').html(res.branches.map(function (b) { return '<option' + (b === current || (!current && (b === 'main' || b === 'master')) ? ' selected' : '') + '>' + esc(b) + '</option>'; }).join('') || '<option value="">No branches found</option>');
            toastr.success(res.message + ': ' + res.branches.length + ' branch(es)');
        }).always(function () { GBX.busy($b, false); });
    });
    $('#confBody').on('click', '#smGitForm [data-deploy]', function () { $('#smGitForm [name=deploy]').val($(this).data('deploy')); });

    // Composer
    function loadComposer() {
        var $info = $('#smComposerInfo');
        if (!$info.length || $info.data('loaded')) return;
        $info.data('loaded', true);
        GBX.get(siteUrl(conf.id, 'manage/composer'), {}, { silent: true }).done(function (r) {
            if (!r.found) { $info.html('<i class="bi bi-exclamation-circle text-warning"></i> No composer.json in ' + esc(r.dir) + '. Set a folder below if the project lives in a subfolder.'); return; }
            var req = Object.keys(r.require || {}).slice(0, 8).map(function (k) { return '<span class="badge badge-soft font-mono me-1 mb-1">' + esc(k) + ' ' + esc(r.require[k]) + '</span>'; }).join('');
            $info.html('<dl class="kv mb-2"><dt>Project</dt><dd class="font-mono">' + esc(r.name || '(no name)') + '</dd><dt>Folder</dt><dd class="font-mono">' + esc(r.dir) + '</dd><dt>PHP</dt><dd>' + esc(r.php || 'system') + '</dd><dt>State</dt><dd>' + (r.lock ? 'composer.lock present' : 'no composer.lock') + ', ' + (r.vendor ? 'vendor installed' : 'vendor missing') + '</dd></dl>' + req);
        });
    }
    function composerCommandChanged() {
        var cmd = $('#smComposerForm [name=command]').val();
        $('#smComposerForm [data-composer-package]').toggleClass('d-none', cmd !== 'require' && cmd !== 'remove');
    }
    $('#confBody').on('change', '#smComposerForm [name=command]', composerCommandChanged);

    // redirects: source is a path input or a domain select
    $('#confBody').on('change', '[data-redirect-type]', function () {
        var domain = this.value === 'domain', $f = $(this).closest('form');
        $f.find('[data-source-path]').toggleClass('d-none', domain).prop('disabled', domain);
        $f.find('[data-source-domain]').toggleClass('d-none', !domain).prop('disabled', !domain);
    });

    // maintenance: add current IP
    $('#confBody').on('click', '[data-add-ip]', function () {
        var $t = $(this).closest('div').find('[name=allowed_ips]'), ip = $(this).data('add-ip'), v = $t.val().trim();
        if ((' ' + v.replace(/\s+/g, ' ') + ' ').indexOf(' ' + ip + ' ') === -1) $t.val((v ? v + '\n' : '') + ip);
    });

    // deep link: /websites?conf=ID#ssl
    var params = new URLSearchParams(location.search);
    if (params.get('conf')) openConf(params.get('conf'), (location.hash || '').replace('#', '') || 'domains');

    // refresh the list after a task started from the modals finishes (SSL, deploy, backup)
    $(document).on('gbx:task-finished', function (e, task) {
        if ($('#backupModal').hasClass('show')) loadBackups();
        if ($('#confModal').hasClass('show') && task && task.status === 'success') { conf.changed = true; loadConf(); }
    });
})(jQuery);
