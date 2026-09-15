@extends('layouts.app')

@section('title', 'Logs')

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/vendor/datatables/dataTables.bootstrap5.min.css') }}">
@endpush

@section('content')
    <div class="page-head">
        <div>
            <h2>Logs</h2>
            <p>System, service, website and panel activity logs.</p>
        </div>
    </div>

    <div class="gbx-card">
        <div class="gbx-card-header py-0">
            <ul class="nav nav-tabs gbx-tabs border-0" id="logTabs">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-files" data-hash="files"><i class="bi bi-file-earmark-text"></i> Log files</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-activity" data-hash="activity"><i class="bi bi-activity"></i> Panel activity</button></li>
            </ul>
        </div>
        <div class="tab-content">
            <div class="tab-pane fade show active" id="tab-files">
                <div class="gbx-card-body">
                    <div class="row g-2 align-items-end mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Log</label>
                            <select class="form-select" id="logKey">
                                @foreach ($sources as $group => $files)
                                    <optgroup label="{{ $group }}">
                                        @foreach ($files as $key => $f)
                                            <option value="{{ $key }}">{{ $f['label'] }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label">Lines</label>
                            <select class="form-select" id="logLines">
                                <option>100</option><option selected>500</option><option>2000</option><option>10000</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label">Search</label>
                            <input type="search" class="form-control" id="logSearch" placeholder="Filter lines (grep)">
                        </div>
                        <div class="col-md-3 d-flex gap-2">
                            <button class="btn btn-secondary flex-grow-1" id="logLoad"><i class="bi bi-arrow-clockwise"></i> Load</button>
                            <button class="btn btn-outline-secondary btn-icon" id="logFollow" title="Follow (auto refresh)"><i class="bi bi-broadcast"></i></button>
                            <button class="btn btn-outline-danger btn-icon" id="logClear" title="Clear log"><i class="bi bi-eraser"></i></button>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between cell-sub mb-2"><span class="font-mono" id="logPath"></span><span id="logSize"></span></div>
                    <pre class="gbx-console tall" id="logOut"></pre>
                </div>
            </div>
            <div class="tab-pane fade" id="tab-activity">
                <div class="table-responsive">
                    <table class="table table-hover" id="activityTable" style="width:100%">
                        <thead><tr><th>Time</th><th>User</th><th>Category</th><th>Action</th><th>Details</th><th>IP</th></tr></thead>
                    </table>
                </div>
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
    var follow = null, activity = null;

    function highlight(text, term) {
        var esc = GBX.escape(text)
            .replace(/^(.*\b(error|fatal|failed|failure|critical|emerg|denied)\b.*)$/gim, '<span class="err">$1</span>')
            .replace(/^(.*\b(warn|warning|notice)\b.*)$/gim, '<span style="color:#f5b454">$1</span>');
        return esc;
    }

    function load(scroll) {
        GBX.get(@json(route('logs.read')), { key: $('#logKey').val(), lines: $('#logLines').val(), search: $('#logSearch').val() }, { silent: !!follow }).done(function (r) {
            var $o = $('#logOut'), atBottom = $o[0].scrollHeight - $o.scrollTop() - $o.outerHeight() < 60;
            $('#logPath').text(r.path);
            $('#logSize').text(GBX.bytes(r.size));
            $o.html(highlight(r.content || '(empty)'));
            if (scroll || atBottom) $o.scrollTop($o[0].scrollHeight);
        });
    }

    $('#logLoad').on('click', function () { load(true); });
    $('#logKey, #logLines').on('change', function () { load(true); });
    $('#logSearch').on('keydown', function (e) { if (e.key === 'Enter') load(true); });
    $('#logFollow').on('click', function () {
        if (follow) { clearInterval(follow); follow = null; $(this).removeClass('btn-primary').addClass('btn-outline-secondary'); return; }
        $(this).removeClass('btn-outline-secondary').addClass('btn-primary');
        follow = setInterval(function () { if (!document.hidden) load(false); }, 3000);
    });
    $('#logClear').on('click', function () {
        GBX.confirm({ text: 'Truncate ' + $('#logKey option:selected').text() + '?', danger: true, confirmText: 'Clear log' }).then(function (r) {
            if (r.isConfirmed) GBX.post(@json(route('logs.clear')), { key: $('#logKey').val() }).done(function (res) { toastr.success(res.message); load(true); });
        });
    });
    load(true);

    function initActivity() {
        if (activity) return activity.ajax.reload();
        activity = $('#activityTable').DataTable({
            ajax: { url: @json(route('logs.activity')), dataSrc: 'data' },
            order: [[0, 'desc']],
            columns: [
                { data: 'time', className: 'text-nowrap cell-sub' },
                { data: 'user' },
                { data: 'category', render: function (v) { return '<span class="badge badge-soft">' + GBX.escape(v) + '</span>'; } },
                { data: 'action', render: function (v) { return GBX.escape(v); } },
                { data: 'details', render: function (v) { return '<span class="font-mono small text-break">' + GBX.escape(v || '') + '</span>'; } },
                { data: 'ip', className: 'font-mono small' }
            ]
        });
    }

    $('#logTabs [data-bs-toggle=tab]').on('shown.bs.tab', function () {
        history.replaceState(null, '', '#' + $(this).data('hash'));
        if ($(this).data('hash') === 'activity') initActivity();
    });
    if (location.hash === '#activity') bootstrap.Tab.getOrCreateInstance($('#logTabs [data-hash=activity]')[0]).show();
});
</script>
@endpush
