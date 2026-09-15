<?php

namespace App\Http\Controllers;

use App\Models\MysqlDatabase;
use App\Models\Website;
use App\Services\MysqlManager;
use App\Services\ServiceManager;
use App\Services\Shell;
use App\Services\TaskRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class DatabaseController extends Controller
{
    public function __construct(protected MysqlManager $mysql) {}

    public function index(ServiceManager $services)
    {
        $installed = $this->mysql->installed();
        $live = $installed ? $this->mysql->databases() : [];

        return view('databases.index', [
            'installed' => $installed,
            'service' => $installed ? $services->status($this->mysql->service()) : null,
            'version' => $installed ? $this->mysql->version() : null,
            'databases' => MysqlDatabase::query()->with('website:id,domain')->orderBy('name')->get(),
            'live' => $live,
            'unmanaged' => array_diff(array_keys($live), MysqlDatabase::query()->pluck('name')->all()),
            'websites' => Website::query()->orderBy('domain')->get(['id', 'domain']),
            'backups' => $installed ? $this->mysql->backups() : [],
            'phpmyadmin' => Shell::simulating() || is_file(rtrim(config('gbx.root'), '/').'/phpmyadmin/index.php'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'regex:/^[a-zA-Z0-9_]{1,64}$/', 'unique:databases,name'],
            'username' => ['required', 'string', 'regex:/^[a-zA-Z0-9_]{1,32}$/'],
            'password' => ['required', 'string', Password::min(8)],
            'host' => ['required', 'string', 'in:localhost,%,127.0.0.1'],
            'charset' => ['required', 'in:utf8mb4,utf8,latin1'],
            'website_id' => ['nullable', 'exists:websites,id'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->mysql->create($data['name'], $data['username'], $data['password'], $data['charset'], $data['host']);
        if ($result->failed()) {
            return $this->fail($result->message());
        }
        MysqlDatabase::query()->create($data);
        $this->audit('database', "Created database {$data['name']}", 'user '.$data['username'].'@'.$data['host']);

        return $this->ok('Database created');
    }

    /** Import databases that exist in MySQL but are not tracked by the panel. */
    public function sync()
    {
        $count = 0;
        foreach (array_keys($this->mysql->databases()) as $name) {
            if (MysqlDatabase::query()->where('name', $name)->exists()) {
                continue;
            }
            MysqlDatabase::query()->create(['name' => $name, 'username' => $name, 'password' => null, 'notes' => 'Imported from server']);
            $count++;
        }

        return $this->ok("{$count} database(s) imported");
    }

    public function password(Request $request, MysqlDatabase $database)
    {
        $data = $request->validate(['password' => ['required', 'string', Password::min(8)]]);
        $result = $this->mysql->changePassword($database->username, $data['password'], $database->host);
        if ($result->failed()) {
            return $this->fail($result->message());
        }
        $database->update(['password' => $data['password']]);
        $this->audit('database', "Changed password of {$database->username}");

        return $this->ok('Password changed');
    }

    public function credentials(MysqlDatabase $database)
    {
        $this->audit('database', "Viewed credentials of {$database->name}");

        return $this->ok('ok', ['name' => $database->name, 'username' => $database->username, 'password' => $database->password, 'host' => $database->host]);
    }

    public function backup(MysqlDatabase $database)
    {
        return $this->task(TaskRunner::dispatch("Backup database {$database->name}", $this->mysql->backupScript($database->name), 'database'), 'Backup started');
    }

    public function import(Request $request, MysqlDatabase $database)
    {
        $request->validate(['file' => ['required', 'file', 'max:512000']]);
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, ['sql', 'gz', 'zip'], true)) {
            return $this->fail('Upload a .sql, .sql.gz or .zip file.');
        }

        $dir = storage_path('app/imports');
        @mkdir($dir, 0775, true);
        $name = Str::random(16).'.'.$ext;
        $file->move($dir, $name);

        $script = $this->mysql->importScript($database->name, $dir.'/'.$name)."\nrm -f ".Shell::arg($dir.'/'.$name);

        return $this->task(TaskRunner::dispatch("Import into {$database->name}", $script, 'database'), 'Import started');
    }

    public function destroy(MysqlDatabase $database)
    {
        $result = $this->mysql->drop($database->name, $database->username, $database->host);
        if ($result->failed()) {
            return $this->fail($result->message());
        }
        $this->audit('database', "Dropped database {$database->name}");
        $database->delete();

        return $this->ok('Database deleted');
    }

    protected function backupPath(string $name): string
    {
        abort_unless((bool) preg_match('/^[a-zA-Z0-9_]+_\d{8}_\d{6}\.sql\.gz$/', $name), 404);

        return $this->mysql->backupDir().'/'.$name;
    }

    public function downloadBackup(string $name, \App\Services\FileManager $files)
    {
        $path = $this->backupPath($name);

        return response()->streamDownload(fn () => $files->stream($path), $name, ['Content-Type' => 'application/gzip']);
    }

    public function deleteBackup(string $name)
    {
        return $this->result(Shell::run('rm -f '.Shell::arg($this->backupPath($name))), 'Backup deleted', 'database', $name);
    }
}
