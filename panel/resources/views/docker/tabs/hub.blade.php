<div class="gbx-card">
    <div class="dk-store-head">
        <form class="dk-store-search" id="dkHubForm">
            <div class="db-search flex-grow-1"><input type="search" class="form-control" id="dkHubQuery" placeholder="Search images on Docker Hub, e.g. nginx, postgres, n8nio/n8n"><i class="bi bi-search"></i></div>
            <button class="btn btn-primary" type="submit">Search</button>
        </form>
        <div class="cell-sub">Results come from hub.docker.com. Images from private registries can be pulled in Local image &gt; Pull from repository.</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover db-table dk-table">
            <thead><tr><th>Image</th><th>Description</th><th class="text-end">Stars</th><th class="text-end">Pulls</th><th class="text-end">Operate</th></tr></thead>
            <tbody id="dkHubRows"><tr><td colspan="5" class="sm-empty"><i class="bi bi-arrow-repeat spin"></i> Loading</td></tr></tbody>
        </table>
    </div>
    <div class="dk-footer">
        <span class="cell-sub" id="dkHubTotal"></span>
        <div class="dk-pager">
            <button class="btn btn-sm btn-outline-secondary btn-icon" id="dkHubPrev" disabled><i class="bi bi-chevron-left"></i></button>
            <span class="dk-page-num" id="dkHubPage">1</span>
            <button class="btn btn-sm btn-outline-secondary btn-icon" id="dkHubNext" disabled><i class="bi bi-chevron-right"></i></button>
        </div>
    </div>
</div>
