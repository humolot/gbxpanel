/* ==========================================================================
   GBX Panel - core jQuery helpers
   ========================================================================== */
(function ($, window) {
    'use strict';

    var GBX = window.GBX = window.GBX || {};

    /* ---------------------------------------------------------------- setup */
    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    });

    if (window.toastr) {
        toastr.options = { positionClass: 'toast-bottom-right', timeOut: 4000, extendedTimeOut: 2000, progressBar: true, closeButton: false, newestOnTop: true, preventDuplicates: true };
    }

    /* -------------------------------------------------------------- helpers */
    GBX.escape = function (value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    };

    GBX.bytes = function (bytes, precision) {
        bytes = Number(bytes) || 0;
        var units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'], i = 0;
        while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++; }
        return bytes.toFixed(i === 0 ? 0 : (precision == null ? 2 : precision)) + ' ' + units[i];
    };

    GBX.duration = function (seconds) {
        seconds = Math.floor(seconds || 0);
        var d = Math.floor(seconds / 86400), h = Math.floor(seconds % 86400 / 3600), m = Math.floor(seconds % 3600 / 60);
        if (d > 0) return d + 'd ' + h + 'h';
        if (h > 0) return h + 'h ' + m + 'm';
        if (m > 0) return m + 'm';
        return seconds + 's';
    };

    GBX.date = function (ts) {
        var d = new Date(ts * 1000), p = function (n) { return n < 10 ? '0' + n : n; };
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
    };

    GBX.level = function (percent) {
        return percent >= 90 ? 'crit' : (percent >= 70 ? 'warn' : '');
    };

    GBX.password = function (length) {
        var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789', out = '', rnd = new Uint32Array(length || 16);
        window.crypto.getRandomValues(rnd);
        for (var i = 0; i < rnd.length; i++) out += chars[rnd[i] % chars.length];
        return out;
    };

    GBX.copy = function (text) {
        var done = function () { toastr.success('Copied to clipboard'); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done);
        } else {
            var $t = $('<textarea>').val(text).css({ position: 'fixed', opacity: 0 }).appendTo('body');
            $t[0].select();
            document.execCommand('copy');
            $t.remove();
            done();
        }
    };

    GBX.statusBadge = function (status) {
        var map = { success: 'badge-success', failed: 'badge-danger', running: 'badge-info', queued: 'badge-soft' };
        return '<span class="badge ' + (map[status] || 'badge-soft') + '">' + GBX.escape(status) + '</span>';
    };

    /* ----------------------------------------------------------------- ajax */
    GBX.errorMessage = function (xhr) {
        var json = xhr.responseJSON || {};
        if (json.errors) {
            return Object.keys(json.errors).map(function (k) { return json.errors[k][0]; }).join('<br>');
        }
        if (json.message) return json.message;
        if (xhr.status === 0) return 'Connection lost. Is the panel reachable?';
        if (xhr.status === 419) return 'Session expired, reload the page.';
        return 'Request failed (' + xhr.status + ')';
    };

    GBX.request = function (method, url, data, options) {
        options = options || {};
        var ajax = {
            url: url,
            method: method,
            data: data,
            dataType: 'json',
            timeout: options.timeout || 300000
        };
        if (data instanceof FormData) {
            ajax.processData = false;
            ajax.contentType = false;
        }
        if (options.xhr) ajax.xhr = options.xhr;

        return $.ajax(ajax).fail(function (xhr, textStatus) {
            if (textStatus === 'abort' || options.silent) return;
            if (xhr.status === 401) { window.location.reload(); return; }
            if (xhr.responseJSON && xhr.responseJSON.redirect) { window.location.href = xhr.responseJSON.redirect; return; }
            toastr.error(GBX.errorMessage(xhr));
        }).done(function (res) {
            if (res && res.task) GBX.task(res.task, options.onTaskDone);
        });
    };

    GBX.get = function (url, data, options) { return GBX.request('GET', url, data, options); };
    GBX.post = function (url, data, options) { return GBX.request('POST', url, data, options); };
    GBX.put = function (url, data, options) { return GBX.request('POST', url, $.extend({ _method: 'PUT' }, data), options); };
    GBX.del = function (url, data, options) { return GBX.request('POST', url, $.extend({ _method: 'DELETE' }, data), options); };

    /* -------------------------------------------------------------- dialogs */
    GBX.confirm = function (opts) {
        opts = typeof opts === 'string' ? { text: opts } : opts;
        return Swal.fire({
            title: opts.title || 'Are you sure?',
            html: opts.html || GBX.escape(opts.text || ''),
            icon: opts.icon || (opts.danger ? 'warning' : 'question'),
            showCancelButton: true,
            confirmButtonText: opts.confirmText || 'Confirm',
            cancelButtonText: 'Cancel',
            reverseButtons: true,
            focusCancel: !!opts.danger,
            buttonsStyling: false,
            customClass: { confirmButton: 'btn ' + (opts.danger ? 'btn-danger' : 'btn-primary'), cancelButton: 'btn btn-outline-secondary' },
            input: opts.input,
            inputPlaceholder: opts.inputPlaceholder,
            inputValue: opts.inputValue || '',
            inputValidator: opts.inputValidator,
            preConfirm: opts.preConfirm
        });
    };

    GBX.prompt = function (title, value, placeholder) {
        return GBX.confirm({ title: title, input: 'text', inputValue: value, inputPlaceholder: placeholder, icon: null, confirmText: 'Save',
            inputValidator: function (v) { if (!v) return 'This field is required'; } });
    };

    /* ------------------------------------------------------ task log modal */
    var taskTimer = null;

    GBX.task = function (task, onDone) {
        var $modal = $('#taskModal'), $out = $modal.find('.task-output'), offset = 0;
        clearTimeout(taskTimer);
        $modal.find('.task-title').text(task.title || ('Task #' + task.id));
        $modal.find('.task-status').html(GBX.statusBadge(task.status || 'queued'));
        $out.text('');
        bootstrap.Modal.getOrCreateInstance($modal[0]).show();

        var poll = function () {
            $.getJSON(GBX.routes.tasks + '/' + task.id, { offset: offset }).done(function (res) {
                if (res.output) {
                    var atBottom = $out[0].scrollHeight - $out.scrollTop() - $out.outerHeight() < 40;
                    $out.append(document.createTextNode(res.output));
                    if (atBottom) $out.scrollTop($out[0].scrollHeight);
                }
                offset = res.offset;
                $modal.find('.task-status').html(GBX.statusBadge(res.status));
                if (res.finished) {
                    GBX.refreshTaskBadge();
                    $(document).trigger('gbx:task-finished', [res]);
                    if (res.status === 'success') toastr.success(res.title + ' finished');
                    else toastr.error(res.title + ' failed');
                    if (typeof onDone === 'function') onDone(res);
                    return;
                }
                taskTimer = setTimeout(poll, 1200);
            }).fail(function () { taskTimer = setTimeout(poll, 3000); });
        };
        $modal.off('hidden.bs.modal.task').on('hidden.bs.modal.task', function () { clearTimeout(taskTimer); });
        poll();
        GBX.refreshTaskBadge();
    };

    GBX.refreshTaskBadge = function () {
        $.getJSON(GBX.routes.tasks).done(function (res) {
            var $badge = $('#taskBadge');
            res.running > 0 ? $badge.text(res.running).show() : $badge.hide();
            var html = res.data.slice(0, 12).map(function (t) {
                return '<a href="#" class="dropdown-item task-open" data-id="' + t.id + '" data-title="' + GBX.escape(t.title) + '">' +
                    '<div class="min-w-0 flex-grow-1"><div class="text-truncate">' + GBX.escape(t.title) + '</div><div class="cell-sub">' + GBX.escape(t.created) + (t.user ? ' by ' + GBX.escape(t.user) : '') + '</div></div>' +
                    GBX.statusBadge(t.status) + '</a>';
            }).join('');
            $('#taskList').html(html || '<div class="px-3 py-3 text-muted small">No tasks yet</div>');
        });
    };

    /* ------------------------------------------------------ generic forms */
    GBX.serialize = function ($form) {
        var data = {};
        $.each($form.serializeArray(), function (_, f) {
            if (f.name.slice(-2) === '[]') {
                var key = f.name.slice(0, -2);
                (data[key] = data[key] || []).push(f.value);
            } else {
                data[f.name] = f.value;
            }
        });
        $form.find('input[type=checkbox][name]').each(function () {
            if (this.name.slice(-2) !== '[]') data[this.name] = this.checked ? 1 : 0;
        });
        return data;
    };

    GBX.busy = function ($btn, busy) {
        if (!$btn || !$btn.length) return;
        if (busy) {
            $btn.data('html', $btn.html()).prop('disabled', true).html('<i class="bi bi-arrow-repeat spin"></i> ' + ($btn.data('busy') || 'Working'));
        } else if ($btn.data('html')) {
            $btn.prop('disabled', false).html($btn.data('html'));
        }
    };

    $(document).on('submit', 'form[data-ajax]', function (e) {
        e.preventDefault();
        var $form = $(this), $btn = $form.find('[type=submit]').first();
        var method = ($form.data('method') || $form.attr('method') || 'POST').toUpperCase();
        var data = GBX.serialize($form);
        if (method !== 'POST' && method !== 'GET') data._method = method;

        $form.find('.is-invalid').removeClass('is-invalid');
        GBX.busy($btn, true);

        GBX.request(method === 'GET' ? 'GET' : 'POST', $form.attr('action'), data, { silent: true })
            .done(function (res) {
                if (res.message && !res.task) toastr.success(res.message);
                var $modal = $form.closest('.modal');
                if ($modal.length && $form.data('keep-open') === undefined) bootstrap.Modal.getOrCreateInstance($modal[0]).hide();
                if (!$form.data('no-reset') && method === 'POST') $form[0].reset();
                var cb = $form.data('success');
                if (cb && typeof window[cb] === 'function') window[cb](res, $form);
                else if ($form.data('reload') !== undefined) setTimeout(function () { window.location.reload(); }, 600);
            })
            .fail(function (xhr) {
                var json = xhr.responseJSON || {};
                if (json.errors) {
                    $.each(json.errors, function (field) {
                        $form.find('[name="' + field + '"]').addClass('is-invalid');
                    });
                }
                toastr.error(GBX.errorMessage(xhr));
            })
            .always(function () { GBX.busy($btn, false); });
    });

    /* data-post buttons: <button data-post="url" data-confirm="text" data-reload> */
    $(document).on('click', '[data-post]', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var run = function () {
            GBX.busy($btn, true);
            var method = ($btn.data('method') || 'POST').toUpperCase();
            var payload = $btn.data('payload') || {};
            var req = method === 'DELETE' ? GBX.del($btn.data('post'), payload) : GBX.post($btn.data('post'), payload);
            req.done(function (res) {
                if (res.message && !res.task) toastr.success(res.message);
                if ($btn.data('reload') !== undefined) setTimeout(function () { window.location.reload(); }, 700);
                var cb = $btn.data('success');
                if (cb && typeof window[cb] === 'function') window[cb](res, $btn);
            }).always(function () { GBX.busy($btn, false); });
        };
        if ($btn.data('confirm')) {
            GBX.confirm({ text: $btn.data('confirm'), danger: $btn.data('danger') !== undefined, confirmText: $btn.data('confirm-text') })
                .then(function (r) { if (r.isConfirmed) run(); });
        } else {
            run();
        }
    });

    $(document).on('click', '[data-copy]', function (e) {
        e.preventDefault();
        GBX.copy($(this).data('copy') || $($(this).data('copy-target')).val());
    });

    $(document).on('click', '[data-generate]', function () {
        $($(this).data('generate')).val(GBX.password(18)).attr('type', 'text').trigger('input');
    });

    $(document).on('click', '[data-toggle-password]', function () {
        var $input = $($(this).data('toggle-password'));
        $input.attr('type', $input.attr('type') === 'password' ? 'text' : 'password');
        $(this).find('i').toggleClass('bi-eye bi-eye-slash');
    });

    /* ----------------------------------------------------------- DataTables */
    if ($.fn.dataTable) {
        $.extend(true, $.fn.dataTable.defaults, {
            pageLength: 25,
            lengthMenu: [10, 25, 50, 100],
            autoWidth: false,
            language: { search: '', searchPlaceholder: 'Search...', lengthMenu: '_MENU_ per page', emptyTable: 'Nothing here yet', zeroRecords: 'No matching records' }
        });
    }

    /* ---------------------------------------------------------- code editor */
    // The code editor runs in a large modal. The editor page is loaded once in an iframe
    // (keeps Monaco's AMD loader away from the panel scripts) and reused: tabs stay open
    // when the modal is closed and opened again.
    GBX.editor = (function () {
        var $modal = null, frame = null, queue = [];

        function api() {
            try { return frame && frame.contentWindow && frame.contentWindow.GBX_EDITOR_API; } catch (e) { return null; }
        }

        function build() {
            if ($modal) return;
            $modal = $(
                '<div class="modal fade gbx-editor-modal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">' +
                '<div class="modal-dialog"><div class="modal-content">' +
                '<div class="gbx-editor-bar">' +
                '<span class="gbx-editor-title"><i class="bi bi-code-slash"></i> Code Editor</span>' +
                '<span class="gbx-editor-path font-mono"></span>' +
                '<button type="button" class="gbx-editor-btn" data-editor="max" title="Maximize"><i class="bi bi-fullscreen"></i></button>' +
                '<button type="button" class="gbx-editor-btn" data-editor="close" title="Close"><i class="bi bi-x-lg"></i></button>' +
                '</div>' +
                '<div class="gbx-editor-body"><div class="gbx-editor-loading"><i class="bi bi-arrow-repeat spin"></i> Loading editor...</div><iframe title="Code editor"></iframe></div>' +
                '</div></div></div>'
            ).appendTo('body');
            frame = $modal.find('iframe')[0];

            $modal.on('click', '[data-editor=max]', function () {
                var max = !$modal.hasClass('maximized');
                $modal.toggleClass('maximized', max);
                $(this).attr('title', max ? 'Restore' : 'Maximize').find('i').attr('class', 'bi ' + (max ? 'bi-fullscreen-exit' : 'bi-fullscreen'));
            });
            $modal.on('click', '[data-editor=close]', close);
            $modal.on('shown.bs.modal', function () { var a = api(); if (a) a.focus(); });

            frame.addEventListener('load', function () {
                $modal.find('.gbx-editor-loading').remove();
                var tries = 0;
                (function flush() {
                    var a = api();
                    if (!a && tries++ < 50) return setTimeout(flush, 100);
                    if (a) queue.splice(0).forEach(function (fn) { fn(a); });
                })();
            });

            window.addEventListener('beforeunload', function (e) {
                var a = api();
                if (a && a.dirtyCount() > 0) { e.preventDefault(); e.returnValue = ''; }
            });
        }

        function open(opts) {
            opts = opts || {};
            build();
            bootstrap.Modal.getOrCreateInstance($modal[0]).show();
            var run = function (a) {
                if (opts.open) a.open(opts.open, opts.root); else if (opts.root) a.setRoot(opts.root);
                a.focus();
            };
            var a = api();
            if (a) return run(a);
            queue.push(run);
            if (!frame.getAttribute('src')) {
                var params = { embed: 1 };
                if (opts.root) params.root = opts.root;
                if (opts.open) params.open = opts.open;
                queue.length = 0; // the first load opens the requested file itself
                frame.setAttribute('src', GBX.routes.editor + '?' + $.param(params));
            }
        }

        function hide() { bootstrap.Modal.getOrCreateInstance($modal[0]).hide(); }

        function close() {
            var a = api(), dirty = a ? a.dirtyCount() : 0;
            if (!dirty) return hide();
            Swal.fire({
                title: 'Unsaved changes', icon: 'warning',
                html: dirty + ' file(s) have unsaved changes.',
                showDenyButton: true, showCancelButton: true,
                confirmButtonText: 'Save all and close', denyButtonText: 'Close without saving', cancelButtonText: 'Cancel',
                buttonsStyling: false,
                customClass: { confirmButton: 'btn btn-primary me-2', denyButton: 'btn btn-outline-danger me-2', cancelButton: 'btn btn-outline-secondary' }
            }).then(function (r) {
                if (r.isConfirmed) a.saveAll().then(function (left) { if (!left) hide(); });
                else if (r.isDenied) { a.discardAll(); hide(); }
            });
        }

        // called from inside the editor to show the active file in the title bar
        function title(path) { if ($modal) $modal.find('.gbx-editor-path').text(path || ''); }

        return { open: open, close: close, title: title };
    })();

    $(document).on('click', '[data-edit-file], [data-edit-root]', function (e) {
        e.preventDefault();
        GBX.editor.open({ open: $(this).data('edit-file') || null, root: $(this).data('edit-root') || null });
    });

    /* --------------------------------------------------------------- layout */
    $(function () {
        $('[data-sidebar-toggle]').on('click', function () { $('body').toggleClass('sidebar-open'); });
        $('.gbx-backdrop').on('click', function () { $('body').removeClass('sidebar-open'); });

        $('[data-bs-toggle="tooltip"]').each(function () { new bootstrap.Tooltip(this); });

        $('#tasksDropdown').on('show.bs.dropdown', GBX.refreshTaskBadge);
        $(document).on('click', '.task-open', function (e) {
            e.preventDefault();
            GBX.task({ id: $(this).data('id'), title: $(this).data('title') });
        });

        if (GBX.routes && GBX.routes.tasks) {
            GBX.refreshTaskBadge();
            setInterval(function () { if (!document.hidden) GBX.refreshTaskBadge(); }, 15000);
        }
    });
})(jQuery, window);
