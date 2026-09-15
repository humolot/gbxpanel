/*
 * GBX Panel - Cron: Cron Job, Task Scheduling and Script library tabs.
 */
(function ($) {
    'use strict';

    var C = window.GBX_CRON, R = C.routes, esc = GBX.escape;
    var reload = function () { setTimeout(function () { window.location.reload(); }, 600); };
    var rowOf = function (el) { return $(el).closest('tr[data-id]'); };
    var jobUrl = function (id, path) { return C.base + '/' + id + (path ? '/' + path : ''); };
    var scriptUrl = function (id, path) { return C.base + '/scripts/' + id + (path ? '/' + path : ''); };

    /* ============================================================ list filters */
    var category = '';
    var filter = function () {
        var q = ($('#cronSearch').val() || '').toLowerCase().trim(), type = $('#cronTypeFilter').val() || category, shown = 0;
        $('#cronTable tbody tr[data-id]').each(function () {
            var $r = $(this), ok = (!q || String($r.data('search')).indexOf(q) !== -1) && (!type || $r.data('type') === type);
            $r.prop('hidden', !ok);
            if (ok) shown++;
        });
        $('#cronTable .cron-nomatch').prop('hidden', shown > 0 || !$('#cronTable tbody tr[data-id]').length);
    };
    $('#cronSearch').on('input', filter);
    $('#cronTypeFilter').on('change', filter);
    $('#scriptCats').on('click', 'a', function (e) {
        e.preventDefault();
        $(this).addClass('active').siblings().removeClass('active');
        category = $(this).data('cat');
        filter();
    });

    /* ================================================================== bulk */
    var selected = function () { return $('.cron-check:checked').map(function () { return +this.value; }).get(); };
    var updateBulk = function () {
        var n = selected().length;
        $('[data-selected]').text(n + ' selected');
        $('[data-bulk-run]').prop('disabled', !n || !$('[data-bulk-action]').val());
    };
    $(document).on('change', '.cron-check, [data-bulk-action]', updateBulk);
    $('[data-check-all]').on('change', function () {
        $('#cronTable tbody tr[data-id]:not([hidden]) .cron-check').prop('checked', this.checked);
        updateBulk();
    });
    $('[data-bulk-run]').on('click', function () {
        var ids = selected(), action = $('[data-bulk-action]').val(), $btn = $(this);
        if (!ids.length || !action) return;
        if (action === 'export') {
            window.location.href = R.export + '?ids=' + ids.join(',');
            return;
        }
        var labels = { run: 'Execute', enable: 'Enable', disable: 'Disable', delete: 'Delete' };
        GBX.confirm({ text: labels[action] + ' ' + ids.length + ' selected task(s)?', danger: action === 'delete', confirmText: labels[action] }).then(function (r) {
            if (!r.isConfirmed) return;
            GBX.busy($btn, true);
            GBX.post(R.bulk, { ids: ids, action: action }).done(function (res) {
                if (!res.task) toastr.success(res.message);
                if (action !== 'run') reload();
            }).always(function () { GBX.busy($btn, false); });
        });
    });

    /* ========================================================== job actions */
    $(document).on('click', '.cron-toggle', function (e) {
        e.preventDefault();
        GBX.post(jobUrl(rowOf(this).data('id'), 'toggle')).done(function (res) { toastr.success(res.message); reload(); });
    });

    $(document).on('click', '.cron-run', function (e) {
        e.preventDefault();
        var $r = rowOf(this);
        GBX.confirm({ text: 'Execute "' + $r.data('name') + '" now?', confirmText: 'Execute' }).then(function (r) {
            if (r.isConfirmed) GBX.post(jobUrl($r.data('id'), 'run'), {}, { onTaskDone: function () { $(document).one('hidden.bs.modal', '#taskModal', reload); } });
        });
    });

    $(document).on('click', '.cron-delete', function (e) {
        e.preventDefault();
        var $r = rowOf(this);
        GBX.confirm({ title: 'Delete task', text: 'Delete "' + $r.data('name') + '" and its log?', danger: true, confirmText: 'Delete' }).then(function (r) {
            if (r.isConfirmed) GBX.del(jobUrl($r.data('id'))).done(function (res) { toastr.success(res.message); reload(); });
        });
    });

    var logUrl = null;
    var loadLog = function () {
        $('#cronLogOut').text('Loading...');
        GBX.get(logUrl).done(function (r) {
            var $out = $('#cronLogOut').text(r.log || 'The log is empty.');
            $out.scrollTop($out[0].scrollHeight);
        });
    };
    $(document).on('click', '.cron-log', function (e) {
        e.preventDefault();
        var $r = rowOf(this);
        logUrl = jobUrl($r.data('id'), 'log');
        $('#cronLogTitle').text('Log: ' + $r.data('name'));
        bootstrap.Modal.getOrCreateInstance('#cronLogModal').show();
        loadLog();
    });
    $('#cronLogRefresh').on('click', loadLog);
    $('#cronLogClear').on('click', function () {
        GBX.del(logUrl).done(function (r) { toastr.success(r.message); $('#cronLogOut').text('The log is empty.'); });
    });

    $('#cronImport').on('click', function () {
        Swal.fire({
            title: 'Import tasks', input: 'file', inputAttributes: { accept: '.json,application/json' },
            html: '<p class="small">Choose a file exported from GBX Panel. Library scripts included in the file are added when missing.</p>',
            showCancelButton: true, confirmButtonText: 'Import', buttonsStyling: false, reverseButtons: true,
            customClass: { confirmButton: 'btn btn-primary', cancelButton: 'btn btn-outline-secondary' },
            inputValidator: function (f) { if (!f) return 'Choose a file'; }
        }).then(function (r) {
            if (!r.isConfirmed) return;
            var fd = new FormData();
            fd.append('file', r.value);
            GBX.post(R.import, fd).done(function (res) { toastr.success(res.message); reload(); });
        });
    });

    /* ========================================================= cycles editor */
    var cycleRow = function (c) {
        c = $.extend({ type: 'day', n: 1, hour: 1, minute: 30, weekday: 1, day: 1, expr: '' }, c || {});
        if (c.type === 'custom' && !c.expr) c.expr = c.cron || '';
        var num = function (cls, v, min, max) { return '<input type="number" class="form-control c-small ' + cls + '" min="' + min + '" max="' + max + '" value="' + esc(String(v)) + '">'; };
        var weekdays = C.weekdays.map(function (d, i) { return '<option value="' + i + '"' + (+c.weekday === i ? ' selected' : '') + '>' + d + '</option>'; }).join('');
        var types = { minute_n: 'N minutes', hour_n: 'N hours', day: 'Daily', day_n: 'N days', week: 'Weekly', month: 'Monthly', custom: 'Cron expression' };
        var $row = $('<div class="cron-cycle">' +
            '<select class="form-select c-type">' + $.map(types, function (l, k) { return '<option value="' + k + '"' + (c.type === k ? ' selected' : '') + '>' + l + '</option>'; }).join('') + '</select>' +
            '<span data-for="minute_n hour_n day_n">' + num('c-n', c.n, 1, 59) + '</span>' +
            '<span class="c-label" data-for="minute_n">minutes</span>' +
            '<span class="c-label" data-for="hour_n">hours, at minute</span>' +
            '<span class="c-label" data-for="day_n">days</span>' +
            '<span data-for="week"><select class="form-select c-weekday">' + weekdays + '</select></span>' +
            '<span data-for="month" class="c-label">day</span><span data-for="month">' + num('c-day', c.day, 1, 31) + '</span>' +
            '<span data-for="day day_n week month">' + num('c-hour', c.hour, 0, 23) + '</span><span class="c-label" data-for="day day_n week month">:</span>' +
            '<span data-for="hour_n day day_n week month">' + num('c-minute', c.minute, 0, 59) + '</span>' +
            '<span data-for="custom"><input type="text" class="form-control font-mono c-expr" placeholder="*/5 * * * *" value="' + esc(c.expr) + '"></span>' +
            '<button type="button" class="c-remove" title="Remove"><i class="bi bi-x-lg"></i></button>' +
            '</div>');
        var sync = function () {
            var t = $row.find('.c-type').val();
            $row.find('[data-for]').each(function () { $(this).prop('hidden', String($(this).data('for')).split(' ').indexOf(t) === -1); });
        };
        $row.find('.c-type').on('change', sync);
        sync();
        return $row;
    };
    var updateRemove = function () { $('#cronCycles .c-remove').prop('hidden', $('#cronCycles .cron-cycle').length < 2); };
    $('#cronAddCycle').on('click', function () {
        if ($('#cronCycles .cron-cycle').length >= 10) return toastr.warning('At most 10 execution cycles');
        $('#cronCycles').append(cycleRow());
        updateRemove();
    });
    $('#cronCycles').on('click', '.c-remove', function () { $(this).closest('.cron-cycle').remove(); updateRemove(); });
    var readCycles = function () {
        return $('#cronCycles .cron-cycle').map(function () {
            var $r = $(this);
            return { type: $r.find('.c-type').val(), n: $r.find('.c-n').val(), hour: $r.find('.c-hour').val(), minute: $r.find('.c-minute').val(), weekday: $r.find('.c-weekday').val(), day: $r.find('.c-day').val(), expr: $r.find('.c-expr').val() };
        }).get();
    };

    /* ============================================================ job modal */
    var $form = $('#cronForm'), editing = null;

    var showSections = function () {
        var type = $form.find('[name=type]').val();
        $form.find('[data-section]').each(function () {
            var on = String($(this).data('section')).split(' ').indexOf(type) !== -1;
            $(this).prop('hidden', !on).find('input, select, textarea').prop('disabled', !on);
        });
        var rootOnly = C.rootOnly.indexOf(type) !== -1;
        $form.find('[data-user-row]').prop('hidden', rootOnly).find('select').prop('disabled', rootOnly);
        $form.find('[data-hide-flow]').prop('hidden', type === 'flow');
        hint($form.find('[name=script_id]'), $form.find('[name=args]'));
        hint($form.find('[name=then_script_id]'), $form.find('[name=then_args]'));
        storageOptions();
        $form.find('[name=match]').prop('hidden', ['contains', 'not_contains'].indexOf($form.find('[name=condition]').val()) === -1);
        filterDatabases();
    };
    var hint = function ($select, $args) {
        var h = $select.find('option:selected').data('hint');
        $args.attr('placeholder', h ? h : 'No arguments').prop('hidden', !h && !$args.val());
    };
    var filterDatabases = function () {
        var engine = $form.find('[name=engine]').val(), $db = $form.find('[name=database]');
        $db.find('option').each(function () { var e = $(this).data('engine'); $(this).prop('hidden', !!e && engine !== 'all' && e !== engine); });
        if ($db.find('option:selected').prop('hidden')) $db.val('all');
    };
    $form.on('change', '[name=type], [name=script_id], [name=then_script_id], [name=condition]', showSections);
    $form.on('change', '[name=engine]', filterDatabases);
    $form.on('change', '[name=script_id]', function () {
        var m = $(this).find('option:selected').data('match');
        if ($form.find('[name=type]').val() === 'flow' && m && !$form.find('[name=match]').val()) $form.find('[name=match]').val(m);
    });

    $('#cronInsertScript').on('change', function () {
        var id = this.value, $sel = $(this);
        if (!id) return;
        GBX.get(scriptUrl(id)).done(function (r) {
            var $ta = $form.find('[name=command]'), cur = $ta.val();
            $ta.val((cur ? cur.replace(/\s+$/, '') + String.fromCharCode(10) + String.fromCharCode(10) : '') + r.script.content).trigger('focus');
            if (!$form.find('[name=name]').val()) $form.find('[name=name]').val(r.script.name);
        }).always(function () { $sel.val(''); });
    });

    // the options of a remote destination only apply when one is chosen
    var storageOptions = function () { $('.cron-remote').prop('hidden', !$('#cronStorage').val()); };
    $(document).on('change', '#cronStorage', storageOptions);

    var openJob = function (type, job) {
        editing = job ? job.id : null;
        $form[0].reset();
        $form.find('[name=args], [name=then_args]').val('');
        $('#cronCycles').empty();
        var isFlow = type === 'flow';
        $('#cronTitle').text((job ? 'Edit ' : 'Add ') + (isFlow ? 'task scheduling' : 'Task'));
        if (isFlow) $form.find('[name=type]').append($('<option value="flow">Task Scheduling</option>').attr('data-temp', 1));
        $form.find('[name=type]').val(type).prop('disabled', false);

        if (job) {
            var p = job.params || {};
            $form.find('[name=name]').val(job.name);
            $form.find('[name=command]').val(type === 'shell' ? job.command : '');
            $form.find('[name=run_as]').val(job.run_as);
            $form.find('[name=keep]').val(job.keep || 3);
            $form.find('[name=notes]').val(job.notes || '');
            $form.find('[name=is_active]').prop('checked', !!job.is_active);
            $.each(p, function (k, v) {
                var $f = $form.find('[name="' + k + '"]');
                if ($f.is(':checkbox')) $f.prop('checked', !!v);
                else if (v !== null) $f.val(String(v));
            });
            (job.cycles || []).forEach(function (c) { $('#cronCycles').append(cycleRow(c)); });
        } else {
            $('#cronCycles').append(cycleRow(isFlow ? { type: 'minute_n', n: 5 } : null));
            if (isFlow) $form.find('[name=condition]').val('not_contains');
            if (isFlow) setTimeout(function () { $form.find('[name=script_id]').trigger('change'); });
        }
        if (!$('#cronCycles .cron-cycle').length) $('#cronCycles').append(cycleRow());
        updateRemove();
        showSections();
        bootstrap.Modal.getOrCreateInstance('#cronModal').show();
    };
    $('#cronModal').on('hidden.bs.modal', function () { $form.find('[name=type] option[data-temp]').remove(); });

    $('[data-cron-add]').on('click', function () { openJob($(this).data('cron-add')); });
    $(document).on('click', '.cron-edit', function (e) {
        e.preventDefault();
        GBX.get(jobUrl(rowOf(this).data('id'))).done(function (r) { openJob(r.job.type, r.job); });
    });

    $form.on('submit', function (e) {
        e.preventDefault();
        var $btn = $form.find('[type=submit]'), data = GBX.serialize($form);
        data.type = $form.find('[name=type]').val();
        data.cycles = JSON.stringify(readCycles());
        if (editing) data._method = 'PUT';
        $form.find('.is-invalid').removeClass('is-invalid');
        GBX.busy($btn, true);
        GBX.post(editing ? jobUrl(editing) : R.store, data, { silent: true }).done(function (res) {
            toastr.success(res.message);
            bootstrap.Modal.getOrCreateInstance('#cronModal').hide();
            reload();
        }).fail(function (xhr) {
            $.each((xhr.responseJSON || {}).errors || {}, function (f) { $form.find('[name="' + f + '"]').addClass('is-invalid'); });
            toastr.error(GBX.errorMessage(xhr));
        }).always(function () { GBX.busy($btn, false); });
    });

    /* ======================================================== script library */
    var $sform = $('#scriptForm'), editingScript = null;

    var openScript = function (script) {
        editingScript = script ? script.id : null;
        $sform[0].reset();
        $('#scriptTitle').text(script ? (C.canWrite ? 'Edit script' : 'Script') : 'Create Script');
        if (script) {
            $.each(['name', 'category', 'language', 'content', 'remark', 'success_match', 'args_hint'], function (_, k) { $sform.find('[name=' + k + ']').val(script[k] || ''); });
        }
        $sform.find('input, select, textarea').prop('readonly', !C.canWrite);
        $sform.find('select').prop('disabled', !C.canWrite);
        $sform.find('[type=submit]').prop('hidden', !C.canWrite);
        bootstrap.Modal.getOrCreateInstance('#scriptModal').show();
    };
    $('#scriptAdd').on('click', function () { openScript(null); });
    $(document).on('click', '.script-edit', function (e) {
        e.preventDefault();
        GBX.get(scriptUrl(rowOf(this).data('id'))).done(function (r) { openScript(r.script); });
    });
    $sform.on('submit', function (e) {
        e.preventDefault();
        var $btn = $sform.find('[type=submit]'), data = GBX.serialize($sform);
        if (editingScript) data._method = 'PUT';
        GBX.busy($btn, true);
        GBX.post(editingScript ? scriptUrl(editingScript) : R.scripts, data, { silent: true }).done(function (res) {
            toastr.success(res.message);
            bootstrap.Modal.getOrCreateInstance('#scriptModal').hide();
            reload();
        }).fail(function (xhr) { toastr.error(GBX.errorMessage(xhr)); }).always(function () { GBX.busy($btn, false); });
    });

    $(document).on('click', '.script-run', function (e) {
        e.preventDefault();
        var $r = rowOf(this), h = $r.data('hint');
        GBX.confirm({
            title: 'Execute script', html: 'Run <strong>' + esc($r.data('name')) + '</strong> now as root?', confirmText: 'Execute', icon: null,
            input: h ? 'text' : undefined, inputPlaceholder: h || undefined
        }).then(function (r) {
            if (r.isConfirmed) GBX.post(scriptUrl($r.data('id'), 'run'), { args: r.value || '' });
        });
    });
    $(document).on('click', '.script-log', function (e) {
        e.preventDefault();
        GBX.get(scriptUrl(rowOf(this).data('id'), 'log'));
    });
    $(document).on('click', '.script-delete', function (e) {
        e.preventDefault();
        var $r = rowOf(this);
        GBX.confirm({ title: 'Delete script', text: 'Delete "' + $r.data('name') + '" from the library?', danger: true, confirmText: 'Delete' }).then(function (r) {
            if (r.isConfirmed) GBX.del(scriptUrl($r.data('id'))).done(function (res) { toastr.success(res.message); reload(); });
        });
    });
})(jQuery);
