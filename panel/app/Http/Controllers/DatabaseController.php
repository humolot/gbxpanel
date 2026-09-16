<?php

namespace App\Http\Controllers;

use App\Models\DatabaseRecycle;
use App\Models\DbServer;
use App\Models\MysqlDatabase;
use App\Models\Setting;
use App\Models\Website;
use App\Services\Api\WebhookManager;
use App\Services\Databases\DatabaseEngine;
use App\Services\Databases\Engines;
use App\Services\Databases\MongoEngine;
use App\Services\Databases\PostgresEngine;
use App\Services\Databases\QdrantManager;
use App\Services\Databases\RedisManager;
use App\Services\Databases\SqlServerEngine;
use App\Services\FileManager;
use App\Services\MysqlManager;
use App\Services\ServiceManager;
use App\Services\Shell;
use App\Services\SoftwareManager;
use App\Services\TaskRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Databases page: MySQL/MariaDB, PostgreSQL, MongoDB and SQL Server databases (local or on
 * remote servers), plus the Redis and Qdrant tabs rendered from their own controllers.
 */
class DatabaseController extends Controller
{
    /* ================================================================ page */

    public function index(Request $request, ServiceManager $services, SoftwareManager $software)
    {
        $engine = array_key_exists($request->query('engine'), Engines::TABS) ? $request->query('engine') : 'mysql';
        $servers = DbServer::query()->where('engine', $engine)->orderBy('name')->get();
        $data = ['engine' => $engine, 'servers' => $servers, 'tabs' => Engines::TABS];

        if ($engine === 'redis') {
            $redis = app(RedisManager::class);

            return view('databases.index', $data + ['installed' => $redis->installed(), 'package' => 'redis', 'service' => $redis->installed() ? $services->status('redis-server') : null, 'backups' => $redis->backups()]);
        }

        if ($engine === 'qdrant') {
            $qdrant = app(QdrantManager::class);

            return view('databases.index', $data + ['installed' => $qdrant->installed(), 'package' => 'qdrant', 'service' => $qdrant->installed() ? $services->status('qdrant') : null,
                'apiKey' => $qdrant->apiKey(), 'public' => $qdrant->isPublic(), 'distances' => QdrantManager::DISTANCES]);
        }

        $db = Engines::get($engine);
        $installed = $db->installed();
        $records = MysqlDatabase::query()->engine($engine)->with(['website:id,domain', 'server'])->orderBy('name')->get();
        $backupCounts = [];
        if ($db->supports('backup')) {
            foreach ($db->backups() as $b) {
                $name = preg_replace('/_\d{8}_\d{6}\..+$/', '', $b['name']);
                $backupCounts[$name] = ($backupCounts[$name] ?? 0) + 1;
            }
        }

        return view('databases.index', $data + [
            'db' => $db,
            'installed' => $installed,
            'package' => $db->package(),
            'service' => $installed && $db->service() ? $services->status($db->service()) : null,
            'version' => $installed ? $db->version() : null,
            'records' => $records,
            'backupCounts' => $backupCounts,
            'websites' => Website::query()->orderBy('domain')->get(['id', 'domain']),
            'autoBackup' => $this->autoBackupSettings(),
            'tools' => [
                'phpmyadmin' => Shell::simulating() || is_file(rtrim(config('gbx.root'), '/').'/phpmyadmin/index.php'),
                'adminer' => Shell::simulating() || is_file(rtrim(config('gbx.root'), '/').'/adminer/index.php'),
                'public' => (bool) config('gbx.tools.public'),
            ],
            'rootPassword' => Setting::secret($engine.'_root_password'),
            'mongoAuth' => $engine === 'mongodb' && $installed ? app(MongoEngine::class)->authEnabled() : false,
            'sqlTools' => $engine === 'sqlserver' ? app(SqlServerEngine::class)->hostToolsInstalled() : true,
            'recycleCount' => DatabaseRecycle::query()->where('engine', $engine)->count(),
        ]);
    }

    protected function engine(Request $request): DatabaseEngine
    {
        $key = (string) $request->input('engine', 'mysql');
        if (! Engines::isDatabaseEngine($key)) {
            throw ValidationException::withMessages(['engine' => 'Unknown database engine.']);
        }

        return Engines::get($key);
    }

