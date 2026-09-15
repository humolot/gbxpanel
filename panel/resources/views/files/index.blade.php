@extends('layouts.app')

@section('title', 'Files')

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/vendor/codemirror/codemirror.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vendor/codemirror/theme/material-darker.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vendor/codemirror/addon/dialog.css') }}">
@endpush

@section('content')
    <div class="gbx-card">
        <div class="fm-toolbar">
            <div class="btn-group">
                <button class="btn btn-outline-secondary btn-icon" id="fmUp" title="Parent folder"><i class="bi bi-arrow-up"></i></button>
                <button class="btn btn-outline-secondary btn-icon" id="fmRefresh" title="Refresh"><i class="bi bi-arrow-clockwise"></i></button>
            </div>
            <div class="fm-path" id="fmPath">
                <div class="crumbs" id="fmCrumbs"></div>
                <input type="text" id="fmPathInput" spellcheck="false">
                <button class="btn btn-ghost btn-sm border-0 rounded-0" id="fmEditPath" title="Type a path"><i class="bi bi-input-cursor-text"></i></button>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <div class="dropdown">
                    <button class="btn btn-primary" data-bs-toggle="dropdown"><i class="bi bi-plus-lg"></i> New</button>
                    <div class="dropdown-menu dropdown-menu-end">
                        <a href="#" class="dropdown-item" id="fmNewFolder"><i class="bi bi-folder-plus"></i> Folder</a>
                        <a href="#" class="dropdown-item" id="fmNewFile"><i class="bi bi-file-earmark-plus"></i> File</a>
                    </div>
                </div>
                <button class="btn btn-secondary" id="fmUploadBtn"><i class="bi bi-upload"></i> Upload</button>
                <input type="file" id="fmUpload" multiple hidden>
            </div>
        </div>

        <div class="fm-toolbar py-2" id="fmSelectionBar">
            <span class="cell-sub me-2" id="fmSelInfo">No selection</span>
            <button class="btn btn-sm btn-outline-secondary sel-only" data-fm="copy" disabled><i class="bi bi-copy"></i> Copy</button>
            <button class="btn btn-sm btn-outline-secondary sel-only" data-fm="cut" disabled><i class="bi bi-scissors"></i> Cut</button>
            <button class="btn btn-sm btn-outline-secondary" data-fm="paste" id="fmPaste" disabled><i class="bi bi-clipboard-check"></i> Paste</button>
            <button class="btn btn-sm btn-outline-secondary sel-only" data-fm="compress" disabled><i class="bi bi-file-zip"></i> Compress</button>
            <button class="btn btn-sm btn-outline-secondary sel-only" data-fm="perms" disabled><i class="bi bi-shield-lock"></i> Permissions</button>
            <button class="btn btn-sm btn-outline-danger sel-only" data-fm="delete" disabled><i class="bi bi-trash"></i> Delete</button>
            <div class="ms-auto d-flex align-items-center gap-2">
                <input type="search" class="form-control form-control-sm" id="fmFilter" placeholder="Filter this folder" style="width: 200px">
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover fm-table mb-0">
                <thead>
                <tr>
                    <th style="width:34px"><input type="checkbox" class="form-check-input" id="fmAll"></th>
                    <th>Name</th><th>Size</th><th>Modified</th><th>Permissions</th><th>Owner</th><th class="text-end"></th>
                </tr>
                </thead>
                <tbody id="fmBody"></tbody>
            </table>
        </div>
        <div class="gbx-card-body py-2 cell-sub border-top border-soft d-flex justify-content-between flex-wrap gap-2">
            <span id="fmStats"></span>
            <span>Max upload per file: {{ \App\Services\SystemStats::bytes($maxUpload, 0) }} &middot; Drag and drop files anywhere to upload</span>
        </div>
    </div>

    <div class="fm-drop" id="fmDrop"><div><i class="bi bi-cloud-arrow-up"></i> Drop files to upload to <span class="font-mono" id="fmDropPath"></span></div></div>
@endsection

