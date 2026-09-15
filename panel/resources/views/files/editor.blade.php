@php
    // shared by the administrator (files.*) and the client sub-panel (client.files.*, only the client's websites)
    $panelTitle = \App\Models\Setting::get('panel_title', 'GBX Panel');
    $account ??= ['name' => auth()->user()->name, 'role' => auth()->user()->role];
    $roots ??= null;
    $filesUrl ??= route('files.index');
    $storeKey ??= 'gbx.editor';
    $routes ??= [
        'list' => route('files.list'), 'open' => route('files.open'), 'write' => route('files.write'),
        'search' => route('files.search'), 'create' => route('files.create'), 'rename' => route('files.rename'),
        'del' => route('files.delete'), 'upload' => route('files.upload'), 'download' => route('files.download'),
    ];
@endphp
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>Code Editor · {{ $panelTitle }}</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%23e8eaed'/%3E%3Cpath d='M12 10l-5 6 5 6M20 10l5 6-5 6' stroke='%230d0f12' stroke-width='2.6' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E">
    <link rel="stylesheet" href="{{ asset('assets/vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vendor/toastr/toastr.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/gbx.css') }}?v={{ config('gbx.version') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/editor.css') }}?v={{ config('gbx.version') }}">
    <link rel="stylesheet" data-name="vs/editor/editor.main" href="{{ asset('assets/vendor/monaco/vs/editor/editor.main.css') }}">
</head>
<body class="ed-body{{ $embed ? ' ed-embed' : '' }}">

