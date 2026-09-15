<?php

namespace App\Services\Ai\Tools;

use App\Models\FtpAccount;
use App\Models\MysqlDatabase;
use App\Models\Website;
use App\Services\FtpManager;
use App\Services\MysqlManager;
use App\Services\Shell;
use App\Services\SystemStats;
use Illuminate\Support\Str;

/** MySQL / MariaDB databases and FTP accounts. */
class DatabaseTools extends ToolGroup
{
    public function __construct(protected MysqlManager $mysql, protected FtpManager $ftp) {}

    public function tools(): array
    {
        return [
            'list_databases' => self::tool('MySQL/MariaDB databases with size, tables, user and linked website, plus server databases not managed by the panel.', self::params(), false, false, fn () => 'Listed databases'),
            'query_database' => self::tool('Run a read-only SQL query (SELECT, SHOW, EXPLAIN, DESCRIBE) on a database. Max 200 rows.', self::params(['database' => self::str('Database name'), 'sql' => self::str('Single read-only SQL statement')], ['database', 'sql']), false, false, fn ($a) => 'Queried '.self::labelArg($a, 'database')),
            'create_database' => self::tool('Create a database and its user. A strong password is generated when omitted and returned in the result.', self::params(['name' => self::str('Database name (letters, numbers, underscore)'), 'username' => self::str('User, default = name'), 'password' => self::str('Password (optional)'), 'host' => self::str('localhost (default), 127.0.0.1 or %', ['localhost', '127.0.0.1', '%']), 'website' => self::str('Link to website domain')], ['name']), true, false, fn ($a) => 'Create database '.self::labelArg($a, 'name')),
            'delete_database' => self::tool('Drop a database and its user permanently.', self::params(['name' => self::str('Database name')], ['name']), true, false, fn ($a) => 'Delete database '.self::labelArg($a, 'name')),
            'change_database_password' => self::tool('Change the password of a database user (generated when omitted).', self::params(['name' => self::str('Database name'), 'password' => self::str('New password (optional)')], ['name']), true, false, fn ($a) => 'Change password of database '.self::labelArg($a, 'name')),

            'list_ftp_accounts' => self::tool('FTP accounts with directory and status.', self::params(), false, false, fn () => 'Listed FTP accounts'),
            'manage_ftp_account' => self::tool('Create, delete, enable, disable or change password/directory of a Pure-FTPd account.', self::params(['action' => self::str('Action', ['create', 'delete', 'enable', 'disable', 'change_password', 'change_path']), 'username' => self::str('FTP username'), 'password' => self::str('Password (generated when omitted on create/change_password)'), 'path' => self::str('Directory, e.g. /www/wwwroot/example.com')], ['action', 'username']), true, false, fn ($a) => str_replace('_', ' ', ucfirst(self::labelArg($a, 'action'))).' FTP '.self::labelArg($a, 'username')),
        ];
    }

