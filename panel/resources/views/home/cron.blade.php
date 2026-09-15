@extends('layouts.app')

@section('title', 'Cron Jobs')

@section('content')
    <div class="page-head">
        <div>
            <h2>Cron Jobs</h2>
            <p>Scheduled tasks written to <code>/etc/cron.d/gbxpanel</code>. Output is kept in a log per job.</p>
        </div>
        <div class="actions">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#cronModal" data-mode="create"><i class="bi bi-plus-lg"></i> Add cron job</button>
        </div>
    </div>

    <div class="gbx-card">
        <div class="gbx-card-body p-0">
            @if ($jobs->isEmpty())
                <div class="empty-state">
                    <div class="icon"><i class="bi bi-calendar2-week"></i></div>
                    <h3>No cron jobs yet</h3>
                    <p>Schedule backups, scripts, Laravel schedulers or cleanup commands.</p>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#cronModal" data-mode="create"><i class="bi bi-plus-lg"></i> Add cron job</button>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead><tr><th>Name</th><th>Schedule</th><th>Command</th><th>User</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                        @foreach ($jobs as $job)
                            <tr>
                                <td><div class="cell-strong">{{ $job->name }}</div><div class="cell-sub">{{ $job->last_run_at ? 'Manual run '.$job->last_run_at->diffForHumans() : 'Created '.$job->created_at->diffForHumans() }}</div></td>
                                <td><code>{{ $job->schedule }}</code><div class="cell-sub">{{ $presets[$job->schedule] ?? 'Custom' }}</div></td>
                                <td><div class="font-mono small text-truncate" style="max-width:380px" title="{{ $job->command }}">{{ $job->command }}</div></td>
                                <td>{{ $job->run_as }}</td>
                                <td>{!! $job->is_active ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-soft">Paused</span>' !!}</td>
                                <td class="table-actions">
                                    <button class="btn btn-sm btn-outline-secondary" data-post="{{ route('home.cron.run', $job) }}" data-confirm="Run &quot;{{ $job->name }}&quot; now?"><i class="bi bi-play-fill"></i> Run</button>
                                    <button class="btn btn-sm btn-outline-secondary view-log" data-url="{{ route('home.cron.log', $job) }}" data-clear="{{ route('home.cron.log.clear', $job) }}" data-name="{{ $job->name }}"><i class="bi bi-journal-text"></i> Log</button>
                                    <button class="btn btn-sm btn-ghost btn-icon" data-bs-toggle="modal" data-bs-target="#cronModal" data-mode="edit" data-job="{{ json_encode($job->only(['id', 'name', 'schedule', 'command', 'run_as', 'is_active'])) }}" title="Edit"><i class="bi bi-pencil"></i></button>
                                    <button class="btn btn-sm btn-ghost btn-icon text-danger" data-post="{{ route('home.cron.destroy', $job) }}" data-method="DELETE" data-confirm="Delete cron job &quot;{{ $job->name }}&quot;?" data-danger data-reload title="Delete"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection

@push('modals')
    <div class="modal fade" id="cronModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <form class="modal-content" data-ajax data-reload action="{{ route('home.cron.store') }}" id="cronForm">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-calendar2-plus"></i> <span id="cronTitle">Add cron job</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Name</label>
                            <input type="text" name="name" class="form-control" required maxlength="100" placeholder="Nightly backup">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Run as</label>
                            <input type="text" name="run_as" class="form-control font-mono" value="root" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Preset</label>
                            <select class="form-select" id="cronPreset">
                                <option value="">Custom expression</option>
                                @foreach ($presets as $expr => $label)
                                    <option value="{{ $expr }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Cron expression</label>
                            <input type="text" name="schedule" class="form-control font-mono" required placeholder="*/5 * * * *">
                            <div class="form-text">minute hour day month weekday &middot; or @daily, @hourly, @reboot</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Command</label>
                            <textarea name="command" class="form-control font-mono" rows="4" required placeholder="cd /www/wwwroot/example.com && php artisan schedule:run"></textarea>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="cronActive" checked>
                                <label class="form-check-label" for="cronActive">Active</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="cronLogModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-journal-text"></i> <span id="cronLogTitle">Log</span></h5>
                    <button class="btn btn-sm btn-outline-secondary ms-auto me-2" id="cronLogClear"><i class="bi bi-eraser"></i> Clear</button>
                    <button type="button" class="btn-close ms-0" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body"><pre class="gbx-console" id="cronLogOut" style="height:60vh"></pre></div>
            </div>
        </div>
    </div>
@endpush

@push('scripts')
<script>
$(function () {
    var storeUrl = @json(route('home.cron.store')), base = @json(url('home/cron'));

    $('#cronModal').on('show.bs.modal', function (e) {
        var $t = $(e.relatedTarget), $f = $('#cronForm');
        $f[0].reset();
        $('#cronPreset').val('');
        if ($t.data('mode') === 'edit') {
            var j = $t.data('job');
            $('#cronTitle').text('Edit cron job');
            $f.attr('action', base + '/' + j.id).attr('data-method', 'PUT').data('method', 'PUT').data('no-reset', 1);
            $f.find('[name=name]').val(j.name);
            $f.find('[name=schedule]').val(j.schedule);
            $f.find('[name=command]').val(j.command);
            $f.find('[name=run_as]').val(j.run_as);
            $f.find('[name=is_active]').prop('checked', !!j.is_active);
            $('#cronPreset').val(j.schedule);
        } else {
            $('#cronTitle').text('Add cron job');
            $f.attr('action', storeUrl).removeAttr('data-method').removeData('method');
        }
    });
    $('#cronPreset').on('change', function () { if (this.value) $('#cronForm [name=schedule]').val(this.value); });

    var clearUrl = null;
    $('.view-log').on('click', function () {
        clearUrl = $(this).data('clear');
        $('#cronLogTitle').text('Log: ' + $(this).data('name'));
        $('#cronLogOut').text('Loading...');
        bootstrap.Modal.getOrCreateInstance('#cronLogModal').show();
        GBX.get($(this).data('url')).done(function (r) { $('#cronLogOut').text(r.log || 'The log is empty.').scrollTop(1e9); });
    });
    $('#cronLogClear').on('click', function () {
        GBX.del(clearUrl).done(function (r) { toastr.success(r.message); $('#cronLogOut').text('The log is empty.'); });
    });
});
</script>
@endpush
