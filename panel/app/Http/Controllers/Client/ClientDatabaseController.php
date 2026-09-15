<?php

namespace App\Http\Controllers\Client;

use App\Models\Client;
use App\Models\MysqlDatabase;
use App\Models\Website;
use App\Services\Clients\PhpMyAdminSignon;
use App\Services\FileManager;
use App\Services\MysqlManager;
use App\Services\Shell;
use App\Services\TaskRunner;
use App\Services\SoftwareManager;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * MySQL databases of the client on the local server. Names and users get a prefix unique to the
 * client, so a client can never take over the database user of another account.
 */
class ClientDatabaseController extends ClientPanelController
{
    public function __construct(protected MysqlManager $mysql) {}

    public static function prefix(Client $client): string
    {
        return substr(preg_replace('/[^a-z0-9]/', '', strtolower($client->username)), 0, 10).$client->id.'_';
    }

    public function index()
    {
        $client = $this->client()->load('package');
        $databases = $client->databases()->with('website:id,domain')->orderBy('name')->get();
        $sizes = $databases->isNotEmpty() && $this->mysql->installed() ? $this->mysql->databases() : [];

        return view('client.databases', [
            'client' => $client,
            'databases' => $databases,
            'sizes' => $sizes,
            'sites' => $client->websites()->orderBy('domain')->get(['id', 'domain']),
            'prefix' => self::prefix($client),
            'installed' => $this->mysql->installed(),
            'canAdd' => $client->canAdd('databases', 'max_databases'),
        ]);
    }

