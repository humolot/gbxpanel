/*
 * GBX Panel - Databases: MySQL, PostgreSQL, MongoDB, SQL Server, Redis and Qdrant tabs.
 */
(function ($) {
    'use strict';

    var D = window.GBX_DB, R = D.routes, esc = GBX.escape;
    var bytes = function (n) { return n === null || n === undefined ? '-' : GBX.bytes(n, 1); };
    var dateOf = function (ts) { return ts ? GBX.date(ts) : '-'; };
    var empty = function (cols, text) { return '<tr><td colspan="' + cols + '" class="sm-empty"><i class="bi bi-inbox"></i> ' + (text || 'No Data') + '</td></tr>'; };
    var loading = function (cols) { return '<tr><td colspan="' + cols + '" class="sm-empty"><i class="bi bi-arrow-repeat spin"></i> Loading</td></tr>'; };
    var upload = function (title, accept, text) {
        return Swal.fire({
            title: title, input: 'file', inputAttributes: { accept: accept }, html: text ? '<p class="small">' + text + '</p>' : undefined,
            showCancelButton: true, confirmButtonText: 'Upload', buttonsStyling: false, reverseButtons: true,
            customClass: { confirmButton: 'btn btn-primary', cancelButton: 'btn btn-outline-secondary' },
            inputValidator: function (f) { if (!f) return 'Choose a file'; }
        });
    };

    if (['mysql', 'pgsql', 'mongodb', 'sqlserver'].indexOf(D.engine) !== -1) sqlTab();
    if (D.engine === 'redis') redisTab();
    if (D.engine === 'qdrant') qdrantTab();

    /* ================================================================ SQL engines */
    function sqlTab() {
        var table = null, current = null;

        if ($('#dbTable').length) {
            table = $('#dbTable').DataTable({ order: [[1, 'asc']], columnDefs: [{ orderable: false, targets: [0, 3, 5, -1] }], dom: 'rt<"d-flex justify-content-between align-items-center px-3 py-2"ip>' });
            $('#dbSearch').on('input', function () { table.search(this.value).draw(); });
            $.fn.dataTable.ext.search.push(function (settings, data, index) {
                var loc = $('#dbLocationFilter').val();
                return !loc || $(table.row(index).node()).data('location') + '' === loc;
            });
            $('#dbLocationFilter').on('change', function () { table.draw(); });
        }

        var rowOf = function (el) { return $(el).closest('tr[data-id]'); };
        var dbUrl = function (id, path) { return D.base + '/' + id + (path ? '/' + path : ''); };

        // sizes and databases that exist on the server but are not managed
        GBX.get(R.live, { engine: D.engine }, { silent: true }).done(function (r) {
            var html = '';
            r.locations.forEach(function (loc) {
                var key = loc.id ? String(loc.id) : 'local';
                $('#dbTable tbody tr[data-location="' + key + '"]').each(function () {
                    var info = loc.databases[$(this).data('name')], $cell = $(this).find('.db-size');
                    if (!loc.connected) $cell.html('<span class="badge badge-warning" title="' + esc(loc.label) + ' is unreachable">offline</span>');
                    else if (!info) $cell.html('<span class="badge badge-danger" title="Not found on the server">missing</span>');
                    else $cell.attr('data-order', info.size || 0).text(bytes(info.size) + (info.tables !== null ? ' / ' + info.tables + ' tables' : ''));
                    if (table) table.cell($cell[0]).invalidate('dom');
                });
                if (!loc.connected) {
                    html += '<div class="db-alert warning"><i class="bi bi-exclamation-triangle"></i> Cannot connect to <strong>' + esc(loc.label) + '</strong>. Check that the server is running and the credentials are valid.</div>';
                } else if (loc.unmanaged.length) {
                    html += '<div class="db-alert"><i class="bi bi-info-circle"></i> ' + loc.unmanaged.length + ' database(s) on <strong>' + esc(loc.label) + '</strong> are not managed by the panel: <span class="font-mono small">' + loc.unmanaged.slice(0, 6).map(esc).join(', ') + (loc.unmanaged.length > 6 ? ', ...' : '') + '</span>' +
                        (D.canWrite ? '<button class="btn btn-sm btn-outline-secondary ms-auto" data-import-location="' + (loc.id || '') + '">Import</button>' : '') + '</div>';
                }
            });
            $('#dbUnmanaged').html(html);
        });

        function importFrom(serverId) {
            GBX.post(R.sync, { engine: D.engine, server_id: serverId || '' }).done(function (res) { toastr.success(res.message); setTimeout(function () { location.reload(); }, 700); });
        }
        $('#dbUnmanaged').on('click', '[data-import-location]', function () { importFrom($(this).data('import-location')); });
        $('#dbImport').on('click', function () {
            var options = $('#dbLocationFilter option').filter(function () { return this.value !== ''; });
            if (options.length <= 1) return importFrom(options.val() === 'local' ? '' : options.val());
            var inputs = {};
            options.each(function () { inputs[this.value === 'local' ? '' : this.value] = $(this).text(); });
            Swal.fire({ title: 'Get databases from', input: 'select', inputOptions: inputs, showCancelButton: true, confirmButtonText: 'Import', buttonsStyling: false, reverseButtons: true, customClass: { confirmButton: 'btn btn-primary', cancelButton: 'btn btn-outline-secondary' } })
                .then(function (r) { if (r.isConfirmed) importFrom(r.value); });
        });
        $('#dbSyncUsers').on('click', function () {
            GBX.confirm({ title: 'Sync all users?', text: 'The password stored in the panel (and MySQL access hosts) is applied to every database user.', confirmText: 'Sync' })
                .then(function (r) { if (r.isConfirmed) GBX.post(R.syncUsers, { engine: D.engine }).done(function (res) { toastr.success(res.message); }); });
        });

        // add database
        $('#dbAddModal').on('show.bs.modal', function () { if (!$('#dbPassword').val()) $('#dbPassword').val(GBX.password(20)); });
        $('#dbName').on('input', function () { $('#dbUser').val(this.value.substring(0, 32)); });
        function hostsChanged() {
            var v = $('#dbAccess').val();
            $('#dbIpsCol').toggleClass('d-none', v !== 'ips');
            $('#dbHosts').val(v === 'ips' ? ($('#dbIps').val() || 'localhost') : v);
        }
        $('#dbAccess, #dbIps').on('change input', hostsChanged);

        // passwords
        $('#dbTable').on('click', '[data-pass-toggle], [data-pass-copy], .db-creds', function (e) {
            e.preventDefault();
            var $btn = $(this), $tr = rowOf(this), $pass = $tr.find('.db-pass');
            if ($btn.is('[data-pass-toggle]') && $pass.data('shown')) {
                $pass.text('**********').data('shown', false);
                $btn.find('i').attr('class', 'bi bi-eye');
                return;
            }
            GBX.get(dbUrl($tr.data('id'), 'credentials')).done(function (r) {
                if ($btn.is('[data-pass-copy]')) return GBX.copy(r.password);
                if ($btn.is('.db-creds') || !r.password) {
                    var row = function (k, v) { return '<dt>' + k + '</dt><dd class="font-mono text-break">' + esc(v || '(not stored)') + '</dd>'; };
                    return Swal.fire({ title: 'Connection', html: '<dl class="kv text-start">' + row('Host', r.host + ':' + r.port) + row('Database', r.name) + row('Username', r.username) + row('Password', r.password) + '</dl>', buttonsStyling: false, customClass: { confirmButton: 'btn btn-primary' } });
                }
                $pass.text(r.password).data('shown', true);
                $btn.find('i').attr('class', 'bi bi-eye-slash');
            });
        });

        $('#dbTable').on('click', '.db-password', function (e) {
            e.preventDefault();
            var $tr = rowOf(this);
            GBX.confirm({ title: 'New password for ' + $tr.data('user'), input: 'text', inputValue: GBX.password(20), icon: null, confirmText: 'Change password',
                inputValidator: function (v) { if (!v || v.length < 8) return 'At least 8 characters'; } })
                .then(function (r) { if (r.isConfirmed) GBX.post(dbUrl($tr.data('id'), 'password'), { password: r.value }).done(function (res) { toastr.success(res.message); }); });
        });

        // notes
        $('#dbTable').on('click', '.ws-remark', function (e) {
            e.preventDefault();
            if (!D.canWrite) return;
            var $a = $(this), $tr = rowOf(this);
            Swal.fire({ title: 'Note', input: 'text', inputValue: $a.data('notes') || '', inputAttributes: { maxlength: 255 }, showCancelButton: true, confirmButtonText: 'Save', buttonsStyling: false, reverseButtons: true, customClass: { confirmButton: 'btn btn-primary', cancelButton: 'btn btn-outline-secondary' } })
                .then(function (r) {
                    if (!r.isConfirmed) return;
                    GBX.post(dbUrl($tr.data('id'), 'meta'), { notes: r.value }).done(function () { $a.data('notes', r.value).text(r.value || 'Add note').toggleClass('empty', !r.value); });
                });
        });

        // delete (recycle bin)
        $('#dbTable').on('click', '.db-delete', function (e) {
            e.preventDefault();
            var $tr = rowOf(this), recyclable = +$(this).data('recycle') === 1;
            GBX.confirm({
                title: 'Delete ' + $tr.data('name') + '?', danger: true, confirmText: 'Delete',
                html: 'The database and user <strong>' + esc($tr.data('user')) + '</strong> are removed from the server.' +
                    (recyclable ? '<div class="form-check text-start d-inline-block mt-3"><input class="form-check-input" type="checkbox" id="dbRecycleOpt" checked><label class="form-check-label" for="dbRecycleOpt">Keep a copy in the recycle bin for 7 days</label></div>' : '<p class="text-danger small mt-2 mb-0">This cannot be undone.</p>'),
                preConfirm: function () { return { recycle: recyclable && $('#dbRecycleOpt').is(':checked') ? 1 : 0 }; }
            }).then(function (r) {
                if (!r.isConfirmed) return;
                GBX.del(dbUrl($tr.data('id')), r.value, { onTaskDone: function () { location.reload(); } }).done(function (res) {
                    if (!res.task) { toastr.success(res.message); setTimeout(function () { location.reload(); }, 600); }
                });
            });
        });

        // bulk
        var selected = function () { return $('.db-check:checked').map(function () { return this.value; }).get(); };
        var updateBulk = function () { var n = selected().length; $('#dbSelected').text(n + ' selected'); $('#dbBulkRun').prop('disabled', !n || !$('#dbBulkAction').val()); };
        $(document).on('change', '.db-check, #dbBulkAction', updateBulk);
        $('#dbAll').on('change', function () { $('.db-check').prop('checked', this.checked); updateBulk(); });
        $('#dbBulkRun').on('click', function () {
            var ids = selected(), action = $('#dbBulkAction').val();
            GBX.confirm({ text: (action === 'delete' ? 'Move ' : 'Back up ') + ids.length + ' database(s)' + (action === 'delete' ? ' to the recycle bin?' : '?'), danger: action === 'delete', confirmText: 'Execute' })
                .then(function (r) { if (r.isConfirmed) GBX.post(R.bulk, { ids: ids, action: action }).done(function (res) { toastr.success(res.message); }); });
        });

        // automatic backup switch
        $('#dbAutoBackup').on('change', function () {
            var $c = $(this);
            GBX.post(R.autoBackup, { enabled: this.checked ? 1 : 0 }).done(function (res) { toastr.success(res.message); setTimeout(function () { location.reload(); }, 800); })
                .fail(function () { $c.prop('checked', !$c.prop('checked')); });
        });

        // phpMyAdmin / Adminer access
        $('#dbToolPublic').on('change', function () {
            var $c = $(this);
            GBX.post($('#dbToolModal').length ? D.base + '/tools-access' : '', { public: this.checked ? 1 : 0 }, { onTaskDone: function () { location.reload(); } })
                .fail(function () { $c.prop('checked', !$c.prop('checked')); });
        });

        // MongoDB access control
        $('#dbMongoAuth').on('click', function () {
            var enabled = +$(this).data('enabled') === 1;
            GBX.confirm({
                title: (enabled ? 'Disable' : 'Enable') + ' security authentication?', danger: enabled, confirmText: enabled ? 'Disable' : 'Enable',
                text: enabled ? 'Anyone who can reach MongoDB will be able to read and write every database.' : 'MongoDB will require a user and password. A root user is created if needed; applications must use their database credentials.'
            }).then(function (r) { if (r.isConfirmed) GBX.post(D.base + '/mongodb/auth', { enabled: enabled ? 0 : 1 }).done(function (res) { toastr.success(res.message); setTimeout(function () { location.reload(); }, 800); }); });
        });

        // backups of a database
        function loadBackups() {
            $('#dbBackupsList').html(loading(4));
            GBX.get(dbUrl(current.id, 'backups')).done(function (r) {
                current.extensions = r.extensions;
                if (!r.backups.length) { $('#dbBackupsList').html(empty(4, 'No backups yet')); return; }
                $('#dbBackupsList').html(r.backups.map(function (b) {
                    return '<tr data-file="' + esc(b.name) + '"><td class="font-mono small text-break">' + esc(b.name) + '</td><td class="text-nowrap">' + bytes(b.size) + '</td><td class="text-nowrap">' + dateOf(b.time) + '</td><td class="text-end text-nowrap">' +
                        '<a class="btn btn-sm btn-ghost" href="' + D.base + '/backups/' + D.engine + '/' + encodeURIComponent(b.name) + '">Download</a>' +
                        (D.canWrite ? '<button class="btn btn-sm btn-ghost" data-backup="restore">Restore</button><button class="btn btn-sm btn-ghost text-danger" data-backup="delete">Delete</button>' : '') + '</td></tr>';
                }).join(''));
            });
        }
        $('#dbTable').on('click', '.db-backups', function (e) {
            e.preventDefault();
            var $tr = rowOf(this);
            current = { id: $tr.data('id'), name: $tr.data('name') };
            $('#dbBackupsName').text(current.name);
            bootstrap.Modal.getOrCreateInstance('#dbBackupsModal').show();
            loadBackups();
        });
        $('#dbBackupNow').on('click', function () { GBX.post(dbUrl(current.id, 'backup'), {}, { onTaskDone: loadBackups }); });
        $('#dbBackupsList').on('click', '[data-backup]', function () {
            var file = $(this).closest('tr').data('file');
            if ($(this).data('backup') === 'restore') {
                GBX.confirm({ title: 'Restore ' + current.name + '?', html: 'The data of <strong>' + esc(current.name) + '</strong> is replaced with <code>' + esc(file) + '</code>.', danger: true, confirmText: 'Restore' })
                    .then(function (r) { if (r.isConfirmed) GBX.post(dbUrl(current.id, 'restore'), { file: file }); });
            } else {
                GBX.confirm({ text: 'Delete ' + file + '?', danger: true, confirmText: 'Delete' })
                    .then(function (r) { if (r.isConfirmed) GBX.del(D.base + '/backups/' + D.engine + '/' + encodeURIComponent(file)).done(function (res) { toastr.success(res.message); loadBackups(); }); });
            }
        });

        function importInto(id, name) {
            var exts = { mysql: '.sql,.gz,.zip', pgsql: '.sql,.gz,.dump', mongodb: '.gz,.archive', sqlserver: '.bak' }[D.engine];
            upload('Import into ' + name, exts, 'Accepted: ' + exts.split(',').join(', ') + '. Existing data with the same names is overwritten.').then(function (r) {
                if (!r.isConfirmed) return;
                var fd = new FormData();
                fd.append('file', r.value);
                toastr.info('Uploading...');
                GBX.post(dbUrl(id, 'import'), fd, { timeout: 0 });
            });
        }
        $('#dbTable').on('click', '.db-import', function (e) { e.preventDefault(); var $tr = rowOf(this); importInto($tr.data('id'), $tr.data('name')); });
        $('#dbImportFromBackups').on('click', function () { importInto(current.id, current.name); });

        // recycle bin
        function loadRecycle() {
            $('#dbRecycleList').html(loading(6));
            GBX.get(R.recycle, { engine: D.engine }).done(function (r) {
                if (!r.items.length) { $('#dbRecycleList').html(empty(6, 'The recycle bin is empty')); return; }
                $('#dbRecycleList').html(r.items.map(function (i) {
                    return '<tr data-id="' + i.id + '"><td class="font-mono">' + esc(i.name) + '</td><td class="font-mono small">' + esc(i.username || '') + '</td><td>' + bytes(i.size) + '</td><td class="text-nowrap">' + esc(i.deleted) + '</td><td class="text-nowrap">' + esc(i.expires) + '</td><td class="text-end text-nowrap">' +
                        (D.canWrite ? '<button class="btn btn-sm btn-ghost" data-recycle="restore">Restore</button><button class="btn btn-sm btn-ghost text-danger" data-recycle="delete">Delete permanently</button>' : '') + '</td></tr>';
                }).join(''));
            });
        }
        $('#dbRecycle').on('click', function () { bootstrap.Modal.getOrCreateInstance('#dbRecycleModal').show(); loadRecycle(); });
        $('#dbRecycleList').on('click', '[data-recycle]', function () {
            var id = $(this).closest('tr').data('id');
            if ($(this).data('recycle') === 'restore') {
                GBX.post(D.base + '/recycle/' + id + '/restore', {}, { onTaskDone: function () { location.reload(); } });
            } else {
                GBX.confirm({ text: 'Delete this dump permanently?', danger: true, confirmText: 'Delete' })
                    .then(function (r) { if (r.isConfirmed) GBX.del(D.base + '/recycle/' + id).done(function (res) { toastr.success(res.message); loadRecycle(); }); });
            }
        });

        // permission (MySQL)
        $('#dbTable').on('click', '.db-permission', function (e) {
            e.preventDefault();
            var $tr = rowOf(this), hosts = String($tr.data('hosts') || 'localhost').split(',');
            current = { id: $tr.data('id'), name: $tr.data('name') };
            $('#dbPermissionUser').text($tr.data('user'));
            var access = hosts.indexOf('%') !== -1 ? 'all' : (hosts.every(function (h) { return h === 'localhost' || h === '127.0.0.1'; }) ? 'localhost' : 'ips');
            $('#dbPermissionForm [name=access][value=' + access + ']').prop('checked', true);
            $('#permIpList').val(access === 'ips' ? hosts.join('\n') : '');
            bootstrap.Modal.getOrCreateInstance('#dbPermissionModal').show();
        });
        $('#dbPermissionForm').on('submit', function (e) {
            e.preventDefault();
            GBX.post(dbUrl(current.id, 'permission'), { access: $(this).find('[name=access]:checked').val(), ips: $('#permIpList').val() }).done(function (res) {
                toastr.success(res.message);
                bootstrap.Modal.getOrCreateInstance('#dbPermissionModal').hide();
                setTimeout(function () { location.reload(); }, 900);
            });
        });

        // tools (tables / collections)
        function loadTables() {
            $('#dbTablesList').html(loading(7));
            GBX.get(dbUrl(current.id, 'tables')).done(function (r) {
                if (!r.tables.length) { $('#dbTablesList').html(empty(7, 'No tables')); return; }
                $('#dbTablesList').html(r.tables.map(function (t) {
                    return '<tr><td class="ws-check"><input type="checkbox" class="form-check-input db-table-check" value="' + esc(t.name) + '"></td><td class="font-mono">' + esc(t.name) + '</td><td>' + esc(t.engine) + '</td><td class="text-end font-mono">' + Number(t.rows).toLocaleString() + '</td><td class="text-end font-mono">' + bytes(t.size) + '</td><td class="small">' + esc(t.collation || '') + '</td><td class="small text-nowrap">' + esc(t.updated || '') + '</td></tr>';
                }).join(''));
            });
        }
        $('#dbTable').on('click', '.db-tools', function (e) {
            e.preventDefault();
            var $tr = rowOf(this);
            current = { id: $tr.data('id'), name: $tr.data('name') };
            $('#dbToolsName').text(current.name);
            bootstrap.Modal.getOrCreateInstance('#dbToolsModal').show();
            loadTables();
        });
        $('#dbTablesAll').on('change', function () { $('.db-table-check').prop('checked', this.checked); });
        $('#dbToolsActions').on('click', '[data-table-action]', function () {
            var $b = $(this), tables = $('.db-table-check:checked').map(function () { return this.value; }).get();
            if (!tables.length) tables = $('.db-table-check').map(function () { return this.value; }).get();
            GBX.busy($b, true);
            GBX.post(dbUrl(current.id, 'tables'), { action: $b.data('table-action'), tables: tables }, { timeout: 1900000 })
                .done(function (res) { toastr.success(res.message); loadTables(); })
                .always(function () { GBX.busy($b, false); });
        });
    }

    /* ================================================================ Redis */
    function redisTab() {
        var state = { db: 0, cursor: '0', keys: [], overview: null };
        var server = function () { return $('#rdLocation').val() || ''; };
        var params = function (extra) { return $.extend({ server_id: server(), db: state.db }, extra || {}); };
        var ttlText = function (t) { return t < 0 ? (t === -1 ? 'No expiry' : '-') : (t > 86400 ? Math.floor(t / 86400) + 'd ' : '') + new Date(t * 1000).toISOString().substr(11, 8); };
        if (!$('#rdKeys').length) return;

        function overview() {
            GBX.get(R.redis + '/overview', { server_id: server() }, { silent: true }).done(function (r) {
                var o = r.overview;
                state.overview = o;
                $('#rdError').toggleClass('d-none', o.connected);
                if (!o.connected) {
                    $('#rdErrorText').text(o.error);
                    $('#rdAuthForm').toggleClass('d-none', !(o.auth && !server()));
                    $('#rdKeys').html(empty(6, 'Not connected'));
                    return;
                }
                var hitRate = o.hits + o.misses ? Math.round(o.hits / (o.hits + o.misses) * 1000) / 10 + '%' : '-';
                $('[data-stat=version]').text(o.version || '-');
                $('[data-stat=memory]').text((o.memory || '-') + (o.peak ? ' (peak ' + o.peak + ')' : ''));
                $('[data-stat=clients]').text(o.clients);
                $('[data-stat=uptime]').text(GBX.duration(o.uptime));
                $('[data-stat=hits]').text(hitRate);
                $('[data-stat=ops]').text(o.ops);
                var selectedDb = state.db;
                $('#rdDb').html(Object.keys(o.keyspace).map(function (db) {
                    return '<option value="' + db + '"' + (+db === selectedDb ? ' selected' : '') + '>db' + db + ' (' + o.keyspace[db].keys + ')</option>';
                }).join(''));
                var cfg = o.config || {};
                $('#rdSettingsForm [name=maxmemory]').val(cfg.maxmemory || '0');
                $('#rdSettingsForm [name=maxmemory_policy]').val(cfg['maxmemory-policy'] || 'noeviction');
                $('#rdSettingsForm [name=appendonly]').val(cfg.appendonly || 'no');
                $('#rdSave').val(cfg.save || '');
                keys(true);
            });
        }

        function keys(reset) {
            if (reset) { state.cursor = '0'; state.keys = []; $('#rdKeys').html(loading(6)); }
            GBX.get(R.redis + '/keys', params({ pattern: $('#rdPattern').val() || '*', cursor: state.cursor }), { silent: true }).done(function (r) {
                state.keys = state.keys.concat(r.keys);
                state.cursor = r.cursor;
                render();
            }).fail(function (xhr) { $('#rdKeys').html(empty(6, esc(GBX.errorMessage(xhr)))); });
        }

        function render() {
            $('#rdMore').toggleClass('d-none', state.cursor === '0');
            $('#rdSummary').text(state.keys.length + ' key(s) shown' + (state.cursor !== '0' ? ', more available' : ''));
            if (!state.keys.length) { $('#rdKeys').html(empty(6, 'No keys match')); return; }
            $('#rdKeys').html(state.keys.map(function (k) {
                return '<tr data-key="' + esc(k.key) + '"><td class="ws-check"><input type="checkbox" class="form-check-input rd-check"></td><td class="font-mono text-break">' + esc(k.key) + '</td><td><span class="badge badge-soft">' + esc(k.type) + '</span></td><td class="small text-nowrap">' + ttlText(k.ttl) + '</td><td class="text-end font-mono small">' + (k.size !== null ? bytes(k.size) : '-') + '</td>' +
                    '<td class="text-end text-nowrap db-ops"><a href="#" data-rd="view">View</a>' + (D.canWrite ? '<a href="#" data-rd="ttl">TTL</a><a href="#" data-rd="delete" class="text-danger">Delete</a>' : '') + '</td></tr>';
            }).join(''));
            updateSelection();
        }

        function updateSelection() { $('#rdDeleteSelected').prop('disabled', !$('.rd-check:checked').length); }

        $('#rdLocation').on('change', function () { state.db = 0; $('#rdBackups').prop('hidden', !!server()); overview(); });
        $('#rdDb').on('change', function () { state.db = +this.value; keys(true); });
        $('#rdRefresh').on('click', overview);
        $('#rdMore').on('click', function () { keys(false); });
        var t = null;
        $('#rdPattern').on('input', function () { clearTimeout(t); t = setTimeout(function () { keys(true); }, 400); });
        $('#rdAll').on('change', function () { $('.rd-check').prop('checked', this.checked); updateSelection(); });
        $('#rdKeys').on('change', '.rd-check', updateSelection);

        $('#rdAuthForm').on('submit', function (e) {
            e.preventDefault();
            GBX.post(R.redis + '/connect', { password: $(this).find('[name=password]').val() }).done(function (res) { toastr.success(res.message); overview(); });
        });

        function openKey(item) {
            var $f = $('#rdKeyForm')[0];
            $f.reset();
            $('#rdKeyTitle').text(item ? item.key : 'Add key');
            $('#rdKeyForm [name=key]').val(item ? item.key : '').prop('readonly', !!item);
            $('#rdKeyForm [name=type]').val(item ? item.type : 'string').trigger('change');
            $('#rdKeyForm [name=ttl]').val(item && item.ttl > 0 ? item.ttl : '');
            var value = '';
            if (item) {
                if (item.type === 'hash') value = Object.keys(item.value).map(function (k) { return k + '=' + item.value[k]; }).join('\n');
                else if (item.type === 'zset') value = Object.keys(item.value).map(function (k) { return item.value[k] + ' ' + k; }).join('\n');
                else if (Array.isArray(item.value)) value = item.value.join('\n');
                else value = item.value === null ? '' : String(item.value);
                if (item.type === 'string') { try { value = JSON.stringify(JSON.parse(value), null, 2); } catch (e) { /* not JSON */ } }
            }
            $('#rdKeyForm [name=value]').val(value);
            $('#rdKeyMeta').text(item ? item.type + ', ' + (item.length !== null ? item.length + (item.type === 'string' ? ' bytes' : ' items') : '') + (item.length > 500 && item.type !== 'string' ? ' (first 500 shown, saving replaces the key with them)' : '') : '');
            bootstrap.Modal.getOrCreateInstance('#rdKeyModal').show();
        }
        $('#rdKeyForm [name=type]').on('change', function () {
            $('#rdValueHint').text({ string: 'text or JSON', hash: 'one field=value per line', list: 'one item per line', set: 'one member per line', zset: 'one "score member" per line' }[this.value]);
        });
        $('#rdAdd').on('click', function () { openKey(null); });
        $('#rdKeyForm').on('submit', function (e) {
            e.preventDefault();
            var data = params({ key: this.key.value, type: this.type.value, value: this.value.value, ttl: this.ttl.value || 0 });
            GBX.post(R.redis + '/key', data).done(function (res) { toastr.success(res.message); bootstrap.Modal.getOrCreateInstance('#rdKeyModal').hide(); overview(); });
        });

        $('#rdKeys').on('click', '[data-rd]', function (e) {
            e.preventDefault();
            var key = $(this).closest('tr').data('key') + '', act = $(this).data('rd');
            if (act === 'view') {
                GBX.get(R.redis + '/key', params({ key: key })).done(function (r) { openKey(r.item); });
            } else if (act === 'ttl') {
                GBX.prompt('TTL for ' + key + ' (seconds, 0 removes it)', '3600').then(function (r) {
                    if (r.isConfirmed) GBX.post(R.redis + '/expire', params({ key: key, ttl: parseInt(r.value, 10) || 0 })).done(function (res) { toastr.success(res.message); keys(true); });
                });
            } else {
                GBX.confirm({ text: 'Delete key ' + key + '?', danger: true, confirmText: 'Delete' })
                    .then(function (r) { if (r.isConfirmed) GBX.post(R.redis + '/delete', params({ keys: [key] })).done(function (res) { toastr.success(res.message); overview(); }); });
            }
        });
        $('#rdDeleteSelected').on('click', function () {
            var list = $('.rd-check:checked').map(function () { return $(this).closest('tr').data('key') + ''; }).get();
            GBX.confirm({ text: 'Delete ' + list.length + ' key(s)?', danger: true, confirmText: 'Delete' })
                .then(function (r) { if (r.isConfirmed) GBX.post(R.redis + '/delete', params({ keys: list })).done(function (res) { toastr.success(res.message); overview(); }); });
        });
        $('#rdFlush').on('click', function () {
            GBX.confirm({ title: 'Flush db' + state.db + '?', text: 'Every key in db' + state.db + ' is deleted. This cannot be undone.', danger: true, confirmText: 'Flush' })
                .then(function (r) { if (r.isConfirmed) GBX.post(R.redis + '/flush', params()).done(function (res) { toastr.success(res.message); overview(); }); });
        });

        $('#rdChangePass').on('change', function () { $('#rdPassGroup').toggleClass('d-none', !this.checked); });
        $('#rdSettingsForm').on('submit', function (e) {
            e.preventDefault();
            var data = GBX.serialize($(this));
            data.server_id = server();
            GBX.post(R.redis + '/config', data).done(function (res) { toastr.success(res.message); bootstrap.Modal.getOrCreateInstance('#rdSettingsModal').hide(); overview(); });
        });

        function loadBackups() {
            $('#rdBackupList').html(loading(4));
            GBX.get(R.redis + '/backups').done(function (r) {
                if (!r.backups.length) { $('#rdBackupList').html(empty(4, 'No backups yet')); return; }
                $('#rdBackupList').html(r.backups.map(function (b) {
                    return '<tr data-file="' + esc(b.name) + '"><td class="font-mono small">' + esc(b.name) + '</td><td>' + bytes(b.size) + '</td><td class="text-nowrap">' + dateOf(b.time) + '</td><td class="text-end text-nowrap">' +
                        '<a class="btn btn-sm btn-ghost" href="' + R.redis + '/backups/' + encodeURIComponent(b.name) + '">Download</a><button class="btn btn-sm btn-ghost" data-rb="restore">Restore</button><button class="btn btn-sm btn-ghost text-danger" data-rb="delete">Delete</button></td></tr>';
                }).join(''));
            });
        }
        $('#rdBackups').on('click', function () { bootstrap.Modal.getOrCreateInstance('#rdBackupsModal').show(); loadBackups(); });
        $('#rdBackupNow').on('click', function () { GBX.post(R.redis + '/backups', {}, { onTaskDone: loadBackups }); });
        $('#rdBackupList').on('click', '[data-rb]', function () {
            var file = $(this).closest('tr').data('file');
            if ($(this).data('rb') === 'restore') {
                GBX.confirm({ title: 'Restore Redis?', text: 'Redis is stopped and every key is replaced with ' + file + '.', danger: true, confirmText: 'Restore' })
                    .then(function (r) { if (r.isConfirmed) GBX.post(R.redis + '/restore', { file: file }, { onTaskDone: overview }); });
            } else {
                GBX.confirm({ text: 'Delete ' + file + '?', danger: true, confirmText: 'Delete' })
                    .then(function (r) { if (r.isConfirmed) GBX.del(R.redis + '/backups/' + encodeURIComponent(file)).done(function (res) { toastr.success(res.message); loadBackups(); }); });
            }
        });

        overview();
    }

    /* ================================================================ Qdrant */
    function qdrantTab() {
        var state = { collections: [], current: null, offset: null };
        var server = function () { return $('#qdLocation').val() || ''; };
        var col = function (name, path) { return R.qdrant + '/collections/' + encodeURIComponent(name) + (path ? '/' + path : ''); };
        if (!$('#qdCollections').length) return;

        function load() {
            $('#qdCollections').html(loading(7));
            GBX.get(R.qdrant + '/overview', { server_id: server() }, { silent: true }).done(function (r) {
                var o = r.overview;
                $('#qdError').toggleClass('d-none', o.connected);
                $('#qdErrorText').text(o.error || '');
                $('#qdVersion').text(o.version || '-');
                state.collections = r.collections;
                $('#qdCount').text(o.connected ? r.collections.length : '-');
                $('#qdPoints').text(o.connected ? r.collections.reduce(function (s, c) { return s + c.points; }, 0).toLocaleString() : '-');
                $('#qdAccess').closest('div').toggle(!server());
                render();
            }).fail(function (xhr) { $('#qdCollections').html(empty(7, esc(GBX.errorMessage(xhr)))); });
        }

        function render() {
            var q = ($('#qdSearch').val() || '').toLowerCase();
            var rows = state.collections.filter(function (c) { return !q || c.name.toLowerCase().indexOf(q) !== -1; });
            if (!rows.length) { $('#qdCollections').html(empty(7, state.collections.length ? 'No collections match' : 'No collections yet')); return; }
            $('#qdCollections').html(rows.map(function (c) {
                var badge = { green: 'badge-success', yellow: 'badge-warning', red: 'badge-danger' }[c.status] || 'badge-soft';
                return '<tr data-name="' + esc(c.name) + '"><td class="cell-strong font-mono">' + esc(c.name) + '</td><td><span class="badge ' + badge + '">' + esc(c.status) + '</span></td><td class="text-end font-mono">' + c.points.toLocaleString() + '</td><td class="font-mono small">' + esc(c.vectors) + '</td><td class="text-end">' + c.segments + '</td><td>' + (c.on_disk ? 'On disk' : 'Memory') + '</td>' +
                    '<td class="text-end text-nowrap db-ops"><a href="#" data-qd="points">Browse</a><a href="#" data-qd="info">Info</a><a href="#" data-qd="snapshots">Snapshots</a>' + (D.canWrite ? '<a href="#" data-qd="delete" class="text-danger">Delete</a>' : '') + '</td></tr>';
            }).join(''));
        }

        $('#qdLocation').on('change', load);
        $('#qdRefresh').on('click', load);
        $('#qdSearch').on('input', render);

        $('#qdPreset').on('change', function () {
            if (!this.value) return;
            var p = this.value.split('|');
            $('#qdCreateForm [name=size]').val(p[0]);
            $('#qdCreateForm [name=distance]').val(p[1]);
        });
        $('#qdCreateForm').on('submit', function (e) {
            e.preventDefault();
            var data = GBX.serialize($(this));
            data.server_id = server();
            GBX.post(R.qdrant + '/collections', data).done(function (res) { toastr.success(res.message); bootstrap.Modal.getOrCreateInstance('#qdCreateModal').hide(); load(); });
        });

        function points(reset) {
            if (reset) { state.offset = null; $('#qdPointsList').html(loading(2)); }
            GBX.get(col(state.current, 'points'), { server_id: server(), limit: 20, offset: state.offset === null ? '' : state.offset }).done(function (r) {
                var html = r.points.map(function (p) { return '<tr><td class="font-mono small text-break">' + esc(String(p.id)) + '</td><td><pre class="gbx-console small mb-0 py-1 px-2">' + esc(JSON.stringify(p.payload || {}, null, 2)) + '</pre></td></tr>'; }).join('');
                reset ? $('#qdPointsList').html(html || empty(2, 'The collection has no points')) : $('#qdPointsList').append(html);
                state.offset = r.next;
                $('#qdPointsMore').toggleClass('d-none', r.next === null || r.next === undefined);
            });
        }

        function snapshots() {
            $('#qdSnapList').html(loading(4));
            GBX.get(col(state.current, 'snapshots'), { server_id: server() }).done(function (r) {
                if (!r.snapshots.length) { $('#qdSnapList').html(empty(4, 'No snapshots yet')); return; }
                $('#qdSnapList').html(r.snapshots.map(function (s) {
                    return '<tr data-snap="' + esc(s.name) + '"><td class="font-mono small text-break">' + esc(s.name) + '</td><td>' + bytes(s.size) + '</td><td class="text-nowrap small">' + esc((s.creation_time || '').replace('T', ' ').substr(0, 19)) + '</td><td class="text-end text-nowrap">' +
                        '<a class="btn btn-sm btn-ghost" href="' + col(state.current, 'snapshots/' + encodeURIComponent(s.name)) + '?server_id=' + server() + '">Download</a>' + (D.canWrite ? '<button class="btn btn-sm btn-ghost text-danger" data-snap-delete>Delete</button>' : '') + '</td></tr>';
                }).join(''));
            });
        }

        $('#qdCollections').on('click', '[data-qd]', function (e) {
            e.preventDefault();
            var name = $(this).closest('tr').data('name') + '', act = $(this).data('qd');
            state.current = name;
            if (act === 'info') {
                $('#qdInfoTitle').text(name);
                $('#qdInfo').text('Loading...');
                bootstrap.Modal.getOrCreateInstance('#qdInfoModal').show();
                GBX.get(col(name), { server_id: server() }).done(function (r) { $('#qdInfo').text(JSON.stringify(r.info, null, 2)); });
            } else if (act === 'points') {
                $('#qdPointsTitle').text(name);
                bootstrap.Modal.getOrCreateInstance('#qdPointsModal').show();
                points(true);
            } else if (act === 'snapshots') {
                $('#qdSnapTitle').text(name);
                bootstrap.Modal.getOrCreateInstance('#qdSnapshotsModal').show();
                snapshots();
            } else {
                GBX.confirm({ title: 'Delete collection ' + name + '?', text: 'All points and indexes are deleted. Create a snapshot first if you may need them.', danger: true, confirmText: 'Delete collection' }).then(function (r) {
                    if (r.isConfirmed) GBX.del(col(name), { server_id: server() }).done(function (res) { toastr.success(res.message); load(); });
                });
            }
        });
        $('#qdPointsMore').on('click', function () { points(false); });

        $('#qdSnapCreate').on('click', function () {
            var $b = $(this);
            GBX.busy($b, true);
            GBX.post(col(state.current, 'snapshots'), { server_id: server() }, { timeout: 1900000 }).done(function (res) { toastr.success(res.message); snapshots(); }).always(function () { GBX.busy($b, false); });
        });
        $('#qdSnapList').on('click', '[data-snap-delete]', function () {
            var snap = $(this).closest('tr').data('snap');
            GBX.confirm({ text: 'Delete snapshot ' + snap + '?', danger: true, confirmText: 'Delete' })
                .then(function (r) { if (r.isConfirmed) GBX.del(col(state.current, 'snapshots/' + encodeURIComponent(snap)), { server_id: server() }).done(function (res) { toastr.success(res.message); snapshots(); }); });
        });
        $('#qdSnapRestore').on('click', function () {
            upload('Restore ' + state.current, '.snapshot', 'The collection data is replaced with the snapshot.').then(function (r) {
                if (!r.isConfirmed) return;
                var fd = new FormData();
                fd.append('snapshot', r.value);
                fd.append('server_id', server());
                toastr.info('Uploading snapshot...');
                GBX.post(col(state.current, 'restore'), fd, { timeout: 0 }).done(function (res) { toastr.success(res.message); load(); });
            });
        });

        $('#qdRegenerate').on('click', function () {
            GBX.confirm({ title: 'Regenerate the API key?', text: 'Qdrant restarts with a new key. Applications using the current key stop working until you update them.', danger: true, confirmText: 'Regenerate' }).then(function (r) {
                if (r.isConfirmed) GBX.post(R.qdrant + '/settings', { action: 'regenerate' }).done(function (res) { toastr.success(res.message); $('#qdApiKey').val(res.api_key); });
            });
        });
        $('#qdPublic').on('change', function () {
            var $c = $(this), on = this.checked;
            GBX.confirm({ title: on ? 'Enable public access?' : 'Disable public access?', text: on ? 'Qdrant will listen on all interfaces. Anyone with the API key can reach it from the internet.' : 'Only applications on this server will reach Qdrant.', danger: on, confirmText: on ? 'Enable' : 'Disable' }).then(function (r) {
                if (!r.isConfirmed) { $c.prop('checked', !on); return; }
                GBX.post(R.qdrant + '/settings', { action: 'public', public: on ? 1 : 0 }).done(function (res) { toastr.success(res.message); $('#qdAccess').text(on ? 'Public :6333' : '127.0.0.1:6333'); })
                    .fail(function () { $c.prop('checked', !on); });
            });
        });

        load();
    }
})(jQuery);
