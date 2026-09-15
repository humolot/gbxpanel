@extends('layouts.app')

@section('title', 'Services')

@section('content')
    <div class="page-head">
        <div>
            <h2>Services</h2>
            <p>Start, stop and inspect systemd services managed by the panel.</p>
        </div>
        <div class="actions">
            <button class="btn btn-outline-secondary" id="refreshBtn"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
        </div>
    </div>

    <div class="gbx-card">
        <div class="gbx-card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead><tr><th>Service</th><th>Status</th><th>Boot</th><th>Memory</th><th>Active since</th><th class="text-end">Actions</th></tr></thead>
                    <tbody id="servicesBody"><tr class="loading-row"><td colspan="6"><i class="bi bi-arrow-repeat spin"></i> Loading services...</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('modals')
    <div class="modal fade" id="journalModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-journal-text"></i> <span id="journalTitle">Journal</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body"><pre class="gbx-console" id="journalOut" style="height:60vh"></pre></div>
            </div>
        </div>
    </div>
@endpush

@push('scripts')
<script>
$(function () {
    function load() {
        GBX.get(@json(route('home.services.data'))).done(function (res) {
            $('#servicesBody').html(res.data.map(function (s) {
                var n = GBX.escape(s.name);
                return '<tr>' +
                    '<td><div class="cell-strong">' + GBX.escape(s.label) + '</div><div class="cell-sub font-mono">' + n + '</div></td>' +
                    '<td><span class="d-inline-flex align-items-center gap-2"><span class="status-dot ' + (s.active ? 'on' : 'off') + '"></span>' + GBX.escape(s.state) + '</span></td>' +
                    '<td>' + (s.enabled ? '<span class="badge badge-success">enabled</span>' : '<span class="badge badge-soft">disabled</span>') + '</td>' +
                    '<td class="font-mono small">' + (s.memory ? GBX.bytes(s.memory, 1) : '-') + '</td>' +
                    '<td class="cell-sub">' + GBX.escape(s.since || '-') + '</td>' +
                    '<td class="table-actions">' +
                    (s.active
                        ? '<button class="btn btn-sm btn-outline-secondary svc" data-a="restart" data-s="' + n + '"><i class="bi bi-arrow-clockwise"></i> Restart</button><button class="btn btn-sm btn-outline-secondary svc" data-a="stop" data-s="' + n + '"><i class="bi bi-stop-fill"></i> Stop</button>'
                        : '<button class="btn btn-sm btn-success svc" data-a="start" data-s="' + n + '"><i class="bi bi-play-fill"></i> Start</button>') +
                    '<div class="dropdown d-inline-block"><button class="btn btn-sm btn-ghost btn-icon" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button><div class="dropdown-menu dropdown-menu-end">' +
                    '<a href="#" class="dropdown-item svc" data-a="reload" data-s="' + n + '"><i class="bi bi-arrow-repeat"></i> Reload config</a>' +
                    (s.enabled ? '<a href="#" class="dropdown-item svc" data-a="disable" data-s="' + n + '"><i class="bi bi-toggle-off"></i> Disable on boot</a>' : '<a href="#" class="dropdown-item svc" data-a="enable" data-s="' + n + '"><i class="bi bi-toggle-on"></i> Enable on boot</a>') +
                    '<a href="#" class="dropdown-item journal" data-s="' + n + '"><i class="bi bi-journal-text"></i> View journal</a></div></div>' +
                    '</td></tr>';
            }).join('') || '<tr class="loading-row"><td colspan="6">No known services found.</td></tr>');
        });
    }
    load();
    $('#refreshBtn').on('click', load);

    $('#servicesBody').on('click', '.svc', function (e) {
        e.preventDefault();
        var $b = $(this), action = $b.data('a'), service = $b.data('s');
        var go = function () {
            GBX.busy($b, true);
            GBX.post(@json(route('home.services.action')), { service: service, action: action })
                .done(function (r) { toastr.success(r.message); load(); })
                .always(function () { GBX.busy($b, false); });
        };
        if (action === 'stop' || action === 'disable') {
            GBX.confirm({ text: action.charAt(0).toUpperCase() + action.slice(1) + ' ' + service + '?', danger: true, confirmText: action }).then(function (r) { if (r.isConfirmed) go(); });
        } else { go(); }
    });

    $('#servicesBody').on('click', '.journal', function (e) {
        e.preventDefault();
        var s = $(this).data('s');
        $('#journalTitle').text('Journal: ' + s);
        $('#journalOut').text('Loading...');
        bootstrap.Modal.getOrCreateInstance('#journalModal').show();
        GBX.get(@json(route('home.services.journal')), { service: s }).done(function (r) {
            $('#journalOut').text(r.log).scrollTop(1e9);
        });
    });
});
</script>
@endpush