@push('modals')
    <div class="modal fade" id="editorModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-fullscreen-lg-down modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title min-w-0"><i class="bi bi-file-earmark-code"></i> <span class="font-mono text-truncate" id="editorPath"></span></h5>
                    <span class="badge badge-warning ms-2 d-none" id="editorDirty">unsaved</span>
                    <div class="ms-auto d-flex gap-2 align-items-center">
                        <span class="cell-sub d-none d-md-inline">Ctrl+S to save</span>
                        <button class="btn btn-sm btn-primary" id="editorSave"><i class="bi bi-check2"></i> Save</button>
                        <button type="button" class="btn-close ms-1" id="editorClose"></button>
                    </div>
                </div>
                <div class="modal-body p-2"><textarea id="editorArea"></textarea></div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="permsModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" id="permsForm">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-shield-lock"></i> Permissions</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <table class="table table-sm text-center mb-3">
                        <thead><tr><th></th><th>Read</th><th>Write</th><th>Execute</th></tr></thead>
                        <tbody>
                        @foreach (['Owner' => 0, 'Group' => 1, 'Public' => 2] as $who => $i)
                            <tr><td class="text-start">{{ $who }}</td>
                                @foreach ([4, 2, 1] as $bit)
                                    <td><input type="checkbox" class="form-check-input perm-bit" data-pos="{{ $i }}" data-bit="{{ $bit }}"></td>
                                @endforeach
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    <div class="row g-3">
                        <div class="col-5">
                            <label class="form-label">Mode</label>
                            <input type="text" class="form-control font-mono" id="permMode" maxlength="4" value="644">
                        </div>
                        <div class="col-7">
                            <label class="form-label">Owner (user:group)</label>
                            <input type="text" class="form-control font-mono" id="permOwner" placeholder="www-data:www-data">
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="permRecursive">
                                <label class="form-check-label" for="permRecursive">Apply recursively to folder contents</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Apply</button>
                </div>
            </form>
        </div>
    </div>
@endpush

@push('vendor')
    <script src="{{ asset('assets/vendor/codemirror/codemirror.js') }}"></script>
    <script src="{{ asset('assets/vendor/codemirror/addon/simple.js') }}"></script>
    @foreach (['xml', 'javascript', 'css', 'htmlmixed', 'clike', 'php', 'shell', 'sql', 'yaml', 'properties', 'nginx', 'python', 'markdown', 'dockerfile'] as $mode)
        <script src="{{ asset('assets/vendor/codemirror/mode/'.$mode.'/'.$mode.'.js') }}"></script>
    @endforeach
    <script src="{{ asset('assets/vendor/codemirror/addon/searchcursor.js') }}"></script>
    <script src="{{ asset('assets/vendor/codemirror/addon/dialog.js') }}"></script>
    <script src="{{ asset('assets/vendor/codemirror/addon/search.js') }}"></script>
    <script src="{{ asset('assets/vendor/codemirror/addon/matchbrackets.js') }}"></script>
@endpush

