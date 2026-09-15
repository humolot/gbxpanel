@extends('layouts.app')

@section('title', 'Terminal')

@section('content')
    <div class="page-head">
        <div>
            <h2>Terminal</h2>
            <p>Run commands as root. Each command runs non-interactively; the working directory is kept between commands.</p>
        </div>
        <div class="actions">
            <div class="dropdown">
                <button class="btn btn-outline-secondary" data-bs-toggle="dropdown"><i class="bi bi-lightning"></i> Quick commands</button>
                <div class="dropdown-menu dropdown-menu-end">
                    @foreach ([
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
                    ] as $cmd => $label)
                        <a href="#" class="dropdown-item quick" data-cmd="{{ $cmd }}"><i class="bi bi-chevron-right"></i> {{ $label }} <span class="ms-auto ps-3 cell-sub font-mono">{{ \Illuminate\Support\Str::limit($cmd, 22) }}</span></a>
                    @endforeach
                </div>
            </div>
            <button class="btn btn-outline-secondary" id="termClear"><i class="bi bi-eraser"></i> Clear</button>
        </div>
    </div>

    <div class="term">
        <div class="term-bar">
            <span class="lights"><span></span><span></span><span></span></span>
            <span class="font-mono">root&#64;{{ $hostname }}</span>
            <span class="ms-auto" id="termState"><span class="status-dot on me-1"></span> Ready</span>
        </div>
        <div class="term-out" id="termOut"><span class="dim">GBX Panel console. Type a command and press Enter. Use Up/Down for history, Ctrl+L to clear.
Interactive programs (vim, nano, top, mysql shell) are not supported here; use SSH for those.</span>
</div>
        <div class="term-in">
            <span class="prompt text-success">#</span>
            <span class="path text-info font-mono" id="termCwd">{{ $cwd }}</span>
            <input type="text" id="termInput" autocomplete="off" spellcheck="false" autofocus>
            <select class="form-select form-select-sm w-auto" id="termTimeout" title="Timeout">
                <option value="60">60s</option>
                <option value="120" selected>2m</option>
                <option value="600">10m</option>
            </select>
        </div>
    </div>
@endsection

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

        GBX.post(@json(route('terminal.exec')), { command: cmd, timeout: $('#termTimeout').val() }, { silent: true, timeout: 700000 })
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

    $('.term').on('click', function (e) { if (!window.getSelection().toString() && !$(e.target).is('select')) $in.focus(); });
    $('#termClear').on('click', function () { $out.empty(); $in.focus(); });
    $('.quick').on('click', function (e) { e.preventDefault(); run($(this).data('cmd')); });

    var params = new URLSearchParams(location.search);
    if (params.get('cmd')) { $in.val(params.get('cmd')).focus(); }
});
</script>
@endpush