<div class="ed-app" id="edApp">
    {{-- ------------------------------------------------------------ toolbar --}}
    <header class="ed-toolbar">
        @unless ($embed)
            <a href="{{ $filesUrl }}?path={{ urlencode($root) }}" class="ed-brand" title="Back to Files">
                <span class="ed-logo"><i class="bi bi-code-slash"></i></span>
                <span class="ed-brand-text">Code Editor</span>
            </a>
        @endunless
        <button class="ed-tb" data-cmd="toggleSidebar" title="Toggle explorer (Ctrl+B)"><i class="bi bi-layout-sidebar"></i></button>
        <span class="ed-sep"></span>
        <div class="ed-tb-scroll">
            @unless ($readOnly)
                <button class="ed-tb" data-cmd="save" title="Save (Ctrl+S)"><i class="bi bi-check2"></i><span>Save</span></button>
                <button class="ed-tb" data-cmd="saveAll" title="Save all (Ctrl+Alt+S)"><i class="bi bi-check2-all"></i><span>Save All</span></button>
            @endunless
            <button class="ed-tb" data-cmd="reload" title="Reload file from server"><i class="bi bi-arrow-clockwise"></i><span>Refresh</span></button>
            <span class="ed-sep"></span>
            <button class="ed-tb" data-cmd="find" title="Find (Ctrl+F)"><i class="bi bi-search"></i><span>Search</span></button>
            <button class="ed-tb" data-cmd="replace" title="Replace (Ctrl+H)"><i class="bi bi-arrow-left-right"></i><span>Replace</span></button>
            <button class="ed-tb" data-cmd="gotoLine" title="Go to line (Ctrl+G)"><i class="bi bi-signpost-split"></i><span>Jump Line</span></button>
            <span class="ed-sep"></span>
            <button class="ed-tb" data-cmd="settings" title="Editor settings"><i class="bi bi-gear"></i><span>Settings</span></button>
            <button class="ed-tb" data-cmd="shortcuts" title="Keyboard shortcuts"><i class="bi bi-keyboard"></i><span>Shortcuts</span></button>
        </div>
        <div class="ed-tb-right">
            @if ($readOnly)
                <span class="badge badge-warning">Read only</span>
            @endif
            <button class="ed-tb" data-cmd="quickOpen" title="Go to file (Ctrl+P)"><i class="bi bi-file-earmark-search"></i></button>
            <button class="ed-tb" data-cmd="palette" title="Command palette (F1)"><i class="bi bi-command"></i></button>
            @unless ($embed)
                <button class="ed-tb d-none d-md-inline-flex" data-cmd="fullscreen" title="Full screen"><i class="bi bi-arrows-fullscreen"></i></button>
            @endunless
        </div>
    </header>

    <div class="ed-main">
        {{-- ------------------------------------------------------- sidebar --}}
        <aside class="ed-side" id="edSide">
            <div class="ed-side-view" id="edExplorer">
                <div class="ed-side-head">
                    <span class="ed-side-label">Directory:</span>
                    <button class="ed-root" id="edRoot" title="Change directory"></button>
                </div>
                <div class="ed-side-actions">
                    <button data-tree="up" title="Parent directory"><i class="bi bi-arrow-up"></i><span>Up</span></button>
                    <button data-tree="refresh" title="Refresh"><i class="bi bi-arrow-clockwise"></i><span>Refresh</span></button>
                    @unless ($readOnly)
                        <div class="dropdown">
                            <button data-bs-toggle="dropdown" title="New"><i class="bi bi-plus-lg"></i><span>New</span></button>
                            <div class="dropdown-menu">
                                <a href="#" class="dropdown-item" data-tree="newFile"><i class="bi bi-file-earmark-plus"></i> New file</a>
                                <a href="#" class="dropdown-item" data-tree="newFolder"><i class="bi bi-folder-plus"></i> New folder</a>
                                <a href="#" class="dropdown-item" data-tree="upload"><i class="bi bi-upload"></i> Upload files</a>
                            </div>
                        </div>
                    @endunless
                    <button data-tree="search" title="Search in files (Ctrl+Shift+F)"><i class="bi bi-search"></i><span>Search</span></button>
                    <button data-tree="collapse" class="ms-auto" title="Collapse all"><i class="bi bi-arrows-collapse"></i></button>
                </div>
                <div class="ed-tree" id="edTree" tabindex="0"></div>
            </div>

            <div class="ed-side-view" id="edSearch" hidden>
                <div class="ed-side-head">
                    <button class="ed-link" data-tree="explorer"><i class="bi bi-chevron-left"></i> Explorer</button>
                    <span class="ms-auto ed-side-label">Search</span>
                </div>
                <form class="ed-search-form" id="edSearchForm" autocomplete="off">
                    <div class="ed-search-box">
                        <input type="text" id="edSearchQuery" placeholder="Search" spellcheck="false">
                        <button type="button" class="ed-opt" data-opt="case" title="Match case">Aa</button>
                        <button type="button" class="ed-opt" data-opt="word" title="Match whole word"><u>ab</u></button>
                        <button type="button" class="ed-opt" data-opt="regex" title="Use regular expression">.*</button>
                    </div>
                    <div class="d-flex gap-2">
                        <select class="form-select form-select-sm" id="edSearchMode">
                            <option value="content">File contents</option>
                            <option value="name">File names</option>
                        </select>
                        <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-search"></i></button>
                    </div>
                    <input type="text" class="form-control form-control-sm font-mono" id="edSearchInclude" placeholder="Files to include, e.g. *.php, *.js">
                    <div class="d-flex justify-content-between align-items-center">
                        <label class="d-flex align-items-center gap-2 small mb-0 text-secondary">
                            <input type="checkbox" class="form-check-input m-0" id="edSearchSkip" checked>
                            <span>Skip vendor, node_modules, .git</span>
                        </label>
                    </div>
                    <div class="ed-search-scope">in <span class="font-mono" id="edSearchScope"></span></div>
                </form>
                <div class="ed-results" id="edResults"></div>
            </div>
        </aside>
        <div class="ed-resizer" id="edResizer"></div>
        <div class="ed-backdrop" id="edBackdrop"></div>

        {{-- ----------------------------------------------------- workspace --}}
        <section class="ed-work">
            <div class="ed-tabs" id="edTabs"></div>
            <div class="ed-editor-wrap">
                <div class="ed-editor" id="edEditor"></div>
                <div class="ed-empty" id="edEmpty">
                    <div class="ed-empty-inner">
                        <div class="ed-empty-logo"><i class="bi bi-code-slash"></i></div>
                        <h5>No file open</h5>
                        <p>Select a file in the explorer to start editing.</p>
                        <dl class="ed-keys">
                            <dt>Go to file</dt><dd><kbd>Ctrl</kbd> <kbd>P</kbd></dd>
                            <dt>Search in files</dt><dd><kbd>Ctrl</kbd> <kbd>Shift</kbd> <kbd>F</kbd></dd>
                            <dt>Command palette</dt><dd><kbd>F1</kbd></dd>
                            <dt>Toggle explorer</dt><dd><kbd>Ctrl</kbd> <kbd>B</kbd></dd>
                        </dl>
                    </div>
                </div>
                <div class="ed-loading" id="edLoading"><i class="bi bi-arrow-repeat spin"></i> Loading editor...</div>
            </div>
        </section>
    </div>

    {{-- ------------------------------------------------------------ status --}}
    <footer class="ed-status">
        <button class="ed-st ed-st-path" id="stPath" title="Copy path"></button>
        <span class="ed-st-spacer"></span>
        <span class="ed-st d-none" id="stSaved"></span>
        <button class="ed-st" id="stEol" title="Line endings"></button>
        <button class="ed-st" id="stPos" title="Go to line"></button>
        <span class="ed-st d-none d-lg-inline-flex" id="stSel"></span>
        <button class="ed-st d-none d-md-inline-flex" id="stIndent" title="Indentation"></button>
        <button class="ed-st d-none d-md-inline-flex" id="stEnc" title="File encoding"></button>
        <button class="ed-st" id="stLang" title="Language mode"></button>
    </footer>
