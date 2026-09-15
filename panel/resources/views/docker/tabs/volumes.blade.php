<div class="gbx-card">
    <div class="db-toolbar">
        @if ($canWrite)
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#dkVolumeModal"><i class="bi bi-plus-lg"></i> Add volume</button>
            <button class="btn btn-outline-secondary" id="dkPruneVolumes"><i class="bi bi-eraser"></i> Clear volume</button>
        @endif
        <div class="db-toolbar-right">
            <div class="db-search"><input type="search" class="form-control" id="dkSearch" placeholder="Search volume, container"><i class="bi bi-search"></i></div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover db-table dk-table">
            <thead>
            <tr>
                @if ($canWrite)<th class="ws-check"><input type="checkbox" class="form-check-input" data-dk-all></th>@endif
                <th>Volume name</th>
                <th>Mount point</th>
                <th>Container</th>
                <th>Driver</th>
                <th>Creation time</th>
                <th>Tag</th>
                <th class="text-end">Operate</th>
            </tr>
            </thead>
            <tbody id="dkRows"><tr><td colspan="8" class="sm-empty"><i class="bi bi-arrow-repeat spin"></i> Loading</td></tr></tbody>
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
