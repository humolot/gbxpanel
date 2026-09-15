/*
 * GBX Panel - code editor
 * Monaco editor with a lazy file tree, tabs, search in files, quick open and per-user settings.
 */
(function ($) {
    'use strict';

    var C = window.GBX_EDITOR, R = C.routes;
    C.storeKey = C.storeKey || 'gbx.editor';
    // client sub-panel: the explorer is limited to the document roots of the client's websites
    var ROOTS = C.roots ? Object.keys(C.roots).map(function (d) { return { domain: d, path: C.roots[d] }; }) : null;
    var $app = $('#edApp'), $tree = $('#edTree'), $tabs = $('#edTabs'), $ctx = $('#edCtx');
    var isMobile = function () { return window.innerWidth < 768; };

    /* ================================================================ storage */
    var store = {
        get: function (key, fallback) {
            try { var v = localStorage.getItem(key); return v === null ? fallback : JSON.parse(v); } catch (e) { return fallback; }
        },
        set: function (key, value) {
            try { localStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* storage unavailable */ }
        }
    };

    var DEFAULTS = {
        fontSize: 14, lineHeight: 0, tabSize: 4, insertSpaces: true,
        fontFamily: '"JetBrains Mono", "Cascadia Code", "SFMono-Regular", Consolas, "Liberation Mono", monospace',
        wordWrap: 'off', renderWhitespace: 'selection', lineNumbers: 'on', cursorStyle: 'line', autoSave: 'off', defaultEol: 'LF',
        minimap: true, stickyScroll: true, bracketPairs: true, guides: true, ligatures: false, smoothScrolling: true,
        trimOnSave: false, finalNewline: false, restoreSession: true
    };
    var savedSettings = store.get(C.storeKey + '.settings', null);
    var settings = $.extend({}, DEFAULTS, savedSettings || {});
    if (!savedSettings && isMobile()) { settings.minimap = false; settings.fontSize = 13; }

    /* ================================================================ helpers */
    var esc = GBX.escape;
    // left-to-right mark: keeps the leading slash in place for right-aligned (rtl) path labels
    var LRM = String.fromCharCode(8206);
    function basename(p) { return p.replace(/\/+$/, '').split('/').pop() || '/'; }
    function dirname(p) { var d = p.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); return d || '/'; }
    function join(dir, name) { return (dir === '/' ? '' : dir.replace(/\/+$/, '')) + '/' + name; }
    function ext(name) { var m = name.toLowerCase().match(/\.([a-z0-9_-]+)$/); return m ? m[1] : ''; }
    function normalize(p) {
        var parts = [];
        String(p || '/').replace(/\\/g, '/').split('/').forEach(function (s) {
            if (!s || s === '.') return;
            if (s === '..') parts.pop(); else parts.push(s);
        });
        return '/' + parts.join('/');
    }
    function relative(path) {
        if (path === state.root) return '';
        var root = state.root === '/' ? '' : state.root;
        return path.indexOf(root + '/') === 0 ? path.slice(root.length + 1) : path;
    }
    function isInside(path, dir) { return path === dir || path.indexOf((dir === '/' ? '' : dir) + '/') === 0; }
    function siteOf(path) { return ROOTS ? ROOTS.filter(function (r) { return isInside(normalize(path), r.path); })[0] || null : null; }
    function allowedRoot(path) { return !ROOTS || !!siteOf(path); }
    function escapeRegExp(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
    function debounce(fn, ms) { var t; return function () { var a = arguments, self = this; clearTimeout(t); t = setTimeout(function () { fn.apply(self, a); }, ms); }; }
    function timeNow() { var d = new Date(); return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); }

    var ICONS = {
        php: 'bi-filetype-php ic-php', phtml: 'bi-filetype-php ic-php', js: 'bi-filetype-js ic-js', mjs: 'bi-filetype-js ic-js', cjs: 'bi-filetype-js ic-js',
        jsx: 'bi-filetype-jsx ic-js', ts: 'bi-filetype-tsx ic-ts', tsx: 'bi-filetype-tsx ic-ts', css: 'bi-filetype-css ic-css', scss: 'bi-filetype-scss ic-css',
        sass: 'bi-filetype-sass ic-css', less: 'bi-filetype-css ic-css', html: 'bi-filetype-html ic-html', htm: 'bi-filetype-html ic-html', vue: 'bi-filetype-html ic-sh',
        json: 'bi-filetype-json ic-json', md: 'bi-filetype-md ic-md', mdx: 'bi-filetype-mdx ic-md', txt: 'bi-filetype-txt ic-file', log: 'bi-file-earmark-text ic-file',
        sql: 'bi-filetype-sql ic-sql', sh: 'bi-filetype-sh ic-sh', bash: 'bi-filetype-sh ic-sh', zsh: 'bi-filetype-sh ic-sh', py: 'bi-filetype-py ic-py',
        rb: 'bi-filetype-rb ic-lock', java: 'bi-filetype-java ic-sql', cs: 'bi-filetype-cs ic-php', yml: 'bi-filetype-yml ic-conf', yaml: 'bi-filetype-yml ic-conf',
        xml: 'bi-filetype-xml ic-html', svg: 'bi-filetype-svg ic-img', png: 'bi-filetype-png ic-img', jpg: 'bi-filetype-jpg ic-img', jpeg: 'bi-filetype-jpg ic-img',
        gif: 'bi-filetype-gif ic-img', webp: 'bi-file-earmark-image ic-img', ico: 'bi-file-earmark-image ic-img', zip: 'bi-file-earmark-zip ic-zip',
        gz: 'bi-file-earmark-zip ic-zip', tgz: 'bi-file-earmark-zip ic-zip', tar: 'bi-file-earmark-zip ic-zip', rar: 'bi-file-earmark-zip ic-zip', '7z': 'bi-file-earmark-zip ic-zip',
        pdf: 'bi-filetype-pdf ic-lock', csv: 'bi-filetype-csv ic-sh', lock: 'bi-file-earmark-lock ic-conf', env: 'bi-file-earmark-lock ic-lock',
        conf: 'bi-file-earmark-code ic-conf', ini: 'bi-file-earmark-code ic-conf', cnf: 'bi-file-earmark-code ic-conf', htaccess: 'bi-file-earmark-code ic-conf',
        toml: 'bi-file-earmark-code ic-conf', service: 'bi-file-earmark-code ic-conf', ttf: 'bi-filetype-ttf ic-file', woff: 'bi-filetype-woff ic-file', woff2: 'bi-filetype-woff ic-file'
    };
    function iconFor(name, type) {
        if (type === 'dir') return 'bi-folder-fill ic-dir';
        var base = name.toLowerCase();
        if (base.indexOf('.env') === 0) return ICONS.env;
        if (base === 'dockerfile' || base.indexOf('docker-compose') === 0) return 'bi-box-seam ic-ts';
        if (base === 'composer.json' || base === 'package.json') return 'bi-box ic-json';
        return ICONS[ext(name)] || 'bi-file-earmark ic-file';
    }

    var LANG_EXT = {
        php: 'php', phtml: 'php', js: 'javascript', mjs: 'javascript', cjs: 'javascript', jsx: 'javascript', ts: 'typescript', tsx: 'typescript',
        json: 'json', jsonc: 'json', map: 'json', webmanifest: 'json', css: 'css', scss: 'scss', sass: 'scss', less: 'less', html: 'html', htm: 'html',
        vue: 'html', svelte: 'html', xml: 'xml', svg: 'xml', xsl: 'xml', plist: 'xml', md: 'markdown', markdown: 'markdown', mdx: 'mdx', sql: 'mysql',
        sh: 'shell', bash: 'shell', zsh: 'shell', py: 'python', rb: 'ruby', go: 'go', rs: 'rust', java: 'java', kt: 'kotlin', c: 'cpp', h: 'cpp',
        cpp: 'cpp', hpp: 'cpp', cs: 'csharp', lua: 'lua', pl: 'perl', ps1: 'powershell', bat: 'bat', cmd: 'bat', yml: 'yaml', yaml: 'yaml',
        ini: 'ini', cnf: 'ini', env: 'ini', properties: 'ini', toml: 'ini', service: 'ini', conf: 'apache', htaccess: 'apache', twig: 'twig',
        graphql: 'graphql', gql: 'graphql', dockerfile: 'dockerfile', log: 'log', txt: 'plaintext', csv: 'plaintext', swift: 'swift', dart: 'dart', r: 'r'
    };
    function languageFor(path) {
        var base = basename(path).toLowerCase();
        if (base === 'dockerfile' || base === 'containerfile' || /^dockerfile\./.test(base)) return 'dockerfile';
        if (base.indexOf('.env') === 0) return 'ini';
        if (/\.blade\.php$/.test(base)) return 'php';
        if (/(^|\.)(bashrc|bash_profile|profile|zshrc)$/.test(base) || base === 'crontab') return 'shell';
        if (/\.lock$/.test(base) && base !== 'yarn.lock') return 'json';
        if (/\.conf$/.test(base) && path.indexOf('/nginx/') !== -1) return 'shell';
        if (/\.log(\.\d+)?$/.test(base) || base === 'syslog') return 'log';
        return LANG_EXT[ext(base)] || 'plaintext';
    }

    var ENCODING_LABELS = {
        'utf-8': 'UTF-8', 'utf-8-bom': 'UTF-8 with BOM', 'iso-8859-1': 'ISO 8859-1', 'windows-1252': 'Windows 1252', 'iso-8859-15': 'ISO 8859-15',
        gbk: 'GBK', big5: 'Big5', shift_jis: 'Shift JIS', 'euc-kr': 'EUC-KR'
    };

    /* ================================================================ theme */
    var THEME = {
        base: 'vs-dark', inherit: true,
        rules: [
            { token: 'log-error', foreground: 'f0616d', fontStyle: 'bold' }, { token: 'log-warn', foreground: 'f5b454' },
            { token: 'log-info', foreground: '6aa7ff' }, { token: 'log-date', foreground: '7d8590' }
        ],
        colors: {
            'editor.background': '#0d0f12', 'editor.lineHighlightBackground': '#14171c', 'editor.lineHighlightBorder': '#00000000',
            'editorLineNumber.foreground': '#454c55', 'editorLineNumber.activeForeground': '#c3c8cf', 'editorGutter.background': '#0d0f12',
            'editor.selectionBackground': '#2b4a7380', 'editor.inactiveSelectionBackground': '#2b4a7340', 'editorIndentGuide.background1': '#1c2026',
            'editorIndentGuide.activeBackground1': '#3a414b', 'editorWidget.background': '#14171b', 'editorWidget.border': '#2a3038',
            'editorSuggestWidget.background': '#14171b', 'editorSuggestWidget.border': '#2a3038', 'editorHoverWidget.background': '#14171b',
            'minimap.background': '#0d0f12', 'editorStickyScroll.background': '#0d0f12', 'editorCursor.foreground': '#f2f3f5',
            'scrollbarSlider.background': '#2c323a90', 'scrollbarSlider.hoverBackground': '#3a414bb0', 'editor.findMatchHighlightBackground': '#f5b45438',
            'input.background': '#1a1e23', 'input.border': '#333a43', 'focusBorder': '#59616c', 'list.activeSelectionBackground': '#21262c',
            'list.hoverBackground': '#1a1e23', 'quickInput.background': '#14171b', 'editorBracketMatch.border': '#6aa7ff80'
        }
    };

    /* ================================================================ state */
    var state = { root: C.root, tabs: [], active: null, selected: null, expanded: {} };
    var editor = null, monacoReady = false, pendingOpen = [];
    var session = store.get(C.storeKey + '.session', null);
    var query = new URLSearchParams(location.search);

    if (!query.has('root') && !C.open && settings.restoreSession && session && session.root && allowedRoot(session.root)) {
        state.root = session.root;
    }

    /* ================================================================ context menu */
    function menu(items, x, y, opts) {
        opts = opts || {};
        var html = items.map(function (it, i) {
            if (it === '-') return '<div class="dropdown-divider"></div>';
            if (it.header) return '<h6 class="dropdown-header">' + esc(it.header) + '</h6>';
            return '<a href="#" class="dropdown-item' + (it.danger ? ' text-danger' : '') + (it.disabled ? ' disabled' : '') + (it.active ? ' active' : '') + '" data-i="' + i + '">' +
                (it.icon ? '<i class="bi ' + it.icon + '"></i>' : '') + '<span>' + esc(it.label) + '</span>' +
                (it.key ? '<span class="ed-ctx-key">' + esc(it.key) + '</span>' : '') + '</a>';
        }).join('');
        $ctx.html(html).addClass('show').css({ left: 0, top: 0 });
        var w = $ctx.outerWidth(), h = $ctx.outerHeight();
        var left = Math.max(4, Math.min(x, window.innerWidth - w - 4));
        var top = opts.above ? y - h - 4 : y;
        top = Math.max(4, Math.min(top, window.innerHeight - h - 4));
        $ctx.css({ left: left, top: top });
        $ctx.off('click').on('click', '.dropdown-item', function (e) {
            e.preventDefault();
            var it = items[$(this).data('i')];
            hideMenu();
            if (it && !it.disabled && it.action) it.action();
        });
    }
    function hideMenu() { $ctx.removeClass('show'); }
    $(document).on('mousedown', function (e) { if (!$(e.target).closest('#edCtx').length) hideMenu(); });
    $(window).on('blur resize', hideMenu);

    /* ================================================================ tree */
    function expandedKey() { return C.storeKey + '.expanded:' + state.root; }

    function nodeFor(path) {
        return $tree.find('.tn').filter(function () { return $(this).data('path') === path; }).first();
    }

    function renderNodes(items, depth) {
        return items.map(function (it) {
            var isDir = it.type === 'dir' || (it.type === 'link' && !/\.[a-z0-9]+$/i.test(it.name));
            var type = isDir ? 'dir' : 'file';
            var $node = $('<div class="tn" role="treeitem">')
                .attr({ 'data-type': type, title: it.path + (it.target ? ' -> ' + it.target : '') })
                .data('path', it.path)
                .css('padding-left', (depth * 14 + 6) + 'px')
                .append('<i class="tn-caret bi bi-chevron-right"' + (isDir ? '' : ' style="visibility:hidden"') + '></i>')
                .append('<i class="tn-icon bi ' + iconFor(it.name, type) + '"></i>')
                .append($('<span class="tn-name">').text(it.name));
            if (it.type === 'link') $node.append('<span class="tn-hint"><i class="bi bi-link-45deg"></i></span>');
            if (it.name.charAt(0) === '.') $node.addClass('tn-dim');
            if (state.active && state.active.path === it.path) $node.addClass('active');
            var out = [$node[0]];
            if (isDir) out.push($('<div class="tn-children" role="group" hidden>').data('depth', depth + 1)[0]);
            return out;
        }).reduce(function (a, b) { return a.concat(b); }, []);
    }

    function loadDir(path, $container, depth) {
        $container.html('<div class="tn-msg" style="padding-left:' + (depth * 14 + 26) + 'px"><i class="bi bi-arrow-repeat spin"></i> Loading</div>');
        return GBX.get(R.list, { path: path }, { silent: true }).then(function (r) {
            $container.empty();
            if (!r.items.length) {
                $container.html('<div class="tn-msg" style="padding-left:' + (depth * 14 + 26) + 'px">Empty folder</div>');
                return;
            }
            $container.append(renderNodes(r.items, depth));
            // restore nested expanded folders
            var jobs = [];
            $container.children('.tn[data-type=dir]').each(function () {
                var p = $(this).data('path');
                if (state.expanded[p]) jobs.push(expand($(this), true));
            });
            return $.when.apply($, jobs);
        }, function (xhr) {
            $container.html($('<div class="tn-msg text-danger">').css('padding-left', (depth * 14 + 26) + 'px').text(GBX.errorMessage(xhr)));
        });
    }

    function expand($node, silent) {
        var $children = $node.next('.tn-children');
        $node.addClass('open');
        $children.prop('hidden', false);
        state.expanded[$node.data('path')] = true;
        if (!silent) store.set(expandedKey(), Object.keys(state.expanded));
        return loadDir($node.data('path'), $children, $children.data('depth'));
    }

    function collapse($node) {
        $node.removeClass('open').next('.tn-children').prop('hidden', true).empty();
        var path = $node.data('path');
        Object.keys(state.expanded).forEach(function (p) { if (isInside(p, path)) delete state.expanded[p]; });
        store.set(expandedKey(), Object.keys(state.expanded));
    }

    function renderTree() {
        $('#edRoot').text(LRM + state.root + LRM).attr('title', state.root);
        $('#edSearchScope').text(state.root);
        state.expanded = {};
        store.get(expandedKey(), []).forEach(function (p) { state.expanded[p] = true; });
        return loadDir(state.root, $tree, 0);
    }

    function refreshDir(dir) {
        if (dir === state.root || !isInside(dir, state.root)) return renderTree();
        var $node = nodeFor(dir);
        if (!$node.length) return $.when();
        return expand($node);
    }

    function setRoot(path) {
        state.root = normalize(path);
        persistSession();
        syncUrl();
        return renderTree();
    }

    function select($node) {
        $tree.find('.tn.selected').removeClass('selected');
        if ($node && $node.length) {
            $node.addClass('selected');
            state.selected = $node.data('path');
            var el = $node[0], box = $tree[0];
            if (el.offsetTop < box.scrollTop || el.offsetTop + el.offsetHeight > box.scrollTop + box.clientHeight) el.scrollIntoView({ block: 'nearest' });
        }
    }

    function markActiveInTree() {
        $tree.find('.tn.active').removeClass('active');
        if (state.active) nodeFor(state.active.path).addClass('active');
    }

    $tree.on('click', '.tn', function (e) {
        var $n = $(this);
        select($n);
        if ($n.data('type') === 'dir') {
            $n.hasClass('open') ? collapse($n) : expand($n);
        } else {
            openFile($n.data('path'));
            if (isMobile()) toggleSidebar(false);
        }
    });

    $tree.on('contextmenu', function (e) {
        e.preventDefault();
        var $n = $(e.target).closest('.tn');
        if ($n.length) select($n);
        treeMenu($n.length ? $n.data('path') : state.root, $n.length ? $n.data('type') : 'dir', e.clientX, e.clientY, !$n.length);
    });

    // long press opens the context menu on touch screens
    (function () {
        var timer = null;
        $tree.on('touchstart', '.tn', function (e) {
            var $n = $(this), t = e.originalEvent.touches[0];
            timer = setTimeout(function () { select($n); treeMenu($n.data('path'), $n.data('type'), t.clientX, t.clientY); }, 550);
        }).on('touchend touchmove', '.tn', function () { clearTimeout(timer); });
    })();

    $tree.on('keydown', function (e) {
        var $visible = $tree.find('.tn:visible'), $cur = $tree.find('.tn.selected'), idx = $visible.index($cur);
        switch (e.key) {
            case 'ArrowDown': e.preventDefault(); select($visible.eq(Math.min($visible.length - 1, idx + 1))); break;
            case 'ArrowUp': e.preventDefault(); select($visible.eq(Math.max(0, idx - 1))); break;
            case 'ArrowRight': if ($cur.data('type') === 'dir' && !$cur.hasClass('open')) expand($cur); break;
            case 'ArrowLeft':
                if ($cur.hasClass('open')) collapse($cur);
                else { var $parent = $cur.parent('.tn-children').prev('.tn'); if ($parent.length) select($parent); }
                break;
            case 'Enter': if ($cur.length) $cur.trigger('click'); break;
            case 'F2': if ($cur.length && !C.readOnly) renameItem($cur.data('path'), $cur.data('type')); break;
            case 'Delete': if ($cur.length && !C.readOnly) deleteItem($cur.data('path'), $cur.data('type')); break;
        }
    });

    function treeMenu(path, type, x, y, isRoot) {
        var dir = type === 'dir' ? path : dirname(path), items = [];
        if (type === 'file') {
            items.push({ label: 'Open', icon: 'bi-file-earmark-code', action: function () { openFile(path); } });
            items.push({ label: 'Download', icon: 'bi-download', action: function () { window.location = R.download + '?path=' + encodeURIComponent(path); } });
            items.push('-');
        }
        if (!C.readOnly) {
            items.push({ label: 'New file', icon: 'bi-file-earmark-plus', action: function () { createItem(dir, 'file'); } });
            items.push({ label: 'New folder', icon: 'bi-folder-plus', action: function () { createItem(dir, 'dir'); } });
            items.push({ label: 'Upload files here', icon: 'bi-upload', action: function () { uploadTo(dir); } });
            items.push('-');
        }
        if (type === 'dir') {
            items.push({ label: 'Refresh', icon: 'bi-arrow-clockwise', action: function () { refreshDir(path); } });
            if (!isRoot) items.push({ label: 'Set as root directory', icon: 'bi-pin-angle', action: function () { setRoot(path); } });
            items.push({ label: 'Search in folder', icon: 'bi-search', action: function () { showSearch(path); } });
            items.push({ label: 'Open in file manager', icon: 'bi-folder2-open', action: function () { window.open(C.filesUrl + '?path=' + encodeURIComponent(path), '_blank'); } });
        }
        items.push({ label: 'Copy path', icon: 'bi-clipboard', action: function () { GBX.copy(path); } });
        if (!C.readOnly && !isRoot) {
            items.push('-');
            items.push({ label: 'Rename', icon: 'bi-input-cursor', key: 'F2', action: function () { renameItem(path, type); } });
            items.push({ label: 'Delete', icon: 'bi-trash', key: 'Del', danger: true, action: function () { deleteItem(path, type); } });
        }
        menu(items, x, y);
    }

    function createItem(dir, type) {
        GBX.prompt(type === 'dir' ? 'New folder in ' + basename(dir) : 'New file in ' + basename(dir), '', type === 'dir' ? 'folder-name' : 'index.php').then(function (r) {
            if (!r.isConfirmed) return;
            var name = String(r.value).trim();
            GBX.post(R.create, { dir: dir, name: name, type: type }).done(function (res) {
                toastr.success(res.message);
                state.expanded[dir] = true;
                refreshDir(dir);
                if (type === 'file') openFile(join(dir, name));
            });
        });
    }

    function renameItem(path, type) {
        var old = basename(path);
        GBX.prompt('Rename', old).then(function (r) {
            if (!r.isConfirmed || r.value === old) return;
            var target = join(dirname(path), String(r.value).trim());
            GBX.post(R.rename, { path: path, name: String(r.value).trim() }).done(function (res) {
                toastr.success(res.message);
                state.tabs.forEach(function (tab) {
                    if (!isInside(tab.path, path)) return;
                    tab.path = target + tab.path.slice(path.length);
                    tab.name = basename(tab.path);
                    if (tab.model && monacoReady) monaco.editor.setModelLanguage(tab.model, languageFor(tab.path));
                });
                if (state.expanded[path]) { delete state.expanded[path]; state.expanded[target] = true; }
                renderTabs();
                updateStatus();
                persistSession();
                refreshDir(dirname(path));
            });
        });
    }

    function deleteItem(path, type) {
        GBX.confirm({ html: 'Delete <strong>' + esc(basename(path)) + '</strong>' + (type === 'dir' ? ' and everything inside it' : '') + '?<div class="font-mono small mt-2 text-break">' + esc(path) + '</div>', danger: true, confirmText: 'Delete permanently' })
            .then(function (r) {
                if (!r.isConfirmed) return;
                GBX.post(R.del, { paths: [path] }).done(function (res) {
                    toastr.success(res.message);
                    state.tabs.filter(function (t) { return isInside(t.path, path); }).forEach(function (t) { closeTab(t, true); });
                    refreshDir(dirname(path));
                });
            });
    }

    var uploadDir = null;
    function uploadTo(dir) { uploadDir = dir; $('#edUpload').trigger('click'); }
    $('#edUpload').on('change', function () {
        var files = Array.prototype.slice.call(this.files), dir = uploadDir, done = 0;
        this.value = '';
        if (!files.length) return;
        var $t = toastr.info('<div>Uploading <span class="up-n">0</span>/' + files.length + '</div><div class="progress mt-2"><div class="progress-bar up-bar" style="width:0%"></div></div>', '', { timeOut: 0, extendedTimeOut: 0, tapToDismiss: false });
        (function next() {
            if (!files.length) { toastr.clear($t); toastr.success(done + ' file(s) uploaded'); state.expanded[dir] = true; refreshDir(dir); return; }
            var f = files.shift(), fd = new FormData();
            fd.append('dir', dir);
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
        })();
    });

    $('[data-tree]').on('click', function (e) {
        e.preventDefault();
        var sel = state.selected && nodeFor(state.selected), selDir = sel && sel.length ? (sel.data('type') === 'dir' ? sel.data('path') : dirname(sel.data('path'))) : state.root;
        switch ($(this).data('tree')) {
            case 'up':
                if (ROOTS && siteOf(state.root) && siteOf(state.root).path === state.root) { chooseRoot(); break; }
                setRoot(dirname(state.root)); break;
            case 'refresh': renderTree(); break;
            case 'newFile': createItem(selDir, 'file'); break;
            case 'newFolder': createItem(selDir, 'dir'); break;
            case 'upload': uploadTo(selDir); break;
            case 'search': showSearch(); break;
            case 'explorer': showExplorer(); break;
            case 'collapse':
                state.expanded = {};
                store.set(expandedKey(), []);
                $tree.find('.tn.open').removeClass('open').next('.tn-children').prop('hidden', true).empty();
                break;
        }
    });

    function chooseRoot() {
        if (!ROOTS) {
            return GBX.prompt('Open directory', state.root, '/www/wwwroot/example.com').then(function (r) { if (r.isConfirmed) setRoot(r.value); });
        }
        var options = {}, current = siteOf(state.root);
        ROOTS.forEach(function (r) { options[r.path] = r.domain; });
        Swal.fire({
            title: 'Open website', input: 'select', inputOptions: options, inputValue: current ? current.path : ROOTS[0] && ROOTS[0].path,
            showCancelButton: true, confirmButtonText: 'Open', buttonsStyling: false, reverseButtons: true,
            customClass: { confirmButton: 'btn btn-primary', cancelButton: 'btn btn-outline-secondary me-2', input: 'form-select' }
        }).then(function (r) { if (r.isConfirmed && r.value) setRoot(r.value); });
    }
    $('#edRoot').on('click', chooseRoot);

    /* ================================================================ search in files */
    var searchOpts = $.extend({ case: false, word: false, regex: false }, store.get(C.storeKey + '.searchOpts', {}));
    var searchDir = null;
    $('.ed-opt').each(function () { $(this).toggleClass('on', !!searchOpts[$(this).data('opt')]); });
    $('.ed-opt').on('click', function () {
        var k = $(this).data('opt');
        searchOpts[k] = !searchOpts[k];
        $(this).toggleClass('on', searchOpts[k]);
        store.set(C.storeKey + '.searchOpts', searchOpts);
    });

    function showSearch(dir) {
        searchDir = dir || null;
        $('#edSearchScope').text(searchDir || state.root);
        $('#edExplorer').prop('hidden', true);
        $('#edSearch').prop('hidden', false);
        toggleSidebar(true);
        var selected = editor && editor.getModel() ? editor.getModel().getValueInRange(editor.getSelection()) : '';
        if (selected && selected.indexOf('\n') === -1 && selected.length < 200) $('#edSearchQuery').val(selected);
        setTimeout(function () { $('#edSearchQuery').trigger('focus').trigger('select'); }, 30);
    }
    function showExplorer() {
        $('#edSearch').prop('hidden', true);
        $('#edExplorer').prop('hidden', false);
    }

    function matcher(q) {
        try {
            var src = searchOpts.regex ? q : escapeRegExp(q);
            if (searchOpts.word) src = '\\b' + src + '\\b';
            return new RegExp(src, searchOpts.case ? 'g' : 'gi');
        } catch (e) { return null; }
    }

    function highlight(text, re) {
        if (!re) return esc(text);
        var out = '', last = 0, m, guard = 0;
        re.lastIndex = 0;
        while ((m = re.exec(text)) && guard++ < 50) {
            if (!m[0].length) { re.lastIndex++; continue; }
            out += esc(text.slice(last, m.index)) + '<mark>' + esc(m[0]) + '</mark>';
            last = m.index + m[0].length;
        }
        return out + esc(text.slice(last));
    }

    $('#edSearchForm').on('submit', function (e) {
        e.preventDefault();
        var q = $('#edSearchQuery').val(), mode = $('#edSearchMode').val(), dir = searchDir || state.root;
        if (!q) return;
        var $res = $('#edResults').html('<div class="sr-summary"><i class="bi bi-arrow-repeat spin"></i> Searching in ' + esc(dir) + '</div>');
        GBX.get(R.search, {
            dir: dir, query: q, mode: mode, include: $('#edSearchInclude').val(),
            case: searchOpts.case ? 1 : 0, word: searchOpts.word ? 1 : 0, regex: searchOpts.regex ? 1 : 0, skip_heavy: $('#edSearchSkip').is(':checked') ? 1 : 0
        }, { silent: true, timeout: 90000 }).done(function (r) {
            var re = matcher(q), html = '';
            if (!r.results.length) {
                $res.html('<div class="sr-summary">No results found.</div>');
                return;
            }
            if (mode === 'name') {
                html = r.results.map(function (it) {
                    return '<div class="sr-file" data-path="' + esc(it.path) + '" data-type="' + it.type + '"><i class="bi ' + iconFor(basename(it.path), it.type) + '"></i><span>' + highlight(basename(it.path), re) + '</span><span class="sr-dir">' + esc(relative(dirname(it.path))) + '</span></div>';
                }).join('');
                $res.html('<div class="sr-summary">' + r.results.length + (r.truncated ? '+' : '') + ' matching names</div>' + html);
                return;
            }
            var groups = {}, order = [];
            r.results.forEach(function (it) {
                if (!groups[it.path]) { groups[it.path] = []; order.push(it.path); }
                groups[it.path].push(it);
            });
            order.forEach(function (path) {
                html += '<div class="sr-group"><div class="sr-file" data-path="' + esc(path) + '" data-type="file"><i class="bi bi-chevron-down small sr-toggle"></i><i class="bi ' + iconFor(basename(path), 'file') + '"></i><span>' + esc(basename(path)) + '</span><span class="sr-dir">' + esc(relative(dirname(path))) + '</span><span class="sr-count">' + groups[path].length + '</span></div><div class="sr-lines">' +
                    groups[path].map(function (it) {
                        return '<div class="sr-line" data-path="' + esc(path) + '" data-line="' + it.line + '"><span class="sr-no">' + it.line + '</span><span class="sr-text">' + highlight(it.text.replace(/^\s+/, ''), re) + '</span></div>';
                    }).join('') + '</div></div>';
            });
            $res.html('<div class="sr-summary">' + r.results.length + (r.truncated ? '+' : '') + ' results in ' + order.length + ' files' + (r.truncated ? ' (showing the first 500)' : '') + '</div>' + html);
        }).fail(function (xhr) {
            $res.html($('<div class="sr-summary text-danger">').text(GBX.errorMessage(xhr)));
        });
    });

    $('#edResults').on('click', '.sr-file', function (e) {
        var $f = $(this), path = $f.data('path');
        if ($f.data('type') === 'dir') { setRoot(path); showExplorer(); return; }
        if ($f.next('.sr-lines').length && $(e.target).closest('.sr-toggle').length) { $f.next('.sr-lines').toggle(); return; }
        openFile(path);
    }).on('click', '.sr-line', function () {
        openFile($(this).data('path'), { line: $(this).data('line'), find: $('#edSearchQuery').val() });
        if (isMobile()) toggleSidebar(false);
    });

    /* ================================================================ tabs */
    function Tab(path) {
        this.path = path;
        this.name = basename(path);
        this.model = null;
        this.view = null;
        this.mtime = null;
        this.encoding = 'utf-8';
        this.saved = 0;
        this.loading = false;
        this.loaded = false;
        this.autoTimer = null;
    }
    Tab.prototype.dirty = function () { return !!this.model && this.model.getAlternativeVersionId() !== this.saved; };

    function findTab(path) { return state.tabs.find(function (t) { return t.path === path; }); }

    function renderTabs() {
        var names = {};
        state.tabs.forEach(function (t) { names[t.name] = (names[t.name] || 0) + 1; });
        $tabs.empty();
        state.tabs.forEach(function (t) {
            var $t = $('<div class="ed-tab" draggable="true">').data('tab', t).attr('title', t.path)
                .toggleClass('active', t === state.active).toggleClass('dirty', t.dirty())
                .append('<i class="bi ' + iconFor(t.name, 'file') + '"></i>')
                .append($('<span class="ed-tab-name">').text(t.name));
            if (names[t.name] > 1) $t.append($('<span class="ed-tab-hint">').text(basename(dirname(t.path))));
            $t.append('<span class="ed-tab-close" title="Close (Alt+W)">' + (t.loading ? '<i class="bi bi-arrow-repeat spin"></i>' : '<i class="bi bi-x"></i><span class="ed-tab-dot"></span>') + '</span>');
            t.$el = $t;
            $tabs.append($t);
        });
        var $active = $tabs.children('.active');
        if ($active.length) $active[0].scrollIntoView({ block: 'nearest', inline: 'nearest' });
        $('#edEmpty').prop('hidden', state.tabs.length > 0);
        document.title = (state.active ? (state.active.dirty() ? '* ' : '') + state.active.name + ' - ' : '') + 'Code Editor';
        var modal = parentModal();
        if (modal) modal.title(state.active ? (state.active.dirty() ? '* ' : '') + state.active.path : '');
    }

    function refreshDirty(tab) {
        if (tab.$el) tab.$el.toggleClass('dirty', tab.dirty());
        if (tab === state.active) {
            document.title = (tab.dirty() ? '* ' : '') + tab.name + ' - Code Editor';
            var modal = parentModal();
            if (modal) modal.title((tab.dirty() ? '* ' : '') + tab.path);
        }
    }

    $tabs.on('click', '.ed-tab', function (e) {
        var tab = $(this).data('tab');
        if ($(e.target).closest('.ed-tab-close').length) closeTab(tab);
        else activate(tab);
    }).on('auxclick', '.ed-tab', function (e) {
        if (e.button === 1) { e.preventDefault(); closeTab($(this).data('tab')); }
    }).on('contextmenu', '.ed-tab', function (e) {
        e.preventDefault();
        var tab = $(this).data('tab'), idx = state.tabs.indexOf(tab);
        menu([
            { label: 'Close', icon: 'bi-x-lg', key: 'Alt+W', action: function () { closeTab(tab); } },
            { label: 'Close others', action: function () { closeMany(state.tabs.filter(function (t) { return t !== tab; })); } },
            { label: 'Close to the right', disabled: idx === state.tabs.length - 1, action: function () { closeMany(state.tabs.slice(idx + 1)); } },
            { label: 'Close saved', action: function () { closeMany(state.tabs.filter(function (t) { return !t.dirty(); })); } },
            { label: 'Close all', action: function () { closeMany(state.tabs.slice()); } },
            '-',
            { label: 'Copy path', icon: 'bi-clipboard', action: function () { GBX.copy(tab.path); } },
            { label: 'Reveal in explorer', icon: 'bi-folder2-open', action: function () { reveal(tab.path); } },
            { label: 'Reload from server', icon: 'bi-arrow-clockwise', action: function () { reloadTab(tab); } }
        ], e.clientX, e.clientY);
    });

    // drag to reorder
    var dragTab = null;
    $tabs.on('dragstart', '.ed-tab', function (e) { dragTab = $(this).data('tab'); e.originalEvent.dataTransfer.effectAllowed = 'move'; })
        .on('dragover', '.ed-tab', function (e) { e.preventDefault(); $tabs.children().removeClass('drag-over'); $(this).addClass('drag-over'); })
        .on('dragleave', '.ed-tab', function () { $(this).removeClass('drag-over'); })
        .on('drop', '.ed-tab', function (e) {
            e.preventDefault();
            var target = $(this).data('tab');
            if (dragTab && target && dragTab !== target) {
                state.tabs.splice(state.tabs.indexOf(dragTab), 1);
                state.tabs.splice(state.tabs.indexOf(target), 0, dragTab);
                renderTabs();
                persistSession();
            }
            dragTab = null;
        })
        .on('dragend', '.ed-tab', function () { $tabs.children().removeClass('drag-over'); dragTab = null; });

    function openFile(path, opts) {
        opts = opts || {};
        path = normalize(path);
        var tab = findTab(path);
        if (!tab) {
            tab = new Tab(path);
            var idx = state.active ? state.tabs.indexOf(state.active) + 1 : state.tabs.length;
            state.tabs.splice(idx, 0, tab);
        }
        if (opts.activate === false) { renderTabs(); return $.when(tab); }
        return activate(tab).then(function () {
            if (opts.line) revealLine(tab, opts.line, opts.find);
            return tab;
        });
    }

    function loadTab(tab, encoding) {
        if (!monacoReady) {
            var d = $.Deferred();
            pendingOpen.push(function () { loadTab(tab, encoding).then(d.resolve, d.reject); });
            return d.promise();
        }
        tab.loading = true;
        renderTabs();
        return GBX.get(R.open, { path: tab.path, encoding: encoding || undefined }, { silent: true }).then(function (r) {
            tab.loading = false;
            tab.loaded = true;
            tab.mtime = r.mtime;
            tab.encoding = r.encoding;
            var uri = monaco.Uri.file(tab.path);
            var existing = monaco.editor.getModel(uri);
            if (tab.model) {
                tab.model.setValue(r.content);
            } else {
                if (existing) existing.dispose();
                tab.model = monaco.editor.createModel(r.content, languageFor(tab.path), uri);
                tab.model.updateOptions({ tabSize: settings.tabSize, insertSpaces: settings.insertSpaces });
                tab.model.onDidChangeContent(function () { onContentChange(tab); });
            }
            var noNewline = r.content.indexOf('\n') === -1;
            tab.model.setEOL((noNewline ? settings.defaultEol : r.eol) === 'CRLF' ? monaco.editor.EndOfLineSequence.CRLF : monaco.editor.EndOfLineSequence.LF);
            tab.saved = tab.model.getAlternativeVersionId();
            tab.meta = { size: r.size, perms: r.perms, owner: r.owner };
            renderTabs();
            addRecent(tab.path);
            return tab;
        }, function (xhr) {
            tab.loading = false;
            toastr.error(GBX.errorMessage(xhr), basename(tab.path));
            closeTab(tab, true);
            return $.Deferred().reject(xhr).promise();
        });
    }

    function activate(tab) {
        if (state.active && state.active !== tab && state.active.model && editor) {
            state.active.view = editor.saveViewState();
            if (settings.autoSave === 'focus' && state.active.dirty()) save(state.active, { quiet: true });
        }
        state.active = tab;
        renderTabs();
        markActiveInTree();
        persistSession();
        syncUrl();

        var ready = tab.loaded ? $.when(tab) : (tab.pending || (tab.pending = loadTab(tab)));
        showLoading(!tab.loaded);
        return ready.then(function () {
            if (state.active !== tab || !editor) return tab;
            showLoading(false);
            editor.setModel(tab.model);
            editor.updateOptions({ readOnly: C.readOnly });
            if (tab.view) editor.restoreViewState(tab.view);
            if (!isMobile()) editor.focus();
            updateStatus();
            return tab;
        });
    }

    function showLoading(on) {
        $('#edLoading').prop('hidden', !on || !state.tabs.length).html('<i class="bi bi-arrow-repeat spin"></i> ' + (monacoReady ? 'Opening file...' : 'Loading editor...'));
    }

    function closeTab(tab, force) {
        if (!force && tab.dirty()) {
            return Swal.fire({
                title: 'Save changes?', html: 'Do you want to save the changes you made to <strong>' + esc(tab.name) + '</strong>?',
                icon: 'warning', showDenyButton: true, showCancelButton: true, confirmButtonText: 'Save', denyButtonText: "Don't save", cancelButtonText: 'Cancel',
                reverseButtons: false, buttonsStyling: false,
                customClass: { confirmButton: 'btn btn-primary me-2', denyButton: 'btn btn-outline-danger me-2', cancelButton: 'btn btn-outline-secondary' }
            }).then(function (r) {
                if (r.isConfirmed) return save(tab).then(function () { return closeTab(tab, true); });
                if (r.isDenied) return closeTab(tab, true);
                return false;
            });
        }
        var idx = state.tabs.indexOf(tab);
        if (idx === -1) return $.when(true);
        clearTimeout(tab.autoTimer);
        state.tabs.splice(idx, 1);
        if (state.active === tab) {
            state.active = null;
            if (editor) editor.setModel(null);
            var next = state.tabs[Math.min(idx, state.tabs.length - 1)];
            if (next) activate(next);
        }
        if (tab.model) tab.model.dispose();
        renderTabs();
        markActiveInTree();
        updateStatus();
        persistSession();
        syncUrl();
        return $.when(true);
    }

    function closeMany(list) {
        var dirty = list.filter(function (t) { return t.dirty(); });
        var run = function () { list.forEach(function (t) { closeTab(t, true); }); };
        if (!dirty.length) return run();
        GBX.confirm({ title: 'Unsaved changes', text: dirty.length + ' file(s) have unsaved changes. Close without saving?', danger: true, confirmText: 'Close without saving' })
            .then(function (r) { if (r.isConfirmed) run(); });
    }

    function reveal(path) {
        toggleSidebar(true);
        showExplorer();
        if (!isInside(path, state.root)) {
            setRoot(dirname(path)).then(function () { select(nodeFor(path)); });
            return;
        }
        // expand every ancestor below the root, then select the file
        var parts = relative(dirname(path)).split('/').filter(Boolean), chain = $.when(), acc = state.root;
        if (dirname(path) === state.root) parts = [];
        parts.forEach(function (part) {
            acc = join(acc, part);
            var p = acc;
            chain = chain.then(function () {
                var $n = nodeFor(p);
                return $n.length && !$n.hasClass('open') ? expand($n) : null;
            });
        });
        chain.then(function () { select(nodeFor(path)); markActiveInTree(); });
    }

    function revealLine(tab, line, find) {
        if (state.active !== tab || !editor || !tab.model) return;
        var model = tab.model, lineNo = Math.min(Math.max(1, parseInt(line, 10) || 1), model.getLineCount());
        var startCol = 1, endCol = 1;
        if (find) {
            var re = matcher(find), text = model.getLineContent(lineNo);
            if (re) {
                re.lastIndex = 0;
                var m = re.exec(text);
                if (m) { startCol = m.index + 1; endCol = startCol + m[0].length; }
            }
        }
        editor.setSelection(new monaco.Range(lineNo, startCol, lineNo, endCol));
        editor.revealLineInCenter(lineNo);
        var deco = editor.deltaDecorations([], [{ range: new monaco.Range(lineNo, 1, lineNo, 1), options: { isWholeLine: true, className: 'ed-flash-line' } }]);
        setTimeout(function () { editor.deltaDecorations(deco, []); }, 1600);
        editor.focus();
    }

    function onContentChange(tab) {
        refreshDirty(tab);
        if (C.readOnly) return;
        var delay = parseInt(settings.autoSave, 10);
        if (delay > 0) {
            clearTimeout(tab.autoTimer);
            tab.autoTimer = setTimeout(function () { if (tab.dirty()) save(tab, { quiet: true }); }, delay);
        }
    }

    /* ================================================================ save / reload */
    function beforeSave(tab) {
        var model = tab.model, edits = [];
        if (settings.trimOnSave) {
            for (var i = 1; i <= model.getLineCount(); i++) {
                var text = model.getLineContent(i), trimmed = text.replace(/[ \t]+$/, '');
                if (trimmed.length !== text.length) edits.push({ range: new monaco.Range(i, trimmed.length + 1, i, text.length + 1), text: '' });
            }
        }
        if (settings.finalNewline) {
            var last = model.getLineCount();
            if (model.getLineContent(last) !== '') edits.push({ range: new monaco.Range(last, model.getLineMaxColumn(last), last, model.getLineMaxColumn(last)), text: model.getEOL() });
        }
        if (edits.length) model.pushEditOperations([], edits, function () { return null; });
        return Promise.resolve();
    }

    function save(tab, opts) {
        opts = opts || {};
        var d = $.Deferred();
        if (!tab || !tab.model) return d.reject().promise();
        if (C.readOnly) { toastr.warning('Your account has read-only access.'); return d.reject().promise(); }
        if (tab.saving) return tab.saving;
        tab.saving = d.promise();

        beforeSave(tab).then(function () {
            var version = tab.model.getAlternativeVersionId();
            GBX.post(R.write, { path: tab.path, content: tab.model.getValue(), encoding: tab.encoding, mtime: opts.force ? null : tab.mtime, force: opts.force ? 1 : 0 }, { silent: true })
                .done(function (r) {
                    tab.mtime = r.mtime;
                    tab.saved = version;
                    refreshDirty(tab);
                    $('#stSaved').removeClass('d-none').html('<i class="bi bi-check2 dot-ok"></i> Saved ' + timeNow());
                    if (!opts.quiet) toastr.success(r.message, '', { timeOut: 1600 });
                    d.resolve(tab);
                })
                .fail(function (xhr) {
                    if (xhr.status === 409) {
                        Swal.fire({
                            title: 'File changed on the server', icon: 'warning',
                            html: '<span class="font-mono small text-break">' + esc(tab.path) + '</span><p class="mt-2 mb-0">It was modified after you opened it. Overwrite the server version or reload it and lose your changes?</p>',
                            showDenyButton: true, showCancelButton: true, confirmButtonText: 'Overwrite', denyButtonText: 'Reload from server', buttonsStyling: false,
                            customClass: { confirmButton: 'btn btn-danger me-2', denyButton: 'btn btn-outline-secondary me-2', cancelButton: 'btn btn-outline-secondary' }
                        }).then(function (res) {
                            if (res.isConfirmed) save(tab, { force: true }).then(d.resolve, d.reject);
                            else if (res.isDenied) reloadTab(tab, true).then(d.reject, d.reject);
                            else d.reject();
                        });
                        return;
                    }
                    toastr.error(GBX.errorMessage(xhr), 'Save failed: ' + tab.name);
                    d.reject(xhr);
                })
                .always(function () { tab.saving = null; });
        });
        return d.promise();
    }

    function saveAll() {
        var dirty = state.tabs.filter(function (t) { return t.dirty(); });
        if (!dirty.length) { toastr.info('No unsaved changes.', '', { timeOut: 1500 }); return; }
        var chain = $.when();
        dirty.forEach(function (t) { chain = chain.then(function () { return save(t, { quiet: true }).then(null, function () { return $.when(); }); }); });
        chain.then(function () {
            var left = state.tabs.filter(function (t) { return t.dirty(); }).length;
            left ? toastr.warning(left + ' file(s) could not be saved.') : toastr.success(dirty.length + ' file(s) saved', '', { timeOut: 1800 });
        });
    }

    function reloadTab(tab, discard, encoding) {
        var run = function () {
            var view = tab === state.active && editor ? editor.saveViewState() : tab.view;
            return loadTab(tab, encoding).then(function () {
                if (tab === state.active && editor) { editor.setModel(tab.model); if (view) editor.restoreViewState(view); updateStatus(); }
                refreshDirty(tab);
            });
        };
        if (!tab.dirty() || discard) return run();
        return GBX.confirm({ title: 'Discard changes?', text: 'Reloading ' + tab.name + ' from the server discards your unsaved changes.', danger: true, confirmText: 'Reload' })
            .then(function (r) { return r.isConfirmed ? run() : null; });
    }

    /* ================================================================ status bar */
    function updateStatus() {
        var tab = state.active, model = tab && tab.model;
        if (!tab || !model || !editor) {
            $('#stPath, #stPos, #stSel, #stIndent, #stEnc, #stEol, #stLang').text('');
            $('#stSaved').addClass('d-none');
            return;
        }
        var pos = editor.getPosition() || { lineNumber: 1, column: 1 }, opts = model.getOptions();
        $('#stPath').text(LRM + tab.path + LRM);
        $('#stPos').text('Ln ' + pos.lineNumber + ', Col ' + pos.column);
        var selLen = 0;
        (editor.getSelections() || []).forEach(function (s) { selLen += model.getValueLengthInRange(s); });
        $('#stSel').text(selLen ? selLen + ' selected' : '');
        $('#stIndent').text((opts.insertSpaces ? 'Spaces: ' : 'Tab Size: ') + opts.tabSize);
        $('#stEnc').text(ENCODING_LABELS[tab.encoding] || tab.encoding);
        $('#stEol').text(model.getEOL() === '\r\n' ? 'CRLF' : 'LF');
        var lang = monaco.languages.getLanguages().find(function (l) { return l.id === model.getLanguageId(); });
        $('#stLang').text(lang ? (lang.aliases && lang.aliases[0]) || lang.id : model.getLanguageId());
    }

    function statusMenu(e, items) {
        var r = e.currentTarget.getBoundingClientRect();
        menu(items, r.left, r.top, { above: true });
    }

    $('#stPath').on('click', function () { if (state.active) GBX.copy(state.active.path); });
    $('#stPos').on('click', function () { commands.gotoLine(); });
    $('#stEol').on('click', function (e) {
        var model = state.active && state.active.model;
        if (!model) return;
        var crlf = model.getEOL() === '\r\n';
        statusMenu(e, [
            { header: 'Line endings' },
            { label: 'LF (Linux, macOS)', active: !crlf, action: function () { model.pushEOL(monaco.editor.EndOfLineSequence.LF); updateStatus(); } },
            { label: 'CRLF (Windows)', active: crlf, action: function () { model.pushEOL(monaco.editor.EndOfLineSequence.CRLF); updateStatus(); } }
        ]);
    });
    $('#stIndent').on('click', function (e) {
        var model = state.active && state.active.model;
        if (!model) return;
        var o = model.getOptions(), items = [{ header: 'Indent using spaces' }];
        [2, 4, 8].forEach(function (n) { items.push({ label: 'Spaces: ' + n, active: o.insertSpaces && o.tabSize === n, action: function () { model.updateOptions({ insertSpaces: true, tabSize: n }); updateStatus(); } }); });
        items.push({ header: 'Indent using tabs' });
        [2, 4, 8].forEach(function (n) { items.push({ label: 'Tab size: ' + n, active: !o.insertSpaces && o.tabSize === n, action: function () { model.updateOptions({ insertSpaces: false, tabSize: n }); updateStatus(); } }); });
        items.push('-');
        items.push({ label: 'Detect from content', action: function () { editor.trigger('gbx', 'editor.action.detectIndentation'); setTimeout(updateStatus, 50); } });
        items.push({ label: 'Convert indentation to spaces', action: function () { editor.trigger('gbx', 'editor.action.indentationToSpaces'); } });
        items.push({ label: 'Convert indentation to tabs', action: function () { editor.trigger('gbx', 'editor.action.indentationToTabs'); } });
        statusMenu(e, items);
    });
    $('#stEnc').on('click', function (e) {
        var tab = state.active;
        if (!tab || !tab.model) return;
        var items = [{ header: 'Reopen with encoding' }];
        C.encodings.forEach(function (enc) {
            items.push({ label: ENCODING_LABELS[enc] || enc, active: tab.encoding === enc, action: function () { reloadTab(tab, false, enc); } });
        });
        if (!C.readOnly) {
            items.push({ header: 'Save with encoding' });
            C.encodings.forEach(function (enc) {
                items.push({ label: ENCODING_LABELS[enc] || enc, icon: 'bi-save', action: function () { tab.encoding = enc; save(tab).always(updateStatus); } });
            });
        }
        statusMenu(e, items);
    });
    $('#stLang').on('click', function () { languagePicker(); });

    /* ================================================================ palettes */
    var quick = { items: [], index: 0, mode: 'file', xhr: null };

    function recent() { return store.get(C.storeKey + '.recent', []); }
    function addRecent(path) { store.set(C.storeKey + '.recent', [path].concat(recent().filter(function (p) { return p !== path; })).slice(0, 30)); }

    function openPalette(mode, placeholder) {
        quick.mode = mode;
        $('#edQuick').prop('hidden', false);
        $('#edQuickInput').val('').attr('placeholder', placeholder).trigger('focus');
        paletteFilter('');
    }
    function closePalette() {
        $('#edQuick').prop('hidden', true);
        if (quick.xhr) quick.xhr.abort();
        if (editor && state.active) editor.focus();
    }
    function paletteRender(message) {
        var html = quick.items.map(function (it, i) {
            return '<div class="qo-item' + (i === quick.index ? ' active' : '') + '" data-i="' + i + '">' + (it.icon ? '<i class="bi ' + it.icon + '"></i>' : '') +
                '<span>' + esc(it.label) + '</span>' + (it.hint ? '<span class="qo-dir">' + esc(it.hint) + '</span>' : '') + '</div>';
        }).join('');
        $('#edQuickList').html(html + (message ? '<div class="qo-msg">' + message + '</div>' : ''));
        var $a = $('#edQuickList .qo-item.active');
        if ($a.length) $a[0].scrollIntoView({ block: 'nearest' });
    }
    var remoteSearch = debounce(function (q) {
        if (quick.xhr) quick.xhr.abort();
        quick.xhr = GBX.get(R.search, { dir: state.root, query: q, mode: 'name', skip_heavy: 1 }, { silent: true }).done(function (r) {
            if ($('#edQuickInput').val() !== q) return;
            var seen = {};
            quick.items.forEach(function (it) { seen[it.path] = true; });
            r.results.filter(function (it) { return it.type === 'file' && !seen[it.path]; }).slice(0, 100).forEach(function (it) {
                quick.items.push({ label: basename(it.path), hint: relative(dirname(it.path)), icon: iconFor(basename(it.path), 'file'), path: it.path });
            });
            paletteRender(quick.items.length ? '' : 'No files match.');
        }).fail(function (xhr, status) { if (status !== 'abort') paletteRender(esc(GBX.errorMessage(xhr))); });
    }, 220);

    function paletteFilter(q) {
        quick.index = 0;
        var lq = q.toLowerCase();
        if (quick.mode === 'language') {
            quick.items = monaco.languages.getLanguages().map(function (l) {
                return { label: (l.aliases && l.aliases[0]) || l.id, hint: l.id, lang: l.id };
            }).filter(function (it) { return !lq || it.label.toLowerCase().indexOf(lq) !== -1 || it.lang.indexOf(lq) !== -1; })
              .sort(function (a, b) { return a.label.localeCompare(b.label); });
            paletteRender(quick.items.length ? '' : 'No language matches.');
            return;
        }
        var paths = state.tabs.map(function (t) { return t.path; }).concat(recent()), seen = {};
        quick.items = paths.filter(function (p) {
            if (seen[p]) return false;
            seen[p] = true;
            return !lq || basename(p).toLowerCase().indexOf(lq) !== -1 || p.toLowerCase().indexOf(lq) !== -1;
        }).slice(0, 20).map(function (p) { return { label: basename(p), hint: relative(dirname(p)), icon: iconFor(basename(p), 'file'), path: p }; });
        if (q.length >= 2) {
            paletteRender('<i class="bi bi-arrow-repeat spin"></i> Searching ' + esc(state.root));
            remoteSearch(q);
        } else {
            paletteRender(quick.items.length ? '' : 'Type at least 2 characters to search in ' + esc(state.root));
        }
    }
    function palettePick(i) {
        var it = quick.items[i];
        if (!it) return;
        closePalette();
        if (quick.mode === 'language') {
            if (state.active && state.active.model) { monaco.editor.setModelLanguage(state.active.model, it.lang); updateStatus(); }
        } else {
            openFile(it.path);
        }
    }
    function languagePicker() {
        if (!state.active || !state.active.model) return;
        openPalette('language', 'Select language mode');
    }

    $('#edQuickInput').on('input', function () { paletteFilter(this.value); }).on('keydown', function (e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); quick.index = Math.min(quick.items.length - 1, quick.index + 1); paletteRender(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); quick.index = Math.max(0, quick.index - 1); paletteRender(); }
        else if (e.key === 'Enter') { e.preventDefault(); palettePick(quick.index); }
        else if (e.key === 'Escape') { e.preventDefault(); closePalette(); }
    });
    $('#edQuickList').on('click', '.qo-item', function () { palettePick($(this).data('i')); });
    $('#edQuick').on('mousedown', function (e) { if (e.target === this) closePalette(); });

    /* ================================================================ settings */
    function editorOptions() {
        return {
            theme: 'gbx-dark', fontSize: +settings.fontSize, lineHeight: +settings.lineHeight || 0, fontFamily: settings.fontFamily,
            fontLigatures: !!settings.ligatures, wordWrap: settings.wordWrap, renderWhitespace: settings.renderWhitespace,
            lineNumbers: settings.lineNumbers, cursorStyle: settings.cursorStyle, minimap: { enabled: !!settings.minimap },
            stickyScroll: { enabled: !!settings.stickyScroll }, bracketPairColorization: { enabled: !!settings.bracketPairs },
            guides: { indentation: !!settings.guides, bracketPairs: settings.bracketPairs ? 'active' : false },
            smoothScrolling: !!settings.smoothScrolling, cursorSmoothCaretAnimation: settings.smoothScrolling ? 'on' : 'off',
            readOnly: C.readOnly
        };
    }

    function applySettings() {
        store.set(C.storeKey + '.settings', settings);
        if (!monacoReady) return;
        editor.updateOptions(editorOptions());
    }

    function fillSettingsForm() {
        var $f = $('#edSettingsForm');
        Object.keys(settings).forEach(function (k) {
            var $el = $f.find('[name="' + k + '"]');
            if (!$el.length) return;
            if ($el.is(':checkbox')) $el.prop('checked', !!settings[k]);
            else if (k === 'insertSpaces') $el.val(settings[k] ? '1' : '0');
            else $el.val(String(settings[k]));
        });
    }
    $('#edSettingsForm').on('submit', function (e) {
        e.preventDefault();
        var $f = $(this);
        $f.find('[name]').each(function () {
            var k = this.name;
            if (this.type === 'checkbox') settings[k] = this.checked;
            else if (k === 'insertSpaces') settings[k] = this.value === '1';
            else if (['fontSize', 'lineHeight', 'tabSize'].indexOf(k) !== -1) settings[k] = parseInt(this.value, 10) || DEFAULTS[k];
            else settings[k] = this.value;
        });
        settings.fontSize = Math.max(9, Math.min(32, settings.fontSize));
        state.tabs.forEach(function (t) { if (t.model) t.model.updateOptions({ tabSize: settings.tabSize, insertSpaces: settings.insertSpaces }); });
        applySettings();
        updateStatus();
        bootstrap.Modal.getOrCreateInstance('#edSettings').hide();
        toastr.success('Settings applied', '', { timeOut: 1400 });
    });
    $('#edSettingsReset').on('click', function () {
        settings = $.extend({}, DEFAULTS);
        fillSettingsForm();
    });

    /* ================================================================ commands */
    function runAction(id, label) {
        if (!editor || !state.active || !state.active.model) { toastr.info('Open a file first.', '', { timeOut: 1500 }); return; }
        editor.focus();
        var action = editor.getAction(id);
        if (action && action.isSupported && !action.isSupported()) {
            toastr.info((label || 'This action') + ' is not available for ' + $('#stLang').text() + ' files.', '', { timeOut: 2200 });
            return;
        }
        if (action) action.run(); else editor.trigger('gbx', id, null);
    }

    function toggleSidebar(show) {
        var hidden = $app.hasClass('side-hidden');
        if (typeof show === 'boolean' && show === !hidden) return;
        $app.toggleClass('side-hidden', typeof show === 'boolean' ? !show : !hidden);
        if (!isMobile()) store.set(C.storeKey + '.sidebar', !$app.hasClass('side-hidden'));
    }

    var commands = {
        save: function () { if (state.active) save(state.active); },
        saveAll: saveAll,
        reload: function () { if (state.active) reloadTab(state.active); else renderTree(); },
        find: function () { runAction('actions.find'); },
        replace: function () { runAction('editor.action.startFindReplaceAction'); },
        gotoLine: function () { runAction('editor.action.gotoLine'); },
        palette: function () { runAction('editor.action.quickCommand'); },
        quickOpen: function () { openPalette('file', 'Go to file by name (searches ' + state.root + ')'); },
        searchFiles: function () { showSearch(); },
        toggleSidebar: function () { toggleSidebar(); },
        closeTab: function () { if (state.active) closeTab(state.active); },
        nextTab: function (dir) {
            if (state.tabs.length < 2) return;
            var i = state.tabs.indexOf(state.active);
            activate(state.tabs[(i + (dir || 1) + state.tabs.length) % state.tabs.length]);
        },
        settings: function () { fillSettingsForm(); bootstrap.Modal.getOrCreateInstance('#edSettings').show(); },
        shortcuts: function () { bootstrap.Modal.getOrCreateInstance('#edShortcuts').show(); },
        fullscreen: function () {
            if (document.fullscreenElement) document.exitFullscreen();
            else if (document.documentElement.requestFullscreen) document.documentElement.requestFullscreen();
        }
    };
    $('[data-cmd]').on('click', function () { commands[$(this).data('cmd')](); });
    $(document).on('fullscreenchange', function () {
        $app.toggleClass('fullscreen', !!document.fullscreenElement);
        $('[data-cmd=fullscreen] i').attr('class', 'bi ' + (document.fullscreenElement ? 'bi-fullscreen-exit' : 'bi-arrows-fullscreen'));
    });

    // shortcuts while the focus is outside Monaco (tree, tabs, search panel)
    $(document).on('keydown', function (e) {
        if ($(e.target).closest('.monaco-editor').length) return;
        var ctrl = e.ctrlKey || e.metaKey, k = (e.key || '').toLowerCase();
        var run = function (fn) { e.preventDefault(); fn(); };
        if (ctrl && e.altKey && k === 's') return run(commands.saveAll);
        if (ctrl && !e.shiftKey && k === 's') return run(commands.save);
        if (ctrl && !e.shiftKey && k === 'p') return run(commands.quickOpen);
        if (ctrl && !e.shiftKey && k === 'b') return run(commands.toggleSidebar);
        if (ctrl && e.shiftKey && k === 'f') return run(commands.searchFiles);
        if (e.altKey && !ctrl && k === 'w') return run(commands.closeTab);
        if (e.key === 'F1') return run(commands.palette);
        if (e.key === 'Escape' && !$('#edQuick').prop('hidden')) return run(closePalette);
    });

    $('#edBackdrop').on('click', function () { toggleSidebar(false); });

    /* ================================================================ resizer */
    (function () {
        var w = store.get(C.storeKey + '.sideWidth', 280);
        $app[0].style.setProperty('--ed-side-w', w + 'px');
        $('#edResizer').on('mousedown', function (e) {
            e.preventDefault();
            var $r = $(this).addClass('dragging');
            $(document).on('mousemove.edr', function (ev) {
                w = Math.max(180, Math.min(window.innerWidth * 0.6, ev.clientX));
                $app[0].style.setProperty('--ed-side-w', w + 'px');
            }).on('mouseup.edr', function () {
                $r.removeClass('dragging');
                $(document).off('.edr');
                store.set(C.storeKey + '.sideWidth', Math.round(w));
            });
        });
    })();

    /* ================================================================ session */
    function persistSession() {
        if (!settings.restoreSession) return;
        store.set(C.storeKey + '.session', { root: state.root, tabs: state.tabs.map(function (t) { return t.path; }).slice(0, 20), active: state.active ? state.active.path : null });
    }

    function syncUrl() {
        var params = new URLSearchParams();
        if (C.embed) params.set('embed', '1');
        params.set('root', state.root);
        if (state.active) params.set('open', state.active.path);
        history.replaceState(null, '', location.pathname + '?' + params.toString());
    }

    if (!C.embed) window.addEventListener('beforeunload', function (e) {
        if (state.tabs.some(function (t) { return t.dirty(); })) { e.preventDefault(); e.returnValue = ''; }
    });

    // API used by the editor modal (GBX.editor in gbx.js) on the parent page
    function parentModal() {
        try { return C.embed && window.parent !== window && window.parent.GBX && window.parent.GBX.editor; } catch (e) { return null; }
    }
    window.GBX_EDITOR_API = {
        open: function (path, root) {
            if (root && !state.tabs.length && root !== state.root && allowedRoot(root)) setRoot(root);
            openFile(path);
        },
        setRoot: function (root) { if (normalize(root) !== state.root && allowedRoot(root)) setRoot(root); showExplorer(); toggleSidebar(true); },
        focus: function () {
            if (editor && state.active && state.active.model && !isMobile()) editor.focus(); else $tree.trigger('focus');
            if (editor) editor.layout();
        },
        dirtyCount: function () { return state.tabs.filter(function (t) { return t.dirty(); }).length; },
        saveAll: function () {
            var d = $.Deferred(), chain = $.when();
            state.tabs.filter(function (t) { return t.dirty(); }).forEach(function (t) {
                chain = chain.then(function () { return save(t, { quiet: true }).then(null, function () { return $.when(); }); });
            });
            chain.always(function () { d.resolve(window.GBX_EDITOR_API.dirtyCount()); });
            return d.promise();
        },
        discardAll: function () {
            state.tabs.filter(function (t) { return t.dirty(); }).forEach(function (t) { closeTab(t, true); });
        }
    };

    /* ================================================================ monaco */
    function registerLanguages() {
        monaco.languages.register({ id: 'apache', extensions: ['.htaccess', '.conf'], aliases: ['Apache config', 'apache'] });
        monaco.languages.setMonarchTokensProvider('apache', {
            ignoreCase: true,
            tokenizer: {
                root: [
                    [/^\s*#.*$/, 'comment'],
                    [/<\/?[A-Za-z]+/, { token: 'tag', next: '@section' }],
                    [/^\s*[A-Za-z][\w]*/, 'keyword'],
                    [/"([^"\\]|\\.)*"/, 'string'],
                    [/%\{[^}]*\}|\$\{[^}]*\}|\$\d/, 'variable'],
                    [/\[[A-Z0-9,=_\-]+\]/, 'type'],
                    [/\b(on|off|all|none|granted|denied)\b/, 'constant'],
                    [/\b\d+\b/, 'number']
                ],
                section: [
                    [/>/, { token: 'tag', next: '@pop' }],
                    [/"([^"\\]|\\.)*"/, 'string'],
                    [/[^>"]+/, 'attribute.value']
                ]
            }
        });
        monaco.languages.setLanguageConfiguration('apache', { comments: { lineComment: '#' }, brackets: [['<', '>']], autoClosingPairs: [{ open: '"', close: '"' }] });

        monaco.languages.register({ id: 'log', extensions: ['.log'], aliases: ['Log', 'log'] });
        monaco.languages.setMonarchTokensProvider('log', {
            tokenizer: {
                root: [
                    [/\b(ERROR|ERR|FATAL|CRITICAL|CRIT|ALERT|EMERG|EMERGENCY|error|crit|alert|fatal|emerg|Exception)\b/, 'log-error'],
                    [/\b(WARN|WARNING|NOTICE|warn|warning|notice)\b/, 'log-warn'],
                    [/\b(INFO|DEBUG|info|debug)\b/, 'log-info'],
                    [/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+\-]\d{2}:?\d{2})?/, 'log-date'],
                    [/\[\w{3} \w{3} \d{1,2} [\d:.]+ \d{4}\]/, 'log-date'],
                    [/\b\d{1,3}(\.\d{1,3}){3}\b/, 'number'],
                    [/"[^"]*"/, 'string']
                ]
            }
        });
    }

    function registerThemes() {
        monaco.editor.defineTheme('gbx-dark', THEME);
    }

    function initMonaco() {
        registerLanguages();
        registerThemes();

        var noSemantic = { noSemanticValidation: true, noSyntaxValidation: false };
        monaco.languages.typescript.javascriptDefaults.setDiagnosticsOptions(noSemantic);
        monaco.languages.typescript.typescriptDefaults.setDiagnosticsOptions(noSemantic);
        monaco.languages.typescript.javascriptDefaults.setCompilerOptions({ allowNonTsExtensions: true, allowJs: true, target: monaco.languages.typescript.ScriptTarget.ES2020 });

        editor = monaco.editor.create(document.getElementById('edEditor'), $.extend(editorOptions(), {
            model: null, automaticLayout: true, detectIndentation: true, scrollBeyondLastLine: true, mouseWheelZoom: true,
            fixedOverflowWidgets: true, renderLineHighlight: 'all', padding: { top: 8 }, tabCompletion: 'on', linkedEditing: true,
            unicodeHighlight: { ambiguousCharacters: false }, dragAndDrop: true, contextmenu: true
        }));

        var K = monaco.KeyMod, KC = monaco.KeyCode;
        [
            { id: 'gbx.save', label: 'File: Save', keys: [K.CtrlCmd | KC.KeyS], run: commands.save },
            { id: 'gbx.saveAll', label: 'File: Save All', keys: [K.CtrlCmd | K.Alt | KC.KeyS], run: commands.saveAll },
            { id: 'gbx.quickOpen', label: 'File: Go to File', keys: [K.CtrlCmd | KC.KeyP], run: commands.quickOpen },
            { id: 'gbx.searchFiles', label: 'Search: Find in Files', keys: [K.CtrlCmd | K.Shift | KC.KeyF], run: commands.searchFiles },
            { id: 'gbx.toggleSidebar', label: 'View: Toggle Explorer', keys: [K.CtrlCmd | KC.KeyB], run: commands.toggleSidebar },
            { id: 'gbx.closeTab', label: 'File: Close Tab', keys: [K.Alt | KC.KeyW], run: commands.closeTab },
            { id: 'gbx.nextTab', label: 'View: Next Tab', keys: [K.CtrlCmd | K.Alt | KC.RightArrow], run: function () { commands.nextTab(1); } },
            { id: 'gbx.prevTab', label: 'View: Previous Tab', keys: [K.CtrlCmd | K.Alt | KC.LeftArrow], run: function () { commands.nextTab(-1); } },
            { id: 'gbx.reload', label: 'File: Reload From Server', run: commands.reload },
            { id: 'gbx.language', label: 'Change Language Mode', run: languagePicker },
            { id: 'gbx.reveal', label: 'File: Reveal in Explorer', run: function () { if (state.active) reveal(state.active.path); } },
            { id: 'gbx.copyPath', label: 'File: Copy Path', run: function () { if (state.active) GBX.copy(state.active.path); } },
            { id: 'gbx.settings', label: 'Preferences: Editor Settings', run: commands.settings },
            { id: 'gbx.wrap', label: 'View: Toggle Word Wrap', keys: [K.Alt | KC.KeyZ], run: function () { settings.wordWrap = settings.wordWrap === 'off' ? 'on' : 'off'; applySettings(); } }
        ].forEach(function (a) {
            editor.addAction({ id: a.id, label: a.label, keybindings: a.keys || [], contextMenuGroupId: a.id === 'gbx.copyPath' || a.id === 'gbx.reveal' ? '9_gbx' : undefined, run: function () { a.run(); } });
        });

        editor.onDidChangeCursorPosition(updateStatus);
        editor.onDidChangeCursorSelection(debounce(updateStatus, 60));
        editor.onDidChangeModelLanguage(updateStatus);
        editor.onDidChangeModelOptions(updateStatus);

        monacoReady = true;
        pendingOpen.splice(0).forEach(function (fn) { fn(); });
        showLoading(false);
        if (state.active && !state.active.loaded && !state.active.loading) activate(state.active);
    }

    /* ================================================================ boot */
    var wasMobile = isMobile();
    if (wasMobile || store.get(C.storeKey + '.sidebar', true) === false) $app.addClass('side-hidden');
    // the explorer is an overlay on phones: hide it when shrinking, restore the saved state when growing
    $(window).on('resize', debounce(function () {
        var mobile = isMobile();
        if (mobile === wasMobile) return;
        wasMobile = mobile;
        $app.toggleClass('side-hidden', mobile || store.get(C.storeKey + '.sidebar', true) === false);
    }, 150));

    renderTree();
    renderTabs();

    var restore = settings.restoreSession && session && session.root === state.root ? session.tabs || [] : [];
    restore.forEach(function (p) { if (!findTab(p)) state.tabs.push(new Tab(p)); });
    var first = C.open || (session && session.root === state.root && session.active) || (state.tabs[0] && state.tabs[0].path);
    if (first) openFile(first); else renderTabs();

    showLoading(true);
    require.config({ paths: { vs: C.vs } });
    require(['vs/editor/editor.main'], initMonaco, function (err) {
        $('#edLoading').prop('hidden', false).html('<span class="text-danger"><i class="bi bi-exclamation-triangle"></i> The editor could not be loaded (' + esc(String(err && err.message || err)) + '). Run gbx update on the server.</span>');
    });
})(jQuery);
