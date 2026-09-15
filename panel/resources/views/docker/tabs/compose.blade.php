<div class="dk-compose">
    <aside class="gbx-card dk-compose-side">
        <div class="dk-compose-side-head">
            @if ($canWrite)
                <button class="btn btn-primary btn-sm" data-dk-compose-add><i class="bi bi-plus-lg"></i> Add Compose</button>
            @endif
            <button class="btn btn-outline-secondary btn-sm" id="dkTemplates"><i class="bi bi-files"></i> Template List</button>
        </div>
        <div class="db-search px-3 pb-2"><input type="search" class="form-control form-control-sm" id="dkProjectSearch" placeholder="Please enter the keyword"><i class="bi bi-search" style="right:1.7rem"></i></div>
        <div class="dk-projects" id="dkProjects"><div class="sm-empty"><i class="bi bi-arrow-repeat spin"></i> Loading</div></div>
        @if ($canWrite)
            <div class="dk-compose-side-foot">
                <input type="checkbox" class="form-check-input" id="dkProjectAll">
                <button class="btn btn-sm btn-outline-danger" id="dkProjectBulkDelete" disabled>Batch Delete</button>
            </div>
        @endif
    </aside>

    <section class="dk-compose-main" id="dkComposeMain">
        <div class="gbx-card"><div class="empty-state">
            <div class="icon"><i class="bi bi-stack"></i></div>
            <h3>Docker Compose</h3>
            <p>Select a project on the left, or add one from a compose file or template. Projects created here are stored in <code>{{ $projectsRoot }}/&lt;name&gt;</code>.</p>
        </div></div>
    </section>
</div>