    protected function server(Request $request, string $engine): ?DbServer
    {
        $id = $request->input('server_id');

        return $id ? DbServer::query()->where('engine', $engine)->findOrFail($id) : null;
    }

    protected function assertLocalInstalled(DatabaseEngine $db, ?DbServer $server): void
    {
        if (! $server && ! $db->installed()) {
            throw new \RuntimeException($db->label().' is not installed on this server. Install it in Home > Software or choose a remote server.');
        }
    }

    /** Sizes, connection state and unmanaged databases for every location of an engine. */
    public function live(Request $request)
    {
        $db = $this->engine($request);
        $locations = [];
        $servers = DbServer::query()->where('engine', $db->key())->get();
        $targets = $db->installed() ? [null] : [];
        foreach ($servers as $server) {
            $targets[] = $server;
        }

        foreach ($targets as $server) {
            $version = $db->version($server);
            $live = $version ? $db->databases($server) : [];
            $known = MysqlDatabase::query()->engine($db->key())->where('server_id', $server?->id)->pluck('name')->all();
            $locations[] = [
                'id' => $server?->id,
                'label' => $server ? $server->label() : 'Localhost',
                'connected' => (bool) $version,
                'version' => $version,
                'databases' => $live,
                'unmanaged' => array_values(array_diff(array_keys($live), $known)),
            ];
        }

        return $this->ok('ok', ['locations' => $locations]);
    }

    /* ============================================================ databases */

