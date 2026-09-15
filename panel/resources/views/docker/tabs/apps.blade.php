<div class="gbx-card">
    <div class="dk-store-head">
        <form class="dk-store-search" id="dkAppSearchForm">
            <div class="db-search flex-grow-1"><input type="search" class="form-control" id="dkAppSearch" placeholder="Search apps by name or description"><i class="bi bi-search"></i></div>
            <button class="btn btn-primary" type="submit">Search</button>
        </form>
        <div class="dk-chips" id="dkAppCats">
            <button class="active" data-cat="">All</button>
            <button data-cat="installed">Installed</button>
            @foreach ($categories as $key => $label)
                <button data-cat="{{ $key }}">{{ $label }}</button>
            @endforeach
        </div>
    </div>
    <div class="dk-apps" id="dkApps">
        <div class="sm-empty w-100"><i class="bi bi-arrow-repeat spin"></i> Loading</div>
    </div>
</div>
