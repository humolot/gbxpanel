<div class="gbx-card">
    @if (! $installed && $servers->isEmpty())
        <div class="empty-state">
            <div class="icon"><i class="bi bi-bounding-box-circles"></i></div>
            <h3>Qdrant is not installed</h3>
            <p>Qdrant is a vector database for semantic search, recommendations and RAG. Install it locally (with an API key) or connect Qdrant Cloud.</p>
            @if ($canWrite)
                <div class="d-flex gap-2 justify-content-center flex-wrap">
                    <button class="btn btn-primary" data-post="{{ route('home.software.install') }}" data-payload='{"key":"qdrant"}' data-confirm="Install Qdrant on this server?"><i class="bi bi-download"></i> Click install</button>
                    <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#serversModal"><i class="bi bi-hdd-network"></i> Add Remote DB</button>
                </div>
            @endif
        </div>
    @else
        <div class="db-toolbar">
            <select class="form-select w-auto" id="qdLocation">
                @if ($installed)<option value="">Localhost</option>@endif
                @foreach ($servers as $server)
                    <option value="{{ $server->id }}">{{ $server->label() }}</option>
                @endforeach
            </select>
            @if ($canWrite)
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#qdCreateModal"><i class="bi bi-plus-lg"></i> Create collection</button>
                @if ($installed)<button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#qdKeyModal" id="qdKeyBtn">API key &amp; access</button>@endif
                <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#serversModal">Remote DB{{ $servers->count() ? ' ('.$servers->count().')' : '' }}</button>
            @endif
            <div class="db-toolbar-right">
                <div class="db-search"><input type="search" class="form-control" id="qdSearch" placeholder="Collection search"><i class="bi bi-search"></i></div>
                <button class="btn btn-outline-secondary btn-icon" id="qdRefresh" title="Refresh"><i class="bi bi-arrow-clockwise"></i></button>
            </div>
        </div>

        <div class="p-3 d-none" id="qdError"><div class="alert alert-danger mb-0"><i class="bi bi-exclamation-octagon me-1"></i> <span id="qdErrorText"></span></div></div>

        <div class="db-stats">
            <div><span>Version</span><strong id="qdVersion">-</strong></div>
            <div><span>Collections</span><strong id="qdCount">-</strong></div>
            <div><span>Points</span><strong id="qdPoints">-</strong></div>
            <div><span>Access</span><strong id="qdAccess">{{ $installed ? ($public ? 'Public :6333' : '127.0.0.1:6333') : '-' }}</strong></div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover db-table mb-0">
                <thead><tr><th>Collection</th><th>Status</th><th class="text-end">Points</th><th>Vectors</th><th class="text-end">Segments</th><th>Storage</th><th class="text-end">Operate</th></tr></thead>
                <tbody id="qdCollections"><tr><td colspan="7" class="sm-empty"><i class="bi bi-arrow-repeat spin"></i> Loading</td></tr></tbody>
            </table>
        </div>
    @endif
</div>