    public function store(Request $request)
    {
        if ($error = $this->ensureCanAdd('databases', 'max_databases', 'database(s)')) {
            return $error;
        }
        if (! $this->mysql->installed()) {
            return $this->fail('MySQL is not available on this server.');
        }
        $client = $this->client();
        $prefix = self::prefix($client);
        $data = $request->validate([
            'name' => ['required', 'string', 'regex:/^[a-zA-Z0-9_]{1,'.(32 - strlen($prefix)).'}$/'],
            'password' => ['nullable', 'string', Password::min(10)->letters()->numbers()],
            'website_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], ['name.regex' => 'Use letters, numbers and underscore (max '.(32 - strlen($prefix)).' characters).']);

        $name = $prefix.strtolower($data['name']);
        if (MysqlDatabase::query()->where('name', $name)->orWhere('username', $name)->exists() || $this->mysql->userHosts($name)) {
            throw ValidationException::withMessages(['name' => 'This database or user already exists.']);
        }
        $siteId = null;
        if (! empty($data['website_id'])) {
            $siteId = $this->owned(Website::query()->findOrFail($data['website_id']))->id;
        }
        $password = ($data['password'] ?? '') ?: Str::password(18, symbols: false);

        $result = $this->mysql->create($name, $name, $password, ['hosts' => ['localhost']]);
        if ($result->failed()) {
            return $this->fail($result->message());
        }
        MysqlDatabase::query()->create([
            'engine' => 'mysql', 'name' => $name, 'username' => $name, 'password' => $password, 'host' => 'localhost',
            'charset' => 'utf8mb4', 'website_id' => $siteId, 'notes' => $data['notes'] ?? null, 'client_id' => $client->id,
        ]);
        $this->audit('database', "Created MySQL database {$name}");

        return $this->ok('Database created', ['database' => ['name' => $name, 'username' => $name, 'password' => $password, 'host' => 'localhost']]);
    }

    public function credentials(MysqlDatabase $database)
    {
        $this->owned($database);

        return $this->ok('ok', ['database' => ['name' => $database->name, 'username' => $database->username, 'password' => $database->password, 'host' => 'localhost']]);
    }

    public function password(Request $request, MysqlDatabase $database)
    {
        $this->owned($database);
        $data = $request->validate(['password' => ['required', 'string', Password::min(10)->letters()->numbers()]]);
        $result = $this->mysql->changePassword($database->name, $database->username, $data['password']);
        if ($result->ok()) {
            $database->update(['password' => $data['password']]);
        }

        return $this->result($result, 'Password changed', 'database', $database->name);
    }

    /* ============================================================== tools */

    protected function local(MysqlDatabase $database): MysqlDatabase
    {
        $this->owned($database);
        abort_unless($database->engine === 'mysql' && $database->server_id === null, 422, 'This database is managed by the administrator.');

        return $database;
    }

    /** Import script that runs with the client's own database user (never as root). */
    protected function importAsClient(MysqlDatabase $database, string $file): string
    {
        $esc = fn ($v) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v).'"';
        $cnf = Shell::secretFile("[client]\nhost=localhost\nuser={$esc($database->username)}\npassword={$esc($database->password)}\n", '.cnf');
        $f = Shell::arg($file);
        $mysql = 'mysql --defaults-extra-file='.Shell::arg($cnf).' --default-character-set=utf8mb4 '.Shell::arg($database->name);

        return "set -e\ntrap 'rm -f ".Shell::arg($cnf)."' EXIT\n"
            ."case {$f} in *.gz) gunzip -c {$f} | {$mysql} ;; *.zip) unzip -p {$f} | {$mysql} ;; *) {$mysql} < {$f} ;; esac\necho 'Import completed'";
    }

    public function phpMyAdmin(Request $request, MysqlDatabase $database, PhpMyAdminSignon $signon)
    {
        $this->local($database);
        if (! PhpMyAdminSignon::installed()) {
            return back()->with('error', 'phpMyAdmin is not installed on this server.');
        }
        $url = $signon->open($database, $request->isSecure());
        $this->audit('database', "Opened phpMyAdmin for {$database->name}");

        $response = redirect($url);
        if (config('gbx.tools.token')) {
            // Apache only serves phpMyAdmin to browsers with this cookie when the tools are private
            $response->withCookie(cookie('gbx_tools', config('gbx.tools.token'), 60 * 12, '/', null, $request->isSecure(), true, false, 'lax'));
        }

        return $response;
    }

    public function backups(MysqlDatabase $database)
    {
        $this->local($database);

        return $this->ok('ok', [
            'backups' => $this->mysql->backups($database->name),
            'extensions' => $this->mysql->importExtensions(),
            'keep' => self::KEEP_BACKUPS,
            'phpmyadmin' => PhpMyAdminSignon::installed(),
        ]);
    }

    public const KEEP_BACKUPS = 5;

    public function backup(MysqlDatabase $database)
    {
        $this->local($database);
        $dir = Shell::arg($this->mysql->backupDir());
        $script = $this->mysql->backupScript($database->name)."\n"
            // clients keep their newest backups only
            .'ls -1t '.$dir.'/'.Shell::arg($database->name.'_').'[0-9]*.'.$this->mysql->dumpExtension().' 2>/dev/null | tail -n +'.(self::KEEP_BACKUPS + 1).' | xargs -r rm -f --';

        return $this->task(TaskRunner::dispatch("Backup database {$database->name}", $script, 'database'), 'Backup started');
    }

    public function restore(Request $request, MysqlDatabase $database)
    {
        $this->local($database);
        try {
            $path = $this->mysql->backupPath((string) $request->input('file'));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        abort_unless(preg_match('/^'.preg_quote($database->name, '/').'_\d{8}_\d{6}\./', basename($path)), 404);
        $this->audit('database', "Restored {$database->name}", basename($path));

        return $this->task(TaskRunner::dispatch("Restore {$database->name} from ".basename($path), $this->importAsClient($database, $path), 'database'), 'Restore started');
    }

    public function downloadBackup(Request $request, MysqlDatabase $database, FileManager $files)
    {
        $this->local($database);
        $file = (string) $request->query('file');
        try {
            $path = $this->mysql->backupPath($file);
        } catch (\InvalidArgumentException) {
            abort(404);
        }
        abort_unless(preg_match('/^'.preg_quote($database->name, '/').'_\d{8}_\d{6}\./', $file), 404);

        return response()->streamDownload(fn () => $files->stream($path), $file, ['Content-Type' => 'application/octet-stream']);
    }

    public function deleteBackup(Request $request, MysqlDatabase $database)
    {
        $this->local($database);
        $file = (string) $request->input('file');
        try {
            $path = $this->mysql->backupPath($file);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        abort_unless(preg_match('/^'.preg_quote($database->name, '/').'_\d{8}_\d{6}\./', $file), 404);

        return $this->result(Shell::run('rm -f -- '.Shell::arg($path)), 'Backup deleted', 'database', $file);
    }

    /** Download a fresh dump without storing it on the server. */
    public function export(MysqlDatabase $database)
    {
        $this->local($database);
        $name = $database->name.'_'.date('Ymd_His').'.sql.gz';
        $this->audit('database', "Exported {$database->name}");

        return response()->streamDownload(function () use ($database) {
            if (Shell::simulating()) {
                echo gzencode("-- Simulated dump of {$database->name}\n");

                return;
            }
            $cmd = ['/bin/bash', '-c', 'set -o pipefail; mysqldump --single-transaction --routines --triggers --default-character-set=utf8mb4 '.Shell::arg($database->name).' | gzip'];
            if (! Shell::isRoot()) {
                $cmd = array_merge(['sudo', '-n'], $cmd);
            }
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (! is_resource($proc)) {
                return;
            }
            while (! feof($pipes[1])) {
                echo fread($pipes[1], 65536);
                flush();
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        }, $name, ['Content-Type' => 'application/gzip']);
    }

    public function import(Request $request, MysqlDatabase $database)
    {
        $this->local($database);
        $request->validate(['file' => ['required', 'file', 'max:1048576']]);
        $file = $request->file('file');
        $original = strtolower($file->getClientOriginalName());
        if (! preg_match('/\.(sql|sql\.gz|gz|zip)$/', $original, $m)) {
            return $this->fail('Upload a .sql, .sql.gz or .zip file.');
        }
        $dir = storage_path('app/imports');
        @mkdir($dir, 0775, true);
        $name = Str::random(16).'.'.$m[1];
        $file->move($dir, $name);
        $this->audit('database', "Import into {$database->name}", $original);

        return $this->task(TaskRunner::dispatch("Import into {$database->name}", $this->importAsClient($database, $dir.'/'.$name)."\nrm -f ".Shell::arg($dir.'/'.$name), 'database'), 'Import started');
    }

    public function destroy(MysqlDatabase $database)
    {
        $this->owned($database);
        abort_unless($database->engine === 'mysql' && $database->server_id === null, 422, 'This database is managed by the administrator.');
        $result = $this->mysql->drop($database->name, $database->username);
        if ($result->ok()) {
            $database->delete();
        }

        return $this->result($result, 'Database deleted', 'database', $database->name);
    }
}
