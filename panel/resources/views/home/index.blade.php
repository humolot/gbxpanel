@extends('layouts.app')

@section('title', 'Home')

@push('topbar')
    <span class="gbx-chip d-none d-xl-inline-flex"><i class="bi bi-hdd-stack"></i> {{ $info['os'] }}</span>
    <span class="gbx-chip d-none d-md-inline-flex"><i class="bi bi-clock-history"></i> <span id="uptimeChip">{{ $info['uptime']['human'] }}</span></span>
@endpush

@section('content')
    {{-- Server strip --}}
    <div class="gbx-card mb-3">
        <div class="gbx-card-body py-3 d-flex flex-wrap align-items-center gap-3">
            <div class="d-flex align-items-center gap-3 me-auto min-w-0">
                <div class="stat-tile p-0 border-0 bg-transparent"><div class="icon"><i class="bi bi-server"></i></div></div>
                <div class="min-w-0">
                    <div class="fw-semibold text-truncate">{{ $info['hostname'] }} <span class="text-muted fw-normal font-mono small ms-1">{{ $info['ip'] }}</span></div>
                    <div class="cell-sub text-truncate">{{ $info['os'] }} &middot; {{ $info['kernel'] }} &middot; {{ $info['arch'] }} &middot; {{ $info['cpu_model'] }}</div>
                </div>
            </div>
            @if (auth()->user()->isAdmin())
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-sm btn-outline-secondary" data-post="{{ route('settings.action') }}" data-payload='{"action":"update_system"}' data-confirm="Run apt update and upgrade all packages now?"><i class="bi bi-cloud-arrow-down"></i> Update <span class="badge badge-warning ms-1 d-none" id="updatesBadge"></span></button>
                    <button class="btn btn-sm btn-outline-secondary" data-post="{{ route('settings.action') }}" data-payload='{"action":"restart_panel"}' data-confirm="Restart the panel services? The page may be unavailable for a few seconds."><i class="bi bi-arrow-clockwise"></i> Restart panel</button>
                    <button class="btn btn-sm btn-outline-danger" data-post="{{ route('settings.action') }}" data-payload='{"action":"reboot"}' data-confirm="Reboot the server now? All services will be unavailable until it comes back." data-danger data-confirm-text="Reboot"><i class="bi bi-power"></i> Reboot</button>
                </div>
            @endif
        </div>
        <div class="alert alert-warning m-3 mt-0 py-2 d-none" id="rebootAlert"><i class="bi bi-exclamation-triangle me-1"></i> A system update requires a reboot to complete.</div>
    </div>

    {{-- System status --}}
    <div class="gbx-card mb-3">
        <div class="gbx-card-header">
            <h2><i class="bi bi-speedometer2"></i> System status</h2>
            <div class="actions"><span class="cell-sub"><span class="status-dot on me-1"></span> Live, every 3s</span></div>
        </div>
        <div class="gbx-card-body">
            <div class="row g-3 justify-content-around" id="gauges">
                @foreach (['load' => 'Load status', 'cpu' => 'CPU usage', 'memory' => 'RAM usage'] as $key => $label)
                    <div class="col-6 col-md-4 col-xl">
                        <div class="gauge-wrap">
                            <div class="gauge-title">{{ $label }}</div>
                            <div class="gauge" id="gauge-{{ $key }}">
                                <svg viewBox="0 0 120 120"><circle class="track" cx="60" cy="60" r="50"/><circle class="bar" cx="60" cy="60" r="50" stroke-dasharray="314.16" stroke-dashoffset="314.16"/></svg>
                                <div class="gauge-value">-</div>
                            </div>
                            <div class="gauge-sub">&nbsp;</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Overview tiles --}}
    <div class="row g-3 mb-3">
        @foreach ([
            ['websites.index', 'bi-globe2', 'Websites', $counts['websites'], null],
            ['ftp.index', 'bi-folder-symlink', 'FTP accounts', $counts['ftp'], null],
            ['databases.index', 'bi-database', 'Databases', $counts['databases'], null],
            ['home.cron', 'bi-calendar2-week', 'Cron jobs', $counts['cron'], null],
            ['security.index', 'bi-shield-check', 'Firewall rules', '-', 'tileFirewall'],
            ['home.processes', 'bi-cpu', 'Processes', '-', 'tileProcs'],
        ] as [$route, $icon, $label, $value, $id])
            <div class="col-6 col-md-4 col-xl-2">
                <a href="{{ route($route) }}" class="stat-tile">
                    <div class="icon"><i class="bi {{ $icon }}"></i></div>
                    <div class="min-w-0">
                        <div class="label text-truncate">{{ $label }}</div>
                        <div class="value" @if($id) id="{{ $id }}" @endif>{{ $value }}</div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    <div class="row g-3 mb-3">
        {{-- Software --}}
        <div class="col-xl-6">
            <div class="gbx-card h-100">
                <div class="gbx-card-header">
                    <h2><i class="bi bi-box-seam"></i> Software</h2>
                    <div class="actions"><a href="{{ route('home.software') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-grid"></i> Manage</a></div>
                </div>
                <div class="gbx-card-body">
                    <div class="row g-2" id="softwareGrid">
                        <div class="col-12 text-center text-muted py-4"><i class="bi bi-arrow-repeat spin"></i> Detecting installed software...</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Traffic / Disk IO --}}
        <div class="col-xl-6">
            <div class="gbx-card h-100">
                <div class="gbx-card-header py-0">
                    <ul class="nav nav-tabs gbx-tabs border-0" role="tablist">
                        <li class="nav-item"><button class="nav-link active" data-chart="net"><i class="bi bi-arrow-down-up"></i> Traffic</button></li>
                        <li class="nav-item"><button class="nav-link" data-chart="io"><i class="bi bi-device-hdd"></i> Disk IO</button></li>
                    </ul>
                </div>
                <div class="gbx-card-body">
                    <div class="row text-center mb-3 g-2">
                        <div class="col-3"><div class="cell-sub"><span class="legend-dot" style="background:#f5b454"></span><span class="lbl-a">Upstream</span></div><div class="fw-semibold font-mono small" id="rateA">-</div></div>
                        <div class="col-3"><div class="cell-sub"><span class="legend-dot" style="background:#e8eaed"></span><span class="lbl-b">Downstream</span></div><div class="fw-semibold font-mono small" id="rateB">-</div></div>
                        <div class="col-3"><div class="cell-sub lbl-c">Total sent</div><div class="fw-semibold font-mono small" id="totalA">-</div></div>
                        <div class="col-3"><div class="cell-sub lbl-d">Total received</div><div class="fw-semibold font-mono small" id="totalB">-</div></div>
                    </div>
                    <div class="chart-box"><canvas id="liveChart"></canvas></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-xl-6">
            <div class="gbx-card h-100">
                <div class="gbx-card-header"><h2><i class="bi bi-hdd"></i> Disks</h2></div>
                <div class="gbx-card-body" id="disksList"><div class="text-muted small">Loading...</div></div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="gbx-card h-100">
                <div class="gbx-card-header">
                    <h2><i class="bi bi-activity"></i> Recent activity</h2>
                    <div class="actions"><a href="{{ route('logs.index') }}#activity" class="btn btn-sm btn-ghost">View all</a></div>
                </div>
                <div class="gbx-card-body p-0">
                    <div class="table-responsive">
                        <table class="table">
                            <tbody>
                            @forelse ($activity as $a)
                                <tr>
                                    <td style="width:1%"><span class="badge badge-soft">{{ $a->category }}</span></td>
                                    <td class="min-w-0"><div class="text-truncate" style="max-width: 360px">{{ $a->action }}</div><div class="cell-sub">{{ $a->user?->username ?? 'system' }} &middot; {{ $a->ip }}</div></td>
                                    <td class="text-end cell-sub text-nowrap">{{ $a->created_at?->diffForHumans() }}</td>
                                </tr>
                            @empty
                                <tr><td class="text-center text-muted py-4">No activity recorded yet.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('vendor')
    <script src="{{ asset('assets/vendor/chartjs/chart.umd.min.js') }}"></script>
