@extends('layouts.app')

@section('title', 'Terminal')

@php
    $quick = [
        'htop' => 'Process monitor',
        'df -h' => 'Disk usage',
        'free -m' => 'Memory',
        'ps aux --sort=-%cpu | head -20' => 'Top CPU processes',
        'ss -tulpn' => 'Listening ports',
        'systemctl --failed' => 'Failed services',
        'journalctl -p err -n 50 --no-pager' => 'Recent errors',
        'apache2ctl -S' => 'Apache virtual hosts',
        'ufw status verbose' => 'Firewall status',
        'last -n 20' => 'Last logins',
        'apt list --upgradable 2>/dev/null' => 'Upgradable packages',
    ];
    if (! $live) {
        unset($quick['htop']);
    }
@endphp

@push('styles')
<link rel="stylesheet" href="{{ asset('assets/vendor/xterm/xterm.css') }}">
<style>
    .term-bar { gap: .75rem; padding: .4rem .6rem; }
    .term-tabs { display: flex; align-items: center; gap: .25rem; flex: 1; min-width: 0; overflow-x: auto; scrollbar-width: none; }
    .term-tabs::-webkit-scrollbar { display: none; }
    .term-tab { display: inline-flex; align-items: center; gap: .45rem; padding: .28rem .55rem; border: 1px solid transparent; border-radius: 6px; background: transparent; color: var(--gbx-muted); font-family: var(--gbx-mono); font-size: .72rem; white-space: nowrap; cursor: pointer; }
    .term-tab:hover { color: var(--gbx-text-2); }
    .term-tab.active { background: var(--gbx-surface-2); border-color: var(--gbx-border); color: var(--gbx-text); }
    .term-tab .term-close { display: inline-flex; padding: 0 .1rem; border-radius: 4px; opacity: .55; }
    .term-tab .term-close:hover { opacity: 1; background: var(--gbx-surface-3); }
    .term-dot { width: 7px; height: 7px; border-radius: 50%; background: #4a515a; flex: 0 0 auto; }
    .term-dot.on { background: var(--gbx-success); }
    .term-dot.wait { background: var(--gbx-warning); }
    .term-dot.off { background: var(--gbx-danger); }
    .term-tool { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border: 0; border-radius: 6px; background: transparent; color: var(--gbx-muted); }
    .term-tool:hover { background: var(--gbx-surface-2); color: var(--gbx-text); }
    .term-tools { display: flex; align-items: center; gap: .1rem; flex: 0 0 auto; }
    .term-panes { position: relative; flex: 1; min-height: 0; background: #050607; }
    .term-pane { position: absolute; inset: .55rem .2rem .2rem .8rem; }
    .term-pane .xterm, .term-pane .xterm-viewport { background: #050607 !important; }
    .term-pane .xterm-viewport::-webkit-scrollbar { width: 8px; }
    .term-pane .xterm-viewport::-webkit-scrollbar-thumb { background: #262b32; border-radius: 4px; }
    .term-keys { display: flex; gap: .3rem; padding: .4rem .6rem; overflow-x: auto; border-top: 1px solid var(--gbx-border); background: #08090b; }
    .term-keys button { flex: 0 0 auto; padding: .2rem .55rem; border: 1px solid var(--gbx-border); border-radius: 5px; background: var(--gbx-surface); color: var(--gbx-text-2); font-family: var(--gbx-mono); font-size: .72rem; }
    .term.term-full { position: fixed; inset: 0; z-index: 1060; height: auto; border-radius: 0; border: 0; }
    .term-meta { font-size: .72rem; color: var(--gbx-muted); white-space: nowrap; }
    @media (max-width: 575.98px) {
        .term { height: calc(100vh - 270px); height: calc(100dvh - 270px); min-height: 340px; }
        .term-meta, .term-tool[data-tool^=font] { display: none; }
        .term-pane { inset: .45rem .1rem .1rem .45rem; }
    }
</style>
@endpush

@section('content')
    <div class="page-head">
        <div>
            <h2>Terminal</h2>
            @if ($live)
                <p>Interactive root shell streamed in real time. Full-screen programs such as htop, nano, vim and mysql are supported.</p>
            @else
                <p>Command mode: each command runs non-interactively; the working directory is kept between commands.</p>
            @endif
        </div>
        <div class="actions">
            <div class="dropdown">
                <button class="btn btn-outline-secondary" data-bs-toggle="dropdown"><i class="bi bi-lightning"></i> Quick commands</button>
                <div class="dropdown-menu dropdown-menu-end">
                    @foreach ($quick as $cmd => $label)
                        <a href="#" class="dropdown-item quick" data-cmd="{{ $cmd }}"><i class="bi bi-chevron-right"></i> {{ $label }} <span class="ms-auto ps-3 cell-sub font-mono">{{ \Illuminate\Support\Str::limit($cmd, 22) }}</span></a>
                    @endforeach
                </div>
            </div>
            @if ($live)
                <button class="btn btn-primary" id="termNew"><i class="bi bi-plus-lg"></i> New session</button>
            @else
                <button class="btn btn-outline-secondary" id="termClear"><i class="bi bi-eraser"></i> Clear</button>
            @endif
        </div>
    </div>

    @if ($live)
        <div class="term" id="term">
            <div class="term-bar">
                <div class="term-tabs" id="termTabs"></div>
                <span class="term-meta font-mono" id="termSize"></span>
                <div class="term-tools">
                    <button class="term-tool" data-tool="font-down" title="Smaller text"><i class="bi bi-dash-lg"></i></button>
                    <button class="term-tool" data-tool="font-up" title="Larger text"><i class="bi bi-plus-lg"></i></button>
                    <button class="term-tool" data-tool="copy" title="Copy selection (Ctrl+Shift+C)"><i class="bi bi-clipboard"></i></button>
                    <button class="term-tool" data-tool="paste" title="Paste (Ctrl+Shift+V)"><i class="bi bi-clipboard-plus"></i></button>
                    <button class="term-tool" data-tool="clear" title="Clear scrollback"><i class="bi bi-eraser"></i></button>
                    <button class="term-tool" data-tool="reconnect" title="Reconnect"><i class="bi bi-arrow-clockwise"></i></button>
                    <button class="term-tool" data-tool="full" title="Full screen"><i class="bi bi-arrows-fullscreen"></i></button>
                </div>
            </div>
            <div class="term-panes" id="termPanes"></div>
            <div class="term-keys d-lg-none" id="termKeys">
                @foreach (['esc' => 'Esc', 'tab' => 'Tab', 'ctrlc' => 'Ctrl+C', 'ctrld' => 'Ctrl+D', 'ctrlz' => 'Ctrl+Z', 'up' => 'Up', 'down' => 'Down', 'left' => 'Left', 'right' => 'Right', 'pipe' => '|', 'slash' => '/', 'dash' => '-', 'tilde' => '~'] as $key => $label)
                    <button type="button" data-key="{{ $key }}">{{ $label }}</button>
                @endforeach
            </div>
        </div>
    @else
        <div class="alert alert-warning d-flex align-items-start gap-2">
            <i class="bi bi-exclamation-triangle mt-1"></i>
            <div>
                The real-time terminal service is not running, so the console works in command mode (no interactive programs).
                Enable it on the server with <code>gbx terminal</code> and reload this page.
            </div>
        </div>

        <div class="term">
            <div class="term-bar">
                <span class="lights"><span></span><span></span><span></span></span>
                <span class="font-mono">root&#64;{{ $hostname }}</span>
                <span class="ms-auto" id="termState"><span class="status-dot on me-1"></span> Ready</span>
            </div>
            <div class="term-out" id="termOut"><span class="dim">GBX Panel console (command mode). Type a command and press Enter. Use Up/Down for history, Ctrl+L to clear.</span>
</div>
            <div class="term-in">
                <span class="prompt text-success">#</span>
                <span class="path text-info font-mono" id="termCwd">{{ $cwd }}</span>
                <input type="text" id="termInput" autocomplete="off" spellcheck="false" autofocus>
            </div>
        </div>
    @endif
@endsection

@if ($live)
@push('vendor')
    <script src="{{ asset('assets/vendor/xterm/xterm.js') }}"></script>
    <script src="{{ asset('assets/vendor/xterm/addon-fit.js') }}"></script>
    <script src="{{ asset('assets/vendor/xterm/addon-web-links.js') }}"></script>
@endpush

@push('scripts')
<script>
$(function () {
    var TOKEN_URL = @json(route('terminal.token'));
    var WS_URL = @json($wsUrl);
    var HOST = @json($hostname);
    var ESC = String.fromCharCode(27);
    var KEYS = {
        esc: ESC, tab: '\t', ctrlc: String.fromCharCode(3), ctrld: String.fromCharCode(4), ctrlz: String.fromCharCode(26),
        up: ESC + '[A', down: ESC + '[B', right: ESC + '[C', left: ESC + '[D', pipe: '|', slash: '/', dash: '-', tilde: '~'
    };
    var THEME = {
        background: '#050607', foreground: '#d4d8dd', cursor: '#f2f3f5', cursorAccent: '#050607',
        selectionBackground: 'rgba(106, 167, 255, 0.35)',
        black: '#1a1e23', red: '#f0616d', green: '#3ecf8e', yellow: '#f5b454', blue: '#6aa7ff', magenta: '#c792ea', cyan: '#56c8d8', white: '#d4d8dd',
        brightBlack: '#5c636d', brightRed: '#ff7b86', brightGreen: '#5fe0a5', brightYellow: '#ffc978', brightBlue: '#8dbcff', brightMagenta: '#d9a8f5', brightCyan: '#7fdcea', brightWhite: '#ffffff'
    };

    var sessions = [], active = null, counter = 0;
    var fontSize = 13;
    try { fontSize = parseInt(localStorage.getItem('gbx.term.font') || '13', 10) || 13; } catch (e) {}
    var pendingCmd = new URLSearchParams(location.search).get('cmd');

    function Session() {
        var self = this;
        this.id = ++counter;
        this.ws = null;
        this.state = 'wait';
        this.ended = false;
        this.keepalive = null;

        this.$tab = $('<div class="term-tab" role="tab">' +
            '<span class="term-dot wait"></span><span class="term-label"></span>' +
            '<span class="term-close" title="Close session"><i class="bi bi-x"></i></span></div>');
        this.$tab.find('.term-label').text('root@' + HOST + (this.id > 1 ? ' (' + this.id + ')' : ''));
        this.$tab.appendTo('#termTabs');
        this.$pane = $('<div class="term-pane">').appendTo('#termPanes');

        this.term = new Terminal({
            cursorBlink: true, fontSize: fontSize, lineHeight: 1.15, scrollback: 10000,
            fontFamily: '"JetBrains Mono", "Cascadia Code", "SFMono-Regular", Consolas, "Liberation Mono", monospace',
            theme: THEME, allowProposedApi: false, macOptionIsMeta: true, rightClickSelectsWord: false
        });
        this.fit = new FitAddon.FitAddon();
        this.term.loadAddon(this.fit);
        this.term.loadAddon(new WebLinksAddon.WebLinksAddon());
        this.term.open(this.$pane[0]);

        this.term.attachCustomKeyEventHandler(function (e) {
            if (e.type !== 'keydown') return true;
            var key = e.key.toLowerCase();
            if (e.ctrlKey && e.shiftKey && key === 'c') { copySelection(self); return false; }
            if (e.ctrlKey && e.shiftKey && key === 'v') { return false; }
            if (e.ctrlKey && !e.shiftKey && key === 'c' && self.term.hasSelection()) { copySelection(self); return false; }
            return true;
        });

        this.term.onData(function (data) {
            if (self.ended) {
                if (data === '\r') self.connect();
                return;
            }
            self.send({ t: 'i', d: data });
        });
        this.term.onResize(function (size) {
            self.send({ t: 'r', c: size.cols, r: size.rows });
            if (self === active) showSize();
        });

        this.$tab.on('click', function (e) {
            if ($(e.target).closest('.term-close').length) { self.destroy(); return; }
            activate(self);
        });

        sessions.push(this);
        activate(this);
        this.connect();
    }

    Session.prototype.setState = function (state) {
        this.state = state;
        this.$tab.find('.term-dot').attr('class', 'term-dot ' + state);
    };

    Session.prototype.send = function (msg) {
        if (this.ws && this.ws.readyState === WebSocket.OPEN) this.ws.send(JSON.stringify(msg));
    };

    Session.prototype.note = function (text) {
        this.term.write('\r\n' + ESC + '[90m' + text + ESC + '[0m\r\n');
    };

    Session.prototype.connect = function () {
        var self = this;
        this.close(true);
        this.ended = false;
        this.setState('wait');
        safeFit(this);

        GBX.post(TOKEN_URL, { cols: this.term.cols, rows: this.term.rows }, { silent: true })
            .done(function (r) {
                var base = r.url || WS_URL || ((location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host + '/gbx-terminal/');
                var ws = new WebSocket(base + (base.indexOf('?') === -1 ? '?' : '&') + 'token=' + encodeURIComponent(r.token));
                ws.binaryType = 'arraybuffer';
                self.ws = ws;

                ws.onopen = function () {
                    self.setState('on');
                    self.send({ t: 'r', c: self.term.cols, r: self.term.rows });
                    self.keepalive = setInterval(function () { self.send({ t: 'k' }); }, 25000);
                    if (pendingCmd && self.id === 1) {
                        self.send({ t: 'i', d: pendingCmd });
                        pendingCmd = null;
                    }
                    if (self === active) self.term.focus();
                };
                ws.onmessage = function (ev) {
                    if (typeof ev.data !== 'string') self.term.write(new Uint8Array(ev.data));
                };
                ws.onclose = function (ev) {
                    if (self.ws !== ws) return;
                    clearInterval(self.keepalive);
                    self.ws = null;
                    self.ended = true;
                    self.setState('off');
                    var reason = ev.reason || (ev.code === 1006 ? 'connection lost' : 'disconnected');
                    self.note('[' + reason + '] Press Enter to start a new session.');
                };
            })
            .fail(function (xhr) {
                self.ended = true;
                self.setState('off');
                self.note('[' + GBX.errorMessage(xhr) + '] Press Enter to retry.');
            });
    };

    Session.prototype.close = function (silent) {
        clearInterval(this.keepalive);
        if (this.ws) {
            var ws = this.ws;
            this.ws = null;
            try { ws.close(1000, 'closed'); } catch (e) {}
        }
    };

    Session.prototype.destroy = function () {
        this.close(true);
        this.term.dispose();
        this.$tab.remove();
        this.$pane.remove();
        var index = sessions.indexOf(this);
        sessions.splice(index, 1);
        if (!sessions.length) { new Session(); return; }
        if (active === this) activate(sessions[Math.max(0, index - 1)]);
    };

    function activate(session) {
        active = session;
        sessions.forEach(function (s) {
            s.$tab.toggleClass('active', s === session);
            s.$pane.prop('hidden', s !== session);
        });
        safeFit(session);
        session.term.focus();
        showSize();
    }

    function safeFit(session) {
        try { session.fit.fit(); } catch (e) {}
    }

    function showSize() {
        if (active) $('#termSize').text(active.term.cols + 'x' + active.term.rows);
    }

    function copySelection(session) {
        var text = session.term.getSelection();
        if (text) GBX.copy(text);
    }

    var resizeTimer = null;
    $(window).on('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () { if (active) safeFit(active); }, 80);
    });

    $('#termNew').on('click', function () { new Session(); });

    $('.term-tool').on('click', function () {
        if (!active) return;
        switch ($(this).data('tool')) {
            case 'font-up':
            case 'font-down':
                fontSize = Math.max(9, Math.min(24, fontSize + ($(this).data('tool') === 'font-up' ? 1 : -1)));
                try { localStorage.setItem('gbx.term.font', fontSize); } catch (e) {}
                sessions.forEach(function (s) { s.term.options.fontSize = fontSize; safeFit(s); });
                break;
            case 'copy':
                copySelection(active);
                break;
            case 'paste':
                if (navigator.clipboard && navigator.clipboard.readText) {
                    navigator.clipboard.readText().then(function (text) { active.term.paste(text); }, function () { toastr.info('Use Ctrl+Shift+V to paste.'); });
                } else {
                    toastr.info('Use Ctrl+Shift+V to paste.');
                }
                break;
            case 'clear':
                active.term.clear();
                break;
            case 'reconnect':
                active.connect();
                break;
            case 'full':
                $('#term').toggleClass('term-full');
                $(this).find('i').toggleClass('bi-arrows-fullscreen bi-fullscreen-exit');
                safeFit(active);
                break;
        }
        if ($(this).data('tool') !== 'paste') active.term.focus();
    });

    $('#termKeys').on('click', 'button', function () {
        if (active && !active.ended) active.send({ t: 'i', d: KEYS[$(this).data('key')] });
        if (active) active.term.focus();
    });

    $('.quick').on('click', function (e) {
        e.preventDefault();
        if (!active || active.ended) return;
        active.send({ t: 'i', d: $(this).data('cmd') + '\r' });
        active.term.focus();
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && $('#term').hasClass('term-full') && !(active && active.term.textarea === document.activeElement)) {
            $('.term-tool[data-tool=full]').trigger('click');
        }
    });

    if (typeof Terminal === 'undefined' || typeof FitAddon === 'undefined') {
        $('#termPanes').html('<div class="p-4 text-danger">Terminal assets are missing (assets/vendor/xterm). Run <code>gbx update</code> on the server.</div>');
        return;
    }

    window.addEventListener('beforeunload', function () { sessions.forEach(function (s) { s.close(true); }); });

    new Session();
});
</script>
@endpush
@else
@push('scripts')
<script>
$(function () {
    var $out = $('#termOut'), $in = $('#termInput'), history = [], pos = -1, busy = false;
    try { history = JSON.parse(localStorage.getItem('gbx.term.history') || '[]'); } catch (e) {}

    function print(html) { $out.append(html); $out.scrollTop($out[0].scrollHeight); }

    function run(cmd) {
        cmd = cmd.trim();
        if (!cmd || busy) return;
        if (cmd === 'clear') { $out.empty(); return; }
        history = history.filter(function (h) { return h !== cmd; });
        history.push(cmd);
        history = history.slice(-100);
        pos = -1;
        try { localStorage.setItem('gbx.term.history', JSON.stringify(history)); } catch (e) {}

        print('<div><span class="prompt">#</span> <span class="path">' + GBX.escape($('#termCwd').text()) + '</span> <span class="cmd">' + GBX.escape(cmd) + '</span></div>');
        busy = true;
        $in.prop('disabled', true);
        $('#termState').html('<i class="bi bi-arrow-repeat spin me-1"></i> Running');

        GBX.post(@json(route('terminal.exec')), { command: cmd, timeout: 600 }, { silent: true, timeout: 700000 })
            .done(function (r) {
                if (r.output) print($('<div>').text(r.output));
                if (r.exit !== 0) print('<div class="exit">[exit ' + r.exit + ']</div>');
                $('#termCwd').text(r.cwd);
            })
            .fail(function (xhr) { print('<div class="exit">' + GBX.escape(GBX.errorMessage(xhr)) + '</div>'); })
            .always(function () {
                busy = false;
                $in.prop('disabled', false).val('').focus();
                $('#termState').html('<span class="status-dot on me-1"></span> Ready');
            });
    }

    $in.on('keydown', function (e) {
        if (e.key === 'Enter') { run(this.value); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); if (!history.length) return; pos = pos < 0 ? history.length - 1 : Math.max(0, pos - 1); this.value = history[pos]; }
        else if (e.key === 'ArrowDown') { e.preventDefault(); if (pos < 0) return; pos++; if (pos >= history.length) { pos = -1; this.value = ''; } else this.value = history[pos]; }
        else if (e.key === 'l' && e.ctrlKey) { e.preventDefault(); $out.empty(); }
    });

    $('.term').on('click', function () { if (!window.getSelection().toString()) $in.focus(); });
    $('#termClear').on('click', function () { $out.empty(); $in.focus(); });
    $('.quick').on('click', function (e) { e.preventDefault(); run($(this).data('cmd')); });

    var params = new URLSearchParams(location.search);
    if (params.get('cmd')) { $in.val(params.get('cmd')).focus(); }
});
</script>
@endpush
@endif
