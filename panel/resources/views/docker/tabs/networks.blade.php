<div class="gbx-card">
    <div class="db-toolbar">
        @if ($canWrite)
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#dkNetworkModal"><i class="bi bi-plus-lg"></i> Add network</button>
            <button class="btn btn-outline-secondary" id="dkPruneNetworks"><i class="bi bi-eraser"></i> Clear network</button>
        @endif
        <div class="db-toolbar-right">
            <div class="db-search"><input type="search" class="form-control" id="dkSearch" placeholder="Search network name, subnet"><i class="bi bi-search"></i></div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover db-table dk-table">
            <thead>
            <tr>
                @if ($canWrite)<th class="ws-check"><input type="checkbox" class="form-check-input" data-dk-all></th>@endif
                <th>Network name</th>
                <th>Driver</th>
                <th>IPv4</th>
                <th>IPv4 Gateway</th>
                <th>IPv6</th>
                <th>IPv6 Gateway</th>
                <th>Containers</th>
                <th>Tag</th>
                <th>Creation time</th>
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
                <button class="btn btn-sm btn-outline-secondary" data-dk-bulk-delete disabled>Batch Delete</button>
            </div>
        @endif
        <div class="dk-pager" id="dkPager"></div>
    </div>
</div>
