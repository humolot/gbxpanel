<div class="gbx-card dk-section">
    <div class="dk-section-head">
        <h3>Resource Overview</h3>
        <button class="btn btn-sm btn-ghost btn-icon ms-auto" data-bs-toggle="collapse" data-bs-target="#dkResourcesWrap" title="Collapse"><i class="bi bi-chevron-up"></i></button>
    </div>
    <div class="collapse show" id="dkResourcesWrap">
        <div class="dk-resources" id="dkResources">
            @foreach ([
                ['containers', 'Containers', 'bi-boxes'], ['compose', 'Compose', 'bi-stack'], ['images', 'Images', 'bi-layers'],
                ['networks', 'Networks', 'bi-globe2'], ['volumes', 'Volumes', 'bi-database'], ['registries', 'Registries', 'bi-archive'],
            ] as [$key, $label, $icon])
                <a class="dk-resource" href="{{ route('docker.index', ['tab' => $key]) }}" data-resource="{{ $key }}">
                    <div class="dk-resource-label">{{ $label }}</div>
                    <div class="dk-resource-value">-</div>
                    <div class="dk-resource-sub">&nbsp;</div>
                    <i class="bi {{ $icon }} dk-resource-icon"></i>
                </a>
            @endforeach
        </div>
    </div>
</div>

<div class="gbx-card dk-section">
    <div class="dk-section-head">
        <h3>Container List</h3>
        <div class="dk-head-tools">
            <select class="form-select form-select-sm" id="dkCardFilter">
                <option value="">All</option>
                <option value="running">Running</option>
                <option value="stopped">Stopped</option>
            </select>
            <div class="db-search"><input type="search" class="form-control form-control-sm" id="dkCardSearch" placeholder="Search container name or image name"><i class="bi bi-search"></i></div>
            <button class="btn btn-sm btn-outline-secondary btn-icon" id="dkCardRefresh" title="Refresh"><i class="bi bi-arrow-clockwise"></i></button>
        </div>
    </div>
    <div class="dk-cards" id="dkCards">
        <div class="sm-empty w-100"><i class="bi bi-arrow-repeat spin"></i> Loading</div>
    </div>
</div>