    public function handle(string $name, array $a): mixed
    {
        switch ($name) {
            case 'list_databases':
                $live = $this->mysql->installed() ? $this->mysql->databases() : [];

                return [
                    'server_installed' => $this->mysql->installed(),
                    'version' => $this->mysql->installed() ? $this->mysql->version() : null,
                    'databases' => MysqlDatabase::query()->with('website:id,domain')->get()->map(fn ($d) => ['name' => $d->name, 'user' => $d->username.'@'.$d->host, 'website' => $d->website?->domain, 'exists' => isset($live[$d->name]), 'size' => isset($live[$d->name]) ? SystemStats::bytes($live[$d->name]['size']) : null, 'tables' => $live[$d->name]['tables'] ?? null]),
                    'unmanaged' => array_values(array_diff(array_keys($live), MysqlDatabase::query()->pluck('name')->all())),
                ];

            case 'query_database':
                $db = (string) $a['database'];
                $sql = trim((string) $a['sql']);
                $sql = rtrim($sql, "; \n\t");
                if (! MysqlManager::validIdentifier($db)) {
                    return ['error' => 'Invalid database name'];
                }
                if (! preg_match('/^(select|show|explain|describe|desc)\b/i', $sql) || str_contains($sql, ';')
                    || preg_match('/\b(into\s+(out|dump)file|load_file|sleep\s*\(|benchmark\s*\(|for\s+update|lock\s+in)\b/i', $sql)) {
                    return ['error' => 'Only a single SELECT/SHOW/EXPLAIN/DESCRIBE statement is allowed.'];
                }
                if (preg_match('/^select\b/i', $sql) && ! preg_match('/\blimit\s+\d+/i', $sql)) {
                    $sql .= ' LIMIT 200';
                }
                if (Shell::simulating()) {
                    return "id\tname\n1\tdemo";
                }
                $r = Shell::run('mysql --batch --default-character-set=utf8mb4 '.Shell::arg($db), 60, $sql.';');

                return $r->ok() ? Str::limit($r->output, 20000) : ['error' => $r->message()];

            case 'create_database':
                $dbName = (string) $a['name'];
                $user = (string) (self::a($a, 'username') ?: $dbName);
                $password = (string) (self::a($a, 'password') ?: Str::password(20, symbols: false));
                $host = in_array(self::a($a, 'host'), ['localhost', '127.0.0.1', '%'], true) ? self::a($a, 'host') : 'localhost';
                if (MysqlDatabase::query()->where('name', $dbName)->exists()) {
                    return ['ok' => false, 'error' => 'A database with this name already exists.'];
                }
                $r = $this->mysql->create($dbName, $user, $password, 'utf8mb4', $host);
                if ($r->failed()) {
                    return ['ok' => false, 'error' => $r->message()];
                }
                $site = self::a($a, 'website') ? Website::query()->where('domain', strtolower(self::a($a, 'website')))->first() : null;
                MysqlDatabase::query()->create(['name' => $dbName, 'username' => $user, 'password' => $password, 'host' => $host, 'website_id' => $site?->id, 'notes' => 'Created by AI assistant']);

                return ['ok' => true, 'database' => $dbName, 'username' => $user, 'password' => $password, 'host' => $host === '%' ? 'any' : 'localhost'];

            case 'delete_database':
                $db = MysqlDatabase::query()->where('name', $a['name'])->first();
                if (! $db) {
                    return ['error' => 'Database not managed by the panel.'];
                }
                $r = $this->mysql->drop($db->name, $db->username, $db->host);
                if ($r->failed()) {
                    return ['ok' => false, 'error' => $r->message()];
                }
                $db->delete();

                return ['ok' => true, 'message' => "Database {$a['name']} dropped"];

            case 'change_database_password':
                $db = MysqlDatabase::query()->where('name', $a['name'])->first();
                if (! $db) {
                    return ['error' => 'Database not managed by the panel.'];
                }
                $password = (string) (self::a($a, 'password') ?: Str::password(20, symbols: false));
                $r = $this->mysql->changePassword($db->username, $password, $db->host);
                if ($r->failed()) {
                    return ['ok' => false, 'error' => $r->message()];
                }
                $db->update(['password' => $password]);

                return ['ok' => true, 'username' => $db->username, 'password' => $password];

            case 'list_ftp_accounts':
                return ['server_installed' => $this->ftp->installed(), 'accounts' => FtpAccount::query()->with('website:id,domain')->get()->map(fn ($f) => ['username' => $f->username, 'path' => $f->path, 'active' => $f->is_active, 'website' => $f->website?->domain])];

            case 'manage_ftp_account':
                return $this->ftpAction($a);
        }

        return ['error' => "Unknown tool {$name}"];
    }

    protected function ftpAction(array $a): array
    {
        $username = (string) $a['username'];
        $account = FtpAccount::query()->where('username', $username)->first();
        $action = (string) $a['action'];

        if ($action !== 'create' && ! $account) {
            return ['error' => 'FTP account not found.'];
        }

        switch ($action) {
            case 'create':
                if ($account) {
                    return ['error' => 'This username already exists.'];
                }
                $path = (string) self::a($a, 'path', '');
                if ($path === '') {
                    return ['error' => 'path is required'];
                }
                $password = (string) (self::a($a, 'password') ?: Str::password(16, symbols: false));
                $r = $this->ftp->create($username, $password, $path);
                if ($r->failed()) {
                    return ['ok' => false, 'error' => $r->message()];
                }
                FtpAccount::query()->create(['username' => $username, 'password' => $password, 'path' => $path, 'notes' => 'Created by AI assistant']);

                return ['ok' => true, 'username' => $username, 'password' => $password, 'path' => $path, 'port' => 21];

            case 'delete':
                $this->ftp->delete($username);
                $account->delete();

                return ['ok' => true, 'message' => 'FTP account deleted'];

            case 'enable':
            case 'disable':
                $r = $this->ftp->setActive($username, $action === 'enable');
                if ($r->ok()) {
                    $account->update(['is_active' => $action === 'enable']);
                }

                return $this->shell($r, 'Account '.$action.'d');

            case 'change_password':
                $password = (string) (self::a($a, 'password') ?: Str::password(16, symbols: false));
                $r = $this->ftp->changePassword($username, $password);
                if ($r->ok()) {
                    $account->update(['password' => $password]);
                }

                return $r->ok() ? ['ok' => true, 'username' => $username, 'password' => $password] : ['ok' => false, 'error' => $r->message()];

            case 'change_path':
                $r = $this->ftp->changePath($username, (string) $a['path']);
                if ($r->ok()) {
                    $account->update(['path' => $a['path']]);
                }

                return $this->shell($r, 'Directory changed');
        }

        return ['error' => 'Unknown action'];
    }
}
