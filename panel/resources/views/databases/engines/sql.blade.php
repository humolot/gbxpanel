@php
    $key = $db->key();
    $adminTool = $key === 'mysql' ? 'phpmyadmin' : ($key === 'pgsql' ? 'adminer' : null);
    $adminToolLabel = $adminTool === 'phpmyadmin' ? 'phpMyAdmin' : 'Adminer';
    $hasTool = $adminTool && ($tools[$adminTool] ?? false);
    $localAvailable = $installed;
    $remoteOnly = $db->supports('remote_only');
    $showLocation = $servers->isNotEmpty();
    $sqlLocal = $key === 'sqlserver' ? $servers->first(fn ($s) => in_array($s->host, ['127.0.0.1', 'localhost'], true)) : null;
@endphp

@if ($db->supports('backup') && ! $remoteOnly && $installed)
    <div class="db-notice">
        <i class="bi bi-info-circle-fill"></i>
        <span class="fw-semibold">Auto Backup Database</span>
        <div class="form-check form-switch m-0">
            <input class="form-check-input" type="checkbox" id="dbAutoBackup" @checked($autoBackup['enabled']) @disabled(! $canWrite)>
        </div>
        <span class="cell-sub">{{ $autoBackup['enabled'] ? 'Every database is backed up daily at '.$autoBackup['time'].', keeping the last '.$autoBackup['keep'].' copies.' : 'Turn on automatic backup to protect your data after adding databases.' }}</span>
        @if ($canWrite)<a href="#" class="ms-auto small" data-bs-toggle="modal" data-bs-target="#autoBackupModal">Settings</a>@endif
    </div>
@endif

