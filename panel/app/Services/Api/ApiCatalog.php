<?php

namespace App\Services\Api;

/**
 * Catalog of the management API.
 *
 * One entry per endpoint, used to register the routes, to check the scope of the key and to
 * generate the OpenAPI document and the examples shown on the API page: the documentation can
 * never drift away from what the panel really answers.
 */
class ApiCatalog
{
    public const VERSION = 'v1';

    /** Permissions a key can hold. ":write" always includes ":read" of the same group. */
    public const SCOPES = [
        'system:read' => 'Read the server status, services and PHP versions',
        'system:write' => 'Start, stop and restart services',
        'websites:read' => 'List websites and their settings, logs and usage',
        'websites:write' => 'Create, change and delete websites, SSL and backups',
        'databases:read' => 'List databases and their backups',
        'databases:write' => 'Create, change, back up, restore and delete databases',
        'ftp:read' => 'List FTP accounts',
        'ftp:write' => 'Create, change and delete FTP accounts',
        'dns:read' => 'List DNS providers, zones and records',
        'dns:write' => 'Create, change and delete DNS records',
        'clients:read' => 'List clients, packages and their resources',
        'clients:write' => 'Create, change, suspend and delete clients',
        'backup:read' => 'List backups, storages and transfers',
        'backup:write' => 'Send backups to a storage and bring them back',
        'cron:read' => 'List scheduled tasks and their logs',
        'cron:write' => 'Run, enable, disable and delete scheduled tasks',
        'files:read' => 'List, read and download files',
        'files:write' => 'Write, create, rename, delete, compress and extract files',
        'security:read' => 'Read firewall rules and blocked addresses',
        'security:write' => 'Add and remove firewall rules and blocks',
        'tasks:read' => 'Follow background tasks and their output',
        'webhooks:read' => 'List webhooks',
        'webhooks:write' => 'Create, test and delete webhooks',
    ];

    /** Events a webhook can subscribe to. */
    public const EVENTS = [
        'website.created' => 'A website was created',
        'website.deleted' => 'A website was deleted',
        'website.status' => 'A website was started or stopped',
        'ssl.issued' => 'A Let\'s Encrypt certificate was issued or renewed',
        'database.created' => 'A database was created',
        'database.deleted' => 'A database was deleted',
        'client.created' => 'A client account was created',
        'client.suspended' => 'A client account was suspended',
        'client.unsuspended' => 'A client account was reactivated',
        'backup.finished' => 'A backup task finished',
        'backup.failed' => 'A backup task failed',
        'transfer.finished' => 'A backup reached its storage',
        'transfer.failed' => 'A backup could not be sent to its storage',
        'task.finished' => 'A background task finished',
        'task.failed' => 'A background task failed',
        'malware.detected' => 'The antivirus found something',
    ];

