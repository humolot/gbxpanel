<?php

namespace App\Services\Ai\Tools;

use App\Models\Website;
use App\Services\ApacheManager;
use App\Services\SslManager;

class WebsiteTools extends ToolGroup
{
    public function __construct(protected ApacheManager $apache, protected SslManager $ssl) {}

    public function tools(): array
    {
        return [
            'list_websites' => self::tool('Websites managed by the panel: PHP version, reverse proxy target, SSL, status and document root.', self::params(), false, false, fn () => 'Listed websites'),
            'get_website' => self::tool('Website details including its Apache virtual host, log paths, linked databases and certificate.', self::params(['domain' => self::str('Primary domain')], ['domain']), false, false, fn ($a) => 'Inspected website '.self::labelArg($a, 'domain')),

            'create_website' => self::tool('Create an Apache website. Use php_version for PHP apps, proxy_target (e.g. http://127.0.0.1:3000) for Node.js/PM2/Docker apps, or neither for static sites. Document root defaults to /www/wwwroot/<domain>.', self::params([
                'domain' => self::str('Primary domain'),
                'php_version' => self::str('Installed PHP version, e.g. 8.4'),
                'proxy_target' => self::str('Reverse proxy URL, e.g. http://127.0.0.1:3000'),
                'aliases' => self::str('Space separated extra domains'),
                'add_www' => self::bool('Add a www alias. Default: true for apex domains (example.com), false for subdomains (shop.example.com). Only add it when the www DNS record exists.'),
                'root_path' => self::str('Custom document root (Laravel: /www/wwwroot/<domain>/public)'),
                'create_database' => self::bool('Also create a MySQL database and user'),
                'create_ftp' => self::bool('Also create an FTP account'),
            ], ['domain']), true, false, fn ($a) => 'Create website '.self::labelArg($a, 'domain')),
            'update_website' => self::tool('Change a website: PHP version, reverse proxy target (empty string removes it), aliases, document root, or start/stop it.', self::params([
                'domain' => self::str('Primary domain'),
                'php_version' => self::str('PHP version (empty = static)'),
                'proxy_target' => self::str('Reverse proxy URL or empty to remove'),
                'aliases' => self::str('Space separated aliases (replaces the list)'),
                'root_path' => self::str('Document root'),
                'status' => self::str('active or stopped', ['active', 'stopped']),
            ], ['domain']), true, false, fn ($a) => 'Update website '.self::labelArg($a, 'domain')),
            'delete_website' => self::tool('Delete a website virtual host, optionally its files, linked databases and FTP accounts.', self::params(['domain' => self::str('Primary domain'), 'delete_files' => self::bool('Delete the document root'), 'delete_databases' => self::bool('Drop linked databases'), 'delete_ftp' => self::bool('Delete linked FTP accounts')], ['domain']), true, false, fn ($a) => 'Delete website '.self::labelArg($a, 'domain')),
            'manage_website_ssl' => self::tool("SSL for a website: issue a free Let's Encrypt certificate (DNS must point here), disable SSL, or turn the HTTP->HTTPS redirect on/off.", self::params(['domain' => self::str('Website domain'), 'action' => self::str('Action', ['issue_letsencrypt', 'disable', 'force_https_on', 'force_https_off']), 'email' => self::str("Contact e-mail (required for Let's Encrypt)")], ['domain', 'action']), true, false, fn ($a) => str_replace('_', ' ', ucfirst(self::labelArg($a, 'action'))).' for '.self::labelArg($a, 'domain')),
            'save_website_vhost' => self::tool('Replace the Apache virtual host of a website with custom configuration. Validated with apache2ctl configtest and rolled back on error.', self::params(['domain' => self::str('Website domain'), 'content' => self::str('Full vhost configuration')], ['domain', 'content']), true, true, fn ($a) => 'Save custom vhost for '.self::labelArg($a, 'domain')),
        ];
    }

    protected function site(string $domain): Website
    {
        return Website::query()->where('domain', strtolower(trim($domain)))->first()
            ?? throw new \RuntimeException("Website {$domain} not found. Use list_websites.");
    }

