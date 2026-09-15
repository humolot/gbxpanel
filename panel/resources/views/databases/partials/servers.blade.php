@php
    $serverDefaults = [
        'mysql' => ['port' => 3306, 'user' => 'root', 'userLabel' => 'Username', 'passLabel' => 'Password'],
        'pgsql' => ['port' => 5432, 'user' => 'postgres', 'userLabel' => 'Username', 'passLabel' => 'Password'],
        'mongodb' => ['port' => 27017, 'user' => 'root', 'userLabel' => 'Username', 'passLabel' => 'Password'],
        'sqlserver' => ['port' => 1433, 'user' => 'sa', 'userLabel' => 'Username', 'passLabel' => 'Password'],
        'redis' => ['port' => 6379, 'user' => '', 'userLabel' => 'ACL username (optional)', 'passLabel' => 'Password'],
        'qdrant' => ['port' => 6333, 'user' => '', 'userLabel' => null, 'passLabel' => 'API key'],
    ][$engine];
@endphp

@push('modals')
    <div class="modal fade" id="serversModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-hdd-network"></i> Remote {{ $tabs[$engine][0] }} servers</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive sm-table mb-3">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Name</th><th>Address</th><th>User</th><th>Notes</th><th class="text-end">Operate</th></tr></thead>
                            <tbody>
                            @forelse ($servers as $server)
                                <tr>
                                    <td>{{ $server->name }}</td>
                                    <td class="font-mono small">{{ $server->host }}{{ $engine === 'qdrant' && preg_match('#:\d+$#', $server->host) ? '' : ':'.$server->port }}</td>
                                    <td class="font-mono small">{{ $server->username ?: '-' }}</td>
                                    <td class="cell-sub">{{ $server->notes }}</td>
                                    <td class="text-end">
                                        @if (auth()->user()->canWrite())
                                            <button class="btn btn-sm btn-ghost text-danger" data-post="{{ route('databases.servers.destroy', $server) }}" data-method="DELETE" data-confirm="Remove {{ $server->name }} from the panel? Databases on the server are not dropped." data-danger data-reload>Remove</button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="sm-empty"><i class="bi bi-inbox"></i> No remote servers</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if (auth()->user()->canWrite())
                        <form data-ajax data-reload action="{{ route('databases.servers.store') }}" class="row g-3 sm-inline-form" autocomplete="off">
                            <input type="hidden" name="engine" value="{{ $engine }}">
                            <div class="col-12 small-caps">Add remote server</div>
                            <div class="col-md-8">
                                <label class="form-label">{{ $engine === 'qdrant' ? 'URL or address' : 'Server address' }}</label>
                                <input type="text" name="host" class="form-control font-mono" placeholder="{{ $engine === 'qdrant' ? 'https://xyz.cloud.qdrant.io or 10.0.0.5' : '10.0.0.5 or db.example.com' }}" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Port</label>
                                <input type="number" name="port" class="form-control" value="{{ $serverDefaults['port'] }}" min="1" max="65535" required>
                            </div>
                            @if ($serverDefaults['userLabel'])
                                <div class="col-md-6">
                                    <label class="form-label">{{ $serverDefaults['userLabel'] }}</label>
                                    <input type="text" name="username" class="form-control font-mono" value="{{ $serverDefaults['user'] }}">
                                </div>
                            @endif
                            <div class="col-md-{{ $serverDefaults['userLabel'] ? 6 : 12 }}">
                                <label class="form-label">{{ $serverDefaults['passLabel'] }}</label>
                                <input type="password" name="password" class="form-control font-mono" autocomplete="new-password">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Name</label>
                                <input type="text" name="name" class="form-control" maxlength="60" placeholder="Production DB">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Notes</label>
                                <input type="text" name="notes" class="form-control" maxlength="255">
                            </div>
                            <div class="col-12 d-flex justify-content-end"><button class="btn btn-primary" type="submit" data-busy="Testing connection"><i class="bi bi-plug"></i> Test and add</button></div>
                        </form>
                        <ul class="sm-hints">
                            <li>Cloud databases are supported (AWS RDS, Azure, Google Cloud SQL, MongoDB Atlas, Redis Cloud, Qdrant Cloud).</li>
                            <li>Make sure the remote server allows connections from this server's IP address.</li>
                            <li>Use an administrator account with permission to create databases and users. Credentials are stored encrypted.</li>
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endpush