@push('scripts')
<script>
$(function () {
    var R = {
        list: @json(route('files.list')), read: @json(route('files.read')), save: @json(route('files.save')), create: @json(route('files.create')),
        rename: @json(route('files.rename')), del: @json(route('files.delete')), paste: @json(route('files.paste')), perms: @json(route('files.permissions')),
        compress: @json(route('files.compress')), extract: @json(route('files.extract')), upload: @json(route('files.upload')), download: @json(route('files.download')), size: @json(route('files.size')),
        scan: @json(route('security.antivirus.scan'))
    };
    var cwd = @json($path), items = [], clipboard = null, editor = null, editingPath = null;
    var maxUpload = {{ (int) $maxUpload }};

    var icons = { php: 'bi-filetype-php', js: 'bi-filetype-js', css: 'bi-filetype-css', html: 'bi-filetype-html', htm: 'bi-filetype-html', json: 'bi-filetype-json', md: 'bi-filetype-md', sql: 'bi-filetype-sql', sh: 'bi-filetype-sh', py: 'bi-filetype-py', yml: 'bi-filetype-yml', yaml: 'bi-filetype-yml', xml: 'bi-filetype-xml', txt: 'bi-filetype-txt', log: 'bi-file-earmark-text', jpg: 'bi-file-earmark-image', jpeg: 'bi-file-earmark-image', png: 'bi-file-earmark-image', gif: 'bi-file-earmark-image', svg: 'bi-filetype-svg', webp: 'bi-file-earmark-image', zip: 'bi-file-earmark-zip', gz: 'bi-file-earmark-zip', tgz: 'bi-file-earmark-zip', tar: 'bi-file-earmark-zip', rar: 'bi-file-earmark-zip', pdf: 'bi-filetype-pdf', conf: 'bi-file-earmark-code', ini: 'bi-file-earmark-code', env: 'bi-file-earmark-lock' };
    var editable = /\.(php|js|mjs|ts|css|scss|html?|json|md|txt|log|sql|sh|bash|py|ya?ml|xml|conf|ini|env|htaccess|vue|jsx|tsx|twig|blade\.php|lock|csv|svg|service|cnf|properties|toml|gitignore|editorconfig|dockerfile)$|^[^.]+$|^\.[^.]+$/i;
    var archive = /\.(zip|tar|tar\.gz|tgz|tar\.bz2|tar\.xz|gz)$/i;

    function ext(name) { var m = name.toLowerCase().match(/\.([a-z0-9]+)$/); return m ? m[1] : ''; }
    function join(dir, name) { return (dir === '/' ? '' : dir) + '/' + name; }

    function crumbs() {
        var parts = cwd.split('/').filter(Boolean), acc = '';
        var html = '<a href="#" data-path="/"><i class="bi bi-hdd"></i></a>';
        parts.forEach(function (p) { acc += '/' + p; html += '<span class="sep"><i class="bi bi-chevron-right"></i></span><a href="#" data-path="' + GBX.escape(acc) + '">' + GBX.escape(p) + '</a>'; });
        $('#fmCrumbs').html(html).scrollLeft(1e5);
        $('#fmPathInput').val(cwd);
        $('#fmDropPath').text(cwd);
    }

    function render() {
        var filter = $('#fmFilter').val().toLowerCase(), dirs = 0, files = 0, total = 0;
        var rows = items.filter(function (i) { return !filter || i.name.toLowerCase().indexOf(filter) !== -1; }).map(function (i, idx) {
            var isDir = i.type === 'dir', icon = isDir ? 'bi-folder-fill' : (i.type === 'link' ? 'bi-link-45deg' : (icons[ext(i.name)] || 'bi-file-earmark'));
            isDir ? dirs++ : (files++, total += i.size);
            var actions = '<div class="dropdown d-inline-block"><button class="btn btn-sm btn-ghost btn-icon" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button><div class="dropdown-menu dropdown-menu-end">' +
                (isDir ? '<a href="#" class="dropdown-item" data-row="open"><i class="bi bi-folder2-open"></i> Open</a><a href="#" class="dropdown-item" data-row="size"><i class="bi bi-rulers"></i> Calculate size</a>'
                    : (editable.test(i.name) ? '<a href="#" class="dropdown-item" data-row="edit"><i class="bi bi-pencil-square"></i> Edit</a>' : '') + '<a href="#" class="dropdown-item" data-row="download"><i class="bi bi-download"></i> Download</a>') +
                (archive.test(i.name) ? '<a href="#" class="dropdown-item" data-row="extract"><i class="bi bi-box-arrow-in-down"></i> Extract here</a>' : '') +
                '<a href="#" class="dropdown-item" data-row="rename"><i class="bi bi-input-cursor"></i> Rename</a>' +
                '<a href="#" class="dropdown-item" data-row="perms"><i class="bi bi-shield-lock"></i> Permissions</a>' +
                '<a href="#" class="dropdown-item" data-row="scan"><i class="bi bi-bug"></i> Scan for malware</a>' +
                '<a href="#" class="dropdown-item" data-row="copypath"><i class="bi bi-clipboard"></i> Copy path</a>' +
                '<div class="dropdown-divider"></div><a href="#" class="dropdown-item text-danger" data-row="delete"><i class="bi bi-trash"></i> Delete</a></div></div>';
            return '<tr data-name="' + GBX.escape(i.name) + '">' +
                '<td><input type="checkbox" class="form-check-input fm-check"></td>' +
                '<td><div class="fm-name"><i class="bi ' + icon + '"></i><a href="#" class="fm-open">' + GBX.escape(i.name) + '</a>' + (i.target ? '<span class="cell-sub font-mono text-truncate">&rarr; ' + GBX.escape(i.target) + '</span>' : '') + '</div></td>' +
                '<td class="font-mono small text-nowrap size-cell">' + (isDir ? '<span class="cell-sub">-</span>' : GBX.bytes(i.size, 1)) + '</td>' +
                '<td class="cell-sub text-nowrap">' + GBX.date(i.mtime) + '</td>' +
                '<td class="font-mono small">' + GBX.escape(i.perms) + '</td>' +
                '<td class="small text-nowrap">' + GBX.escape(i.owner + ':' + i.group) + '</td>' +
                '<td class="table-actions">' + actions + '</td></tr>';
        });
        $('#fmBody').html(rows.join('') || '<tr class="loading-row"><td colspan="7">This folder is empty.</td></tr>');
        $('#fmStats').text(dirs + ' folders, ' + files + ' files, ' + GBX.bytes(total, 1));
        $('#fmAll').prop('checked', false);
        selection();
    }

    function load(path, push) {
        $('#fmBody').html('<tr class="loading-row"><td colspan="7"><i class="bi bi-arrow-repeat spin"></i> Loading...</td></tr>');
        GBX.get(R.list, { path: path }, { silent: true }).done(function (r) {
            cwd = r.path;
            items = r.items;
            crumbs();
            render();
            if (push !== false) history.replaceState(null, '', '?path=' + encodeURIComponent(cwd));
        }).fail(function (xhr) {
            toastr.error(GBX.errorMessage(xhr));
            if (!items.length) render();
            else render();
        });
    }

    function selected() {
        return $('#fmBody tr.selected').map(function () { return $(this).data('name'); }).get();
    }

    function selection() {
        var sel = selected();
        $('#fmSelInfo').text(sel.length ? sel.length + ' selected' : 'No selection');
        $('.sel-only').prop('disabled', sel.length === 0);
        $('#fmPaste').prop('disabled', !clipboard).html('<i class="bi bi-clipboard-check"></i> Paste' + (clipboard ? ' (' + clipboard.paths.length + ')' : ''));
    }

    $('#fmBody').on('change', '.fm-check', function () { $(this).closest('tr').toggleClass('selected', this.checked); selection(); });
    $('#fmAll').on('change', function () { $('#fmBody .fm-check').prop('checked', this.checked).closest('tr').toggleClass('selected', this.checked); selection(); });
    $('#fmBody').on('click', 'td:not(:first-child):not(.table-actions)', function (e) {
        if ($(e.target).closest('a,button').length) return;
        var $tr = $(this).closest('tr'), on = !$tr.hasClass('selected');
        if (!e.ctrlKey && !e.metaKey) $('#fmBody tr').removeClass('selected').find('.fm-check').prop('checked', false);
        $tr.toggleClass('selected', on).find('.fm-check').prop('checked', on);
        selection();
    });

    function item(name) { return items.find(function (i) { return i.name === name; }); }

    function open(name) {
        var i = item(name);
        if (!i) return;
        if (i.type === 'dir' || (i.type === 'link' && !/\.[a-z0-9]+$/i.test(name))) return load(join(cwd, name));
        if (editable.test(name)) return edit(join(cwd, name));
        window.location = R.download + '?path=' + encodeURIComponent(join(cwd, name));
    }

    $('#fmBody').on('click', '.fm-open', function (e) { e.preventDefault(); open($(this).closest('tr').data('name')); });
    $('#fmBody').on('dblclick', 'tr', function () { open($(this).data('name')); });
    $('#fmCrumbs').on('click', 'a', function (e) { e.preventDefault(); load($(this).data('path')); });
    $('#fmUp').on('click', function () { load(cwd.replace(/\/[^\/]+\/?$/, '') || '/'); });
    $('#fmRefresh').on('click', function () { load(cwd); });
    $('#fmFilter').on('input', render);
    $('#fmEditPath').on('click', function () { $('#fmPath').toggleClass('editing'); $('#fmPathInput').focus().select(); });
    $('#fmPathInput').on('keydown', function (e) {
        if (e.key === 'Enter') { $('#fmPath').removeClass('editing'); load(this.value); }
        if (e.key === 'Escape') $('#fmPath').removeClass('editing');
    }).on('blur', function () { setTimeout(function () { $('#fmPath').removeClass('editing'); }, 150); });

    /* ------------------------------------------------------------- editor */
    function modeFor(path) {
        var e = ext(path), base = path.split('/').pop().toLowerCase();
        if (base === 'dockerfile') return 'dockerfile';
        return ({ php: 'application/x-httpd-php', js: 'javascript', mjs: 'javascript', json: { name: 'javascript', json: true }, ts: 'text/typescript', css: 'css', scss: 'text/x-scss', html: 'htmlmixed', htm: 'htmlmixed', vue: 'htmlmixed', xml: 'xml', svg: 'xml', sql: 'sql', sh: 'shell', bash: 'shell', py: 'python', yml: 'yaml', yaml: 'yaml', md: 'markdown', ini: 'properties', env: 'properties', cnf: 'properties', conf: 'nginx', htaccess: 'nginx' })[e] || (base.startsWith('.env') ? 'properties' : 'text/plain');
    }

    function edit(path) {
        GBX.get(R.read, { path: path }).done(function (r) {
            editingPath = r.path;
            $('#editorPath').text(r.path);
            bootstrap.Modal.getOrCreateInstance('#editorModal').show();
            if (!editor) {
                editor = CodeMirror.fromTextArea(document.getElementById('editorArea'), {
                    theme: 'material-darker', lineNumbers: true, indentUnit: 4, matchBrackets: true, lineWrapping: false,
                    extraKeys: { 'Ctrl-S': save, 'Cmd-S': save, 'Tab': function (cm) { cm.replaceSelection('    '); } }
                });
                editor.on('change', function () { $('#editorDirty').removeClass('d-none'); });
            }
            editor.setOption('mode', modeFor(r.path));
            editor.setValue(r.content);
            editor.clearHistory();
            $('#editorDirty').addClass('d-none');
            setTimeout(function () { editor.refresh(); editor.focus(); }, 200);
        });
    }

    function save() {
        var $b = $('#editorSave');
        GBX.busy($b, true);
        GBX.post(R.save, { path: editingPath, content: editor.getValue() }).done(function (r) {
            toastr.success(r.message);
            $('#editorDirty').addClass('d-none');
        }).always(function () { GBX.busy($b, false); });
    }
    $('#editorSave').on('click', save);
    $('#editorClose').on('click', function () {
        var close = function () { bootstrap.Modal.getOrCreateInstance('#editorModal').hide(); load(cwd, false); };
        if ($('#editorDirty').hasClass('d-none')) return close();
        GBX.confirm({ text: 'Discard unsaved changes?', danger: true, confirmText: 'Discard' }).then(function (r) { if (r.isConfirmed) close(); });
    });

    /* ------------------------------------------------------------ actions */
    function perms(names) {
        var first = item(names[0]) || {};
        $('#permMode').val(first.perms || '644').trigger('input');
        $('#permOwner').val(first.owner ? first.owner + ':' + first.group : '');
        $('#permRecursive').prop('checked', false);
        $('#permsForm').data('names', names);
        bootstrap.Modal.getOrCreateInstance('#permsModal').show();
    }
    $('#permMode').on('input', function () {
        var m = this.value.slice(-3);
        $('.perm-bit').each(function () { var d = parseInt(m.charAt($(this).data('pos')) || '0', 10); this.checked = (d & $(this).data('bit')) !== 0; });
    });
    $('.perm-bit').on('change', function () {
        var digits = [0, 0, 0];
        $('.perm-bit:checked').each(function () { digits[$(this).data('pos')] += $(this).data('bit'); });
        $('#permMode').val(digits.join(''));
    });
    $('#permsForm').on('submit', function (e) {
        e.preventDefault();
        var names = $(this).data('names');
        GBX.post(R.perms, { paths: names.map(function (n) { return join(cwd, n); }), mode: $('#permMode').val(), owner: $('#permOwner').val(), recursive: $('#permRecursive').is(':checked') ? 1 : 0 })
            .done(function (r) { toastr.success(r.message); bootstrap.Modal.getOrCreateInstance('#permsModal').hide(); load(cwd, false); });
    });

    function remove(names) {
        GBX.confirm({ html: 'Delete <strong>' + names.length + '</strong> item(s)?<div class="font-mono small mt-2 text-break">' + names.slice(0, 8).map(GBX.escape).join('<br>') + (names.length > 8 ? '<br>...' : '') + '</div>', danger: true, confirmText: 'Delete permanently' })
            .then(function (r) { if (r.isConfirmed) GBX.post(R.del, { paths: names.map(function (n) { return join(cwd, n); }) }).done(function (res) { toastr.success(res.message); load(cwd, false); }); });
    }

    function compress(names) {
        var def = (names.length === 1 ? names[0] : (cwd.split('/').pop() || 'archive')) + '.zip';
        Swal.fire({
            title: 'Compress ' + names.length + ' item(s)', buttonsStyling: false, showCancelButton: true, confirmButtonText: 'Compress', reverseButtons: true,
            customClass: { confirmButton: 'btn btn-primary', cancelButton: 'btn btn-outline-secondary' },
            html: '<input id="arcName" class="swal2-input" value="' + GBX.escape(def) + '"><select id="arcFormat" class="swal2-select"><option value="zip">zip</option><option value="tar.gz">tar.gz</option><option value="tar">tar</option></select>',
            preConfirm: function () { return { archive: $('#arcName').val(), format: $('#arcFormat').val() }; }
        }).then(function (r) {
            if (!r.isConfirmed) return;
            var name = r.value.archive.replace(/\.(zip|tar\.gz|tgz|tar)$/i, '') + '.' + r.value.format;
            toastr.info('Compressing...');
            GBX.post(R.compress, { dir: cwd, names: names, archive: name, format: r.value.format }).done(function (res) { toastr.success(res.message); load(cwd, false); });
        });
    }

    $('[data-fm]').on('click', function () {
        var act = $(this).data('fm'), sel = selected();
        if (act === 'copy' || act === 'cut') {
            clipboard = { mode: act, paths: sel.map(function (n) { return join(cwd, n); }) };
            toastr.info(sel.length + ' item(s) ready to ' + (act === 'cut' ? 'move' : 'copy') + '. Open the destination and click Paste.');
            selection();
        } else if (act === 'paste') {
            GBX.post(R.paste, { paths: clipboard.paths, destination: cwd, mode: clipboard.mode }).done(function (res) {
                toastr.success(res.message);
                if (clipboard.mode === 'cut') clipboard = null;
                load(cwd, false);
            });
        } else if (act === 'delete') remove(sel);
        else if (act === 'perms') perms(sel);
        else if (act === 'compress') compress(sel);
    });

    $('#fmBody').on('click', '[data-row]', function (e) {
        e.preventDefault();
        var act = $(this).data('row'), name = $(this).closest('tr').data('name'), path = join(cwd, name), $tr = $(this).closest('tr');
        switch (act) {
            case 'open': load(path); break;
            case 'edit': edit(path); break;
            case 'download': window.location = R.download + '?path=' + encodeURIComponent(path); break;
            case 'delete': remove([name]); break;
            case 'perms': perms([name]); break;
            case 'scan':
                toastr.info('Scanning ' + name + '...');
                GBX.post(R.scan, { path: path }, { onTaskDone: function () { load(cwd, false); } }).done(function (r) {
                    if (!r.result) return;
                    if (r.result.status === 'infected') {
                        Swal.fire({ title: 'Malware found', html: '<code>' + GBX.escape(path) + '</code><br><span class="badge badge-danger mt-2">' + GBX.escape(r.result.signature) + '</span><p class="small mt-3">Quarantine or delete it in Security &gt; Antivirus.</p>', icon: 'error', buttonsStyling: false, confirmButtonText: 'Open Antivirus', showCancelButton: true, customClass: { confirmButton: 'btn btn-danger', cancelButton: 'btn btn-outline-secondary' } })
                            .then(function (res) { if (res.isConfirmed) window.location = @json(route('security.antivirus')); });
                    } else if (r.result.status === 'clean') {
                        toastr.success(name + ': no threats found');
                    } else {
                        toastr.warning(r.result.message);
                    }
                });
                break;
            case 'copypath': GBX.copy(path); break;
            case 'size':
                $tr.find('.size-cell').html('<i class="bi bi-arrow-repeat spin"></i>');
                GBX.get(R.size, { path: path }).done(function (r) { $tr.find('.size-cell').text(r.size); });
                break;
            case 'rename':
                GBX.prompt('Rename', name).then(function (r) {
                    if (r.isConfirmed && r.value !== name) GBX.post(R.rename, { path: path, name: r.value }).done(function (res) { toastr.success(res.message); load(cwd, false); });
                });
                break;
            case 'extract':
                GBX.prompt('Extract to', cwd).then(function (r) {
                    if (!r.isConfirmed) return;
                    toastr.info('Extracting...');
                    GBX.post(R.extract, { path: path, destination: r.value }).done(function (res) { toastr.success(res.message); load(cwd, false); });
                });
                break;
        }
    });

    $('#fmNewFolder, #fmNewFile').on('click', function (e) {
        e.preventDefault();
        var type = this.id === 'fmNewFolder' ? 'dir' : 'file';
        GBX.prompt(type === 'dir' ? 'New folder' : 'New file', '', type === 'dir' ? 'folder-name' : 'index.php').then(function (r) {
            if (!r.isConfirmed) return;
            GBX.post(R.create, { dir: cwd, name: r.value, type: type }).done(function (res) {
                toastr.success(res.message);
                load(cwd, false);
                if (type === 'file' && editable.test(r.value)) edit(join(cwd, r.value));
            });
        });
    });

    /* ------------------------------------------------------------- upload */
    function upload(files) {
        files = Array.prototype.slice.call(files);
        var queue = files.filter(function (f) {
            if (maxUpload && f.size > maxUpload) { toastr.error(f.name + ' exceeds the upload limit'); return false; }
            return true;
        });
        if (!queue.length) return;
        var done = 0, $t = toastr.info('<div>Uploading <span class="up-n">0</span>/' + queue.length + '</div><div class="progress mt-2"><div class="progress-bar up-bar" style="width:0%"></div></div>', '', { timeOut: 0, extendedTimeOut: 0, tapToDismiss: false });
        var next = function () {
            if (!queue.length) { toastr.clear($t); toastr.success(done + ' file(s) uploaded'); load(cwd, false); return; }
            var f = queue.shift(), fd = new FormData();
            fd.append('dir', cwd);
            fd.append('file', f);
            GBX.post(R.upload, fd, {
                silent: true,
                xhr: function () {
                    var x = new window.XMLHttpRequest();
                    x.upload.addEventListener('progress', function (e) { if (e.lengthComputable) $t.find('.up-bar').css('width', Math.round(e.loaded / e.total * 100) + '%'); });
                    return x;
                }
            }).done(function () { done++; $t.find('.up-n').text(done); })
              .fail(function (xhr) { toastr.error(f.name + ': ' + GBX.errorMessage(xhr)); })
              .always(next);
        };
        next();
    }
    $('#fmUploadBtn').on('click', function () { $('#fmUpload').trigger('click'); });
    $('#fmUpload').on('change', function () { upload(this.files); this.value = ''; });

    var dragDepth = 0;
    $(document).on('dragenter', function (e) { if (e.originalEvent.dataTransfer && Array.prototype.indexOf.call(e.originalEvent.dataTransfer.types, 'Files') !== -1) { dragDepth++; $('#fmDrop').addClass('show'); } })
        .on('dragleave', function () { if (--dragDepth <= 0) { dragDepth = 0; $('#fmDrop').removeClass('show'); } })
        .on('dragover', function (e) { e.preventDefault(); })
        .on('drop', function (e) {
            e.preventDefault();
            dragDepth = 0;
            $('#fmDrop').removeClass('show');
            if (e.originalEvent.dataTransfer.files.length) upload(e.originalEvent.dataTransfer.files);
        });

    crumbs();
    load(cwd, false);

    var params = new URLSearchParams(location.search);
    if (params.get('edit')) edit(params.get('edit'));
});
</script>
@endpush