    public function handle(string $name, array $a): mixed
    {
        switch ($name) {
            case 'list_websites':
                return Website::query()->withCount(['databases', 'ftpAccounts'])->orderBy('domain')->get()->map(fn ($w) => $w->only(['domain', 'aliases', 'root_path', 'php_version', 'proxy_target', 'status', 'ssl_enabled', 'ssl_provider', 'force_https', 'databases_count', 'ftp_accounts_count']) + ['ssl_expires_at' => $w->ssl_expires_at?->toDateString()])->all();

            case 'get_website':
                $site = $this->site((string) $a['domain']);

                return $site->only(['domain', 'aliases', 'root_path', 'php_version', 'proxy_target', 'status', 'ssl_enabled', 'ssl_provider', 'force_https', 'notes']) + [
                    'vhost_file' => $this->apache->configPath($site),
                    'vhost' => $this->apache->read($site),
                    'error_log' => $site->logPath('error'),
                    'access_log' => $site->logPath('access'),
                    'databases' => $site->databases()->pluck('name'),
                    'ftp_accounts' => $site->ftpAccounts()->pluck('username'),
                    'certificate' => $this->ssl->certificateInfo($site),
                ];

            case 'create_website':
                return $this->panelRequest('POST', '/websites', array_filter([
                    'domain' => $a['domain'],
                    'php_version' => ! empty($a['proxy_target']) ? '' : (self::a($a, 'php_version') ?? config('gbx.default_php')),
                    'proxy_target' => self::a($a, 'proxy_target'),
                    'aliases' => self::a($a, 'aliases', ''),
                    'root_path' => self::a($a, 'root_path'),
                    'add_www' => (bool) self::a($a, 'add_www', \App\Models\Website::isApexDomain((string) $a['domain'])),
                    'create_database' => (bool) self::a($a, 'create_database', false),
                    'create_ftp' => (bool) self::a($a, 'create_ftp', false),
                ], fn ($v) => $v !== null));

            case 'update_website':
                $site = $this->site((string) $a['domain']);
                $result = ['ok' => true];
                if (isset($a['status']) && $a['status'] !== $site->status) {
                    $result = $this->panelRequest('POST', "/websites/{$site->id}/status");
                    $site->refresh();
                }
                if (array_key_exists('php_version', $a) || array_key_exists('proxy_target', $a) || isset($a['aliases']) || isset($a['root_path'])) {
                    $result = $this->panelRequest('PUT', "/websites/{$site->id}", [
                        'php_version' => array_key_exists('php_version', $a) ? (string) $a['php_version'] : (string) $site->php_version,
                        'proxy_target' => array_key_exists('proxy_target', $a) ? (string) $a['proxy_target'] : (string) $site->proxy_target,
                        'aliases' => $a['aliases'] ?? $site->aliases,
                        'root_path' => $a['root_path'] ?? $site->root_path,
                        'notes' => $site->notes,
                    ]);
                }

                return $result;

            case 'delete_website':
                $site = $this->site((string) $a['domain']);

                return $this->panelRequest('DELETE', "/websites/{$site->id}", [
                    'delete_files' => (bool) self::a($a, 'delete_files', false),
                    'delete_databases' => (bool) self::a($a, 'delete_databases', false),
                    'delete_ftp' => (bool) self::a($a, 'delete_ftp', false),
                ]);

            case 'manage_website_ssl':
                $site = $this->site((string) $a['domain']);

                return match ($a['action']) {
                    'issue_letsencrypt' => $this->queued($this->ssl->issue($site, (string) self::a($a, 'email', '')), "The certificate request for {$site->domain}"),
                    'disable' => $this->shell($this->ssl->disable($site), 'SSL disabled'),
                    'force_https_on', 'force_https_off' => $this->panelRequest('POST', "/websites/{$site->id}/ssl/force", ['enabled' => $a['action'] === 'force_https_on']),
                    default => ['error' => 'Unknown action'],
                };

            case 'save_website_vhost':
                return $this->panelRequest('POST', '/websites/'.$this->site((string) $a['domain'])->id.'/config', ['content' => (string) $a['content']]);
        }

        return ['error' => "Unknown tool {$name}"];
    }
}