    public function store(Request $request)
    {
        $db = $this->engine($request);
        $server = $this->server($request, $db->key());
        $data = $request->validate([
            'name' => ['required', 'string', 'max:63'],
            'username' => ['required', 'string', 'max:63'],
            'password' => ['required', 'string', Password::min(8)],
            'hosts' => ['nullable', 'string', 'max:500'],
            'charset' => ['nullable', 'in:utf8mb4,utf8,latin1'],
            'website_id' => ['nullable', 'exists:websites,id'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        if (! $db::validIdentifier($data['name']) || ! $db::validIdentifier($data['username'])) {
            throw ValidationException::withMessages(['name' => $db->key() === 'pgsql' ? 'Use lowercase letters, numbers and underscore, starting with a letter.' : 'Use letters, numbers and underscore only.']);
        }
        if (MysqlDatabase::query()->engine($db->key())->where('server_id', $server?->id)->where('name', $data['name'])->exists()) {
            throw ValidationException::withMessages(['name' => 'A database with this name already exists here.']);
        }
        if ($db->supports('remote_only') && ! $server) {
            throw ValidationException::withMessages(['server_id' => 'Choose a SQL Server.']);
        }
        $this->assertLocalInstalled($db, $server);

        $hosts = $db->supports('permission') ? $this->parseHosts($data['hosts'] ?? 'localhost') : [];
        $result = $db->create($data['name'], $data['username'], $data['password'], ['charset' => $data['charset'] ?? 'utf8mb4', 'hosts' => $hosts], $server);
        if ($result->failed()) {
            return $this->fail($result->message());
        }

        $record = MysqlDatabase::query()->create([
            'engine' => $db->key(), 'server_id' => $server?->id, 'name' => $data['name'], 'username' => $data['username'], 'password' => $data['password'],
            'host' => $hosts ? implode(',', $hosts) : 'localhost', 'charset' => $data['charset'] ?? 'utf8mb4', 'website_id' => $data['website_id'] ?? null, 'notes' => $data['notes'] ?? null,
        ]);
        $this->audit('database', "Created {$db->label()} database {$data['name']}", 'user '.$data['username'].($server ? ' on '.$server->label() : ''));

        WebhookManager::event('database.created', ['id' => $record->id, 'engine' => $record->engine, 'name' => $record->name, 'username' => $record->username, 'website_id' => $record->website_id]);

        return $this->ok('Database created');
    }

    protected function parseHosts(string $value): array
    {
        $hosts = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,]+/', $value)))));
        foreach ($hosts as $host) {
            if (! in_array($host, ['localhost', '%', '127.0.0.1'], true) && ! filter_var($host, FILTER_VALIDATE_IP) && ! preg_match('/^[0-9.]+%$|^[a-z0-9.\-]+$/i', $host)) {
                throw ValidationException::withMessages(['hosts' => "Invalid host: {$host}"]);
            }
        }

        return $hosts ?: ['localhost'];
    }

    /** Import databases that exist on a server but are not tracked (Get DB from server). */
    public function sync(Request $request)
    {
        $db = $this->engine($request);
        $server = $this->server($request, $db->key());
        $this->assertLocalInstalled($db, $server);
        $count = 0;
        foreach (array_keys($db->databases($server)) as $name) {
            if (MysqlDatabase::query()->engine($db->key())->where('server_id', $server?->id)->where('name', $name)->exists()) {
                continue;
            }
            MysqlDatabase::query()->create(['engine' => $db->key(), 'server_id' => $server?->id, 'name' => $name, 'username' => $name, 'password' => null, 'notes' => 'Imported from server']);
            $count++;
        }
        $this->audit('database', "Imported {$count} {$db->label()} database(s)", $server?->label() ?? 'Localhost');

        return $this->ok("{$count} database(s) imported");
    }

    /** Sync all: apply the stored password (and MySQL hosts) of every database user to its server. */
    public function syncUsers(Request $request)
    {
        $db = $this->engine($request);
        $done = 0;
        $errors = [];
        foreach (MysqlDatabase::query()->engine($db->key())->with('server')->get() as $record) {
            if (! $record->password || (! $record->server && ! $db->installed())) {
                continue;
            }
            $result = $db->supports('permission')
                ? app(MysqlManager::class)->setHosts($record->name, $record->username, $record->password, $record->hosts(), $record->server)
                : $db->changePassword($record->name, $record->username, $record->password, $record->server);
            $result->ok() ? $done++ : $errors[] = $record->name.': '.$result->message();
        }
        $this->audit('database', "Synced {$done} {$db->label()} user(s)");

        return $errors ? $this->fail(implode("\n", array_slice($errors, 0, 5)), 422, ['done' => $done]) : $this->ok("{$done} user(s) synced with the stored passwords");
    }

    public function credentials(MysqlDatabase $database)
    {
        $this->audit('database', "Viewed credentials of {$database->name}");
        $host = $database->server ? $database->server->host : '127.0.0.1';
        $port = $database->server ? $database->server->port : Engines::get($database->engine)->defaultPort();

        return $this->ok('ok', ['name' => $database->name, 'username' => $database->username, 'password' => $database->password, 'host' => $host, 'port' => $port, 'engine' => $database->engine]);
    }

    public function password(Request $request, MysqlDatabase $database)
    {
        $data = $request->validate(['password' => ['required', 'string', Password::min(8)]]);
        $db = Engines::get($database->engine);
        $result = $db->changePassword($database->name, $database->username, $data['password'], $database->server, ['hosts' => $database->hosts()]);
        if ($result->failed()) {
            return $this->fail($result->message());
        }
        $database->update(['password' => $data['password']]);
        $this->audit('database', "Changed password of {$database->username}", $database->name);

        return $this->ok('Password changed');
    }

    /** MySQL: hosts the user may connect from. */
    public function permission(Request $request, MysqlDatabase $database, MysqlManager $mysql)
    {
        abort_unless($database->engine === 'mysql', 404);
        $data = $request->validate(['access' => ['required', 'in:localhost,all,ips'], 'ips' => ['nullable', 'string', 'max:1000']]);
        if (! $database->password) {
            return $this->fail('The password of this user is not stored in the panel. Set a new password first.');
        }
        $hosts = match ($data['access']) {
            'localhost' => ['localhost', '127.0.0.1'],
            'all' => ['%'],
            'ips' => $this->parseHosts((string) ($data['ips'] ?? '')),
        };
        $result = $mysql->setHosts($database->name, $database->username, $database->password, $hosts, $database->server);
        if ($result->failed()) {
            return $this->fail($result->message());
        }
        $database->update(['host' => implode(',', $hosts)]);
        $this->audit('database', "Changed access of {$database->username}", implode(', ', $hosts));

        return $this->ok('Access updated: '.implode(', ', $hosts).(in_array('%', $hosts, true) || $data['access'] === 'ips' ? '. Open port 3306 in Security > Firewall for remote clients.' : ''));
    }

    public function tables(MysqlDatabase $database)
    {
        $db = Engines::get($database->engine);
        abort_unless($db->supports('tools'), 404);

        return $this->ok('ok', ['tables' => $db->tables($database->name, $database->server), 'engine' => $database->engine]);
    }

    public function tablesAction(Request $request, MysqlDatabase $database)
    {
        $data = $request->validate(['action' => ['required', 'in:optimize,repair,analyze,innodb,myisam,vacuum'], 'tables' => ['nullable', 'array'], 'tables.*' => ['string']]);
        $result = match ($database->engine) {
            'mysql' => app(MysqlManager::class)->tableAction($database->name, $data['action'], $data['tables'] ?? [], $database->server),
            'pgsql' => app(PostgresEngine::class)->maintenance($database->name, $data['action'] === 'analyze' ? 'analyze' : 'vacuum', $database->server),
            default => abort(404),
        };
        $this->audit('database', ucfirst($data['action']).' '.$database->name, implode(', ', $data['tables'] ?? []));

        return $this->result($result, ucfirst($data['action']).' completed', 'database', $database->name);
    }

    public function meta(Request $request, MysqlDatabase $database)
    {
        $data = $request->validate(['notes' => ['sometimes', 'nullable', 'string', 'max:255'], 'website_id' => ['sometimes', 'nullable', 'exists:websites,id']]);
        $database->update($data);

        return $this->ok('Saved');
    }

    /* =============================================================== backups */

    protected function backupTask(MysqlDatabase $database): \App\Models\Task
    {
        $db = Engines::get($database->engine);
        if (! $db->canBackup($database->server)) {
            throw new \RuntimeException('Backups of databases on this server are not available from the panel.');
        }
        if ($db instanceof SqlServerEngine) {
            $file = $database->name.'_'.date('Ymd_His').'.bak';
            $script = "set -e\n".$db->queryScript($db->backupSql($database->name, $file), $database->server)."\nls -lh ".Shell::arg($db->backupDir().'/'.$file);

            return TaskRunner::dispatch("Backup SQL Server database {$database->name}", $script, 'database');
        }

        return TaskRunner::dispatch("Backup {$db->label()} database {$database->name}", $db->backupScript($database->name, $database->server), 'database');
    }

    public function backup(MysqlDatabase $database)
    {
        return $this->task($this->backupTask($database), 'Backup started');
    }

    public function backups(MysqlDatabase $database)
    {
        $db = Engines::get($database->engine);

        return $this->ok('ok', ['backups' => $db->backups($database->name), 'can_backup' => $db->canBackup($database->server), 'extensions' => $db->importExtensions()]);
    }

    /** Load a stored backup into the database. */
    public function restore(Request $request, MysqlDatabase $database)
    {
        $db = Engines::get($database->engine);
        $path = $db->backupPath((string) $request->input('file'));
        if ($db instanceof SqlServerEngine) {
            $script = $db->queryScript($db->restoreSql($database->name, basename($path)), $database->server);
        } else {
            $script = $db->importScript($database->name, $path, $database->server);
        }
        $this->audit('database', "Restore {$database->name}", basename($path));

        return $this->task(TaskRunner::dispatch("Restore {$database->name} from ".basename($path), $script, 'database'), 'Restore started');
    }

    public function import(Request $request, MysqlDatabase $database)
    {
        $request->validate(['file' => ['required', 'file', 'max:2048000']]);
        $db = Engines::get($database->engine);
        $file = $request->file('file');
        $original = strtolower($file->getClientOriginalName());
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, $db->importExtensions(), true)) {
            return $this->fail('Upload a '.implode(', ', array_map(fn ($e) => '.'.$e, $db->importExtensions())).' file.');
        }
        if ($db instanceof SqlServerEngine) {
            if (! $db->canBackup($database->server)) {
                return $this->fail('Importing .bak files is only available for the local SQL Server container.');
            }
            $name = $database->name.'_'.date('Ymd_His').'.bak';
            $file->move(storage_path('app/imports'), $name);
            $target = $db->backupDir().'/'.$name;
            Shell::run('mkdir -p '.Shell::arg($db->backupDir()).' && mv '.Shell::arg(storage_path('app/imports/'.$name)).' '.Shell::arg($target).' && chown 10001:0 '.Shell::arg($target));
            $script = $db->queryScript($db->restoreSql($database->name, $name), $database->server);
        } else {
            $dir = storage_path('app/imports');
            @mkdir($dir, 0775, true);
            // keep the double extension (.sql.gz, .archive.gz) so the import script picks the right loader
            $suffix = preg_match('/(\.(sql|archive)\.gz|\.[a-z0-9]+)$/', $original, $m) ? $m[1] : '.'.$ext;
            $name = Str::random(16).$suffix;
            $file->move($dir, $name);
            $script = $db->importScript($database->name, $dir.'/'.$name, $database->server)."\nrm -f ".Shell::arg($dir.'/'.$name);
        }
        $this->audit('database', "Import into {$database->name}", $original);

        return $this->task(TaskRunner::dispatch("Import into {$database->name}", $script, 'database'), 'Import started');
    }

