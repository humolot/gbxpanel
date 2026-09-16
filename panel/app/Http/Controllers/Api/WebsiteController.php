<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\WebsiteController as PanelWebsites;
use App\Http\Controllers\WebsiteSettingsController as PanelSettings;
use App\Models\Website;
use App\Services\SslManager;
use Illuminate\Http\Request;

class WebsiteController extends ApiController
{
    public static function resource(Website $site, bool $full = false): array
    {
        $data = [
            'id' => $site->id,
            'domain' => $site->domain,
            'aliases' => array_values(array_filter(explode(' ', (string) $site->aliases))),
            'root_path' => $site->root_path,
            'document_root' => $site->documentRoot(),
            'php_version' => $site->php_version,
            'proxy_target' => $site->proxy_target,
            'status' => $site->status,
            'ssl' => [
                'enabled' => (bool) $site->ssl_enabled,
                'provider' => $site->ssl_provider,
                'expires_at' => $site->ssl_expires_at?->toIso8601String(),
                'force_https' => (bool) $site->force_https,
            ],
            'client_id' => $site->client_id,
            'expires_at' => $site->expires_at?->toDateString(),
            'notes' => $site->notes,
            'created_at' => $site->created_at?->toIso8601String(),
        ];

        if ($full) {
            $data['databases'] = $site->databases->map(fn ($db) => ['id' => $db->id, 'engine' => $db->engine, 'name' => $db->name, 'username' => $db->username])->all();
            $data['ftp_accounts'] = $site->ftpAccounts->map(fn ($ftp) => ['id' => $ftp->id, 'username' => $ftp->username, 'path' => $ftp->path, 'is_active' => (bool) $ftp->is_active])->all();
        }

        return $data;
    }

    public function index(Request $request)
    {
        $query = Website::query()->orderBy('domain');
        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($q) => $q->where('domain', 'like', "%{$search}%")->orWhere('aliases', 'like', "%{$search}%"));
        }
        if ($request->filled('client_id')) {
            $query->where('client_id', (int) $request->query('client_id'));
        }

        return $this->page($query, $request, fn (Website $site) => self::resource($site));
    }

    public function show(Website $website)
    {
        return $this->data(self::resource($website->load(['databases', 'ftpAccounts']), true));
    }

    public function store(Request $request)
    {
        $response = $this->forward(PanelWebsites::class, 'store', [
            'domain' => $request->input('domain'),
            'aliases' => $request->input('aliases'),
            'root_path' => $request->input('root_path'),
            'php_version' => $request->input('php_version'),
            'proxy_target' => $request->input('proxy_target'),
            'notes' => $request->input('notes'),
            'add_www' => $this->flag($request, 'add_www'),
            'create_database' => $this->flag($request, 'create_database'),
            'create_ftp' => $this->flag($request, 'create_ftp'),
            'create_dns' => $this->flag($request, 'create_dns'),
        ]);

        // the panel answers with the created site and the credentials it generated
        $site = Website::query()->where('domain', strtolower(trim((string) $request->input('domain'))))->first();
        if ($site && $request->filled('client_id')) {
            $site->update(['client_id' => (int) $request->input('client_id')]);
        }
        if ($site && $response->isSuccessful()) {
            $body = $response->getData(true);
            $body['data'] = ($body['data'] ?? []) + ['website' => self::resource($site->fresh())];

            return response()->json($body, $response->getStatusCode());
        }

        return $response;
    }

    public function update(Request $request, Website $website)
    {
        $response = $this->forward(PanelWebsites::class, 'update', [
            'aliases' => $request->input('aliases', $website->aliases),
            'root_path' => $request->input('root_path', $website->root_path),
            'php_version' => $request->input('php_version', $website->php_version),
            'proxy_target' => $request->input('proxy_target', $website->proxy_target),
            'notes' => $request->input('notes', $website->notes),
        ], ['website' => $website]);

        if ($request->has('client_id') && $response->isSuccessful()) {
            $website->update(['client_id' => $request->input('client_id') ? (int) $request->input('client_id') : null]);
        }

        return $response;
    }

    public function destroy(Request $request, Website $website)
    {
        return $this->forward(PanelWebsites::class, 'destroy', [
            'delete_files' => $this->flag($request, 'delete_files', false),
            'delete_databases' => $this->flag($request, 'delete_databases', false),
            'delete_ftp' => $this->flag($request, 'delete_ftp', false),
        ], ['website' => $website]);
    }

    public function start(Website $website)
    {
        return $this->setStatus($website, 'active');
    }

    public function stop(Website $website)
    {
        return $this->setStatus($website, 'stopped');
    }

    protected function setStatus(Website $website, string $status)
    {
        if ($website->status === $status) {
            return $this->message($status === 'active' ? 'The website is already running' : 'The website is already stopped');
        }

        return $this->forward(PanelWebsites::class, 'status', [], ['website' => $website]);
    }

    public function ssl(Request $request, Website $website)
    {
        return $this->forward(PanelWebsites::class, 'sslIssue', [
            'email' => $request->input('email'),
            'method' => $request->input('method', 'http'),
            'include_aliases' => $this->flag($request, 'include_aliases', true),
            'wildcard' => $this->flag($request, 'wildcard', false),
        ], ['website' => $website, 'ssl' => app(SslManager::class)]);
    }

    public function sslDisable(Website $website)
    {
        return $this->forward(PanelWebsites::class, 'sslDisable', [], ['website' => $website]);
    }

    public function settings(Request $request, Website $website, string $section)
    {
        return $this->forward(PanelSettings::class, 'data', $request->query(), ['website' => $website, 'section' => $section]);
    }

    public function saveSettings(Request $request, Website $website, string $section)
    {
        if (! in_array($section, PanelSettings::SECTIONS, true)) {
            return $this->error('unknown_section', 'Unknown section. Available: '.implode(', ', PanelSettings::SECTIONS), 404);
        }

        return $this->forward(PanelSettings::class, 'update', (array) $request->json()->all(), ['website' => $website, 'section' => $section]);
    }

    public function backups(Website $website)
    {
        return $this->forward(PanelSettings::class, 'backups', [], ['website' => $website]);
    }

    public function backup(Request $request, Website $website)
    {
        return $this->forward(PanelSettings::class, 'backupCreate', [
            'databases' => $this->flag($request, 'databases', true),
            'storage_id' => $request->input('storage_id'),
            'delete_local' => $this->flag($request, 'delete_local', false),
        ], ['website' => $website]);
    }

    public function restore(Request $request, Website $website)
    {
        return $this->forward(PanelSettings::class, 'backupRestore', ['file' => $request->input('file')], ['website' => $website]);
    }

    public function deleteBackup(Request $request, Website $website)
    {
        return $this->forward(PanelSettings::class, 'backupDelete', ['file' => $request->input('file')], ['website' => $website]);
    }

    public function logs(Request $request, Website $website)
    {
        return $this->forward(PanelSettings::class, 'logs', [
            'type' => $request->query('type', 'error'),
            'lines' => $request->query('lines', 200),
            'filter' => $request->query('filter'),
        ], ['website' => $website]);
    }

    public function usage(Request $request, Website $website)
    {
        return $this->forward(PanelSettings::class, 'usage', ['range' => $request->query('range', '24h')], ['website' => $website]);
    }
}