<div class="gbx-card">
    <div class="db-toolbar">
        @if ($canWrite)
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#dbAddModal" @disabled(! $installed && $servers->isEmpty())><i class="bi bi-plus-lg"></i> Add DB</button>
            @if ($db->supports('root') && $installed)
                <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#dbRootModal">Root password</button>
            @endif
        @endif
        @if ($adminTool && $installed)
            @if ($hasTool)
                <a href="{{ route('databases.tool', ['tool' => $adminTool, 'engine' => $key]) }}" target="_blank" rel="noopener" class="btn btn-outline-secondary">{{ $adminToolLabel }}</a>
                @if ($canWrite)<button class="btn btn-outline-secondary btn-icon" data-bs-toggle="modal" data-bs-target="#dbToolModal" title="{{ $adminToolLabel }} access"><i class="bi bi-gear"></i></button>@endif
            @elseif ($canWrite)
                <button class="btn btn-outline-secondary" data-post="{{ route('home.software.install') }}" data-payload='{{ json_encode(['key' => $adminTool]) }}' data-confirm="Install {{ $adminToolLabel }} on the panel port?"><i class="bi bi-download"></i> {{ $adminToolLabel }}</button>
            @endif
        @endif
        @if ($key === 'mongodb' && $installed && $canWrite)
            <button class="btn btn-outline-secondary" id="dbMongoAuth" data-enabled="{{ $mongoAuth ? 1 : 0 }}"><i class="bi {{ $mongoAuth ? 'bi-shield-check text-success' : 'bi-shield-exclamation text-warning' }}"></i> Security authentication</button>
        @endif
        @if ($canWrite)
            <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#serversModal">Remote DB{{ $servers->count() ? ' ('.$servers->count().')' : '' }}</button>
            @if ($installed || $servers->isNotEmpty())
            <span class="db-sep"></span>
            <button class="btn btn-outline-secondary" id="dbSyncUsers" title="Apply the stored passwords to every database user">Sync all</button>
            <button class="btn btn-outline-secondary" id="dbImport">Get DB from server</button>
            @if ($db->supports('backup') && ! $remoteOnly)
                <button class="btn btn-outline-secondary" id="dbRecycle"><i class="bi bi-trash3"></i> Recycle Bin{{ $recycleCount ? ' ('.$recycleCount.')' : '' }}</button>
            @endif
            @endif
        @endif
        <div class="db-toolbar-right">
            <select class="form-select" id="dbLocationFilter">
                <option value="">All</option>
                @if (! $remoteOnly)<option value="local">Localhost</option>@endif
                @foreach ($servers as $server)
                    <option value="{{ $server->id }}">{{ $server->label() }}</option>
                @endforeach
            </select>
            <div class="db-search"><input type="search" class="form-control" id="dbSearch" placeholder="Database search"><i class="bi bi-search"></i></div>
        </div>
    </div>

    <div id="dbUnmanaged"></div>

    @if (! $installed && $servers->isEmpty())
        <div class="empty-state">
            <div class="icon"><i class="bi {{ $tabs[$key][1] }}"></i></div>
            @if ($remoteOnly)
                <h3>No SQL Server configured</h3>
                <p>Run SQL Server 2022 Express locally in Docker, or connect a remote or cloud SQL Server.</p>
            @else
                <h3>{{ $db->label() }} is not installed</h3>
                <p>Install {{ $db->label() }} on this server or connect a remote server to manage its databases.</p>
            @endif
            @if ($canWrite)
                <div class="d-flex gap-2 justify-content-center flex-wrap">
                    @if ($package)
                        <button class="btn btn-primary" data-post="{{ route('home.software.install') }}" data-payload='{{ json_encode(['key' => $package]) }}' data-confirm="Install {{ $remoteOnly ? 'SQL Server (Docker)' : $db->label() }} on this server?"><i class="bi bi-download"></i> Click install</button>
                    @endif
                    @if ($key === 'mysql')
                        <button class="btn btn-secondary" data-post="{{ route('home.software.install') }}" data-payload='{"key":"mariadb"}' data-confirm="Install MariaDB Server?"><i class="bi bi-download"></i> Install MariaDB</button>
                    @endif
                    <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#serversModal"><i class="bi bi-hdd-network"></i> Add Remote DB</button>
                </div>
                @if ($remoteOnly && ! $sqlTools)
                    <p class="small mt-3 mb-0">Remote servers need the sqlcmd tools: <a href="#" data-post="{{ route('home.software.install') }}" data-payload='{"key":"mssql-tools"}' data-confirm="Install the SQL Server command-line tools?">install them</a>.</p>
                @endif
            @endif
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover db-table" id="dbTable">
                <thead>
                <tr>
                    <th class="ws-check"><input type="checkbox" class="form-check-input" id="dbAll"></th>
                    <th>Database name</th>
                    <th>Username</th>
                    <th>Password</th>
                    <th>Size</th>
                    <th>Backup</th>
                    @if ($showLocation)<th>Location</th>@endif
                    <th>Note</th>
                    <th class="text-end">Operate</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($records as $record)
                    @php
                        $canBackup = $db->canBackup($record->server);
                        $count = $record->server ? null : ($backupCounts[$record->name] ?? 0);
                    @endphp
                    <tr data-id="{{ $record->id }}" data-name="{{ $record->name }}" data-location="{{ $record->server_id ?: 'local' }}" data-user="{{ $record->username }}" data-hosts="{{ $record->host }}">
                        <td class="ws-check"><input type="checkbox" class="form-check-input db-check" value="{{ $record->id }}"></td>
                        <td data-search="{{ $record->name }} {{ $record->notes }} {{ $record->website?->domain }}">
                            <span class="cell-strong font-mono">{{ $record->name }}</span>
                            @if ($record->website)<div class="cell-sub"><i class="bi bi-globe2"></i> {{ $record->website->domain }}</div>@endif
                        </td>
                        <td class="font-mono small">{{ $record->username }}</td>
                        <td class="text-nowrap">
                            <span class="db-pass font-mono">{{ $record->password ? '**********' : 'not stored' }}</span>
                            @if ($record->password)
                                <button class="db-icon" data-pass-toggle title="Show"><i class="bi bi-eye"></i></button>
                                <button class="db-icon" data-pass-copy title="Copy"><i class="bi bi-clipboard"></i></button>
                            @endif
                        </td>
                        <td class="font-mono small db-size" data-order="0"><span class="cell-sub">...</span></td>
                        <td class="text-nowrap">
                            @if ($canBackup)
                                <a href="#" class="db-backups {{ ($count ?? 1) ? 'text-success' : 'text-warning' }}">{{ $count === null ? 'Backups' : ($count ? $count.' Backup' : 'Not exist') }}</a>
                                @if ($canWrite)<span class="cell-sub">|</span> <a href="#" class="db-import">Import</a>@endif
                            @else
                                <span class="cell-sub">-</span>
                            @endif
                        </td>
                        @if ($showLocation)<td>{{ $record->location() }}</td>@endif
                        <td><a href="#" class="ws-remark {{ $record->notes ? '' : 'empty' }}" data-notes="{{ $record->notes }}">{{ $record->notes ?: ($canWrite ? 'Add note' : '') }}</a></td>
                        <td class="text-end text-nowrap db-ops">
                            @if ($hasTool && ! $record->server)
                                <a href="{{ route('databases.tool', ['tool' => $adminTool, 'engine' => $key, 'db' => $record->name, 'user' => $record->username]) }}" target="_blank" rel="noopener">{{ $adminToolLabel }}</a>
                            @endif
                            @if ($canWrite && $db->supports('permission'))
                                <a href="#" class="db-permission">Permission</a>
                            @endif
                            @if ($db->supports('tools'))
                                <a href="#" class="db-tools">Tools</a>
                            @endif
                            @if ($canWrite)
                                <a href="#" class="db-password">Password</a>
                                <a href="#" class="db-delete text-danger" data-recycle="{{ $canBackup && ! $remoteOnly ? 1 : 0 }}">Delete</a>
                            @else
                                <a href="#" class="db-creds">Connection</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @if ($canWrite)
            <div class="ws-bulk">
                <span class="cell-sub" id="dbSelected">0 selected</span>
                <select class="form-select form-select-sm w-auto" id="dbBulkAction">
                    <option value="">Please choose</option>
                    <option value="backup">Backup</option>
                    <option value="delete">Delete (recycle bin)</option>
                </select>
                <button class="btn btn-sm btn-outline-secondary" id="dbBulkRun" disabled>Execute</button>
            </div>
        @endif
    @endif
