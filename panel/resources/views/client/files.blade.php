@extends('client.layout')

@section('title', 'File')

@section('content')
    @if (! $roots)
        <div class="gbx-card"><div class="empty-state">
            <div class="icon"><i class="bi bi-folder2-open"></i></div>
            <h3>No websites yet</h3>
            <p>Files are organized by website. Create a website to manage its files here.</p>
            <a href="{{ route('client.websites') }}" class="btn btn-primary"><i class="bi bi-globe2"></i> Websites</a>
        </div></div>
    @else
        <div class="gbx-card">
            <div class="fm-toolbar">
                <select class="form-select w-auto" id="fmSite">
                    @foreach ($roots as $domain => $root)
                        <option value="{{ $root }}">{{ $domain }}</option>
                    @endforeach
                </select>
                <div class="btn-group">
                    <button class="btn btn-outline-secondary btn-icon" id="fmUp" title="Parent folder"><i class="bi bi-arrow-up"></i></button>
                    <button class="btn btn-outline-secondary btn-icon" id="fmRefresh" title="Refresh"><i class="bi bi-arrow-clockwise"></i></button>
                </div>
                <div class="fm-path"><div class="crumbs" id="fmCrumbs"></div></div>
                <div class="d-flex gap-2 flex-wrap">
                    <div class="dropdown">
                        <button class="btn btn-primary" data-bs-toggle="dropdown"><i class="bi bi-plus-lg"></i> New</button>
                        <div class="dropdown-menu dropdown-menu-end">
                            <a href="#" class="dropdown-item" data-new="dir"><i class="bi bi-folder-plus"></i> Folder</a>
                            <a href="#" class="dropdown-item" data-new="file"><i class="bi bi-file-earmark-plus"></i> File</a>
                        </div>
                    </div>
                    <button class="btn btn-secondary" id="fmUploadBtn"><i class="bi bi-upload"></i> Upload</button>
                    <button class="btn btn-secondary" id="fmEditor"><i class="bi bi-code-slash"></i> Code editor</button>
                    <input type="file" id="fmUpload" multiple hidden>
                </div>
            </div>

            <div class="fm-toolbar py-2">
                <span class="cell-sub me-2" id="fmSelInfo">No selection</span>
                <button class="btn btn-sm btn-outline-secondary sel-only" data-fm="copy" disabled><i class="bi bi-copy"></i> Copy</button>
                <button class="btn btn-sm btn-outline-secondary sel-only" data-fm="cut" disabled><i class="bi bi-scissors"></i> Cut</button>
                <button class="btn btn-sm btn-outline-secondary" data-fm="paste" id="fmPaste" disabled><i class="bi bi-clipboard-check"></i> Paste</button>
                <button class="btn btn-sm btn-outline-secondary sel-only" data-fm="compress" disabled><i class="bi bi-file-zip"></i> Compress</button>
                <button class="btn btn-sm btn-outline-danger sel-only" data-fm="delete" disabled><i class="bi bi-trash"></i> Delete</button>
                <div class="ms-auto"><input type="search" class="form-control form-control-sm" id="fmFilter" placeholder="Filter this folder" style="width: 200px"></div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover fm-table mb-0">
                    <thead><tr><th style="width:34px"><input type="checkbox" class="form-check-input" id="fmAll"></th><th>Name</th><th>Size</th><th>Modified</th><th>Permissions</th><th class="text-end"></th></tr></thead>
                    <tbody id="fmBody"></tbody>
                </table>
            </div>
            <div class="gbx-card-body py-2 cell-sub border-top border-soft d-flex justify-content-between flex-wrap gap-2">
                <span id="fmStats"></span>
                <span>Max upload per file: {{ \App\Services\SystemStats::bytes($maxUpload, 0) }} &middot; Drag and drop files to upload</span>
            </div>
        </div>
        <div class="fm-drop" id="fmDrop"><div><i class="bi bi-cloud-arrow-up"></i> Drop files to upload</div></div>
    @endif
@endsection


