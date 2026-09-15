/*
 * GBX Panel - Backup: transfers, local backups and remote storages (rclone).
 */
(function ($) {
    'use strict';

    var B = window.GBX_BACKUP, esc = GBX.escape;
    var url = function (path) { return B.base + '/' + path; };
    var typeOf = function (key) { return B.types.find(function (t) { return t.key === key; }) || { fields: {}, notes: [] }; };
    var modal = function (id) { return bootstrap.Modal.getOrCreateInstance(document.getElementById(id)); };
    var logo = function (t) { return '<span class="dns-logo sm" style="background:' + esc(t.color || '#444') + '"><i class="bi ' + esc(t.icon || 'bi-cloud') + '"></i></span>'; };

    var STATUS = {
        queued: ['badge-soft', 'bi-hourglass-split', 'Queued'],
        running: ['badge-warning', 'bi-arrow-repeat spin', 'Running'],
        success: ['badge-success', 'bi-check2', 'Finished'],
        failed: ['badge-danger', 'bi-x-circle', 'Failed'],
        canceled: ['badge-soft', 'bi-slash-circle', 'Canceled']
    };

    /* ============================================================= install */
    $('#bkInstall, #bkInstall2').on('click', function (e) {
        e.preventDefault();
        GBX.post(url('install')).done(function (r) { toastr.success(r.message); });
    });

    /* =========================================================== transfers */
    function transfersTab() {
        var timer = null;

        var row = function (t) {
            var s = STATUS[t.status] || STATUS.queued;
            var detail = t.status === 'failed' ? '<div class="cell-sub bk-error">' + esc(t.message || '') + '</div>'
                : (t.progress ? '<div class="cell-sub">' + esc(t.progress) + '</div>' : '');
            return '<tr data-id="' + t.id + '" data-status="' + t.status + '">' +
                '<td><span class="badge ' + s[0] + '"><i class="bi ' + s[1] + '"></i> ' + s[2] + '</span></td>' +
                '<td class="bk-file"><div class="cell-strong font-mono text-break">' + esc(t.file) + '</div><div class="cell-sub"><i class="bi ' + (t.direction === 'upload' ? 'bi-arrow-up' : 'bi-arrow-down') + '"></i> ' + esc(t.category) + (t.label ? ' / ' + esc(t.label) : '') + (t.parts ? ' &middot; ' + t.parts + ' parts' : '') + '</div></td>' +
                '<td>' + esc(t.storage || '-') + '</td>' +
                '<td class="text-nowrap">' + esc(t.size) + '</td>' +
                '<td class="bk-progress">' + (t.status === 'running' ? '<span class="cell-sub">' + esc(t.progress || 'Starting') + '</span>' : detail) + '</td>' +
                '<td class="text-nowrap cell-sub">' + esc(t.finished_at || t.started_at || t.created_at || '-') + '</td>' +
                '<td class="text-end text-nowrap db-ops">' +
                    '<a href="#" data-log>Log</a>' +
                    (t.status === 'running' || t.status === 'queued' ? '<a href="#" class="text-danger" data-cancel>Cancel</a>' : '<a href="#" data-retry>Retry</a><a href="#" class="text-danger" data-remove>Remove</a>') +
                '</td></tr>';
        };

        var load = function () {
            GBX.get(url('transfers'), { status: $('#bkStatus').val() || undefined }, { silent: true }).done(function (r) {
                $('#bkTransfers').html(r.data.length ? r.data.map(row).join('')
                    : '<tr><td colspan="7" class="sm-empty"><i class="bi bi-inbox"></i> No transfers yet. Choose a storage in a scheduled backup, or send a local backup from the Local backups tab.</td></tr>');
                clearTimeout(timer);
                if (r.active) timer = setTimeout(load, 5000);
            });
        };

        $('#bkRefresh').on('click', load);
        $('#bkStatus').on('change', load);
        $('#bkTransfers').on('click', '[data-log]', function (e) {
            e.preventDefault();
            var id = $(this).closest('tr').data('id');
            $('#bkLogTitle').text('#' + id);
            $('#bkLog').text('Loading...');
            modal('bkLogModal').show();
            GBX.get(url('transfers/' + id + '/log')).done(function (r) {
                var $log = $('#bkLog').text(r.log);
                $log.scrollTop($log[0].scrollHeight);
            });
        }).on('click', '[data-retry]', function (e) {
            e.preventDefault();
            GBX.post(url('transfers/' + $(this).closest('tr').data('id') + '/retry')).done(function (r) { toastr.success(r.message); load(); });
        }).on('click', '[data-cancel]', function (e) {
            e.preventDefault();
            var id = $(this).closest('tr').data('id');
            GBX.confirm({ text: 'Stop this transfer? The part already sent stays at the destination and a retry continues from there.', danger: true, confirmText: 'Cancel transfer' }).then(function (r) {
                if (r.isConfirmed) GBX.post(url('transfers/' + id + '/cancel')).done(function (res) { toastr.success(res.message); load(); });
            });
        }).on('click', '[data-remove]', function (e) {
            e.preventDefault();
            GBX.del(url('transfers/' + $(this).closest('tr').data('id'))).done(function (r) { toastr.success(r.message); load(); });
        });

        load();
    }

    /* ======================================================== local backups */
    function localTab() {
        var load = function () {
            GBX.get(url('local'), { type: $('#bkLocalType').val() || undefined }).done(function (r) {
                $('#bkLocal').html(r.data.length ? r.data.map(function (b) {
                    return '<tr data-file="' + esc(b.file) + '">' +
                        '<td class="font-mono small text-break">' + esc(b.file) + '</td>' +
                        '<td>' + esc(b.type) + '</td><td class="text-nowrap">' + esc(b.size) + '</td><td class="text-nowrap cell-sub">' + esc(b.date) + '</td>' +
                        '<td class="text-end text-nowrap db-ops">' + (B.hasStorages ? '<a href="#" data-send>Send to storage</a>' : '<span class="cell-sub">Add a storage first</span>') + '</td></tr>';
                }).join('') : '<tr><td colspan="5" class="sm-empty"><i class="bi bi-inbox"></i> No backups on this server yet</td></tr>');
            });
        };
        $('#bkLocalType').on('change', load);
        $('#bkLocal').on('click', '[data-send]', function (e) {
            e.preventDefault();
            var file = $(this).closest('tr').data('file');
            $('#bkUploadFile').text(file);
            $('#bkUploadForm').data('file', file);
            modal('bkUploadModal').show();
        });
        $('#bkUploadForm').on('submit', function (e) {
            e.preventDefault();
            var $form = $(this), payload = GBX.serialize($form);
            payload.file = $form.data('file');
            GBX.post(url('upload'), payload).done(function (r) {
                toastr.success(r.message);
                modal('bkUploadModal').hide();
            });
        });
        load();
    }

    /* ============================================================ storages */
    function storageTab() {
        var data = [], editing = null;
        var $form = $('#bkStorageForm');

        var load = function () {
            GBX.get(url('storages')).done(function (r) {
                data = r.data;
                $('#bkStorages').html(data.length ? data.map(function (s) {
                    var t = typeOf(s.type);
                    var check = s.last_error
                        ? '<span class="badge badge-danger"><i class="bi bi-x-circle"></i> Error</span><div class="cell-sub dns-error" title="' + esc(s.last_error) + '">' + esc(s.last_error) + '</div>'
                        : (s.checked_at ? '<span class="badge badge-success"><i class="bi bi-check2"></i> OK</span><div class="cell-sub">' + esc(s.checked_at) + '</div>' : '<span class="cell-sub">-</span>');
                    return '<tr data-id="' + s.id + '">' +
                        '<td><div class="form-check form-switch m-0"><input class="form-check-input bk-toggle" type="checkbox"' + (s.is_active ? ' checked' : '') + '></div></td>' +
                        '<td class="text-nowrap">' + logo(t) + ' <span class="cell-strong">' + esc(s.name) + '</span><div class="cell-sub">' + esc(s.type_name) + '</div></td>' +
                        '<td class="font-mono small text-break">' + esc(s.root) + '</td>' +
                        '<td class="text-nowrap">' + (s.used ? esc(s.used) + (s.total ? ' / ' + esc(s.total) : '') : '<span class="cell-sub">-</span>') + '</td>' +
                        '<td>' + check + '</td>' +
                        '<td class="text-end text-nowrap db-ops"><a href="#" data-browse>Browse</a><a href="#" data-test>Test</a><a href="#" data-usage>Usage</a><a href="#" data-edit>Edit</a><a href="#" class="text-danger" data-delete>Delete</a></td></tr>';
                }).join('') : '<tr><td colspan="6" class="sm-empty"><i class="bi bi-cloud-slash"></i> No storage yet. Add object storage, Google Drive, FTP, SFTP or WebDAV to keep backups off this server.</td></tr>');
            });
        };

        var field = function (key, f, value, stored) {
            var name = 'credentials[' + key + ']', input;
            if (f.type === 'checkbox') {
                input = '<div class="form-check form-switch m-0 pt-2"><input class="form-check-input" type="checkbox" name="' + name + '" value="1"' + (value ? ' checked' : '') + '>' +
                    (f.help ? '<label class="form-check-label cell-sub">' + esc(f.help) + '</label>' : '') + '</div>';
            } else if (f.type === 'select') {
                input = '<select name="' + name + '" class="form-select">' + Object.keys(f.options).map(function (k) {
                    return '<option value="' + esc(k) + '"' + (String(value) === k ? ' selected' : '') + '>' + esc(f.options[k]) + '</option>';
                }).join('') + '</select>';
            } else if (f.type === 'secret_text' || f.type === 'token') {
                input = '<textarea name="' + name + '" class="form-control font-mono" rows="' + (f.type === 'token' ? 3 : 4) + '" spellcheck="false" placeholder="' + esc(stored ? 'Stored - leave empty to keep it' : (f.placeholder || '')) + '"></textarea>';
            } else {
                var ph = f.type === 'password' && stored ? 'Stored - leave empty to keep it' : (f.placeholder || '');
                input = '<input type="' + (f.type === 'password' ? 'password' : 'text') + '" name="' + name + '" class="form-control' + (f.type === 'password' ? ' font-mono' : '') + '" value="' + esc(f.type === 'password' ? '' : (value || '')) + '" placeholder="' + esc(ph) + '" autocomplete="off">';
            }
            return '<div class="cron-row"><label>' + esc(f.label) + '</label><div>' + input + '</div></div>';
        };

        var authorizeCommand = function () {
            var t = typeOf($form.find('[name=type]').val());
            if (!t.authorize) return '';
            var id = ($form.find('[name="credentials[client_id]"]').val() || '').trim();
            var secret = ($form.find('[name="credentials[client_secret]"]').val() || '').trim();
            return 'rclone authorize "' + t.authorize + '"' + (id ? ' "' + id + '" "' + secret + '"' : '');
        };

        var renderFields = function (type, values, stored) {
            var t = typeOf(type);
            values = values || {};
            stored = stored || [];
            $('#bkFields').html(Object.keys(t.fields).map(function (key) {
                return field(key, t.fields[key], values[key], stored.indexOf(key) !== -1);
            }).join(''));
            $('#bkFolderHint').text(t.bucket
                ? 'Folder inside the bucket. Backups are stored under it in site/, database/ and path/ subfolders.'
                : (t.absolute ? 'Folder at the destination; a path that starts with / is absolute.' : 'Folder at the destination. Backups are stored under it in site/, database/ and path/ subfolders.'));
            $('#bkOauth').prop('hidden', !t.authorize);
            $('#bkGoogleConnect').prop('hidden', t.oauth !== 'google');
            $('#bkRedirect').prop('hidden', t.oauth !== 'google');
            $('#bkOauthHint').text(t.oauth === 'google' ? 'Fill in Client ID and Client Secret first.' : '');
            $('#bkAuthorize').text(authorizeCommand());
            $('#bkNotes').html(t.notes.map(function (n) { return '<li>' + esc(n) + '</li>'; }).join('') +
                '<li class="dns-docs"><a href="' + esc(t.docs) + '" target="_blank" rel="noopener noreferrer">Documentation of this destination</a></li>');
        };

        var open = function (s, type) {
            editing = s ? s.id : null;
            $form[0].reset();
            $('#bkStorageTitle').text(s ? 'Edit storage' : 'Add storage');
            $form.find('[name=type]').val(s ? s.type : (type || 'aws')).prop('disabled', !!s);
            $form.find('[name=name]').val(s ? s.name : '');
            $form.find('[name=folder]').val(s ? s.folder || '' : '');
            $form.find('[name=bwlimit]').val(s ? s.bwlimit || '' : '');
            $form.find('[name=is_active]').prop('checked', s ? s.is_active : true);
            renderFields(s ? s.type : (type || 'aws'), s ? s.credentials : {}, s ? s.stored : []);
            modal('bkStorageModal').show();
        };

        $form.find('[name=type]').on('change', function () { renderFields(this.value); });
        $form.on('input', '[name="credentials[client_id]"], [name="credentials[client_secret]"]', function () { $('#bkAuthorize').text(authorizeCommand()); });
        $('#bkAuthorizeCopy').on('click', function (e) { e.preventDefault(); GBX.copy($('#bkAuthorize').text()); });

        var payloadOf = function () {
            var payload = GBX.serialize($form);
            payload.credentials = {};
            $form.find('[name^="credentials["]').each(function () {
                var key = this.name.slice(12, -1);
                payload.credentials[key] = this.type === 'checkbox' ? (this.checked ? '1' : '') : this.value;
                delete payload[this.name];
            });
            if (!payload.type) payload.type = $form.find('[name=type]').val();
            return payload;
        };

        $form.on('submit', function (e) {
            e.preventDefault();
            var $btn = $form.find('[type=submit]'), payload = payloadOf();
            if (editing) payload._method = 'PUT';
            GBX.busy($btn.data('busy', 'Testing'), true);
            GBX.post(url('storages' + (editing ? '/' + editing : '')), payload, { silent: true }).done(function (r) {
                toastr.success(r.message);
                modal('bkStorageModal').hide();
                load();
            }).fail(function (xhr) { toastr.error(GBX.errorMessage(xhr), '', { timeOut: 15000 }); }).always(function () { GBX.busy($btn, false); });
        });

        // Google: the panel asks for the authorization itself and comes back on the callback route
        $('#bkGoogleConnect').on('click', function () {
            var payload = payloadOf(), c = payload.credentials || {};
            GBX.post(url('google/start'), {
                storage_id: editing, name: payload.name, folder: payload.folder, bwlimit: payload.bwlimit,
                client_id: c.client_id, client_secret: c.client_secret, scope: c.scope, root_folder_id: c.root_folder_id, team_drive: c.team_drive
            }, { silent: true }).done(function (r) {
                window.location = r.url;
            }).fail(function (xhr) { toastr.error(GBX.errorMessage(xhr), '', { timeOut: 15000 }); });
        });

        $('#bkAddStorage').on('click', function () { open(null); });
        $('.bk-type').on('click', function () { open(null, $(this).data('type')); });

        var rowData = function (el) { var id = $(el).closest('tr').data('id'); return data.find(function (s) { return s.id === id; }); };
        $('#bkStorages').on('click', '[data-edit]', function (e) { e.preventDefault(); open(rowData(this)); })
            .on('change', '.bk-toggle', function () {
                GBX.post(url('storages/' + rowData(this).id + '/toggle')).done(function (r) { toastr.success(r.message); load(); });
            })
            .on('click', '[data-test], [data-usage]', function (e) {
                e.preventDefault();
                var $a = $(this), s = rowData(this), action = $a.is('[data-test]') ? 'test' : 'usage', text = $a.text();
                $a.text(action === 'test' ? 'Testing...' : 'Reading...');
                GBX.post(url('storages/' + s.id + '/' + action), {}, { silent: true }).done(function (r) { toastr.success(r.message); })
                    .fail(function (xhr) { toastr.error(GBX.errorMessage(xhr), '', { timeOut: 15000 }); })
                    .always(function () { $a.text(text); load(); });
            })
            .on('click', '[data-delete]', function (e) {
                e.preventDefault();
                var s = rowData(this);
                GBX.confirm({ title: 'Remove storage', text: 'Remove ' + s.name + ' from the panel? The backups stored in it are not deleted.', danger: true, confirmText: 'Remove' }).then(function (r) {
                    if (r.isConfirmed) GBX.del(url('storages/' + s.id)).done(function (res) { toastr.success(res.message); load(); });
                });
            })
            .on('click', '[data-browse]', function (e) { e.preventDefault(); browse(rowData(this), ''); });

        /* ------------------------------------------------------- browsing */
        var current = null;
        var browse = function (storage, path) {
            current = storage;
            $('#bkBrowseTitle').text(storage.name);
            $('#bkBrowseList').html('<tr><td colspan="4" class="sm-empty"><i class="bi bi-arrow-repeat spin"></i> Loading</td></tr>');
            modal('bkBrowseModal').show();
            GBX.get(url('storages/' + storage.id + '/browse'), { path: path || '' }).done(function (r) {
                var crumbs = '<a href="#" data-path=""><i class="bi bi-cloud"></i> ' + esc(r.root) + '</a>', acc = '';
                (r.path ? r.path.split('/') : []).forEach(function (part) {
                    acc += (acc ? '/' : '') + part;
                    crumbs += '<span class="sep"><i class="bi bi-chevron-right"></i></span><a href="#" data-path="' + esc(acc) + '">' + esc(part) + '</a>';
                });
                $('#bkCrumbs').html(crumbs);
                $('#bkBrowseList').html(r.items.length ? r.items.map(function (i) {
                    var isBackup = !i.dir || i.parts;
                    var ops = isBackup
                        ? '<a href="' + url('storages/' + storage.id + '/download') + '?path=' + encodeURIComponent(i.path) + '">Download</a>' +
                          '<a href="#" data-fetch>Download to server</a>' +
                          (i.restore ? '<a href="#" data-restore>Restore ' + esc(i.restore.label) + '</a>' : '') +
                          '<a href="#" class="text-danger" data-remove>Delete</a>'
                        : '<a href="#" data-open>Open</a>';
                    return '<tr data-path="' + esc(i.path) + '">' +
                        '<td><i class="bi ' + (i.parts ? 'bi-collection' : (i.dir ? 'bi-folder-fill' : 'bi-file-earmark-zip')) + '"></i> ' +
                        (i.dir && !i.parts ? '<a href="#" data-open>' + esc(i.name) + '</a>' : '<span class="font-mono small">' + esc(i.name) + '</span>') +
                        (i.parts ? '<div class="cell-sub">sent in parts</div>' : '') + '</td>' +
                        '<td class="text-nowrap">' + esc(i.size_text) + '</td><td class="text-nowrap cell-sub">' + esc(i.date) + '</td>' +
                        '<td class="text-end text-nowrap db-ops">' + ops + '</td></tr>';
                }).join('') : '<tr><td colspan="4" class="sm-empty"><i class="bi bi-inbox"></i> Nothing here yet</td></tr>');
            });
        };

        $('#bkCrumbs').on('click', 'a', function (e) { e.preventDefault(); browse(current, $(this).data('path') + ''); });
        $('#bkBrowseList').on('click', '[data-open]', function (e) { e.preventDefault(); browse(current, $(this).closest('tr').data('path') + ''); })
            .on('click', '[data-fetch], [data-restore]', function (e) {
                e.preventDefault();
                var path = $(this).closest('tr').data('path') + '', restore = $(this).is('[data-restore]');
                var go = function () {
                    GBX.post(url('storages/' + current.id + '/fetch'), { path: path, restore: restore ? 1 : 0 }).done(function (r) {
                        toastr.success(r.message);
                        modal('bkBrowseModal').hide();
                        if (B.tab === 'transfers') $('#bkRefresh').trigger('click');
                    });
                };
                if (!restore) return go();
                GBX.confirm({ title: 'Restore from the storage', text: 'The backup is downloaded to this server and then restored over the current data. Continue?', danger: true, confirmText: 'Download and restore' })
                    .then(function (r) { if (r.isConfirmed) go(); });
            })
            .on('click', '[data-remove]', function (e) {
                e.preventDefault();
                var $tr = $(this).closest('tr'), path = $tr.data('path') + '';
                GBX.confirm({ text: 'Delete this backup at the destination? This cannot be undone.', danger: true, confirmText: 'Delete' }).then(function (r) {
                    if (r.isConfirmed) GBX.post(url('storages/' + current.id + '/delete-file'), { path: path }).done(function (res) { toastr.success(res.message); $tr.remove(); });
                });
            });

        load();
        if (B.openAdd) open(null);
    }

    /* ============================================================ settings */
    function settingsTab() {
        $('#bkSettingsForm').on('submit', function (e) {
            e.preventDefault();
            var $btn = $(this).find('[type=submit]');
            GBX.busy($btn, true);
            GBX.post(url('settings'), GBX.serialize($(this))).done(function (r) { toastr.success(r.message); }).always(function () { GBX.busy($btn, false); });
        });
    }

    $(function () {
        if (B.tab === 'transfers') transfersTab();
        if (B.tab === 'local') localTab();
        if (B.tab === 'storage') storageTab();
        if (B.tab === 'settings') settingsTab();
    });
})(jQuery);
