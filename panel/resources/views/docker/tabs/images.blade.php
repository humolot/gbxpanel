<div class="gbx-card">
    <div class="db-toolbar">
        @if ($canWrite)
            <button class="btn btn-primary" data-dk-pull><i class="bi bi-cloud-download"></i> Pull from repository</button>
            <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#dkImportModal"><i class="bi bi-upload"></i> Import image</button>
            <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#dkBuildModal"><i class="bi bi-hammer"></i> Build image</button>
        @endif
        <a class="btn btn-outline-secondary" href="{{ route('docker.index', ['tab' => 'hub']) }}"><i class="bi bi-cloud"></i> Cloud image</a>
        @if ($canWrite)
            <button class="btn btn-outline-secondary" id="dkPruneImages"><i class="bi bi-eraser"></i> Clear image</button>
        @endif
        <div class="db-toolbar-right">
            <div class="db-search"><input type="search" class="form-control" id="dkSearch" placeholder="Search image name, ID, containers in use"><i class="bi bi-search"></i></div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover db-table dk-table">
            <thead>
            <tr>
                @if ($canWrite)<th class="ws-check"><input type="checkbox" class="form-check-input" data-dk-all></th>@endif
                <th>ID</th>
                <th>Image name</th>
                <th>Size</th>
                <th>Creation time</th>
                <th>Containers using image</th>
                <th class="text-end">Operate</th>
            </tr>
            </thead>
            <tbody id="dkRows"><tr><td colspan="7" class="sm-empty"><i class="bi bi-arrow-repeat spin"></i> Loading</td></tr></tbody>
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
