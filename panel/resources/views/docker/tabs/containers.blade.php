<div class="gbx-card">
    <div class="db-toolbar">
        @if ($canWrite)
            <button class="btn btn-primary" data-dk-create><i class="bi bi-plus-lg"></i> Create Container</button>
            <button class="btn btn-outline-secondary" id="dkLogManage"><i class="bi bi-journal-text"></i> Log Manage</button>
            <button class="btn btn-outline-secondary" id="dkPruneContainers"><i class="bi bi-eraser"></i> Clear Container</button>
        @endif
        <div class="db-toolbar-right">
            <select class="form-select" id="dkStateFilter">
                <option value="">All states</option>
                <option value="running">Running</option>
                <option value="exited">Stopped</option>
                <option value="paused">Paused</option>
            </select>
            <div class="db-search"><input type="search" class="form-control" id="dkSearch" placeholder="Search container name, ID, container image"><i class="bi bi-search"></i></div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover db-table dk-table">
            <thead>
            <tr>
                @if ($canWrite)<th class="ws-check"><input type="checkbox" class="form-check-input" data-dk-all></th>@endif
                <th>Container name</th>
                <th>Container ID</th>
                <th>Status</th>
                <th>Image</th>
                <th>IP</th>
                <th>IPV6</th>
                <th>Port (Host--&gt;Container)</th>
                <th>Create time</th>
                <th>Note</th>
                <th class="text-end">Operate</th>
            </tr>
            </thead>
            <tbody id="dkRows"><tr><td colspan="11" class="sm-empty"><i class="bi bi-arrow-repeat spin"></i> Loading</td></tr></tbody>
        </table>
    </div>
    <div class="dk-footer">
        @if ($canWrite)
            <div class="d-flex align-items-center gap-2">
                <span class="cell-sub" data-dk-selected>0 selected</span>
                <select class="form-select form-select-sm w-auto" data-dk-bulk>
                    <option value="">Please choose</option>
                    <option value="start">Start</option>
                    <option value="stop">Stop</option>
                    <option value="restart">Restart</option>
                    <option value="rm">Delete</option>
                </select>
                <button class="btn btn-sm btn-outline-secondary" data-dk-bulk-run disabled>Execute</button>
            </div>
        @endif
        <div class="dk-pager" id="dkPager"></div>
    </div>
</div>