@push('scripts')
@if ($roots)
<script>
$(function () {
    var R = {
        list: @json(route('client.files.list')),
        create: @json(route('client.files.create')), rename: @json(route('client.files.rename')), del: @json(route('client.files.delete')),
        paste: @json(route('client.files.paste')), upload: @json(route('client.files.upload')), download: @json(route('client.files.download')),
        extract: @json(route('client.files.extract')), compress: @json(route('client.files.compress'))
    };
    var esc = GBX.escape, path = @json($start), root = null, items = [], clip = null;
    var editable = /\.(php|phtml|html?|css|scss|less|js|mjs|ts|json|xml|txt|md|ini|conf|env|htaccess|yml|yaml|sql|log|csv|svg|twig|vue|py|sh)$|^\.(htaccess|user\.ini|env)$/i;
    var archive = /\.(zip|tar\.gz|tgz|tar)$/i;
    var icons = { php: 'bi-filetype-php', js: 'bi-filetype-js', css: 'bi-filetype-css', html: 'bi-filetype-html', htm: 'bi-filetype-html', json: 'bi-filetype-json', zip: 'bi-file-zip', gz: 'bi-file-zip', png: 'bi-file-image', jpg: 'bi-file-image', jpeg: 'bi-file-image', gif: 'bi-file-image', svg: 'bi-filetype-svg', sql: 'bi-filetype-sql', txt: 'bi-file-text', md: 'bi-filetype-md', pdf: 'bi-file-pdf' };
    var ext = function (n) { return (n.split('.').pop() || '').toLowerCase(); };
    var join = function (dir, name) { return dir.replace(/\/+$/, '') + '/' + name; };

    var render = function () {
        var filter = ($('#fmFilter').val() || '').toLowerCase(), dirs = 0, files = 0, total = 0;
        var rows = items.filter(function (i) { return !filter || i.name.toLowerCase().indexOf(filter) !== -1; }).map(function (i) {
            var isDir = i.type === 'dir', icon = isDir ? 'bi-folder-fill' : (i.type === 'link' ? 'bi-link-45deg' : (icons[ext(i.name)] || 'bi-file-earmark'));
            isDir ? dirs++ : (files++, total += i.size);
            var actions = '<div class="dropdown d-inline-block"><button class="btn btn-sm btn-ghost btn-icon" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button><div class="dropdown-menu dropdown-menu-end">' +
                (isDir ? '<a href="#" class="dropdown-item" data-row="open"><i class="bi bi-folder2-open"></i> Open</a>'
                    : (editable.test(i.name) ? '<a href="#" class="dropdown-item" data-row="edit"><i class="bi bi-pencil-square"></i> Edit</a>' : '') + (i.type === 'file' ? '<a href="#" class="dropdown-item" data-row="download"><i class="bi bi-download"></i> Download</a>' : '')) +
                (archive.test(i.name) && i.type === 'file' ? '<a href="#" class="dropdown-item" data-row="extract"><i class="bi bi-box-arrow-in-down"></i> Extract here</a>' : '') +
                '<a href="#" class="dropdown-item" data-row="rename"><i class="bi bi-input-cursor"></i> Rename</a>' +
                '<div class="dropdown-divider"></div><a href="#" class="dropdown-item text-danger" data-row="delete"><i class="bi bi-trash"></i> Delete</a></div></div>';
            return '<tr data-name="' + esc(i.name) + '" data-type="' + i.type + '">' +
                '<td><input type="checkbox" class="form-check-input fm-check"></td>' +
                '<td><div class="fm-name"><i class="bi ' + icon + '"></i><a href="#" class="fm-open">' + esc(i.name) + '</a>' + (i.target ? '<span class="cell-sub font-mono text-truncate">&rarr; ' + esc(i.target) + '</span>' : '') + '</div></td>' +
                '<td class="font-mono small text-nowrap">' + (isDir ? '<span class="cell-sub">-</span>' : GBX.bytes(i.size, 1)) + '</td>' +
                '<td class="cell-sub text-nowrap">' + GBX.date(i.mtime) + '</td>' +
                '<td class="font-mono small">' + esc(i.perms) + '</td>' +
                '<td class="table-actions">' + actions + '</td></tr>';
        });
        $('#fmBody').html(rows.join('') || '<tr class="loading-row"><td colspan="6">This folder is empty.</td></tr>');
        $('#fmStats').text(dirs + ' folders, ' + files + ' files, ' + GBX.bytes(total, 1));
        $('#fmAll').prop('checked', false);
        selection();
    };

    var crumbs = function () {
        var rel = path.slice(root.length).split('/').filter(Boolean), acc = root, html = '<a href="#" data-path="' + esc(root) + '"><i class="bi bi-house"></i> ' + esc($('#fmSite option:selected').text()) + '</a>';
        rel.forEach(function (part) { acc += '/' + part; html += '<span class="sep"><i class="bi bi-chevron-right"></i></span><a href="#" data-path="' + esc(acc) + '">' + esc(part) + '</a>'; });
        $('#fmCrumbs').html(html);
        $('#fmUp').prop('disabled', path === root);
    };

    var load = function (p) {
        $('#fmBody').html('<tr class="loading-row"><td colspan="6"><i class="bi bi-arrow-repeat spin"></i> Loading...</td></tr>');
        GBX.get(R.list, { path: p || path }).done(function (r) {
            path = r.path; root = r.root; items = r.items;
            $('#fmSite').val(root);
            crumbs();
            render();
            try { history.replaceState(null, '', '?path=' + encodeURIComponent(path)); } catch (e) {}
        });
    };

    var selected = function () { return $('.fm-check:checked').map(function () { return $(this).closest('tr').data('name') + ''; }).get(); };
    var selection = function () {
        var n = selected().length;
        $('#fmSelInfo').text(n ? n + ' selected' : 'No selection');
        $('.sel-only').prop('disabled', !n);
        $('#fmPaste').prop('disabled', !clip).html('<i class="bi bi-clipboard-check"></i> Paste' + (clip ? ' (' + clip.paths.length + ')' : ''));
    };
    $(document).on('change', '.fm-check', selection);
    $('#fmAll').on('change', function () { $('.fm-check').prop('checked', this.checked); selection(); });

    var post = function (url, data, done) {
        return GBX.post(url, data).done(function (r) { toastr.success(r.message); if (done) done(r); load(); });
    };

    $('#fmSite').on('change', function () { load(this.value); });
    $('#fmUp').on('click', function () { if (path !== root) load(path.replace(/\/[^\/]+\/?$/, '') || root); });
    $('#fmRefresh').on('click', function () { load(); });
    $('#fmCrumbs').on('click', 'a', function (e) { e.preventDefault(); load($(this).data('path')); });
    $('#fmFilter').on('input', render);

    // same code editor as the administrator (Monaco), limited to the websites of the client
    var openEditor = function (file) { GBX.editor.open({ root: root, open: file }); };
    $('#fmEditor').on('click', function () { GBX.editor.open({ root: path }); });

    $('#fmBody').on('click', '.fm-open', function (e) {
        e.preventDefault();
        var $tr = $(this).closest('tr'), name = $tr.data('name') + '';
        if ($tr.data('type') === 'dir') load(join(path, name));
        else if (editable.test(name)) openEditor(join(path, name));
        else window.location = R.download + '?path=' + encodeURIComponent(join(path, name));
    }).on('click', '[data-row]', function (e) {
        e.preventDefault();
        var $tr = $(this).closest('tr'), name = $tr.data('name') + '', full = join(path, name), action = $(this).data('row');
        if (action === 'open') load(full);
        if (action === 'edit') openEditor(full);
        if (action === 'download') window.location = R.download + '?path=' + encodeURIComponent(full);
        if (action === 'extract') {
            GBX.confirm({ text: 'Extract ' + name + ' into this folder? Existing files with the same names are replaced.', confirmText: 'Extract' }).then(function (r) { if (r.isConfirmed) post(R.extract, { path: full, destination: path }); });
        }
        if (action === 'rename') {
            GBX.prompt('Rename', name).then(function (r) { if (r.isConfirmed && r.value !== name) post(R.rename, { path: full, name: r.value }); });
        }
        if (action === 'delete') {
            GBX.confirm({ text: 'Delete ' + name + '? This cannot be undone.', danger: true, confirmText: 'Delete' }).then(function (r) { if (r.isConfirmed) post(R.del, { paths: [full] }); });
        }
    });

    $('[data-new]').on('click', function (e) {
        e.preventDefault();
        var type = $(this).data('new');
        GBX.prompt(type === 'dir' ? 'New folder' : 'New file', '', type === 'dir' ? 'folder-name' : 'index.php').then(function (r) {
            if (r.isConfirmed) post(R.create, { dir: path, name: r.value, type: type }, function () { if (type === 'file' && editable.test(r.value)) setTimeout(function () { openEditor(join(path, r.value)); }, 400); });
        });
    });

    $('[data-fm]').on('click', function () {
        var action = $(this).data('fm'), names = selected();
        if (action === 'copy' || action === 'cut') {
            clip = { mode: action, paths: names.map(function (n) { return join(path, n); }) };
            toastr.info(names.length + ' item(s) ready to paste');
            selection();
        } else if (action === 'paste' && clip) {
            post(R.paste, { paths: clip.paths, destination: path, mode: clip.mode }, function () { if (clip.mode === 'cut') clip = null; });
        } else if (action === 'compress') {
            GBX.prompt('Archive name', (names.length === 1 ? names[0] : 'archive') + '.zip').then(function (r) { if (r.isConfirmed) post(R.compress, { dir: path, names: names, archive: r.value }); });
        } else if (action === 'delete') {
            GBX.confirm({ text: 'Delete ' + names.length + ' item(s)? This cannot be undone.', danger: true, confirmText: 'Delete' }).then(function (r) {
                if (r.isConfirmed) post(R.del, { paths: names.map(function (n) { return join(path, n); }) });
            });
        }
    });

    var upload = function (fileList) {
        if (!fileList.length) return;
        var fd = new FormData();
        fd.append('dir', path);
        $.each(fileList, function (_, f) { fd.append('files[]', f); });
        toastr.info('Uploading ' + fileList.length + ' file(s)...');
        GBX.post(R.upload, fd, { timeout: 3600000 }).done(function (r) { toastr.success(r.message); load(); });
    };
    $('#fmUploadBtn').on('click', function () { $('#fmUpload').trigger('click'); });
    $('#fmUpload').on('change', function () { upload(this.files); this.value = ''; });
    var dragDepth = 0;
    $(document).on('dragenter', function (e) { if (e.originalEvent.dataTransfer && Array.prototype.indexOf.call(e.originalEvent.dataTransfer.types, 'Files') !== -1) { dragDepth++; $('#fmDrop').addClass('show'); } })
        .on('dragleave', function () { if (--dragDepth <= 0) { dragDepth = 0; $('#fmDrop').removeClass('show'); } })
        .on('dragover', function (e) { e.preventDefault(); })
        .on('drop', function (e) { e.preventDefault(); dragDepth = 0; $('#fmDrop').removeClass('show'); if (e.originalEvent.dataTransfer) upload(e.originalEvent.dataTransfer.files); });

    load(path);
});
</script>
@endif
@endpush