</div>

@push('modals')
    {{-- add database --}}
    <div class="modal fade" id="dbAddModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <form class="modal-content" data-ajax data-reload action="{{ route('databases.store') }}" autocomplete="off">
                <input type="hidden" name="engine" value="{{ $key }}">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-database-add"></i> Add {{ $db->label() }} database</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Database name</label>
                            <input type="text" name="name" id="dbName" class="form-control font-mono" required maxlength="63" pattern="{{ $key === 'pgsql' ? '[a-z_][a-z0-9_]{0,62}' : '[a-zA-Z0-9_]{1,63}' }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" id="dbUser" class="form-control font-mono" required maxlength="63">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Password</label>
                            <div class="input-group">
                                <input type="text" name="password" id="dbPassword" class="form-control font-mono" required minlength="8">
                                <button type="button" class="btn btn-secondary" data-generate="#dbPassword" title="Generate"><i class="bi bi-magic"></i></button>
                                <button type="button" class="btn btn-secondary" data-copy-target="#dbPassword" data-copy="" title="Copy"><i class="bi bi-clipboard"></i></button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Location</label>
                            <select name="server_id" class="form-select">
                                @if (! $remoteOnly && $installed)<option value="">Localhost</option>@endif
                                @foreach ($servers as $server)
                                    <option value="{{ $server->id }}">{{ $server->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        @if ($db->supports('permission'))
                            <div class="col-md-4">
                                <label class="form-label">Access</label>
                                <select class="form-select" id="dbAccess">
                                    <option value="localhost">Local server</option>
                                    <option value="%">Everyone (%)</option>
                                    <option value="ips">Specified IPs</option>
                                </select>
                                <input type="hidden" name="hosts" id="dbHosts" value="localhost">
                            </div>
                            <div class="col-md-4 d-none" id="dbIpsCol">
                                <label class="form-label">Allowed IPs</label>
                                <input type="text" class="form-control font-mono" id="dbIps" placeholder="203.0.113.10, 10.0.0.%">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Charset</label>
                                <select name="charset" class="form-select">
                                    <option value="utf8mb4">utf8mb4</option>
                                    <option value="utf8">utf8</option>
                                    <option value="latin1">latin1</option>
                                </select>
                            </div>
                        @endif
                        <div class="col-md-6">
                            <label class="form-label">Website</label>
                            <select name="website_id" class="form-select">
                                <option value="">None</option>
                                @foreach ($websites as $w)
                                    <option value="{{ $w->id }}">{{ $w->domain }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Note</label>
                            <input type="text" name="notes" class="form-control" maxlength="255">
                        </div>
                    </div>
                    @if ($key === 'pgsql')
                        <div class="form-text mt-2">PostgreSQL names are lowercase. The user becomes the owner of the database.</div>
                    @elseif ($key === 'mongodb')
                        <div class="form-text mt-2">A user with readWrite and dbAdmin roles is created on the database. The database appears in listings after the first write.</div>
                    @elseif ($key === 'sqlserver')
                        <div class="form-text mt-2">A SQL login is created and added to the db_owner role of the new database.</div>
                    @endif
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Confirm</button>
                </div>
            </form>
        </div>
    </div>

    {{-- root password --}}
    <div class="modal fade" id="dbRootModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" data-ajax data-reload action="{{ route('databases.root') }}" autocomplete="off">
                <input type="hidden" name="engine" value="{{ $key }}">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-shield-lock"></i> {{ $db->label() }} root password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    @if ($rootPassword)
                        <label class="form-label">Current password</label>
                        <div class="input-group mb-3">
                            <input type="password" class="form-control font-mono" id="dbRootCurrent" value="{{ $rootPassword }}" readonly>
                            <button type="button" class="btn btn-secondary" data-toggle-password="#dbRootCurrent"><i class="bi bi-eye"></i></button>
                            <button type="button" class="btn btn-secondary" data-copy-target="#dbRootCurrent" data-copy=""><i class="bi bi-clipboard"></i></button>
                        </div>
                    @endif
                    <label class="form-label">New password</label>
                    <div class="input-group">
                        <input type="text" name="password" id="dbRootPassword" class="form-control font-mono" required minlength="10">
                        <button type="button" class="btn btn-secondary" data-generate="#dbRootPassword"><i class="bi bi-magic"></i></button>
                    </div>
                    <div class="form-text">
                        @if ($key === 'mysql')
                            User root@localhost. The panel keeps working through /root/.my.cnf; MariaDB also keeps socket login for root.
                        @elseif ($key === 'pgsql')
                            Role postgres, for password logins (local peer authentication keeps working).
                        @else
                            User root in the admin database, used when security authentication is enabled.
                        @endif
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Confirm</button>
                </div>
            </form>
        </div>
    </div>

    {{-- automatic backup --}}
    <div class="modal fade" id="autoBackupModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" data-ajax data-reload action="{{ route('databases.autobackup') }}">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-clock-history"></i> Automatic database backup</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="enabled" id="abEnabled" @checked($autoBackup['enabled'])>
                        <label class="form-check-label" for="abEnabled">Back up every database daily</label>
                    </div>
                    <div class="row g-3">
                        <div class="col-6"><label class="form-label">Time</label><input type="time" name="time" class="form-control" value="{{ $autoBackup['time'] }}"></div>
                        <div class="col-6"><label class="form-label">Keep copies</label><input type="number" name="keep" class="form-control" min="1" max="90" value="{{ $autoBackup['keep'] }}"></div>
                    </div>
                    <div class="form-text mt-2">Applies to MySQL, PostgreSQL, MongoDB and the local SQL Server container. Older copies of each database are deleted. Files are stored in {{ $db->backupRoot() }}.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    {{-- phpMyAdmin / Adminer access --}}
    @if ($adminTool)
        <div class="modal fade" id="dbToolModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ $adminToolLabel }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="dbToolPublic" @checked($tools['public'])>
                            <label class="form-check-label" for="dbToolPublic">Enable public access</label>
                        </div>
                        <ul class="sm-hints text-danger mb-3"><li>Turning off public access improves security.</li></ul>
                        <hr class="sm-hr my-3">
                        <div class="d-flex gap-2 flex-wrap">
                            <a href="{{ route('databases.tool', ['tool' => $adminTool, 'engine' => $key]) }}" target="_blank" rel="noopener" class="btn btn-secondary">Access through the panel</a>
                            @if ($tools['public'])<a href="{{ url('/'.$adminTool.'/') }}" target="_blank" rel="noopener" class="btn btn-outline-secondary">Public access</a>@endif
                        </div>
                        <ul class="sm-hints">
                            <li>Without public access, {{ $adminToolLabel }} only opens for browsers that came from this panel (a signed cookie valid for 12 hours); everyone else receives 403.</li>
                            <li>Log in with the database user and password from the list (Password column).</li>
                            <li>The setting applies to both phpMyAdmin and Adminer.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- backups of one database --}}
    <div class="modal fade" id="dbBackupsModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-truncate"><i class="bi bi-archive"></i> Backups [<span id="dbBackupsName"></span>]</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    @if ($canWrite)
                        <div class="d-flex gap-2 mb-3 flex-wrap">
                            <button class="btn btn-primary" id="dbBackupNow"><i class="bi bi-cloud-arrow-up"></i> Backup now</button>
                            <button class="btn btn-outline-secondary" id="dbImportFromBackups"><i class="bi bi-upload"></i> Import file</button>
                        </div>
                    @endif
                    <div class="table-responsive sm-table">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>File</th><th>Size</th><th>Created</th><th class="text-end">Operate</th></tr></thead>
                            <tbody id="dbBackupsList"></tbody>
                        </table>
                    </div>
                    <ul class="sm-hints"><li>Restoring replaces the current data of the database with the backup.</li></ul>
                </div>
            </div>
        </div>
    </div>

    {{-- recycle bin --}}
    <div class="modal fade" id="dbRecycleModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-trash3"></i> Recycle Bin</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive sm-table">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Database</th><th>User</th><th>Size</th><th>Deleted</th><th>Kept until</th><th class="text-end">Operate</th></tr></thead>
                            <tbody id="dbRecycleList"></tbody>
                        </table>
                    </div>
                    <ul class="sm-hints"><li>Deleted local databases are dumped before they are dropped and kept for {{ \App\Models\DatabaseRecycle::KEEP_DAYS }} days. Restoring recreates the database and its user with the stored password.</li></ul>
                </div>
            </div>
        </div>
    </div>

    {{-- permission --}}
    <div class="modal fade" id="dbPermissionModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" id="dbPermissionForm">
                <div class="modal-header">
                    <h5 class="modal-title text-truncate"><i class="bi bi-person-lock"></i> Access permission [<span id="dbPermissionUser"></span>]</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="form-check mb-2"><input class="form-check-input" type="radio" name="access" value="localhost" id="permLocal"><label class="form-check-label" for="permLocal">Local server (localhost, 127.0.0.1)</label></div>
                    <div class="form-check mb-2"><input class="form-check-input" type="radio" name="access" value="all" id="permAll"><label class="form-check-label" for="permAll">Everyone (%)</label></div>
                    <div class="form-check mb-2"><input class="form-check-input" type="radio" name="access" value="ips" id="permIps"><label class="form-check-label" for="permIps">Specified IPs</label></div>
                    <textarea class="form-control font-mono" name="ips" id="permIpList" rows="3" placeholder="One IP per line, % wildcard allowed (10.0.0.%)"></textarea>
                    <div class="form-text">Remote access also needs port 3306 open in Security &gt; Firewall and MySQL listening on a public address (bind-address).</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Confirm</button>
                </div>
            </form>
        </div>
    </div>

    {{-- tools --}}
    <div class="modal fade" id="dbToolsModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-truncate"><i class="bi bi-tools"></i> Tools [<span id="dbToolsName"></span>]</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    @if ($canWrite)
                        <div class="d-flex gap-2 mb-3 flex-wrap" id="dbToolsActions">
                            @if ($key === 'mysql')
                                <button class="btn btn-sm btn-outline-secondary" data-table-action="optimize">Optimize</button>
                                <button class="btn btn-sm btn-outline-secondary" data-table-action="repair">Repair</button>
                                <button class="btn btn-sm btn-outline-secondary" data-table-action="analyze">Analyze</button>
                                <button class="btn btn-sm btn-outline-secondary" data-table-action="innodb">Convert to InnoDB</button>
                                <button class="btn btn-sm btn-outline-secondary" data-table-action="myisam">Convert to MyISAM</button>
                                <span class="cell-sub align-self-center">Applies to the selected tables (all when none is selected).</span>
                            @elseif ($key === 'pgsql')
                                <button class="btn btn-sm btn-outline-secondary" data-table-action="vacuum">VACUUM ANALYZE</button>
                                <button class="btn btn-sm btn-outline-secondary" data-table-action="analyze">ANALYZE</button>
                            @endif
                        </div>
                    @endif
                    <div class="table-responsive sm-table">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th class="ws-check"><input type="checkbox" class="form-check-input" id="dbTablesAll"></th><th>{{ $key === 'mongodb' ? 'Collection' : 'Table' }}</th><th>{{ $key === 'mysql' ? 'Engine' : 'Type' }}</th><th class="text-end">{{ $key === 'mongodb' ? 'Documents' : 'Rows' }}</th><th class="text-end">Size</th><th>{{ $key === 'mysql' ? 'Collation' : '' }}</th><th>{{ $key === 'pgsql' ? 'Last vacuum' : 'Updated' }}</th></tr></thead>
                            <tbody id="dbTablesList"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endpush