</div>

<input type="file" id="edUpload" multiple hidden>
<div class="ed-ctx dropdown-menu" id="edCtx"></div>

{{-- ----------------------------------------------------------- quick open --}}
<div class="ed-palette" id="edQuick" hidden>
    <div class="ed-palette-box">
        <input type="text" id="edQuickInput" placeholder="Type a file name to open" spellcheck="false" autocomplete="off">
        <div class="ed-palette-list" id="edQuickList"></div>
    </div>
</div>

{{-- -------------------------------------------------------------- settings --}}
<div class="modal fade" id="edSettings" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <form class="modal-content" id="edSettingsForm">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-gear"></i> Editor settings</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-6 col-md-3"><label class="form-label">Font size</label><input type="number" min="9" max="32" class="form-control" name="fontSize"></div>
                    <div class="col-6 col-md-3"><label class="form-label">Line height</label><input type="number" min="0" max="40" class="form-control" name="lineHeight" placeholder="auto"></div>
                    <div class="col-6 col-md-3"><label class="form-label">Tab size</label><select class="form-select" name="tabSize"><option>2</option><option>4</option><option>8</option></select></div>
                    <div class="col-6 col-md-3"><label class="form-label">Indent with</label><select class="form-select" name="insertSpaces"><option value="1">Spaces</option><option value="0">Tabs</option></select></div>
                    <div class="col-md-6"><label class="form-label">Font family</label><input type="text" class="form-control font-mono" name="fontFamily"></div>
                    <div class="col-6 col-md-3"><label class="form-label">Word wrap</label><select class="form-select" name="wordWrap"><option value="off">Off</option><option value="on">On</option><option value="bounded">Bounded</option></select></div>
                    <div class="col-6 col-md-3"><label class="form-label">Whitespace</label><select class="form-select" name="renderWhitespace"><option value="none">None</option><option value="selection">Selection</option><option value="boundary">Boundary</option><option value="all">All</option></select></div>
                    <div class="col-6 col-md-3"><label class="form-label">Line numbers</label><select class="form-select" name="lineNumbers"><option value="on">On</option><option value="relative">Relative</option><option value="off">Off</option></select></div>
                    <div class="col-6 col-md-3"><label class="form-label">Cursor</label><select class="form-select" name="cursorStyle"><option value="line">Line</option><option value="block">Block</option><option value="underline">Underline</option></select></div>
                    <div class="col-6 col-md-3"><label class="form-label">Auto save</label><select class="form-select" name="autoSave"><option value="off">Off</option><option value="1000">After 1 s</option><option value="3000">After 3 s</option><option value="focus">On tab change</option></select></div>
                    <div class="col-6 col-md-3"><label class="form-label">Default EOL</label><select class="form-select" name="defaultEol"><option value="LF">LF</option><option value="CRLF">CRLF</option></select></div>
                    <div class="col-12"><hr class="my-1"></div>
                    @foreach ([
                        'minimap' => 'Minimap',
                        'stickyScroll' => 'Sticky scroll',
                        'bracketPairs' => 'Bracket pair colors',
                        'guides' => 'Indentation guides',
                        'ligatures' => 'Font ligatures',
                        'smoothScrolling' => 'Smooth scrolling',
                        'trimOnSave' => 'Trim trailing whitespace on save',
                        'finalNewline' => 'Insert final newline on save',
                        'restoreSession' => 'Reopen tabs on next visit',
                    ] as $key => $label)
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" name="{{ $key }}" id="set_{{ $key }}">
                                <label class="form-check-label" for="set_{{ $key }}">{{ $label }}</label>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary me-auto" id="edSettingsReset">Reset to defaults</button>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Apply</button>
            </div>
        </form>
    </div>