    /**
     * Endpoints. Every entry is [method, path, action, scope, summary] plus the documented
     * query and body fields. "task" marks endpoints that answer with a background task.
     *
     * @return list<array<string, mixed>>
     */
    public static function endpoints(): array
    {
        return [
            // ---------------------------------------------------------- meta
            self::e('GET', '/', 'Meta@index', null, 'Versions and links of this API', tag: 'Meta'),
            self::e('GET', '/whoami', 'Meta@whoami', null, 'Information about the key making the call', tag: 'Meta'),
            self::e('GET', '/openapi.json', 'Meta@openapi', null, 'OpenAPI document of this API', tag: 'Meta'),

            // -------------------------------------------------------- system
            self::e('GET', '/system', 'System@overview', 'system:read', 'CPU, memory, disk, network and panel information', tag: 'System'),
            self::e('GET', '/system/services', 'System@services', 'system:read', 'Services managed by the panel with their state', tag: 'System'),
            self::e('POST', '/system/services/{service}', 'System@serviceAction', 'system:write', 'Start, stop, restart, enable or disable a service', tag: 'System',
                body: ['action' => 'start | stop | restart | reload | enable | disable']),
            self::e('GET', '/system/php', 'System@php', 'system:read', 'PHP versions installed on the server', tag: 'System'),
            self::e('GET', '/system/metrics', 'System@metrics', 'system:read', 'History of CPU, memory, disk and network', tag: 'System',
                query: ['range' => '1h, 6h, 24h, 7d (default 24h)']),

            // ------------------------------------------------------ websites
            self::e('GET', '/websites', 'Website@index', 'websites:read', 'List websites', tag: 'Websites',
                query: ['search' => 'Filter by domain', 'client_id' => 'Only websites of a client', 'page' => 'Page number', 'per_page' => 'Rows per page (max 200)']),
            self::e('POST', '/websites', 'Website@store', 'websites:write', 'Create a website', tag: 'Websites',
                body: [
                    'domain' => 'Main domain [required]',
                    'aliases' => 'Extra domains, separated by spaces or commas',
                    'php_version' => 'Installed PHP version, for example 8.3; empty for a static site',
                    'root_path' => 'Document root (default /www/wwwroot/<domain>)',
                    'add_www' => 'Also answer on www.<domain>',
                    'create_database' => 'Create a MySQL database for the site',
                    'create_ftp' => 'Create an FTP account for the site',
                    'create_dns' => 'Create the DNS records when the zone is managed by the panel',
                    'client_id' => 'Owner of the website (client sub-panel)',
                    'notes' => 'Remark',
                ]),
            self::e('GET', '/websites/{website}', 'Website@show', 'websites:read', 'One website with its SSL, databases and FTP accounts', tag: 'Websites'),
            self::e('PATCH', '/websites/{website}', 'Website@update', 'websites:write', 'Change domains, document root, PHP version or proxy', tag: 'Websites',
                body: ['aliases' => 'Extra domains', 'root_path' => 'Document root', 'php_version' => 'PHP version', 'proxy_target' => 'Reverse proxy target', 'notes' => 'Remark', 'client_id' => 'Owner']),
            self::e('DELETE', '/websites/{website}', 'Website@destroy', 'websites:write', 'Delete a website', tag: 'Websites',
                body: ['delete_files' => 'Also delete the files', 'delete_databases' => 'Also delete its databases', 'delete_ftp' => 'Also delete its FTP accounts']),
            self::e('POST', '/websites/{website}/start', 'Website@start', 'websites:write', 'Start a website', tag: 'Websites'),
            self::e('POST', '/websites/{website}/stop', 'Website@stop', 'websites:write', 'Stop a website', tag: 'Websites'),
            self::e('POST', '/websites/{website}/ssl', 'Website@ssl', 'websites:write', 'Issue a Let\'s Encrypt certificate', tag: 'Websites', task: true,
                body: ['email' => 'Contact address [required]', 'method' => 'http (default) or dns', 'include_aliases' => 'Include the aliases (default true)', 'wildcard' => 'Wildcard certificate (needs method dns)']),
            self::e('DELETE', '/websites/{website}/ssl', 'Website@sslDisable', 'websites:write', 'Turn SSL off', tag: 'Websites'),
            self::e('GET', '/websites/{website}/settings/{section}', 'Website@settings', 'websites:read', 'Read one settings section (rewrite, composer, subdirs, git-key)', tag: 'Websites'),
            self::e('POST', '/websites/{website}/settings/{section}', 'Website@saveSettings', 'websites:write', 'Change one settings section of a website', tag: 'Websites',
                body: ['action' => 'Action inside the section (default save)', '...' => 'The fields of that section, as in the panel']),
            self::e('GET', '/websites/{website}/backups', 'Website@backups', 'websites:read', 'Backups of a website', tag: 'Websites'),
            self::e('POST', '/websites/{website}/backups', 'Website@backup', 'websites:write', 'Back up a website now', tag: 'Websites', task: true,
                body: ['databases' => 'Include the linked databases (default true)', 'storage_id' => 'Also send it to this storage', 'delete_local' => 'Delete the local copy after the upload']),
            self::e('POST', '/websites/{website}/backups/restore', 'Website@restore', 'websites:write', 'Restore a backup over the website', tag: 'Websites', task: true,
                body: ['file' => 'File name as listed in the backups [required]']),
            self::e('DELETE', '/websites/{website}/backups', 'Website@deleteBackup', 'websites:write', 'Delete a backup of the website', tag: 'Websites',
                body: ['file' => 'File name [required]']),
            self::e('GET', '/websites/{website}/logs', 'Website@logs', 'websites:read', 'Access or error log of a website', tag: 'Websites',
                query: ['type' => 'error (default) or access', 'lines' => 'Number of lines (max 5000)', 'filter' => 'Only lines containing this text']),
            self::e('GET', '/websites/{website}/usage', 'Website@usage', 'websites:read', 'Requests, visitors and bandwidth of a website', tag: 'Websites',
                query: ['range' => 'today, 24h, 7d or all']),

            // ----------------------------------------------------- databases
            self::e('GET', '/databases', 'Database@index', 'databases:read', 'List databases', tag: 'Databases',
                query: ['engine' => 'mysql, pgsql, mongodb or sqlserver', 'search' => 'Filter by name', 'client_id' => 'Only databases of a client', 'page' => 'Page number', 'per_page' => 'Rows per page']),
            self::e('POST', '/databases', 'Database@store', 'databases:write', 'Create a database with its user', tag: 'Databases',
                body: ['name' => 'Database name [required]', 'username' => 'User name [required]', 'password' => 'Password [required]', 'engine' => 'mysql (default), pgsql, mongodb', 'website_id' => 'Website it belongs to', 'charset' => 'utf8mb4 (default), utf8, latin1', 'hosts' => 'Hosts allowed to connect', 'notes' => 'Remark']),
            self::e('GET', '/databases/{database}', 'Database@show', 'databases:read', 'One database', tag: 'Databases'),
            self::e('GET', '/databases/{database}/credentials', 'Database@credentials', 'databases:read', 'Connection details including the password', tag: 'Databases'),
            self::e('POST', '/databases/{database}/password', 'Database@password', 'databases:write', 'Change the password of the database user', tag: 'Databases',
                body: ['password' => 'New password [required]']),
            self::e('DELETE', '/databases/{database}', 'Database@destroy', 'databases:write', 'Delete a database', tag: 'Databases',
                body: ['recycle' => 'Keep a dump in the recycle bin first (default true)']),
            self::e('GET', '/databases/{database}/backups', 'Database@backups', 'databases:read', 'Backups of a database', tag: 'Databases'),
            self::e('POST', '/databases/{database}/backups', 'Database@backup', 'databases:write', 'Back up a database now', tag: 'Databases', task: true),
            self::e('POST', '/databases/{database}/restore', 'Database@restore', 'databases:write', 'Load a backup into the database', tag: 'Databases', task: true,
                body: ['file' => 'Backup file name [required]']),

            // ----------------------------------------------------------- ftp
            self::e('GET', '/ftp', 'Ftp@index', 'ftp:read', 'List FTP accounts', tag: 'FTP', query: ['website_id' => 'Only accounts of a website']),
            self::e('POST', '/ftp', 'Ftp@store', 'ftp:write', 'Create an FTP account', tag: 'FTP',
                body: ['username' => 'User name [required]', 'password' => 'Password [required]', 'path' => 'Folder [required]', 'website_id' => 'Website it belongs to', 'notes' => 'Remark']),
            self::e('PATCH', '/ftp/{ftp}', 'Ftp@update', 'ftp:write', 'Change the folder, password or remark', tag: 'FTP',
                body: ['path' => 'Folder [required]', 'password' => 'New password', 'notes' => 'Remark']),
            self::e('POST', '/ftp/{ftp}/toggle', 'Ftp@toggle', 'ftp:write', 'Enable or disable an account', tag: 'FTP'),
            self::e('DELETE', '/ftp/{ftp}', 'Ftp@destroy', 'ftp:write', 'Delete an FTP account', tag: 'FTP'),

            // ----------------------------------------------------------- dns
            self::e('GET', '/dns/providers', 'Dns@providers', 'dns:read', 'DNS accounts configured in the panel', tag: 'DNS'),
            self::e('GET', '/dns/zones', 'Dns@zones', 'dns:read', 'Domains of every DNS account', tag: 'DNS'),
            self::e('GET', '/dns/zones/{zone}/records', 'Dns@records', 'dns:read', 'Records of a domain, read live at the provider', tag: 'DNS'),
            self::e('POST', '/dns/zones/{zone}/records', 'Dns@recordStore', 'dns:write', 'Create a record', tag: 'DNS',
                body: ['type' => 'A, AAAA, CNAME, MX, TXT, CAA or NS [required]', 'name' => 'Name or @ for the domain itself [required]', 'content' => 'Value [required]', 'ttl' => 'Seconds', 'priority' => 'MX priority', 'proxied' => 'Cloudflare proxy']),
            self::e('PATCH', '/dns/zones/{zone}/records/{record}', 'Dns@recordUpdate', 'dns:write', 'Change a record', tag: 'DNS',
                body: ['type' => 'Record type', 'name' => 'Name', 'content' => 'Value', 'ttl' => 'Seconds', 'priority' => 'MX priority', 'proxied' => 'Cloudflare proxy']),
            self::e('DELETE', '/dns/zones/{zone}/records/{record}', 'Dns@recordDestroy', 'dns:write', 'Delete a record', tag: 'DNS',
                body: ['type' => 'Record type (some providers need it)', 'name' => 'Record name']),
            self::e('POST', '/dns/zones/{zone}/point', 'Dns@point', 'dns:write', 'Point the domain and www to an address', tag: 'DNS',
                body: ['hosts' => 'Host names to point (default: the domain and www)', 'www' => 'Also point www when no hosts are given (default true)', 'ipv6' => 'Also create the AAAA record (default true)', 'proxied' => 'Cloudflare proxy']),

            // ------------------------------------------------------- clients
            self::e('GET', '/clients', 'Client@index', 'clients:read', 'List client accounts', tag: 'Clients',
                query: ['search' => 'Filter by user name, name or e-mail', 'status' => 'active or suspended', 'page' => 'Page number', 'per_page' => 'Rows per page']),
            self::e('POST', '/clients', 'Client@store', 'clients:write', 'Create a client account', tag: 'Clients',
                body: ['username' => 'User name [required]', 'password' => 'Password [required]', 'name' => 'Full name', 'email' => 'E-mail', 'package_id' => 'Package', 'expires_at' => 'Expiration date', 'notes' => 'Remark']),
            self::e('GET', '/clients/{client}', 'Client@show', 'clients:read', 'One client with its usage and limits', tag: 'Clients'),
            self::e('PATCH', '/clients/{client}', 'Client@update', 'clients:write', 'Change a client account', tag: 'Clients',
                body: ['name' => 'Full name', 'email' => 'E-mail', 'password' => 'New password', 'package_id' => 'Package', 'expires_at' => 'Expiration date', 'notes' => 'Remark']),
            self::e('DELETE', '/clients/{client}', 'Client@destroy', 'clients:write', 'Delete a client (its resources stay and return to the administrator)', tag: 'Clients'),
            self::e('POST', '/clients/{client}/suspend', 'Client@suspend', 'clients:write', 'Suspend a client: its websites stop and FTP is disabled', tag: 'Clients',
                body: ['reason' => 'Reason shown to the client']),
            self::e('POST', '/clients/{client}/unsuspend', 'Client@unsuspend', 'clients:write', 'Reactivate a client', tag: 'Clients'),
            self::e('GET', '/clients/{client}/resources', 'Client@resources', 'clients:read', 'Resources of a client and what can still be assigned', tag: 'Clients'),
            self::e('POST', '/clients/{client}/resources', 'Client@assign', 'clients:write', 'Assign or release resources of a client', tag: 'Clients',
                body: ['type' => 'websites, databases, ftp, cron or dns [required]', 'ids' => 'List of ids [required]', 'attach' => 'true assigns them to the client, false releases them (default true)']),
            self::e('GET', '/clients/packages', 'Client@packages', 'clients:read', 'Hosting packages', tag: 'Clients'),
            self::e('POST', '/clients/packages', 'Client@packageStore', 'clients:write', 'Create a package', tag: 'Clients',
                body: ['name' => 'Name [required]', 'max_websites' => '0 = unlimited', 'max_databases' => '0 = unlimited', 'max_ftp' => '0 = unlimited', 'disk_mb' => '0 = unlimited', 'bandwidth_mb' => '0 = unlimited', 'php_versions' => 'Allowed PHP versions', 'allow_ssl' => 'Let the client issue SSL']),

            // -------------------------------------------------------- backup
            self::e('GET', '/backup/storages', 'Backup@storages', 'backup:read', 'Remote storages', tag: 'Backup'),
            self::e('GET', '/backup/local', 'Backup@local', 'backup:read', 'Backups stored on this server', tag: 'Backup',
                query: ['type' => 'site, database or path']),
            self::e('POST', '/backup/upload', 'Backup@upload', 'backup:write', 'Send a local backup to a storage', tag: 'Backup',
                body: ['file' => 'Backup file [required]', 'storage_id' => 'Destination [required]', 'keep' => 'Copies to keep at the destination', 'delete_local' => 'Delete the local copy afterwards']),
            self::e('GET', '/backup/storages/{storage}/browse', 'Backup@browse', 'backup:read', 'What is stored at a destination', tag: 'Backup',
                query: ['path' => 'Folder inside the storage']),
            self::e('POST', '/backup/storages/{storage}/fetch', 'Backup@fetch', 'backup:write', 'Bring a backup back to this server, optionally restoring it', tag: 'Backup',
                body: ['path' => 'Path at the destination [required]', 'restore' => 'Restore it after the download']),
            self::e('GET', '/backup/transfers', 'Backup@transfers', 'backup:read', 'Uploads and downloads with their state', tag: 'Backup',
                query: ['status' => 'active, failed or success']),
            self::e('POST', '/backup/transfers/{transfer}/retry', 'Backup@retry', 'backup:write', 'Start a transfer again', tag: 'Backup'),
            self::e('POST', '/backup/transfers/{transfer}/cancel', 'Backup@cancel', 'backup:write', 'Stop a running transfer', tag: 'Backup'),

            // ---------------------------------------------------------- cron
            self::e('GET', '/cron', 'Cron@index', 'cron:read', 'Scheduled tasks', tag: 'Cron'),
            self::e('GET', '/cron/{cron}', 'Cron@show', 'cron:read', 'One scheduled task with its cycles', tag: 'Cron'),
            self::e('GET', '/cron/{cron}/log', 'Cron@log', 'cron:read', 'Log of the last runs', tag: 'Cron'),
            self::e('POST', '/cron/{cron}/run', 'Cron@run', 'cron:write', 'Run a scheduled task now', tag: 'Cron'),
            self::e('POST', '/cron/{cron}/toggle', 'Cron@toggle', 'cron:write', 'Enable or disable a scheduled task', tag: 'Cron'),
            self::e('DELETE', '/cron/{cron}', 'Cron@destroy', 'cron:write', 'Delete a scheduled task', tag: 'Cron'),

            // --------------------------------------------------------- files
            self::e('GET', '/files', 'File@index', 'files:read', 'List a folder', tag: 'Files', query: ['path' => 'Folder [required]']),
            self::e('GET', '/files/read', 'File@read', 'files:read', 'Read a text file', tag: 'Files', query: ['path' => 'File [required]']),
            self::e('GET', '/files/download', 'File@download', 'files:read', 'Download a file', tag: 'Files', query: ['path' => 'File [required]']),
            self::e('POST', '/files/write', 'File@write', 'files:write', 'Write a text file', tag: 'Files',
                body: ['path' => 'File [required]', 'content' => 'Content [required]']),
            self::e('POST', '/files/create', 'File@create', 'files:write', 'Create a file or folder', tag: 'Files',
                body: ['dir' => 'Parent folder [required]', 'name' => 'Name [required]', 'type' => 'file or dir [required]']),
            self::e('POST', '/files/rename', 'File@rename', 'files:write', 'Rename a file or folder', tag: 'Files',
                body: ['path' => 'Current path [required]', 'name' => 'New name [required]']),
            self::e('DELETE', '/files', 'File@destroy', 'files:write', 'Delete files or folders', tag: 'Files',
                body: ['paths' => 'List of paths [required]']),
            self::e('POST', '/files/compress', 'File@compress', 'files:write', 'Create a .zip or .tar.gz', tag: 'Files',
                body: ['dir' => 'Folder [required]', 'names' => 'Items inside it [required]', 'archive' => 'Archive name [required]']),
            self::e('POST', '/files/extract', 'File@extract', 'files:write', 'Extract an archive', tag: 'Files',
                body: ['path' => 'Archive [required]', 'destination' => 'Target folder [required]']),

            // ------------------------------------------------------ security
            self::e('GET', '/security/firewall', 'Security@firewall', 'security:read', 'Firewall state, rules and blocked addresses', tag: 'Security'),
            self::e('POST', '/security/firewall/rules', 'Security@addRule', 'security:write', 'Open or close a port', tag: 'Security',
                body: ['port' => 'Port or range [required]', 'protocol' => 'tcp, udp or both', 'action' => 'allow or deny', 'source' => 'Address or range the rule applies to', 'notes' => 'Remark']),
            self::e('DELETE', '/security/firewall/rules/{number}', 'Security@deleteRule', 'security:write', 'Delete a firewall rule by its number', tag: 'Security'),
            self::e('POST', '/security/block', 'Security@block', 'security:write', 'Block or unblock an IP address', tag: 'Security',
                body: ['ip' => 'Address [required]', 'notes' => 'Remark shown in the firewall rule']),
            self::e('POST', '/security/fail2ban/unban', 'Security@unban', 'security:write', 'Release an address banned by Fail2ban', tag: 'Security',
                body: ['ip' => 'Address [required]', 'jail' => 'Jail name [required]']),

            // --------------------------------------------------------- tasks
            self::e('GET', '/tasks', 'Task@index', 'tasks:read', 'Background tasks', tag: 'Tasks',
                query: ['status' => 'queued, running, success or failed', 'per_page' => 'Rows per page']),
            self::e('GET', '/tasks/{task}', 'Task@show', 'tasks:read', 'One task with its output', tag: 'Tasks',
                query: ['offset' => 'Read the output from this position']),

            // ------------------------------------------------------ webhooks
            self::e('GET', '/webhooks', 'Webhook@index', 'webhooks:read', 'Webhooks and the events they listen to', tag: 'Webhooks'),
            self::e('POST', '/webhooks', 'Webhook@store', 'webhooks:write', 'Create a webhook (the secret is returned once)', tag: 'Webhooks',
                body: ['name' => 'Name [required]', 'url' => 'URL called by the panel [required]', 'events' => 'List of events, or ["*"] for all [required]']),
            self::e('POST', '/webhooks/{webhook}/test', 'Webhook@test', 'webhooks:write', 'Send a test message', tag: 'Webhooks'),
            self::e('DELETE', '/webhooks/{webhook}', 'Webhook@destroy', 'webhooks:write', 'Delete a webhook', tag: 'Webhooks'),
        ];
    }

    /** @return array<string, mixed> */
    protected static function e(string $method, string $path, string $action, ?string $scope, string $summary, string $tag = 'Other', array $query = [], array $body = [], bool $task = false): array
    {
        return compact('method', 'path', 'action', 'scope', 'summary', 'tag', 'query', 'body', 'task');
    }

    /** Endpoints grouped by tag, for the documentation page. */
    public static function byTag(): array
    {
        $groups = [];
        foreach (self::endpoints() as $endpoint) {
            $groups[$endpoint['tag']][] = $endpoint;
        }

        return $groups;
    }

    public static function scopesByGroup(): array
    {
        $groups = [];
        foreach (self::SCOPES as $scope => $label) {
            $groups[explode(':', $scope)[0]][$scope] = $label;
        }

        return $groups;
    }
}
