@extends('layouts.app')

@section('title', 'Processes')

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/vendor/datatables/dataTables.bootstrap5.min.css') }}">
@endpush

@section('content')
    <div class="page-head">
        <div>
            <h2>Processes</h2>
            <p>Running processes sorted by CPU usage.</p>
        </div>
        <div class="actions">
            <div class="form-check form-switch d-flex align-items-center gap-2 mb-0 me-2">
                <input class="form-check-input" type="checkbox" id="autoRefresh" checked>
                <label class="form-check-label small text-muted" for="autoRefresh">Auto refresh</label>
            </div>
            <button class="btn btn-outline-secondary" id="refreshBtn"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
        </div>
    </div>

    <div class="gbx-card">
        <div class="gbx-card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover" id="procTable" style="width:100%">
                    <thead>
                    <tr><th>PID</th><th>User</th><th>CPU</th><th>Memory</th><th>RSS</th><th>Running</th><th>Process</th><th></th></tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('vendor')
    <script src="{{ asset('assets/vendor/datatables/dataTables.min.js') }}"></script>
    <script src="{{ asset('assets/vendor/datatables/dataTables.bootstrap5.min.js') }}"></script>
@endpush

@push('scripts')
<script>
$(function () {
    var bar = function (v) {
        return '<div class="d-flex align-items-center gap-2" style="min-width:110px"><div class="progress flex-grow-1"><div class="progress-bar ' + GBX.level(v) + '" style="width:' + Math.min(100, v) + '%"></div></div><span class="font-mono small" style="width:42px">' + v.toFixed(1) + '%</span></div>';
    };

    var table = $('#procTable').DataTable({
        ajax: { url: @json(route('home.processes.data')), dataSrc: 'data' },
        order: [[2, 'desc']],
        pageLength: 50,
        columns: [
            { data: 'pid', className: 'font-mono' },
            { data: 'user' },
            { data: 'cpu', render: function (v, t) { return t === 'display' ? bar(v) : v; } },
            { data: 'mem', render: function (v, t) { return t === 'display' ? bar(v) : v; } },
            { data: 'rss', render: function (v, t) { return t === 'display' ? GBX.bytes(v, 1) : v; } },
            { data: 'elapsed', render: function (v, t) { return t === 'display' ? GBX.duration(v) : v; } },
            { data: 'name', render: function (v, t, row) {
                return t === 'display' ? '<div class="cell-strong">' + GBX.escape(v) + '</div><div class="cell-sub font-mono text-truncate" style="max-width:420px" title="' + GBX.escape(row.command) + '">' + GBX.escape(row.command) + '</div>' : v + ' ' + row.command;
            } },
            { data: null, orderable: false, className: 'table-actions', render: function (row) {
                return '<div class="dropdown"><button class="btn btn-sm btn-ghost btn-icon" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button><div class="dropdown-menu dropdown-menu-end">' +
                    '<a class="dropdown-item kill" href="#" data-pid="' + row.pid + '" data-signal="TERM"><i class="bi bi-stop-circle"></i> Terminate (TERM)</a>' +
                    '<a class="dropdown-item kill" href="#" data-pid="' + row.pid + '" data-signal="HUP"><i class="bi bi-arrow-repeat"></i> Reload (HUP)</a>' +
                    '<a class="dropdown-item text-danger kill" href="#" data-pid="' + row.pid + '" data-signal="KILL"><i class="bi bi-x-octagon"></i> Force kill (KILL)</a></div></div>';
            } }
        ]
    });

    var reload = function () { if ($('.dropdown-menu.show').length === 0) table.ajax.reload(null, false); };
    $('#refreshBtn').on('click', reload);
    setInterval(function () { if ($('#autoRefresh').is(':checked') && !document.hidden) reload(); }, 5000);

    $('#procTable').on('click', '.kill', function (e) {
        e.preventDefault();
        var pid = $(this).data('pid'), signal = $(this).data('signal');
        GBX.confirm({ text: 'Send ' + signal + ' to process ' + pid + '?', danger: signal === 'KILL', confirmText: 'Send signal' }).then(function (r) {
            if (!r.isConfirmed) return;
            GBX.post(@json(route('home.processes.kill')), { pid: pid, signal: signal }).done(function (res) { toastr.success(res.message); reload(); });
        });
    });
});
</script>
@endpush