@endpush

@push('scripts')
<script>
$(function () {
    var CIRC = 314.16, prev = null, mode = 'net', points = 40;

    function setGauge(id, percent, text, sub) {
        var $g = $('#gauge-' + id), p = Math.max(0, Math.min(100, percent));
        $g.removeClass('warn crit').addClass(GBX.level(p));
        $g.find('.bar').attr('stroke-dashoffset', CIRC - CIRC * p / 100);
        $g.find('.gauge-value').text(text);
        $g.closest('.gauge-wrap').find('.gauge-sub').text(sub);
    }

    function ensureDiskGauges(disks) {
        disks.forEach(function (d, i) {
            if ($('#gauge-disk' + i).length) return;
            $('#gauges').append(
                '<div class="col-6 col-md-4 col-xl"><div class="gauge-wrap"><div class="gauge-title text-truncate" title="' + GBX.escape(d.mount) + '">' + GBX.escape(d.mount) + '</div>' +
                '<div class="gauge" id="gauge-disk' + i + '"><svg viewBox="0 0 120 120"><circle class="track" cx="60" cy="60" r="50"/><circle class="bar" cx="60" cy="60" r="50" stroke-dasharray="314.16" stroke-dashoffset="314.16"/></svg><div class="gauge-value">-</div></div>' +
                '<div class="gauge-sub">&nbsp;</div></div></div>');
        });
    }

    var chart = new Chart(document.getElementById('liveChart'), {
        type: 'line',
        data: { labels: Array(points).fill(''), datasets: [
            { label: 'A', data: Array(points).fill(null), borderColor: '#f5b454', backgroundColor: 'rgba(245,180,84,.08)', fill: true, tension: .35, pointRadius: 0, borderWidth: 1.6 },
            { label: 'B', data: Array(points).fill(null), borderColor: '#e8eaed', backgroundColor: 'rgba(232,234,237,.06)', fill: true, tension: .35, pointRadius: 0, borderWidth: 1.6 }
        ] },
        options: {
            maintainAspectRatio: false, animation: false, interaction: { intersect: false, mode: 'index' },
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return ' ' + GBX.bytes(c.parsed.y) + '/s'; } } } },
            scales: {
                x: { display: false },
                y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,.05)' }, border: { display: false }, ticks: { color: '#858d97', font: { size: 10 }, maxTicksLimit: 5, callback: function (v) { return GBX.bytes(v, 0); } } }
            }
        }
    });
    var series = { net: [[], []], io: [[], []] };

    function redraw() {
        chart.data.datasets[0].data = Array(points - series[mode][0].length).fill(null).concat(series[mode][0]);
        chart.data.datasets[1].data = Array(points - series[mode][1].length).fill(null).concat(series[mode][1]);
        chart.update();
    }

    $('[data-chart]').on('click', function () {
        $('[data-chart]').removeClass('active');
        mode = $(this).addClass('active').data('chart');
        $('.lbl-a').text(mode === 'net' ? 'Upstream' : 'Read');
        $('.lbl-b').text(mode === 'net' ? 'Downstream' : 'Write');
        $('.lbl-c').text(mode === 'net' ? 'Total sent' : 'Total read');
        $('.lbl-d').text(mode === 'net' ? 'Total received' : 'Total written');
        redraw();
        paintTotals();
    });

    var last = null;
    function paintTotals() {
        if (!last) return;
        var a = mode === 'net' ? last.network.tx : last.diskio.read, b = mode === 'net' ? last.network.rx : last.diskio.write;
        var sa = series[mode][0], sb = series[mode][1];
        $('#rateA').text(sa.length ? GBX.bytes(sa[sa.length - 1]) + '/s' : '-');
        $('#rateB').text(sb.length ? GBX.bytes(sb[sb.length - 1]) + '/s' : '-');
        $('#totalA').text(GBX.bytes(a));
        $('#totalB').text(GBX.bytes(b));
    }

    function push(arr, v) { arr.push(Math.max(0, v)); if (arr.length > points) arr.shift(); }

    function poll() {
        $.getJSON(@json(route('home.stats'))).done(function (s) {
            setGauge('load', s.load.percent, s.load.percent + '%', s.load.label + ' · ' + s.load.one);
            setGauge('cpu', s.cpu.percent, s.cpu.percent + '%', s.cpu.cores + ' cores');
            setGauge('memory', s.memory.percent, Math.round(s.memory.percent) + '%', Math.round(s.memory.used / 1048576) + ' / ' + Math.round(s.memory.total / 1048576) + ' MB');
            ensureDiskGauges(s.disks);
            s.disks.forEach(function (d, i) { setGauge('disk' + i, d.percent, d.percent + '%', GBX.bytes(d.used, 1) + ' / ' + GBX.bytes(d.total, 1)); });
            $('#uptimeChip').text(GBX.duration(s.uptime.seconds));

            if (prev) {
                var dt = Math.max(0.5, s.time - prev.time);
                push(series.net[0], (s.network.tx - prev.network.tx) / dt);
                push(series.net[1], (s.network.rx - prev.network.rx) / dt);
                push(series.io[0], (s.diskio.read - prev.diskio.read) / dt);
                push(series.io[1], (s.diskio.write - prev.diskio.write) / dt);
                redraw();
            }
            prev = s; last = s;
            paintTotals();

            $('#disksList').html(s.disks.map(function (d) {
                return '<div class="mb-3"><div class="d-flex justify-content-between small mb-1"><span><i class="bi bi-hdd me-1 text-muted"></i><strong>' + GBX.escape(d.mount) + '</strong> <span class="cell-sub">' + GBX.escape(d.device) + ' · ' + GBX.escape(d.fs) + '</span></span>' +
                    '<span class="font-mono cell-sub">' + GBX.bytes(d.used, 1) + ' / ' + GBX.bytes(d.total, 1) + '</span></div>' +
                    '<div class="progress"><div class="progress-bar ' + GBX.level(d.percent) + '" style="width:' + d.percent + '%"></div></div>' +
                    '<div class="cell-sub mt-1">' + d.percent + '% used · inodes ' + d.inodes_percent + '%</div></div>';
            }).join('') || '<div class="text-muted small">No disks detected.</div>');
        }).always(function () { setTimeout(poll, document.hidden ? 10000 : 3000); });
    }
    poll();

    $.getJSON(@json(route('home.overview'))).done(function (o) {
        $('#tileFirewall').text(o.firewall_rules);
        if (o.updates > 0) $('#updatesBadge').text(o.updates).removeClass('d-none');
        if (o.reboot_required) $('#rebootAlert').removeClass('d-none');
        $('#softwareGrid').html(o.software.map(function (s) {
            var dot = s.running === null ? '' : '<span class="status-dot ' + (s.running ? 'on' : 'off') + '" title="' + (s.running ? 'Running' : 'Stopped') + '"></span>';
            return '<div class="col-6 col-md-4"><a href="{{ route('home.software') }}" class="soft-mini text-reset">' +
                '<div class="soft-icon"><i class="bi ' + s.icon + '"></i></div><div class="min-w-0 flex-grow-1"><div class="name text-truncate">' + GBX.escape(s.name) + '</div>' +
                '<div class="ver text-truncate">' + GBX.escape(s.installed_version || 'installed') + '</div></div>' + dot + '</a></div>';
        }).join('') || '<div class="col-12 text-center text-muted py-4">No software detected. <a href="{{ route('home.software') }}">Install software</a></div>');
    });

    $.getJSON(@json(route('home.processes.data'))).done(function (r) { $('#tileProcs').text(r.data.length); });
});
</script>
@endpush
