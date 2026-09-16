<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\DatabaseController as PanelDatabases;
use App\Models\MysqlDatabase;
use Illuminate\Http\Request;

class DatabaseController extends ApiController
{
    public static function resource(MysqlDatabase $db): array
    {
        return [
            'id' => $db->id,
            'engine' => $db->engine,
            'name' => $db->name,
            'username' => $db->username,
            'host' => $db->host ?: 'localhost',
            'charset' => $db->charset,
            'server_id' => $db->server_id,
            'website_id' => $db->website_id,
            'client_id' => $db->client_id,
            'notes' => $db->notes,
            'created_at' => $db->created_at?->toIso8601String(),
        ];
    }

    public function index(Request $request)
    {
        $query = MysqlDatabase::query()->orderBy('name');
        if ($engine = $request->query('engine')) {
            $query->where('engine', $engine);
        }
        if ($search = trim((string) $request->query('search'))) {
            $query->where('name', 'like', "%{$search}%");
        }
        if ($request->filled('client_id')) {
            $query->where('client_id', (int) $request->query('client_id'));
        }
        if ($request->filled('website_id')) {
            $query->where('website_id', (int) $request->query('website_id'));
        }

        return $this->page($query, $request, fn (MysqlDatabase $db) => self::resource($db));
    }

    public function show(MysqlDatabase $database)
    {
        return $this->data(self::resource($database));
    }

    public function store(Request $request)
    {
        $response = $this->forward(PanelDatabases::class, 'store', [
            'engine' => $request->input('engine', 'mysql'),
            'server_id' => $request->input('server_id'),
            'name' => $request->input('name'),
            'username' => $request->input('username', $request->input('name')),
            'password' => $request->input('password'),
            'charset' => $request->input('charset'),
            'hosts' => $request->input('hosts'),
            'website_id' => $request->input('website_id'),
            'notes' => $request->input('notes'),
        ]);

        $database = MysqlDatabase::query()->where('engine', $request->input('engine', 'mysql'))->where('name', $request->input('name'))->first();
        if ($database && $request->filled('client_id')) {
            $database->update(['client_id' => (int) $request->input('client_id')]);
        }

        return $response;
    }

    public function credentials(MysqlDatabase $database)
    {
        return $this->forward(PanelDatabases::class, 'credentials', [], ['database' => $database]);
    }

    public function password(Request $request, MysqlDatabase $database)
    {
        return $this->forward(PanelDatabases::class, 'password', ['password' => $request->input('password')], ['database' => $database]);
    }

    public function destroy(Request $request, MysqlDatabase $database)
    {
        return $this->forward(PanelDatabases::class, 'destroy', ['recycle' => $this->flag($request, 'recycle', true)], ['database' => $database]);
    }

    public function backups(MysqlDatabase $database)
    {
        return $this->forward(PanelDatabases::class, 'backups', [], ['database' => $database]);
    }

    public function backup(MysqlDatabase $database)
    {
        return $this->forward(PanelDatabases::class, 'backup', [], ['database' => $database]);
    }

    public function restore(Request $request, MysqlDatabase $database)
    {
        return $this->forward(PanelDatabases::class, 'restore', ['file' => $request->input('file')], ['database' => $database]);
    }
}
