<?php

namespace App\Http\Controllers\Client;

use App\Models\Client;
use App\Models\MysqlDatabase;
use App\Models\Website;
use App\Services\MysqlManager;
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