@push('modals')
    <div class="modal fade" id="qdCreateModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" id="qdCreateForm" autocomplete="off">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-square"></i> Create collection</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12"><label class="form-label">Name</label><input type="text" name="name" class="form-control font-mono" required pattern="[A-Za-z0-9_\-]{1,120}" placeholder="documents"></div>
                        <div class="col-12">
                            <label class="form-label">Embedding model preset</label>
                            <select class="form-select" id="qdPreset">
                                <option value="">Custom</option>
                                <option value="1536|Cosine">OpenAI text-embedding-3-small (1536)</option>
                                <option value="3072|Cosine">OpenAI text-embedding-3-large (3072)</option>
                                <option value="1024|Cosine">BAAI bge-m3 / Voyage (1024)</option>
                                <option value="768|Cosine">nomic-embed-text / Gemini (768)</option>
                                <option value="384|Cosine">all-MiniLM-L6-v2 (384)</option>
                            </select>
                        </div>
                        <div class="col-6"><label class="form-label">Vector size</label><input type="number" name="size" class="form-control" min="1" max="65536" value="1536" required></div>
                        <div class="col-6">
                            <label class="form-label">Distance</label>
                            <select name="distance" class="form-select">@foreach ($distances ?? [] as $d)<option>{{ $d }}</option>@endforeach</select>
                        </div>
                        <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="on_disk" id="qdOnDisk"><label class="form-check-label" for="qdOnDisk">Store vectors on disk (less RAM, slower search)</label></div></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create</button>
                </div>
            </form>
        </div>
    </div>

    @if ($installed)
        <div class="modal fade" id="qdKeyModal" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-key"></i> API key and access</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label">API key</label>
                        <div class="input-group mb-2">
                            <input type="password" class="form-control font-mono" id="qdApiKey" value="{{ $apiKey }}" readonly placeholder="Not configured">
                            <button class="btn btn-secondary" type="button" data-toggle-password="#qdApiKey"><i class="bi bi-eye"></i></button>
                            <button class="btn btn-secondary" type="button" data-copy-target="#qdApiKey" data-copy=""><i class="bi bi-clipboard"></i></button>
                            @if ($canWrite)<button class="btn btn-outline-danger" type="button" id="qdRegenerate">Regenerate</button>@endif
                        </div>
                        <div class="form-text mb-3">Send it in the <code>api-key</code> header. Regenerating breaks applications that use the old key.</div>

                        <div class="sm-switch-row">
                            <div>
                                <div class="fw-semibold">Public access</div>
                                <div class="form-text mt-0">Listen on all interfaces and open ports 6333 (HTTP) and 6334 (gRPC) in the firewall. Keep it off when your applications run on this server.</div>
                            </div>
                            <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" id="qdPublic" @checked($public) @disabled(! $canWrite)></div>
                        </div>

                        <div class="small-caps mt-3 mb-2">Connect</div>
                        <pre class="gbx-console small mb-2" id="qdExample">curl http://127.0.0.1:6333/collections -H "api-key: $QDRANT_API_KEY"

# Python
from qdrant_client import QdrantClient
client = QdrantClient(url="http://127.0.0.1:6333", api_key="...")</pre>
                        <ul class="sm-hints">
                            <li>Web dashboard: <span class="font-mono">http://{{ request()->getHost() }}:6333/dashboard</span> when public access is on, or through an SSH tunnel (<span class="font-mono">ssh -L 6333:127.0.0.1:6333 root@{{ request()->getHost() }}</span>).</li>
                            <li>Data: /var/lib/qdrant/storage. Snapshots: /var/lib/qdrant/snapshots.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="modal fade" id="qdInfoModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-truncate"><i class="bi bi-info-circle"></i> <span id="qdInfoTitle"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body"><pre class="gbx-console mb-0" id="qdInfo"></pre></div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="qdPointsModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-truncate"><i class="bi bi-grid-3x3-gap"></i> Points [<span id="qdPointsTitle"></span>]</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive sm-table">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th style="width: 22%">ID</th><th>Payload</th></tr></thead>
                            <tbody id="qdPointsList"></tbody>
                        </table>
                    </div>
                    <div class="d-flex mt-2"><button class="btn btn-sm btn-outline-secondary ms-auto d-none" id="qdPointsMore">Next page</button></div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="qdSnapshotsModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-truncate"><i class="bi bi-camera"></i> Snapshots [<span id="qdSnapTitle"></span>]</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    @if ($canWrite)
                        <div class="d-flex gap-2 mb-3 flex-wrap">
                            <button class="btn btn-primary" id="qdSnapCreate"><i class="bi bi-camera"></i> Create snapshot</button>
                            <button class="btn btn-outline-secondary" id="qdSnapRestore"><i class="bi bi-upload"></i> Restore from file</button>
                        </div>
                    @endif
                    <div class="table-responsive sm-table">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Snapshot</th><th>Size</th><th>Created</th><th class="text-end">Operate</th></tr></thead>
                            <tbody id="qdSnapList"></tbody>
                        </table>
                    </div>
                    <ul class="sm-hints"><li>Snapshots contain the vectors, payload and indexes of the collection. Restoring replaces the collection data.</li></ul>
                </div>
            </div>
        </div>
    </div>
@endpush
