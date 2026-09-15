/*
 * GBX Panel - DNS: provider accounts (DNS API), domains and records.
 */
(function ($) {
    'use strict';

    var D = window.GBX_DNS, esc = GBX.escape;
    var url = function (path) { return D.base + '/' + path; };
    var typeOf = function (key) { return D.types.find(function (t) { return t.key === key; }) || {}; };
    var modal = function (id) { return bootstrap.Modal.getOrCreateInstance(document.getElementById(id)); };
    var confirmDo = function (opts, fn) { GBX.confirm(opts).then(function (r) { if (r.isConfirmed) fn(r); }); };
    var ttlLabel = function (ttl) { return D.ttls[ttl] || (ttl >= 3600 && ttl % 3600 === 0 ? (ttl / 3600) + ' h' : ttl >= 60 && ttl % 60 === 0 ? (ttl / 60) + ' min' : ttl + ' s'); };
    var logo = function (t, small) { return '<span class="dns-logo' + (small ? ' sm' : '') + '" style="background:' + esc(t.color || '#444') + '"><i class="bi ' + esc(t.icon || 'bi-globe') + '"></i></span>'; };

    /* ============================================================ providers */
    function providersTab() {
        var data = [], editing = null;
        var $form = $('#dnsProviderForm');

        var load = function () {
            GBX.get(url('providers')).done(function (r) {
                data = r.data;
                $('#dnsProviders').html(data.length ? data.map(function (p) {
                    var t = typeOf(p.type);
                    var check = p.last_error
                        ? '<span class="badge badge-danger" title="' + esc(p.last_error) + '"><i class="bi bi-x-circle"></i> Error</span><div class="cell-sub dns-error">' + esc(p.last_error) + '</div>'
                        : (p.checked_at ? '<span class="badge badge-success"><i class="bi bi-check2"></i> OK</span><div class="cell-sub">' + esc(p.checked_at) + '</div>' : '<span class="cell-sub">-</span>');
                    return '<tr data-id="' + p.id + '">' +
                        '<td>' + (D.isAdmin ? '<div class="form-check form-switch m-0"><input class="form-check-input dns-toggle" type="checkbox"' + (p.is_active ? ' checked' : '') + '></div>' : (p.is_active ? 'On' : 'Off')) + '</td>' +
                        '<td class="text-nowrap">' + logo(t, true) + ' ' + esc(p.type_name) + '</td>' +
                        '<td>' + esc(p.alias || '-') + '</td>' +
                        '<td class="small">' + esc(p.account || '-') + '</td>' +
                        '<td><a href="' + D.base + '?provider=' + p.id + '">' + p.zones + '</a></td>' +
                        '<td>' + (p.rate_limit ? '<span class="badge badge-soft">On</span>' : '<span class="cell-sub">Off</span>') + '</td>' +
                        '<td>' + check + '</td>' +
                        '<td class="text-end text-nowrap db-ops">' + (D.isAdmin ? '<a href="#" data-test>Test</a><a href="#" data-sync>Sync</a><a href="#" data-edit>Edit</a><a href="#" class="text-danger" data-delete>Delete</a>' : '-') + '</td></tr>';
                }).join('') : '<tr><td colspan="8" class="sm-empty"><i class="bi bi-plug"></i> No DNS API yet. Add Cloudflare, Namecheap or another provider to manage records from the panel.</td></tr>');
            });
        };

        var renderFields = function (type, values) {
            var t = typeOf(type);
            values = values || {};
            $('#dnsFields').html(Object.keys(t.fields).map(function (key) {
                var f = t.fields[key], name = 'credentials[' + key + ']', value = values[key] || '';
                var input;
                if (f.type === 'checkbox') {
                    input = '<div class="form-check form-switch m-0 pt-2"><input class="form-check-input" type="checkbox" name="' + name + '" value="1"' + (value ? ' checked' : '') + '></div>';
                } else {
                    var ph = f.type === 'password' && editing ? 'Unchanged' : (f.placeholder || 'Please enter ' + f.label);
                    if (key === 'client_ip') ph = 'Empty: ' + D.serverIp;
                    input = '<input type="' + (f.type === 'password' ? 'password' : 'text') + '" name="' + name + '" class="form-control' + (f.type === 'password' ? ' font-mono' : '') + '" value="' + esc(value) + '" placeholder="' + esc(ph) + '" autocomplete="off">';
                }
                return '<div class="cron-row"><label>' + esc(f.label) + (f.required ? '' : '') + '</label><div>' + input + '</div></div>';
            }).join(''));
            $('#dnsNotes').html(t.notes.map(function (n) { return '<li>' + esc(n).replace(esc(D.serverIp), '<span class="font-mono">' + esc(D.serverIp) + '</span>') + '</li>'; }).join('') +
                '<li class="dns-docs"><a href="' + esc(t.docs) + '" target="_blank" rel="noopener noreferrer">How to obtain API Key</a></li>' +
                (type === 'namecheap' || type === 'vultr' || type === 'godaddy' ? '<li>IPv4 of this server: <span class="font-mono">' + esc(D.serverIp) + '</span> <a href="#" data-copy="' + esc(D.serverIp) + '">copy</a></li>' : ''));
        };

        var open = function (p) {
            editing = p ? p.id : null;
            $form[0].reset();
            $('#dnsProviderTitle').text(p ? 'Edit DNS Provider API' : 'Integrate DNS Provider API');
            $form.find('[name=type]').val(p ? p.type : 'cloudflare').prop('disabled', !!p);
            $form.find('[name=alias]').val(p ? p.alias || '' : '');
            $form.find('[name=is_active]').prop('checked', p ? p.is_active : true);
            $form.find('[name=rate_limit]').prop('checked', p ? p.rate_limit : false);
            renderFields(p ? p.type : 'cloudflare', p ? p.credentials : {});
            modal('dnsProviderModal').show();
        };

        $form.find('[name=type]').on('change', function () { renderFields(this.value); });
        $form.on('submit', function (e) {
            e.preventDefault();
            var $btn = $form.find('[type=submit]'), payload = GBX.serialize($form);
            payload.credentials = {};
            $form.find('[name^="credentials["]').each(function () {
                var key = this.name.slice(12, -1);
                payload.credentials[key] = this.type === 'checkbox' ? (this.checked ? '1' : '') : this.value;
                delete payload[this.name];
            });
            if (editing) payload._method = 'PUT';
            GBX.busy($btn.data('busy', 'Checking'), true);
            GBX.post(url('providers' + (editing ? '/' + editing : '')), payload, { silent: true }).done(function (r) {
                toastr.success(r.message);
                modal('dnsProviderModal').hide();
                load();
            }).fail(function (xhr) { toastr.error(GBX.errorMessage(xhr), '', { timeOut: 12000 }); }).always(function () { GBX.busy($btn, false); });
        });

        $('#dnsAddProvider').on('click', function () { open(null); });
        var rowData = function (el) { var id = $(el).closest('tr').data('id'); return data.find(function (p) { return p.id === id; }); };
        $('#dnsProviders').on('click', '[data-edit]', function (e) { e.preventDefault(); open(rowData(this)); })
            .on('click', '[data-test], [data-sync]', function (e) {
                e.preventDefault();
                var $a = $(this), p = rowData(this), action = $a.is('[data-test]') ? 'test' : 'sync', text = $a.text();
                $a.text(action === 'test' ? 'Testing...' : 'Syncing...');
                GBX.post(url('providers/' + p.id + '/' + action), {}, { silent: true }).done(function (r) { toastr.success(r.message); })
                    .fail(function (xhr) { toastr.error(GBX.errorMessage(xhr), '', { timeOut: 12000 }); })
                    .always(function () { $a.text(text); load(); });
            })
            .on('change', '.dns-toggle', function () {
                GBX.post(url('providers/' + rowData(this).id + '/toggle')).done(function (r) { toastr.success(r.message); load(); });
            })
            .on('click', '[data-delete]', function (e) {
                e.preventDefault();
                var p = rowData(this);
                confirmDo({ title: 'Remove DNS API', text: 'Remove ' + p.label + ' from the panel? Its ' + p.zones + ' domain(s) disappear from the list; the records at the provider are not changed.', danger: true, confirmText: 'Remove' }, function () {
                    GBX.del(url('providers/' + p.id)).done(function (r) { toastr.success(r.message); load(); });
                });
            });

        load();
        if (D.openAdd && D.isAdmin) open(null);
    }

    /* ============================================================== records */
    var zone = null, records = [], meta = {}, editingRecord = null;
    var hints = {
        A: 'IPv4 address, e.g. ' + D.serverIp, AAAA: 'IPv6 address', CNAME: 'Target host name, e.g. example.com',
        MX: 'Mail server host name, e.g. mail.example.com', TXT: 'Text, e.g. v=spf1 a mx ~all', CAA: 'e.g. 0 issue "letsencrypt.org"', NS: 'Name server, e.g. ns1.example.com'
    };

    var openZone = function (z) {
        zone = z;
        $('#dnsZoneTitle').text(z.name);
        $('#dnsZoneProvider').text(z.provider);
        $('#dnsRecordForm').prop('hidden', true);
        $('#dnsRecordSearch').val('');
        $('#dnsRecords').html('<tr><td colspan="7" class="sm-empty"><i class="bi bi-arrow-repeat spin"></i> Loading records from ' + esc(z.provider) + '</td></tr>');
        modal('dnsRecordsModal').show();
        loadRecords();
    };

    var loadRecords = function () {
        GBX.get(url('zones/' + zone.id + '/records'), {}, { silent: true }).done(function (r) {
            records = r.data;
            meta = r;
            $('#dnsRecordsModal [data-proxy]').prop('hidden', !r.proxy);
            var types = [];
            records.forEach(function (x) { if (types.indexOf(x.type) === -1) types.push(x.type); });
            var current = $('#dnsTypeFilter').val();
            $('#dnsTypeFilter').html('<option value="">All types (' + records.length + ')</option>' + types.sort().map(function (t) { return '<option' + (t === current ? ' selected' : '') + '>' + t + '</option>'; }).join(''));
            $('#dnsRecordForm [name=type]').html(r.record_types.map(function (t) { return '<option>' + t + '</option>'; }).join(''));
            $('.dns-suffix').text('.' + zone.name);
            renderRecords();
            $(document).trigger('dns:records', [zone.id, records.length]);
        }).fail(function (xhr) {
            $('#dnsRecords').html('<tr><td colspan="7" class="sm-empty text-danger"><i class="bi bi-x-circle"></i> ' + esc(GBX.errorMessage(xhr)) + '</td></tr>');
        });
    };

    var renderRecords = function () {
        var q = ($('#dnsRecordSearch').val() || '').toLowerCase(), type = $('#dnsTypeFilter').val();
        var rows = records.filter(function (r) {
            return (!type || r.type === type) && (!q || (r.name + ' ' + r.content).toLowerCase().indexOf(q) !== -1);
        });
        $('#dnsRecords').html(rows.length ? rows.map(function (r) {
            var editable = D.canWrite && meta.record_types.indexOf(r.type) !== -1;
            var host = r.name === '@' ? zone.name : r.name + '.' + zone.name;
            return '<tr data-id="' + esc(r.id) + '">' +
                '<td><span class="dns-type dns-type-' + esc(r.type.toLowerCase()) + '">' + esc(r.type) + '</span></td>' +
                '<td class="font-mono small" title="' + esc(host) + '">' + esc(r.name) + '</td>' +
                '<td class="font-mono small dns-value" title="' + esc(r.content) + '">' + esc(r.content) + '</td>' +
                '<td class="small text-nowrap">' + esc(ttlLabel(r.ttl)) + '</td>' +
                '<td class="small">' + (r.priority === null ? '<span class="cell-sub">-</span>' : r.priority) + '</td>' +
                (meta.proxy ? '<td>' + (r.proxied === null ? '<span class="cell-sub">-</span>' : '<i class="bi bi-cloud-fill ' + (r.proxied ? 'dns-proxied' : 'dns-dns-only') + '" title="' + (r.proxied ? 'Proxied' : 'DNS only') + '"></i>') + '</td>' : '') +
                '<td class="text-end text-nowrap db-ops">' + (editable ? '<a href="#" data-edit>Edit</a><a href="#" class="text-danger" data-delete>Delete</a>' : '<span class="cell-sub">' + (D.canWrite ? 'not editable' : '-') + '</span>') + '</td></tr>';
        }).join('') : '<tr><td colspan="7" class="sm-empty"><i class="bi bi-inbox"></i> No records</td></tr>');
    };

    var syncForm = function () {
        var $f = $('#dnsRecordForm'), type = $f.find('[name=type]').val();
        $f.find('[data-for-type]').prop('hidden', $f.find('[data-for-type]').data('for-type') !== type);
        $f.find('[data-proxy]').prop('hidden', !meta.proxy || ['A', 'AAAA', 'CNAME'].indexOf(type) === -1);
        $f.find('[name=content]').attr('placeholder', hints[type] || '');
        $('#dnsValueHint').text((meta.min_ttl > 60 ? 'Minimum TTL at this provider: ' + ttlLabel(meta.min_ttl) + '. ' : '') + (type === 'CNAME' ? 'A CNAME cannot share its host with other records.' : ''));
    };

    var openRecordForm = function (r) {
        editingRecord = r ? r.id : null;
        var $f = $('#dnsRecordForm');
        $f[0].reset();
        $f.find('[name=type]').val(r ? r.type : 'A');
        $f.find('[name=name]').val(r ? r.name : '');
        $f.find('[name=content]').val(r ? r.content : (r === null ? '' : ''));
        $f.find('[name=priority]').val(r && r.priority !== null ? r.priority : 10);
        var ttl = r ? r.ttl : 1;
        if (!$f.find('[name=ttl] option[value="' + ttl + '"]').length) $f.find('[name=ttl]').append('<option value="' + ttl + '">' + esc(ttlLabel(ttl)) + '</option>');
        $f.find('[name=ttl]').val(ttl);
        $f.find('[name=proxied]').prop('checked', !!(r && r.proxied));
        $f.find('[name=type]').prop('disabled', false);
        syncForm();
        $f.prop('hidden', false);
        $f.find(r ? '[name=content]' : '[name=name]').trigger('focus');
    };

    $('#dnsRecordForm [name=type]').on('change', syncForm);
    $('#dnsAddRecord').on('click', function () { openRecordForm(null); });
    $('#dnsCancelRecord').on('click', function () { $('#dnsRecordForm').prop('hidden', true); });
    $('#dnsReload').on('click', loadRecords);
    $('#dnsRecordSearch').on('input', renderRecords);
    $('#dnsTypeFilter').on('change', renderRecords);
    $('#dnsRecords').on('click', '[data-edit]', function (e) {
        e.preventDefault();
        var id = $(this).closest('tr').data('id') + '';
        openRecordForm(records.find(function (r) { return r.id === id; }));
    }).on('click', '[data-delete]', function (e) {
        e.preventDefault();
        var id = $(this).closest('tr').data('id') + '', r = records.find(function (x) { return x.id === id; });
        var label = r.type + ' ' + (r.name === '@' ? zone.name : r.name + '.' + zone.name) + ' ' + r.content;
        confirmDo({ title: 'Delete record', text: 'Delete ' + label + '?', danger: true, confirmText: 'Delete' }, function () {
            GBX.del(url('zones/' + zone.id + '/records/' + encodeURIComponent(id)), { label: label }).done(function (res) { toastr.success(res.message); loadRecords(); });
        });
    });
    $('#dnsRecordForm').on('submit', function (e) {
        e.preventDefault();
        var $f = $(this), $btn = $f.find('[type=submit]'), data = GBX.serialize($f);
        if (editingRecord) data._method = 'PUT';
        GBX.busy($btn, true);
        GBX.post(url('zones/' + zone.id + '/records' + (editingRecord ? '/' + encodeURIComponent(editingRecord) : '')), data, { silent: true }).done(function (r) {
            toastr.success(r.message);
            $f.prop('hidden', true);
            loadRecords();
        }).fail(function (xhr) { toastr.error(GBX.errorMessage(xhr), '', { timeOut: 12000 }); }).always(function () { GBX.busy($btn, false); });
    });

    var pointZone = function (z, done) {
        var proxy = typeOf(z.provider_type || (zone && zone.type)).proxy;
        GBX.confirm({
            title: 'Point to this server', icon: null, confirmText: 'Update DNS',
            html: '<p class="small">Create or update the A records of <strong>' + esc(z.name) + '</strong> and <strong>www.' + esc(z.name) + '</strong> with <span class="font-mono">' + esc(D.serverIp) + '</span>. Hosts that use a CNAME are not changed.</p>' +
                '<div class="text-start small"><div class="form-check"><input class="form-check-input" type="checkbox" id="dnsPointV6" checked><label class="form-check-label" for="dnsPointV6">Also AAAA (IPv6) when the server has a public IPv6</label></div>' +
                (proxy ? '<div class="form-check"><input class="form-check-input" type="checkbox" id="dnsPointProxy"><label class="form-check-label" for="dnsPointProxy">Cloudflare proxy (orange cloud)</label></div>' : '') + '</div>',
            preConfirm: function () { return { ipv6: $('#dnsPointV6').is(':checked') ? 1 : 0, proxied: $('#dnsPointProxy').length ? ($('#dnsPointProxy').is(':checked') ? 1 : 0) : undefined }; }
        }).then(function (r) {
            if (!r.isConfirmed) return;
            var payload = { ipv6: r.value.ipv6 };
            if (r.value.proxied !== undefined) payload.proxied = r.value.proxied;
            GBX.post(url('zones/' + z.id + '/point'), payload).done(function (res) {
                Swal.fire({ title: 'DNS updated', html: '<ul class="text-start small mb-0">' + res.messages.map(function (m) { return '<li>' + esc(m) + '</li>'; }).join('') + '</ul>', icon: 'success', buttonsStyling: false, customClass: { confirmButton: 'btn btn-primary' } });
                if (done) done();
            });
        });
    };
    $('#dnsPoint').on('click', function () { pointZone(zone, loadRecords); });

    /* ============================================================== domains */
    function domainsTab() {
        var zones = [], lookups = {};
        var render = function () {
            var q = ($('#dnsSearch').val() || '').toLowerCase(), provider = $('#dnsProviderFilter').val();
            var rows = zones.filter(function (z) { return (!provider || String(z.provider_id) === provider) && (!q || z.name.indexOf(q) !== -1); });
            $('#dnsZones').html(rows.length ? rows.map(function (z) {
                var t = typeOf(z.provider_type), look = lookups[z.id];
                var points = !z.manageable ? '<span class="cell-sub">-</span>' : look === undefined ? '<span class="cell-sub"><i class="bi bi-arrow-repeat spin"></i></span>'
                    : !look.length ? '<span class="badge badge-soft">No A record</span>'
                    : '<span class="badge ' + (look.indexOf(D.serverIp) !== -1 ? 'badge-success" title="Points to this server"><i class="bi bi-check2"></i> ' : 'badge-warning" title="Another server"> ') + esc(look.slice(0, 2).join(', ')) + '</span>';
                return '<tr data-id="' + z.id + '">' +
                    '<td><a href="#" class="dns-zone-link' + (z.manageable && z.provider_active ? '' : ' disabled') + '">' + esc(z.name) + '</a>' + (z.note ? '<div class="cell-sub dns-error" title="' + esc(z.note) + '">' + esc(z.note) + '</div>' : '') + '</td>' +
                    '<td class="text-nowrap">' + logo(t, true) + ' ' + esc(z.provider) + (z.provider_active ? '' : ' <span class="badge badge-soft">disabled</span>') + '</td>' +
                    '<td>' + (z.records === null ? '<span class="cell-sub">-</span>' : z.records) + '</td>' +
                    '<td class="small">' + (z.websites.length ? z.websites.map(function (w) { return '<a href="' + D.websites + '?search=' + encodeURIComponent(w) + '">' + esc(w) + '</a>'; }).join('<br>') : '<span class="cell-sub">-</span>') + '</td>' +
                    '<td>' + points + '</td>' +
                    '<td class="small text-nowrap">' + esc(z.synced_at || '-') + '</td>' +
                    '<td class="text-end text-nowrap db-ops">' + (z.manageable && z.provider_active ? '<a href="#" data-records>Records</a>' + (D.canWrite ? '<a href="#" data-point>Point to server</a>' : '') : '<span class="cell-sub">-</span>') + '</td></tr>';
            }).join('') : '<tr><td colspan="7" class="sm-empty"><i class="bi bi-inbox"></i> No domains. Sync the DNS API accounts or check that the API key can read the zones.</td></tr>');
        };
        var lookupAll = function () {
            zones.filter(function (z) { return z.manageable && lookups[z.id] === undefined; }).slice(0, 60).forEach(function (z) {
                GBX.get(url('lookup'), { host: z.name }, { silent: true }).done(function (r) { lookups[z.id] = r.ips; render(); }).fail(function () { lookups[z.id] = []; render(); });
            });
        };
        var load = function () {
            GBX.get(url('zones')).done(function (r) {
                zones = r.data;
                var providers = {};
                zones.forEach(function (z) { providers[z.provider_id] = z.provider; });
                var selected = $('#dnsProviderFilter').val() || new URLSearchParams(location.search).get('provider') || '';
                $('#dnsProviderFilter').html('<option value="">All providers</option>' + Object.keys(providers).map(function (id) { return '<option value="' + id + '"' + (id === selected ? ' selected' : '') + '>' + esc(providers[id]) + '</option>'; }).join(''));
                render();
                lookupAll();
            });
        };
        var zoneOf = function (el) { var id = $(el).closest('tr').data('id'); return zones.find(function (z) { return z.id === id; }); };

        $('#dnsSearch').on('input', render);
        $('#dnsProviderFilter').on('change', render);
        $('#dnsZones').on('click', '.dns-zone-link:not(.disabled), [data-records]', function (e) { e.preventDefault(); openZone(zoneOf(this)); })
            .on('click', '[data-point]', function (e) {
                e.preventDefault();
                var z = zoneOf(this);
                pointZone(z, function () { delete lookups[z.id]; setTimeout(lookupAll, 1500); });
            });
        $('#dnsSyncAll').on('click', function () {
            var $b = $(this);
            GBX.busy($b, true);
            GBX.post(url('zones/sync')).done(function (r) { toastr.success(r.message); }).always(function () { GBX.busy($b, false); load(); });
        });
        $(document).on('dns:records', function (e, id, count) { var z = zones.find(function (x) { return x.id === id; }); if (z) { z.records = count; render(); } });
        load();
    }

    if (D.tab === 'providers') providersTab();
    else if ($('#dnsZones').length) domainsTab();
})(jQuery);
