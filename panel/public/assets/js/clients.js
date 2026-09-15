/*
 * GBX Panel - Clients: accounts, packages, storage, logs.
 */
(function ($) {
    'use strict';

    var C = window.GBX_CLIENTS, esc = GBX.escape;
    var url = function (path) { return C.base + (path ? '/' + path : ''); };
    var modal = function (id) { return bootstrap.Modal.getOrCreateInstance(document.getElementById(id)); };
    var reload = function () { setTimeout(function () { location.reload(); }, 600); };
    var bar = function (used, limit) {
        if (!limit) return '';
        var p = Math.min(100, used / limit * 100);
        return '<div class="cl-bar"><i class="' + (p >= 90 ? 'crit' : p >= 75 ? 'warn' : '') + '" style="width:' + p.toFixed(1) + '%"></i></div>';
    };

    /* ============================================================= accounts */
    function accountsTab() {
        var data = [], editing = null;
        var $form = $('#clForm');

        var render = function () {
            var q = ($('#clSearch').val() || '').toLowerCase(), pkg = $('#clPackageFilter').val(), st = $('#clStatusFilter').val();
            var rows = data.filter(function (c) {
                return (!pkg || String(c.package_id) === pkg) && (!st || c.status === st) && (!q || (c.username + ' ' + c.name + ' ' + (c.email || '')).toLowerCase().indexOf(q) !== -1);
            });
            $('#clRows').html(rows.length ? rows.map(function (c) {
                var active = c.status === 'active';
                return '<tr data-id="' + c.id + '">' +
                    '<td><a href="#" class="cl-edit cell-strong text-decoration-none text-success">' + esc(c.username) + '</a><div class="cell-sub">' + esc(c.name) + (c.two_factor ? ' <i class="bi bi-shield-check" title="Two-factor authentication"></i>' : '') + '</div></td>' +
                    '<td>' + esc(c.package || '-') + '</td>' +
                    '<td class="small">' + esc(c.email || '-') + '</td>' +
                    '<td class="small text-nowrap">' + esc(c.disk_h) + bar(c.disk_used, c.disk_limit) + '</td>' +
                    '<td class="small text-nowrap">' + esc(c.bandwidth_h) + bar(c.bandwidth_used, c.bandwidth_limit) + '</td>' +
                    '<td class="small text-nowrap"><a href="#" class="cl-resources">' + c.websites + ' sites, ' + c.databases + ' DB, ' + c.ftp + ' FTP</a></td>' +
                    '<td>' + (active ? '<span class="text-success">Normal</span>' : '<span class="text-danger" title="' + esc(c.suspended_reason || '') + '">Suspended</span>') + '</td>' +
                    '<td class="small">' + (c.expires_at ? '<span class="' + (c.expired ? 'text-danger' : '') + '">' + esc(c.expires_at) + '</span>' : 'Perpetual') + '</td>' +
                    '<td class="small">' + esc(c.notes || '--') + '</td>' +
                    '<td class="text-end text-nowrap db-ops"><a href="#" class="cl-login">Login Sub Panel</a><a href="#" class="cl-edit">Edit</a>' +
                    '<div class="dropdown d-inline-block"><a href="#" data-bs-toggle="dropdown" class="ms-1 ps-2 border-start">More <i class="bi bi-chevron-down small"></i></a><div class="dropdown-menu dropdown-menu-end">' +
                    '<a class="dropdown-item cl-resources" href="#">Resources</a>' +
                    (active ? '<a class="dropdown-item cl-suspend" href="#">Suspend</a>' : '<a class="dropdown-item cl-unsuspend" href="#">Reactivate</a>') +
                    (c.two_factor ? '<a class="dropdown-item cl-2fa" href="#">Reset two-factor</a>' : '') +
                    '<div class="dropdown-divider"></div><a class="dropdown-item text-danger cl-delete" href="#">Delete</a></div></div></td></tr>';
            }).join('') : '<tr><td colspan="10" class="sm-empty"><i class="bi bi-person-badge"></i> No client accounts</td></tr>');
        };
        var load = function () { GBX.get(url('list')).done(function (r) { data = r.data; render(); }); };
        var clientOf = function (el) { var id = $(el).closest('tr').data('id'); return data.find(function (c) { return c.id === id; }); };

        $('#clSearch').on('input', render);
        $('#clPackageFilter, #clStatusFilter').on('change', render);

        var open = function (c) {
            editing = c ? c.id : null;
            $form[0].reset();
            $('#clTitle').text(c ? 'Edit ' + c.username : 'Add Account');
            $form.find('[name=username]').val(c ? c.username : '').prop('disabled', !!c);
            $form.find('[name=name]').val(c ? c.name : '');
            $form.find('[name=email]').val(c ? c.email || '' : '');
            $form.find('[name=package_id]').val(c && c.package_id ? c.package_id : ($form.find('[name=package_id] option').eq(1).val() || ''));
            $form.find('[name=expires_at]').val(c ? c.expires_at || '' : '');
            $form.find('[name=notes]').val(c ? c.notes || '' : '');
            $form.find('[name=password]').val(c ? '' : GBX.password(16)).prop('required', !c);
            $('#clPassHint').text(c ? 'Leave empty to keep the current password.' : 'At least 10 characters with letters and numbers. Send it to the client.');
            modal('clModal').show();
        };
        $('#clAdd').on('click', function () { open(null); });
        $('#clRows').on('click', '.cl-edit', function (e) { e.preventDefault(); open(clientOf(this)); });
        $form.on('submit', function (e) {
            e.preventDefault();
            var d = GBX.serialize($form), $btn = $form.find('[type=submit]');
            if (editing) { d._method = 'PUT'; delete d.username; }
            GBX.busy($btn, true);
            GBX.post(url(editing ? String(editing) : ''), d, { silent: true }).done(function (r) {
                toastr.success(r.message);
                modal('clModal').hide();
                load();
            }).fail(function (xhr) { toastr.error(GBX.errorMessage(xhr)); }).always(function () { GBX.busy($btn, false); });
        });

        $('#clRows').on('click', '.cl-login', function (e) {
            e.preventDefault();
            var c = clientOf(this);
            var form = $('<form method="POST" target="_blank">').attr('action', url(c.id + '/login')).append($('<input type="hidden" name="_token">').val($('meta[name=csrf-token]').attr('content')));
            form.appendTo('body').trigger('submit').remove();
        }).on('click', '.cl-suspend', function (e) {
            e.preventDefault();
            var c = clientOf(this);
            GBX.confirm({ title: 'Suspend ' + c.username, text: 'The client cannot sign in, its websites are stopped and its FTP accounts disabled until it is reactivated.', input: 'text', inputPlaceholder: 'Reason shown to the client (optional)', danger: true, confirmText: 'Suspend' }).then(function (r) {
                if (r.isConfirmed) GBX.post(url(c.id + '/suspend'), { reason: r.value || '' }).done(function (res) { toastr.success(res.message); load(); });
            });
        }).on('click', '.cl-unsuspend', function (e) {
            e.preventDefault();
            GBX.post(url(clientOf(this).id + '/unsuspend')).done(function (res) { toastr.success(res.message); load(); });
        }).on('click', '.cl-2fa', function (e) {
            e.preventDefault();
            var c = clientOf(this);
            GBX.confirm({ text: 'Reset the two-factor authentication of ' + c.username + '?', danger: true, confirmText: 'Reset' }).then(function (r) {
                if (r.isConfirmed) GBX.post(url(c.id + '/two-factor-reset')).done(function (res) { toastr.success(res.message); load(); });
            });
        }).on('click', '.cl-delete', function (e) {
            e.preventDefault();
            var c = clientOf(this);
            GBX.confirm({ title: 'Delete ' + c.username, text: 'The client account is removed. Its websites, databases and FTP accounts are kept and return to the administrator.', danger: true, confirmText: 'Delete' }).then(function (r) {
                if (r.isConfirmed) GBX.del(url(String(c.id))).done(function (res) { toastr.success(res.message); load(); });
            });
        });

        /* resources */
        var resClient = null, resData = {}, resKey = 'websites';
        var renderRes = function () {
            var list = resData[resKey] || [];
            $('#clResList').html(list.length ? list.map(function (r) {
                var taken = r.owner && !r.mine;
                return '<label class="' + (taken ? 'taken' : '') + '"><input type="checkbox" class="form-check-input m-0" value="' + r.id + '"' + (r.mine ? ' checked' : '') + (taken ? ' disabled' : '') + '>' +
                    '<span class="flex-grow-1">' + esc(r.label) + '</span>' + (r.owner ? '<span class="badge ' + (r.mine ? 'badge-success' : 'badge-soft') + '">' + esc(r.owner) + '</span>' : '<span class="cell-sub">administrator</span>') + '</label>';
            }).join('') : '<div class="sm-empty">Nothing to assign</div>');
        };
        var loadRes = function () { GBX.get(url(resClient.id + '/resources')).done(function (r) { resData = r.data; renderRes(); }); };
        $('#clRows').on('click', '.cl-resources', function (e) {
            e.preventDefault();
            resClient = clientOf(this);
            $('#clResTitle').text(resClient.username);
            $('#clResList').html('<div class="sm-empty"><i class="bi bi-arrow-repeat spin"></i> Loading</div>');
            modal('clResourcesModal').show();
            loadRes();
        });
        $('#clResTabs').on('click', '[data-res]', function () {
            $('#clResTabs .nav-link').removeClass('active');
            resKey = $(this).addClass('active').data('res');
            renderRes();
        });
        $('#clResList').on('change', 'input', function () {
            var $i = $(this);
            GBX.post(url(resClient.id + '/resources'), { resource: resKey, ids: [$i.val()], attach: $i.is(':checked') ? 1 : 0 }).done(function (r) { toastr.success(r.message); loadRes(); })
                .fail(function () { $i.prop('checked', !$i.is(':checked')); });
        });
        $('#clResourcesModal').on('hidden.bs.modal', load);

        load();
    }

    /* ============================================================= packages */
    function packagesTab() {
        var editing = null, $f = $('#packageForm');
        $(document).on('click', '[data-package]', function (e) {
            e.preventDefault();
            var p = $(this).data('package');
            p = p === 'new' ? null : p;
            editing = p ? p.id : null;
            $f[0].reset();
            $('#packageTitle').text(p ? 'Edit ' + p.name : 'Add Package');
            if (p) {
                $.each(['name', 'max_websites', 'max_databases', 'max_ftp', 'disk_mb', 'bandwidth_mb', 'notes'], function (_, k) { $f.find('[name=' + k + ']').val(p[k] === null ? '' : p[k]); });
                $f.find('[name="php_versions[]"]').each(function () { this.checked = (p.php_versions || []).indexOf(this.value) !== -1; });
                $f.find('[name=allow_ssl]').prop('checked', !!p.allow_ssl);
            }
            modal('packageModal').show();
        });
        $f.on('submit', function (e) {
            e.preventDefault();
            var d = GBX.serialize($f), $btn = $f.find('[type=submit]');
            if (editing) d._method = 'PUT';
            GBX.busy($btn, true);
            GBX.post(url('packages' + (editing ? '/' + editing : '')), d).done(function (r) { toastr.success(r.message); reload(); }).always(function () { GBX.busy($btn, false); });
        });
    }

    /* ============================================================== storage */
    function storageTab() {
        var load = function () {
            GBX.get(url('storage')).done(function (r) {
                $('#clStorage').html(r.data.length ? r.data.map(function (c) {
                    return '<tr><td class="cell-strong">' + esc(c.username) + '<div class="cell-sub">' + esc(c.name) + '</div></td><td>' + esc(c.package || '-') + '</td>' +
                        '<td class="small">' + esc(c.disk_h) + bar(c.disk_used, c.disk_limit) + '</td>' +
                        '<td class="small">' + esc(c.bandwidth_h) + bar(c.bandwidth_used, c.bandwidth_limit) + '</td>' +
                        '<td class="small font-mono">' + (c.sites.length ? c.sites.map(function (s) { return esc(s.root); }).join('<br>') : '<span class="cell-sub">-</span>') + '</td>' +
                        '<td class="small text-nowrap">' + esc(c.usage_updated_at || 'Never') + '</td></tr>';
                }).join('') : '<tr><td colspan="6" class="sm-empty">No clients</td></tr>');
            });
        };
        $('#clRefreshUsage').on('click', function () {
            var $b = $(this);
            GBX.busy($b, true);
            GBX.post(url('usage'), {}, { timeout: 600000 }).done(function (r) { toastr.success(r.message); load(); }).always(function () { GBX.busy($b, false); });
        });
        load();
    }

    /* ================================================================= logs */
    function logsTab() {
        var data = [];
        var render = function () {
            var q = ($('#clLogSearch').val() || '').toLowerCase();
            var rows = data.filter(function (l) { return !q || (l.action + ' ' + (l.details || '')).toLowerCase().indexOf(q) !== -1; });
            $('#clLogs').html(rows.length ? rows.map(function (l) {
                return '<tr><td class="small text-nowrap">' + esc(l.time) + '</td><td>' + esc(l.client) + (l.admin ? '<div class="cell-sub">by admin ' + esc(l.admin) + '</div>' : '') + '</td>' +
                    '<td>' + esc(l.action) + '</td><td class="small font-mono text-truncate" style="max-width:360px" title="' + esc(l.details || '') + '">' + esc(l.details || '-') + '</td><td class="small font-mono">' + esc(l.ip || '') + '</td></tr>';
            }).join('') : '<tr><td colspan="5" class="sm-empty">No activity</td></tr>');
        };
        var load = function () { GBX.get(url('logs'), { client_id: $('#clLogClient').val() }).done(function (r) { data = r.data; render(); }); };
        GBX.get(url('list'), {}, { silent: true }).done(function (r) {
            $('#clLogClient').append(r.data.map(function (c) { return '<option value="' + c.id + '">' + esc(c.username) + '</option>'; }).join(''));
        });
        $('#clLogClient').on('change', load);
        $('#clLogSearch').on('input', render);
        load();
    }

    ({ accounts: accountsTab, packages: packagesTab, storage: storageTab, logs: logsTab }[C.tab] || function () {})();
})(jQuery);
