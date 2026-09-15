@extends('layouts.app')

@section('title', 'Monitor')

@section('content')
    <div class="page-head">
        <div>
            <h2>Monitor</h2>
            <p>Historical resource usage collected every minute.</p>
        </div>
        <div class="actions">
            <ul class="nav nav-pills gbx-pills bg-surface-2 rounded-3 p-1" id="rangePills">
                @foreach ([1 => '1h', 6 => '6h', 24 => '24h', 72 => '3d', 168 => '7d'] as $h => $label)
                    <li class="nav-item"><button class="nav-link {{ $h === 24 ? 'active' : '' }}" data-hours="{{ $h }}">{{ $label }}</button></li>
                @endforeach
            </ul>
        </div>
    </div>

    <div class="alert alert-secondary d-none" id="noData"><i class="bi bi-info-circle me-1"></i> No samples yet. Metrics are collected by the scheduler every minute (<code>php artisan schedule:run</code> in cron).</div>

    <div class="row g-3">
        @foreach (['cpu' => ['CPU usage', 'bi-cpu'], 'memory' => ['Memory usage', 'bi-memory'], 'load' => ['Load average (1m)', 'bi-speedometer'], 'net' => ['Network traffic', 'bi-arrow-down-up']] as $key => [$label, $icon])
            <div class="col-xl-6">
                <div class="gbx-card">
                    <div class="gbx-card-header"><h2><i class="bi {{ $icon }}"></i> {{ $label }}</h2></div>
                    <div class="gbx-card-body"><div class="chart-box"><canvas id="chart-{{ $key }}"></canvas></div></div>
                </div>
            </div>
        @endforeach
    </div>
@endsection

@push('vendor')
    <script src="{{ asset('assets/vendor/chartjs/chart.umd.min.js') }}"></script>
@endpush

@push('scripts')
<script>
$(function () {
    var charts = {};
    var base = function (yFormat, max) {
        return {
            maintainAspectRatio: false, animation: false, interaction: { intersect: false, mode: 'index' },
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return ' ' + c.dataset.label + ': ' + yFormat(c.parsed.y); } } } },
            scales: {
                x: { grid: { display: false }, ticks: { color: '#858d97', maxTicksLimit: 8, font: { size: 10 } } },
                y: { beginAtZero: true, max: max, grid: { color: 'rgba(255,255,255,.05)' }, border: { display: false }, ticks: { color: '#858d97', font: { size: 10 }, callback: yFormat } }
            }
        };
    };
    var line = function (label, color) { return { label: label, data: [], borderColor: color, backgroundColor: color.replace('1)', '.08)'), fill: true, tension: .3, pointRadius: 0, borderWidth: 1.5 }; };
    var pct = function (v) { return Math.round(v) + '%'; };
    var rate = function (v) { return GBX.bytes(v, 1) + '/s'; };

    charts.cpu = new Chart('chart-cpu', { type: 'line', data: { labels: [], datasets: [line('CPU', 'rgba(62,207,142,1)')] }, options: base(pct, 100) });
    charts.memory = new Chart('chart-memory', { type: 'line', data: { labels: [], datasets: [line('Memory', 'rgba(106,167,255,1)')] }, options: base(pct, 100) });
    charts.load = new Chart('chart-load', { type: 'line', data: { labels: [], datasets: [line('Load', 'rgba(245,180,84,1)')] }, options: base(function (v) { return Number(v).toFixed(2); }) });
    charts.net = new Chart('chart-net', { type: 'line', data: { labels: [], datasets: [line('Out', 'rgba(245,180,84,1)'), line('In', 'rgba(232,234,237,1)')] }, options: base(rate) });

    function load(hours) {
        GBX.get(@json(route('home.monitor.data')), { hours: hours }).done(function (d) {
            $('#noData').toggleClass('d-none', d.labels.length > 0);
            charts.cpu.data.labels = charts.memory.data.labels = charts.load.data.labels = charts.net.data.labels = d.labels;
            charts.cpu.data.datasets[0].data = d.cpu;
            charts.memory.data.datasets[0].data = d.memory;
            charts.load.data.datasets[0].data = d.load;
            charts.net.data.datasets[0].data = d.net_tx;
            charts.net.data.datasets[1].data = d.net_rx;
            $.each(charts, function (_, c) { c.update(); });
        });
    }

    $('#rangePills').on('click', '[data-hours]', function () {
        $('#rangePills .nav-link').removeClass('active');
        load($(this).addClass('active').data('hours'));
    });
    load(24);
    setInterval(function () { if (!document.hidden) load($('#rangePills .active').data('hours')); }, 60000);
});
</script>
@endpush