</div>

{{-- ------------------------------------------------------------- shortcuts --}}
<div class="modal fade" id="edShortcuts" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-keyboard"></i> Keyboard shortcuts</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-4">
                    @foreach ([
                        'Files' => ['Ctrl+S' => 'Save', 'Ctrl+Alt+S' => 'Save all', 'Ctrl+P' => 'Go to file', 'Alt+W' => 'Close tab', 'Ctrl+Alt+Right / Left' => 'Next / previous tab', 'Ctrl+B' => 'Toggle explorer', 'Ctrl+Shift+F' => 'Search in files'],
                        'Editing' => ['Ctrl+Z / Ctrl+Y' => 'Undo / redo', 'Ctrl+D' => 'Select next occurrence', 'Alt+Click' => 'Add cursor', 'Alt+Up / Down' => 'Move line', 'Shift+Alt+Down' => 'Copy line down', 'Ctrl+Shift+K' => 'Delete line', 'Ctrl+/' => 'Toggle comment'],
                        'Navigation' => ['Ctrl+F' => 'Find', 'Ctrl+H' => 'Replace', 'Ctrl+G' => 'Go to line', 'Ctrl+Shift+O' => 'Go to symbol', 'Ctrl+Shift+\\' => 'Go to bracket', 'F1' => 'Command palette'],
                        'Folding and view' => ['Ctrl+Shift+[' => 'Fold region', 'Ctrl+Shift+]' => 'Unfold region', 'Ctrl+K Ctrl+0' => 'Fold all', 'Ctrl+K Ctrl+J' => 'Unfold all', 'Alt+Z' => 'Toggle word wrap'],
                    ] as $group => $keys)
                        <div class="col-md-6">
                            <h6 class="ed-sc-title">{{ $group }}</h6>
                            <table class="table table-sm ed-sc mb-0">
                                @foreach ($keys as $k => $label)
                                    <tr><td>{{ $label }}</td><td class="text-end">@foreach (explode(' / ', $k) as $i => $combo){!! $i ? ' <span class="cell-sub">/</span> ' : '' !!}@foreach (explode(' ', $combo) as $j => $chord){!! $j ? ' ' : '' !!}<kbd>{{ $chord }}</kbd>@endforeach @endforeach</td></tr>
                                @endforeach
                            </table>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>

<script src="{{ asset('assets/vendor/jquery/jquery.min.js') }}"></script>
<script src="{{ asset('assets/vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('assets/vendor/toastr/toastr.min.js') }}"></script>
<script src="{{ asset('assets/vendor/sweetalert2/sweetalert2.all.min.js') }}"></script>
<script>
    window.GBX = { routes: {}, user: @json($account) };
    window.GBX_EDITOR = {
        root: @json($root),
        open: @json($open),
        readOnly: @json($readOnly),
        embed: @json($embed),
        encodings: @json($encodings),
        vs: @json(asset('assets/vendor/monaco/vs')),
        filesUrl: @json($filesUrl),
        roots: @json($roots),
        storeKey: @json($storeKey),
        routes: @json($routes)
    };
</script>
<script src="{{ asset('assets/js/gbx.js') }}?v={{ config('gbx.version') }}"></script>
<script src="{{ asset('assets/vendor/monaco/vs/loader.js') }}"></script>
<script src="{{ asset('assets/js/editor.js') }}?v={{ config('gbx.version') }}"></script>
</body>
</html>