    public function downloadBackup(Request $request, string $engine, string $file, FileManager $files)
    {
        abort_unless(Engines::isDatabaseEngine($engine), 404);
        $path = Engines::get($engine)->backupPath($file);
        $this->audit('database', 'Downloaded backup', $path);

        return response()->streamDownload(fn () => $files->stream($path), $file, ['Content-Type' => 'application/octet-stream']);
    }

    public function deleteBackup(string $engine, string $file)
    {
        abort_unless(Engines::isDatabaseEngine($engine), 404);

        return $this->result(Shell::run('rm -f '.Shell::arg(Engines::get($engine)->backupPath($file))), 'Backup deleted', 'database', $file);
    }

    /* ================================================================ delete */

    public function destroy(Request $request, MysqlDatabase $database)
    {
        $db = Engines::get($database->engine);
        $recycle = $request->boolean('recycle', true) && $db->canBackup($database->server) && ! ($db instanceof SqlServerEngine);

        if (! $recycle) {
            $result = $db->drop($database->name, $database->username, $database->server);
            if ($result->failed()) {
                return $this->fail($result->message());
            }
            $this->audit('database', "Dropped database {$database->name}", $database->location());
            $database->delete();

            return $this->ok('Database deleted');
        }

        return $this->task($this->recycleTask($database, $db), 'Moving to the recycle bin');
    }

