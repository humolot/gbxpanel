<div class="db-notice cron-notice">
    <i class="bi bi-info-circle-fill"></i>
    <span>Public images from Docker Hub need no repository. Add private registries (Docker Hub accounts, GitHub Container Registry, GitLab, Harbor, AWS/Azure/Google registries or your own) to pull and push images. Passwords and tokens are stored encrypted and passed to <code>docker login</code> through stdin.</span>
</div>
<div class="gbx-card">
    <div class="db-toolbar">
        @if ($canWrite)
            <button class="btn btn-primary" data-dk-registry-add><i class="bi bi-plus-lg"></i> Add repository</button>
        @endif
    </div>
    <div class="table-responsive">
        <table class="table table-hover db-table dk-table">
            <thead><tr><th>Name</th><th>Address</th><th>Username</th><th>Namespace</th><th>Remark</th><th>Creation time</th><th class="text-end">Operate</th></tr></thead>
            <tbody id="dkRows"><tr><td colspan="7" class="sm-empty"><i class="bi bi-arrow-repeat spin"></i> Loading</td></tr></tbody>
        </table>
    </div>
</div>
