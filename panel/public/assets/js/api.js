/*
 * GBX Panel - API: keys, webhooks and the call log.
 */
(function ($) {
    'use strict';

    var A = window.GBX_API, esc = GBX.escape;
    var url = function (path) { return A.base + '/' + path; };
    var modal = function (id) { return bootstrap.Modal.getOrCreateInstance(document.getElementById(id)); };

    /** Show a value that is only available once, with a copy button. */
    function showSecret(title, value, hint) {
        Swal.fire({
            title: title, icon: null, buttonsStyling: false, confirmButtonText: 'I saved it',
            customClass: { confirmButton: 'btn btn-primary' },
            html: '<p class="small text-start">' + esc(hint) + '</p>' +
                '<div class="api-secret font-mono">' + esc(value) + '</div>' +
                '<button type="button" class="btn btn-outline-secondary btn-sm mt-2" data-copy="' + esc(value) + '"><i class="bi bi-clipboard"></i> Copy</button>'
        });
    }

    /* ================================================================ keys */
    function keysTab() {
        var data = [], editing = null;
        var $form = $('#apiKeyForm');

        var load = function () {
            GBX.get(url('keys')).done(function (r) {
                data = r.data;
                $('#apiKeys').html(data.length ? data.map(function (k) {
                    var scopes = k.scopes.indexOf('*') !== -1
                        ? '<span class="badge badge-warning">Full access</span>'
                        : k.scopes.map(function (s) { return '<span class="api-scope">' + esc(s) + '</span>'; }).join(' ');
                    var state = k.expired
                        ? '<span class="badge badge-danger"><i class="bi bi-clock-history"></i> Expired</span>'
                        : (k.is_active ? '<span class="badge badge-success"><i class="bi bi-check2"></i> Active</span>' : '<span class="badge badge-soft">Off</span>');
                    return '<tr data-id="' + k.id + '">' +
                        '<td>' + state + '</td>' +
                        '<td><div class="cell-strong">' + esc(k.name) + '</div><div class="cell-sub font-mono">' + esc(k.prefix) + '_...' + '</div>' +
                            (k.client ? '<div class="cell-sub"><i class="bi bi-person-badge"></i> ' + esc(k.client) + '</div>' : '') + '</td>' +
                        '<td class="api-scopes-cell">' + scopes + '</td>' +
                        '<td class="cell-sub">' + k.rate_limit + '/min' + (k.allowed_ips ? '<div class="text-truncate" style="max-width:190px" title="' + esc(k.allowed_ips) + '"><i class="bi bi-hdd-network"></i> ' + esc(k.allowed_ips) + '</div>' : '') +
                            (k.expires_at ? '<div><i class="bi bi-calendar-event"></i> ' + esc(k.expires_at) + '</div>' : '') + '</td>' +
                        '<td class="cell-sub">' + (k.last_used_at ? esc(k.last_used_at) + '<div>' + esc(k.last_ip || '') + '</div>' : 'never') + '<div>' + k.requests + ' calls</div></td>' +
                        '<td class="text-end text-nowrap db-ops"><a href="#" data-edit>Edit</a><a href="#" data-toggle>' + (k.is_active ? 'Disable' : 'Enable') + '</a><a href="#" data-rotate>New token</a><a href="#" class="text-danger" data-delete>Delete</a></td></tr>';
                }).join('') : '<tr><td colspan="6" class="sm-empty"><i class="bi bi-key"></i> No key yet. Create one for each integration.</td></tr>');
            });
        };

        var open = function (key) {
            editing = key ? key.id : null;
            $form[0].reset();
            $('#apiKeyTitle').text(key ? 'Edit API key' : 'Create API key');
            $form.find('[name=name]').val(key ? key.name : '');
            $form.find('[name=allowed_ips]').val(key ? key.allowed_ips || '' : '');
            $form.find('[name=rate_limit]').val(key ? key.rate_limit : 120);
            $form.find('[name=expires_at]').val(key ? key.expires_at || '' : '');
            $form.find('[name=client_id]').val(key ? key.client_id || '' : '');
            $form.find('[name=is_active]').prop('checked', key ? key.is_active : true);
            $form.find('[name="scopes[]"]').prop('checked', false);
            (key ? key.scopes : []).forEach(function (s) { $form.find('[name="scopes[]"][value="' + s + '"]').prop('checked', true); });
            $('#apiScopes').prop('hidden', $('#apiScopeAll').is(':checked'));
            modal('apiKeyModal').show();
        };

        $('#apiScopeAll').on('change', function () {
            $('#apiScopes').prop('hidden', this.checked);
            if (this.checked) $('#apiScopes').find('input').prop('checked', false);
        });

        $form.on('submit', function (e) {
            e.preventDefault();
            var payload = GBX.serialize($form);
            payload.scopes = $form.find('[name="scopes[]"]:checked').map(function () { return this.value; }).get();
            if (!payload.scopes.length) { toastr.warning('Choose at least one permission.'); return; }
            if (editing) payload._method = 'PUT';
            GBX.post(url('keys' + (editing ? '/' + editing : '')), payload).done(function (r) {
                modal('apiKeyModal').hide();
                load();
                if (r.token) showSecret('API key created', r.token, 'Copy it now: the panel stores only its hash and cannot show it again.');
                else toastr.success(r.message);
            });
        });

        $('#apiAddKey').on('click', function () { open(null); });
        var rowData = function (el) { var id = $(el).closest('tr').data('id'); return data.find(function (k) { return k.id === id; }); };
        $('#apiKeys').on('click', '[data-edit]', function (e) { e.preventDefault(); open(rowData(this)); })
            .on('click', '[data-toggle]', function (e) {
                e.preventDefault();
                GBX.post(url('keys/' + rowData(this).id + '/toggle')).done(function (r) { toastr.success(r.message); load(); });
            })
            .on('click', '[data-rotate]', function (e) {
                e.preventDefault();
                var key = rowData(this);
                GBX.confirm({ title: 'New token for ' + key.name, text: 'The current token stops working immediately. Anything still using it will fail.', danger: true, confirmText: 'Create new token' }).then(function (r) {
                    if (r.isConfirmed) GBX.post(url('keys/' + key.id + '/rotate')).done(function (res) { load(); showSecret('New token', res.token, 'Copy it now and replace it wherever the old one was used.'); });
                });
            })
            .on('click', '[data-delete]', function (e) {
                e.preventDefault();
                var key = rowData(this);
                GBX.confirm({ title: 'Delete ' + key.name + '?', text: 'Anything using this key stops working right away.', danger: true, confirmText: 'Delete' }).then(function (r) {
                    if (r.isConfirmed) GBX.del(url('keys/' + key.id)).done(function (res) { toastr.success(res.message); load(); });
                });
            });

        load();
    }

    /* ============================================================ webhooks */
    function hooksTab() {
        var data = [], editing = null;
        var $form = $('#apiHookForm');

        var load = function () {
            GBX.get(url('webhooks')).done(function (r) {
                data = r.data;
                $('#apiHooks').html(data.length ? data.map(function (w) {
                    var events = w.events.indexOf('*') !== -1 ? '<span class="badge badge-soft">all events</span>'
                        : w.events.slice(0, 4).map(function (e) { return '<span class="api-scope">' + esc(e) + '</span>'; }).join(' ') + (w.events.length > 4 ? ' <span class="cell-sub">+' + (w.events.length - 4) + '</span>' : '');
                    var last = w.last_at
                        ? (w.last_error ? '<span class="badge badge-danger" title="' + esc(w.last_error) + '">HTTP ' + (w.last_status || 'error') + '</span>' : '<span class="badge badge-success">HTTP ' + w.last_status + '</span>') + '<div class="cell-sub">' + esc(w.last_at) + '</div>'
                        : '<span class="cell-sub">never</span>';
                    return '<tr data-id="' + w.id + '">' +
                        '<td>' + (w.is_active ? '<span class="badge badge-success">On</span>' : '<span class="badge badge-soft">Off</span>') + '</td>' +
                        '<td class="cell-strong">' + esc(w.name) + '</td>' +
                        '<td class="font-mono small text-break">' + esc(w.url) + '</td>' +
                        '<td class="api-scopes-cell">' + events + '</td>' +
                        '<td>' + last + '</td>' +
                        '<td class="text-end text-nowrap db-ops"><a href="#" data-test>Test</a><a href="#" data-deliveries>Deliveries</a><a href="#" data-secret>New secret</a><a href="#" data-edit>Edit</a><a href="#" class="text-danger" data-delete>Delete</a></td></tr>';
                }).join('') : '<tr><td colspan="6" class="sm-empty"><i class="bi bi-broadcast"></i> No webhook yet. Add one to be told when websites, backups or clients change.</td></tr>');
            });
        };

        var open = function (hook) {
            editing = hook ? hook.id : null;
            $form[0].reset();
            $('#apiHookTitle').text(hook ? 'Edit webhook' : 'Add webhook');
            $form.find('[name=name]').val(hook ? hook.name : '');
            $form.find('[name=url]').val(hook ? hook.url : '');
            $form.find('[name=is_active]').prop('checked', hook ? hook.is_active : true);
            $form.find('[name="events[]"]').prop('checked', false);
            (hook ? hook.events : []).forEach(function (e) { $form.find('[name="events[]"][value="' + e + '"]').prop('checked', true); });
            $('#apiEvents').prop('hidden', $('#apiEventAll').is(':checked'));
            modal('apiHookModal').show();
        };

        $('#apiEventAll').on('change', function () {
            $('#apiEvents').prop('hidden', this.checked);
            if (this.checked) $('#apiEvents').find('input').prop('checked', false);
        });

        $form.on('submit', function (e) {
            e.preventDefault();
            var payload = GBX.serialize($form);
            payload.events = $form.find('[name="events[]"]:checked').map(function () { return this.value; }).get();
            if (!payload.events.length) { toastr.warning('Choose at least one event.'); return; }
            if (editing) payload._method = 'PUT';
            GBX.post(url('webhooks' + (editing ? '/' + editing : '')), payload).done(function (r) {
                modal('apiHookModal').hide();
                load();
                if (r.secret) showSecret('Webhook created', r.secret, 'Use this secret to check the X-GBX-Signature header of every message.');
                else toastr.success(r.message);
            });
        });

        $('#apiAddHook').on('click', function () { open(null); });
        var rowData = function (el) { var id = $(el).closest('tr').data('id'); return data.find(function (w) { return w.id === id; }); };
        $('#apiHooks').on('click', '[data-edit]', function (e) { e.preventDefault(); open(rowData(this)); })
            .on('click', '[data-test]', function (e) {
                e.preventDefault();
                var $a = $(this), text = $a.text();
                $a.text('Sending...');
                GBX.post(url('webhooks/' + rowData(this).id + '/test'), {}, { silent: true })
                    .done(function (r) { toastr.success(r.message); })
                    .fail(function (xhr) { toastr.error(GBX.errorMessage(xhr), '', { timeOut: 12000 }); })
                    .always(function () { $a.text(text); load(); });
            })
            .on('click', '[data-secret]', function (e) {
                e.preventDefault();
                var hook = rowData(this);
                GBX.confirm({ title: 'New secret for ' + hook.name, text: 'Messages are signed with the new secret right away; update your receiver.', danger: true, confirmText: 'Create' }).then(function (r) {
                    if (r.isConfirmed) GBX.post(url('webhooks/' + hook.id + '/secret')).done(function (res) { showSecret('New signing secret', res.secret, 'Store it in your receiver to check the signature.'); });
                });
            })
            .on('click', '[data-deliveries]', function (e) {
                e.preventDefault();
                var hook = rowData(this);
                $('#apiDeliveriesTitle').text(hook.name);
                $('#apiDeliveries').html('<tr><td colspan="5" class="sm-empty"><i class="bi bi-arrow-repeat spin"></i> Loading</td></tr>');
                modal('apiDeliveriesModal').show();
                GBX.get(url('webhooks/' + hook.id + '/deliveries')).done(function (r) {
                    $('#apiDeliveries').html(r.data.length ? r.data.map(function (d) {
                        var badge = d.status === 'sent' ? 'badge-success' : (d.status === 'failed' ? 'badge-danger' : 'badge-soft');
                        return '<tr><td class="cell-sub text-nowrap">' + esc(d.created_at) + '</td><td class="font-mono small">' + esc(d.event) + '</td>' +
                            '<td><span class="badge ' + badge + '">' + esc(d.status) + '</span></td><td>' + d.attempts + '</td>' +
                            '<td class="small">' + (d.response_code ? 'HTTP ' + d.response_code : '') + (d.error ? '<div class="cell-sub bk-error">' + esc(d.error) + '</div>' : '') + '</td></tr>';
                    }).join('') : '<tr><td colspan="5" class="sm-empty">Nothing sent yet</td></tr>');
                });
            })
            .on('click', '[data-delete]', function (e) {
                e.preventDefault();
                var hook = rowData(this);
                GBX.confirm({ text: 'Delete the webhook ' + hook.name + '?', danger: true, confirmText: 'Delete' }).then(function (r) {
                    if (r.isConfirmed) GBX.del(url('webhooks/' + hook.id)).done(function (res) { toastr.success(res.message); load(); });
                });
            });

        load();
    }

    /* ================================================================= log */
    function logsTab() {
        var load = function () {
            GBX.get(url('logs'), { key: $('#apiLogKey').val() || undefined, only: $('#apiLogOnly').val() || undefined, search: $('#apiLogSearch').val() || undefined }).done(function (r) {
                if (!$('#apiLogKey option').length || $('#apiLogKey option').length === 1) {
                    $('#apiLogKey').append(r.keys.map(function (k) { return '<option value="' + k.id + '">' + esc(k.name) + '</option>'; }).join(''));
                }
                $('#apiLog').html(r.data.length ? r.data.map(function (l) {
                    var badge = l.status < 300 ? 'badge-success' : (l.status < 400 ? 'badge-soft' : 'badge-danger');
                    return '<tr><td class="cell-sub text-nowrap">' + esc(l.at) + '</td><td>' + esc(l.key || '-') + '</td>' +
                        '<td><span class="api-method api-' + l.method.toLowerCase() + '">' + esc(l.method) + '</span> <span class="font-mono small">/' + esc(l.path) + '</span>' +
                        (l.message ? '<div class="cell-sub bk-error">' + esc(l.message) + '</div>' : '') + '</td>' +
                        '<td><span class="badge ' + badge + '">' + l.status + '</span></td><td class="cell-sub">' + l.duration + ' ms</td><td class="cell-sub font-mono">' + esc(l.ip || '') + '</td></tr>';
                }).join('') : '<tr><td colspan="6" class="sm-empty"><i class="bi bi-inbox"></i> No calls yet</td></tr>');
            });
        };
        $('#apiLogRefresh').on('click', load);
        $('#apiLogKey, #apiLogOnly').on('change', load);
        $('#apiLogSearch').on('input', GBX.debounce ? GBX.debounce(load, 400) : load);
        $('#apiLogClear').on('click', function () {
            GBX.confirm({ text: 'Delete the whole API log?', danger: true, confirmText: 'Clear' }).then(function (r) {
                if (r.isConfirmed) GBX.post(url('logs/clear')).done(function (res) { toastr.success(res.message); load(); });
            });
        });
        load();
    }

    $(function () {
        if (A.tab === 'keys') keysTab();
        if (A.tab === 'webhooks') hooksTab();
        if (A.tab === 'logs') logsTab();
    });
})(jQuery);