    protected function recycleTask(MysqlDatabase $database, DatabaseEngine $db): \App\Models\Task
    {
        $file = $db->backupRoot().'/recycle/'.$database->engine.'_'.$database->name.'_'.date('Ymd_His').'.'.$db->dumpExtension();
        // the task only dumps; TaskHooks drops the database once the dump succeeded
        $script = "set -e\nmkdir -p ".Shell::arg(dirname($file))."\n".$db->dumpScript($database->name, $file, $database->server)."\necho 'Dump saved to {$file}'";
        $this->audit('database', "Deleted database {$database->name} (recycle bin)", $database->location());

        return TaskRunner::dispatch("Delete database {$database->name}", $script, 'database', ['on_success' => 'db_recycled', 'database_id' => $database->id, 'file' => $file]);
    }

    public function bulk(Request $request)
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer'], 'action' => ['required', 'in:backup,delete']]);
        $count = 0;
        foreach (MysqlDatabase::query()->whereIn('id', $data['ids'])->with('server')->get() as $database) {
            $db = Engines::get($database->engine);
            if (! $db->canBackup($database->server)) {
                continue;
            }
            $data['action'] === 'backup' ? $this->backupTask($database) : $this->recycleTask($database, $db);
            $count++;
        }

        return $this->ok($data['action'] === 'backup' ? "{$count} backup(s) started" : "{$count} database(s) moved to the recycle bin");
    }

    /* ========================================================== recycle bin */

    public function recycle(Request $request)
    {
        $engine = (string) $request->query('engine', 'mysql');

        return $this->ok('ok', ['items' => DatabaseRecycle::query()->where('engine', $engine)->latest()->get()->map(fn ($i) => [
            'id' => $i->id, 'name' => $i->name, 'username' => $i->username, 'size' => $i->size, 'deleted' => $i->created_at->format('Y-m-d H:i'),
            'expires' => $i->created_at->addDays(DatabaseRecycle::KEEP_DAYS)->format('Y-m-d'),
        ])]);
    }

    public function recycleRestore(DatabaseRecycle $item)
    {
        $db = Engines::get($item->engine);
        if (! $db->installed()) {
            return $this->fail($db->label().' is not installed on this server.');
        }
        if (MysqlDatabase::query()->engine($item->engine)->whereNull('server_id')->where('name', $item->name)->exists()) {
            return $this->fail("A database named {$item->name} already exists.");
        }
        $password = $item->password ?: Str::password(20, symbols: false);
        $hosts = explode(',', (string) ($item->host ?: 'localhost'));

        // create the database and user from PHP, then load the dump in the background
        $created = $db->create($item->name, $item->username ?: $item->name, $password, ['charset' => $item->charset ?: 'utf8mb4', 'hosts' => $hosts]);
        if ($created->failed()) {
            return $this->fail($created->message());
        }
        if (! $item->password) {
            $item->update(['password' => $password]);
        }

        return $this->task(TaskRunner::dispatch("Restore {$item->name} from the recycle bin", $db->importScript($item->name, $item->file), 'database', ['on_success' => 'db_restored', 'recycle_id' => $item->id]), 'Restore started');
    }

    public function recycleDestroy(DatabaseRecycle $item)
    {
        Shell::run('rm -f '.Shell::arg($item->file));
        $this->audit('database', "Permanently deleted {$item->name} from the recycle bin");
        $item->delete();

        return $this->ok('Deleted permanently');
    }

    /* ======================================================= server settings */

    public function rootPassword(Request $request)
    {
        $db = $this->engine($request);
        $data = $request->validate(['password' => ['required', 'string', Password::min(10)]]);
        $this->assertLocalInstalled($db, null);
        $result = $db->rootPassword($data['password']);
        if ($result->failed()) {
            return $this->fail($result->message());
        }
        $this->audit('database', "Changed the {$db->label()} root password");

        return $this->ok('Root password changed');
    }

    public function mongoAuth(Request $request, MongoEngine $mongo)
    {
        $result = $mongo->setAuth($request->boolean('enabled'));
        if ($result->failed()) {
            return $this->fail($result->message());
        }
        $this->audit('database', ($request->boolean('enabled') ? 'Enabled' : 'Disabled').' MongoDB access control');

        return $this->ok($request->boolean('enabled') ? 'Access control enabled. Applications must log in with their database user.' : 'Access control disabled');
    }

    protected function autoBackupSettings(): array
    {
        return [
            'enabled' => (bool) Setting::get('db_backup_enabled', false),
            'time' => Setting::get('db_backup_time', '02:30'),
            'keep' => (int) Setting::get('db_backup_keep', config('gbx.database_backup_keep')),
            'storage' => (int) Setting::get('db_backup_storage', 0),
            'storage_move' => (bool) Setting::get('db_backup_storage_move', false),
            'remote_keep' => (int) Setting::get('db_backup_remote_keep', 30),
            'storages' => \App\Models\BackupStorage::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }

    public function autoBackup(Request $request)
    {
        $data = $request->validate([
            'time' => ['nullable', 'date_format:H:i'],
            'keep' => ['nullable', 'integer', 'min:1', 'max:90'],
            'storage' => ['nullable', 'integer'],
            'storage_move' => ['nullable', 'boolean'],
            'remote_keep' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);
        if ($request->has('storage')) {
            $storage = (int) $request->input('storage');
            if ($storage > 0 && ! \App\Models\BackupStorage::query()->whereKey($storage)->exists()) {
                return $this->fail('Storage not found.');
            }
            Setting::put('db_backup_storage', $storage);
            Setting::put('db_backup_storage_move', $storage > 0 && $request->boolean('storage_move'));
            Setting::put('db_backup_remote_keep', (int) ($data['remote_keep'] ?? 30));
        }
        Setting::put('db_backup_enabled', $request->boolean('enabled'));
        if (! empty($data['time'])) {
            Setting::put('db_backup_time', $data['time']);
        }
        if (! empty($data['keep'])) {
            Setting::put('db_backup_keep', $data['keep']);
        }
        $this->audit('database', ($request->boolean('enabled') ? 'Enabled' : 'Disabled').' automatic database backups');

        return $this->ok($request->boolean('enabled') ? 'Automatic backups enabled (daily at '.Setting::get('db_backup_time', '02:30').')' : 'Automatic backups disabled');
    }

    /** Open phpMyAdmin or Adminer: the cookie lets Apache serve them when access is restricted. */
    public function openTool(Request $request, string $tool)
    {
        abort_unless(in_array($tool, ['phpmyadmin', 'adminer'], true), 404);
        $query = [];
        if ($tool === 'adminer' && $request->query('engine') === 'pgsql') {
            $query = array_filter(['pgsql' => '127.0.0.1', 'username' => $request->query('user'), 'db' => $request->query('db')]);
        } elseif ($tool === 'adminer') {
            $query = array_filter(['server' => '127.0.0.1', 'username' => $request->query('user'), 'db' => $request->query('db')]);
        } elseif ($request->query('db')) {
            $query = ['db' => $request->query('db')];
        }
        $response = redirect('/'.$tool.'/'.($query ? '?'.http_build_query($query) : ''));
        if (config('gbx.tools.token')) {
            $response->withCookie(cookie('gbx_tools', config('gbx.tools.token'), 60 * 12, '/', null, $request->isSecure(), true, false, 'lax'));
        }
        $this->audit('database', 'Opened '.$tool, $request->query('db'));

        return $response;
    }

    public function toolsAccess(Request $request)
    {
        $public = $request->boolean('public');
        $this->audit('database', 'Set phpMyAdmin/Adminer access to '.($public ? 'public' : 'panel only'));

        return $this->task(TaskRunner::dispatch('phpMyAdmin/Adminer access: '.($public ? 'public' : 'panel only'), rtrim(config('gbx.root'), '/').'/bin/gbx tools-access '.($public ? 'public' : 'private'), 'database'), 'Updating access');
    }

    /* ================================================================ remote servers */

    public function serverStore(Request $request, RedisManager $redis, QdrantManager $qdrant)
    {
        $data = $request->validate([
            'engine' => ['required', Rule::in(Engines::SERVER_ENGINES)],
            'name' => ['nullable', 'string', 'max:60'],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:120'],
            'password' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $host = $data['engine'] === 'qdrant' ? preg_replace('#/+$#', '', $data['host']) : $data['host'];
        if ($data['engine'] === 'qdrant' ? ! preg_match('#^(https?://)?[a-z0-9.\-]+(:\d+)?$#i', $host) : (! filter_var($host, FILTER_VALIDATE_IP) && ! preg_match('/^[a-z0-9.\-]+$/i', $host))) {
            throw ValidationException::withMessages(['host' => 'Invalid server address.']);
        }

        $server = new DbServer(['engine' => $data['engine'], 'name' => $data['name'] ?: $host, 'host' => $host, 'port' => $data['port'], 'username' => $data['username'] ?? null, 'password' => $data['password'] ?? null, 'notes' => $data['notes'] ?? null]);

        $error = match ($data['engine']) {
            'redis' => (fn () => $redis->overview($server)['error'])(),
            'qdrant' => $qdrant->overview($server)['error'],
            default => Shell::simulating() ? null : (($r = Engines::get($data['engine'])->testConnection($server))->failed() ? $r->message() : null),
        };
        if ($error) {
            return $this->fail('Connection failed: '.$error);
        }
        $server->save();
        $this->audit('database', 'Added remote '.$data['engine'].' server', $server->label());

        return $this->ok('Server added', ['id' => $server->id]);
    }

    public function serverDestroy(DbServer $server)
    {
        $count = $server->databases()->count();
        $this->audit('database', 'Removed remote '.$server->engine.' server', $server->label());
        $server->delete();

        return $this->ok('Server removed'.($count ? " ({$count} database record(s) removed from the panel; the databases were not dropped)" : ''));
    }
}
