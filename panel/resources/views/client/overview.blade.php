@extends('client.layout')

@section('title', 'Overview')

@section('content')
    @if (! $client->package)
        <div class="db-notice cron-notice"><i class="bi bi-exclamation-triangle"></i><span>Your account has no hosting package yet. Contact support to activate it.</span></div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3"><div class="stat-tile"><div class="icon"><i class="bi bi-activity"></i></div><div class="min-w-0"><div class="label">Today's Requests</div><div class="value" id="ovRequests">-</div></div></div></div>
        <a class="col-6 col-xl-3 text-decoration-none" href="{{ route('client.websites') }}"><div class="stat-tile"><div class="icon"><i class="bi bi-globe2"></i></div><div class="min-w-0"><div class="label">Website</div><div class="value" data-count="websites">-</div></div></div></a>
        <a class="col-6 col-xl-3 text-decoration-none" href="{{ route('client.ftp') }}"><div class="stat-tile"><div class="icon"><i class="bi bi-folder-symlink"></i></div><div class="min-w-0"><div class="label">FTP</div><div class="value" data-count="ftp">-</div></div></div></a>
        <a class="col-6 col-xl-3 text-decoration-none" href="{{ route('client.databases') }}"><div class="stat-tile"><div class="icon"><i class="bi bi-database"></i></div><div class="min-w-0"><div class="label">Database</div><div class="value" data-count="databases">-</div></div></div></a>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-xl-4">
            <div class="gbx-card h-100">
                <div class="gbx-card-header"><h2><i class="bi bi-graph-up"></i> Requests (24 hours)</h2></div>
                <div class="gbx-card-body"><div style="height: 220px"><canvas id="ovRequestsChart"></canvas></div></div>
            </div>
        </div>
        <div class="col-xl-8">
            <div class="gbx-card h-100">
                <div class="gbx-card-header"><h2><i class="bi bi-arrow-down-up"></i> Bandwidth</h2><div class="actions cell-sub">This month</div></div>
                <div class="gbx-card-body">
                    <div class="row g-3 align-items-center">
                        <div class="col-md-4">
                            <div class="gauge-wrap">
                                <div class="gauge-title">Bandwidth Usage</div>
                                <div class="gauge" id="gauge-bandwidth"><svg viewBox="0 0 120 120"><circle class="track" cx="60" cy="60" r="50"/><circle class="bar" cx="60" cy="60" r="50" stroke-dasharray="314.16" stroke-dashoffset="314.16"/></svg><div class="gauge-value">-</div></div>
                                <div class="gauge-sub">&nbsp;</div>
                            </div>
                        </div>
                        <div class="col-md-8"><div style="height: 220px"><canvas id="ovBandwidthChart"></canvas></div></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="gbx-card">
        <div class="gbx-card-header"><h2><i class="bi bi-hdd"></i> Hard Disk</h2><div class="actions cell-sub" id="ovUpdated"></div></div>
        <div class="gbx-card-body">
            <div class="row g-3 align-items-center">
                <div class="col-md-3">
                    <div class="gauge-wrap">
                        <div class="gauge-title">Disk Usage</div>
                        <div class="gauge" id="gauge-disk"><svg viewBox="0 0 120 120"><circle class="track" cx="60" cy="60" r="50"/><circle class="bar" cx="60" cy="60" r="50" stroke-dasharray="314.16" stroke-dashoffset="314.16"/></svg><div class="gauge-value">-</div></div>
                        <div class="gauge-sub">&nbsp;</div>
                    </div>
                </div>
                <div class="col-md-9"><div style="height: 220px"><canvas id="ovDiskChart"></canvas></div></div>
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
    var CIRC = 314.16;
    var gauge = function (id, used, limit, text, sub) {
        var p = limit ? Math.min(100, used / limit * 100) : 0, $g = $('#gauge-' + id);
        $g.removeClass('warn crit').addClass(GBX.level(p));
        $g.find('.bar').attr('stroke-dashoffset', CIRC - CIRC * p / 100);
        $g.find('.gauge-value').text(limit ? Math.round(p) + '%' : text);
        $g.closest('.gauge-wrap').find('.gauge-sub').text(sub);
    };
    var lineChart = function (id, labels, values, color, fmt) {
        return new Chart(document.getElementById(id), {
            type: 'line',
            data: { labels: labels, datasets: [{ data: values, borderColor: color, backgroundColor: color.replace('rgb(', 'rgba(').replace(')', ',.10)'), fill: true, tension: .3, pointRadius: 0, borderWidth: 1.6 }] },
            options: {
                maintainAspectRatio: false, animation: false, interaction: { intersect: false, mode: 'index' },
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return ' ' + fmt(c.parsed.y); } } } },
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#858d97', font: { size: 10 }, maxTicksLimit: 8 } },
                    y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,.05)' }, border: { display: false }, ticks: { color: '#858d97', font: { size: 10 }, maxTicksLimit: 5, callback: function (v) { return fmt(v); } } }
                }
            }
        });
    };

    GBX.get(@json(route('client.overview'))).done(function (r) {
        $('#ovRequests').text(r.requests_today.toLocaleString());
        $.each(r.counts, function (key, pair) {
            $('[data-count=' + key + ']').html(pair[0] + ' <span class="cell-sub" style="font-size:.8rem">/ ' + (pair[1] === 0 ? '&infin;' : pair[1] < 0 ? '0' : pair[1]) + '</span>');
        });
        gauge('bandwidth', r.bandwidth.used, r.bandwidth.limit, r.bandwidth.used_h, r.bandwidth.used_h + ' / ' + (r.bandwidth.limit_h || 'Unlimited'));
        gauge('disk', r.disk.used, r.disk.limit, r.disk.used_h, r.disk.used_h + ' / ' + (r.disk.limit_h || 'Unlimited'));
        $('#ovUpdated').text(r.updated ? 'Updated ' + r.updated : 'Usage is measured every hour');

        var days = r.days.map(function (d) { return d.day.slice(5); });
        lineChart('ovRequestsChart', r.requests_24h.labels, r.requests_24h.values, 'rgb(106,167,255)', function (v) { return Math.round(v).toLocaleString(); });
        lineChart('ovBandwidthChart', days, r.days.map(function (d) { return d.bandwidth; }), 'rgb(62,207,142)', function (v) { return GBX.bytes(v, 1); });
        lineChart('ovDiskChart', days, r.days.map(function (d) { return d.disk; }), 'rgb(245,180,84)', function (v) { return GBX.bytes(v, 1); });
    });
});
</script>
@endpush
