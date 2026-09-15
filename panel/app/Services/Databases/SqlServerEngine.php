<?php

namespace App\Services\Databases;

use App\Models\DbServer;
use App\Services\Shell;
use App\Services\ShellResult;

/**
 * Microsoft SQL Server. Linux servers are usually remote or run in Docker, so every database
 * belongs to a server record. Queries use sqlcmd (mssql-tools18) on the host, or the sqlcmd
 * inside the local "gbx-mssql" container; the password is passed through SQLCMDPASSWORD.
 */
class SqlServerEngine extends DatabaseEngine
{
    public const CONTAINER = 'gbx-mssql';

    public const CONTAINER_BACKUP_DIR = '/var/opt/mssql/backup';

    public function key(): string
    {
        return 'sqlserver';
    }

    public function label(): string
    {
        return 'SQL Server';
    }

    public function defaultPort(): int
    {
        return 1433;
    }

    public function defaultUser(): string
    {
        return 'sa';
    }

    public function package(): ?string
    {
        return 'sqlserver';
    }

    protected function features(): array
    {
        return ['backup', 'remote_only'];
    }

    public static function ident(string $name): string
    {
        return '['.str_replace(']', ']]', $name).']';
    }

    public static function nstring(string $value): string
    {
        return "N'".str_replace("'", "''", $value)."'";
    }

    /** SQL Server has no local mode here: the Docker installation registers a server record. */
    public function installed(): bool
    {
        return false;
    }

    public function isLocalContainer(?DbServer $server): bool
    {
        if (! $server || ! in_array($server->host, ['127.0.0.1', 'localhost'], true)) {
            return false;
        }

        return Shell::simulating() || Shell::test('docker inspect '.self::CONTAINER.' >/dev/null 2>&1');
    }

    public function hostToolsInstalled(): bool
    {
        return Shell::simulating() || Shell::test('test -x /opt/mssql-tools18/bin/sqlcmd');
    }

    public function query(string $sql, DbServer $server, int $timeout = 60): ShellResult
    {
        if (Shell::simulating()) {
            return new ShellResult(0, '', '');
        }
        $pass = Shell::secretFile((string) $server->password);
        $args = '-C -b -h -1 -W -s "|" -l 10 -i /dev/stdin';
        $env = 'SQLCMDPASSWORD="$(cat '.Shell::arg($pass).')"';

        if ($this->isLocalContainer($server)) {
            $cmd = "{$env} docker exec -i -e SQLCMDPASSWORD ".self::CONTAINER.' /opt/mssql-tools18/bin/sqlcmd -S localhost -U '.Shell::arg((string) $server->username)." {$args}";
        } elseif ($this->hostToolsInstalled()) {
            $cmd = "{$env} /opt/mssql-tools18/bin/sqlcmd -S ".Shell::arg($server->host.','.$server->port).' -U '.Shell::arg((string) $server->username)." {$args}";
        } else {
            Shell::run('rm -f '.Shell::arg($pass));

            return new ShellResult(1, '', 'sqlcmd is not installed. Install "SQL Server command-line tools" in Home > Software.');
        }

        return Shell::run($cmd.'; rc=$?; rm -f '.Shell::arg($pass).'; exit $rc', $timeout, "SET NOCOUNT ON;\n".$sql."\nGO\n");
    }

    /** Shell script running $sql (used by background tasks). */
    public function queryScript(string $sql, DbServer $server): string
    {
        if (str_contains($sql, 'GBXSQL')) {
            throw new \InvalidArgumentException('Invalid SQL.');
        }
        $pass = Shell::secretFile((string) $server->password);
        $sqlcmd = $this->isLocalContainer($server)
            ? 'docker exec -i -e SQLCMDPASSWORD '.self::CONTAINER.' /opt/mssql-tools18/bin/sqlcmd -S localhost'
            : '/opt/mssql-tools18/bin/sqlcmd -S '.Shell::arg($server->host.','.$server->port);

        return $this->cleanup($pass).'SQLCMDPASSWORD="$(cat '.Shell::arg($pass).')" '.$sqlcmd.' -U '.Shell::arg((string) $server->username)." -C -b -l 30 -i /dev/stdin <<'GBXSQL'\n".$sql."\nGO\nGBXSQL";
    }

    protected function requireServer(?DbServer $server): DbServer
    {
        return $server ?? throw new \InvalidArgumentException('Choose a SQL Server (add one with Remote DB or install SQL Server in Home > Software).');
    }

    public function version(?DbServer $server = null): ?string
    {
        if (! $server) {
            return null;
        }
        if (Shell::simulating()) {
            return '16.0.4135.4';
        }
        $r = $this->query("SELECT CAST(SERVERPROPERTY('ProductVersion') AS nvarchar(64));", $server, 20);

        return $r->ok() ? trim($r->lines()[0] ?? '') ?: null : null;
    }

    public function databases(?DbServer $server = null): array
    {
        if (! $server) {
            return [];
        }
        if (Shell::simulating()) {
            return ['erp' => ['size' => 73741824, 'tables' => null]];
        }
        $rows = [];
        $sql = 'SELECT d.name, SUM(CAST(mf.size AS bigint)) * 8192 FROM sys.databases d JOIN sys.master_files mf ON mf.database_id = d.database_id WHERE d.database_id > 4 GROUP BY d.name ORDER BY d.name;';
        foreach ($this->query($sql, $server)->lines() as $line) {
            [$name, $size] = array_pad(explode('|', $line), 2, 0);
            $rows[trim($name)] = ['size' => (int) $size, 'tables' => null];
        }

        return $rows;
    }

    public function create(string $name, string $user, string $password, array $options = [], ?DbServer $server = null): ShellResult
    {
        $server = $this->requireServer($server);
        $this->assertName($name, $user);
        $u = self::ident($user);
        $sql = 'CREATE DATABASE '.self::ident($name).";\nGO\n"
            ."IF NOT EXISTS (SELECT 1 FROM sys.server_principals WHERE name = ".self::nstring($user).") CREATE LOGIN {$u} WITH PASSWORD = ".self::nstring($password).', CHECK_POLICY = OFF;'
            ." ELSE ALTER LOGIN {$u} WITH PASSWORD = ".self::nstring($password).";\nGO\n"
            .'USE '.self::ident($name).";\nCREATE USER {$u} FOR LOGIN {$u};\nALTER ROLE db_owner ADD MEMBER {$u};";

        return $this->query($sql, $server);
    }

    public function drop(string $name, ?string $user, ?DbServer $server = null): ShellResult
    {
        $server = $this->requireServer($server);
        $this->assertName($name);
        $n = self::ident($name);
        $sql = 'IF DB_ID('.self::nstring($name).") IS NOT NULL BEGIN ALTER DATABASE {$n} SET SINGLE_USER WITH ROLLBACK IMMEDIATE; DROP DATABASE {$n}; END;\n";
        if ($user && strtolower($user) !== 'sa') {
            $this->assertName($user);
            $sql .= "GO\nIF EXISTS (SELECT 1 FROM sys.server_principals WHERE name = ".self::nstring($user).') DROP LOGIN '.self::ident($user).';';
        }

        return $this->query($sql, $server);
    }

    public function changePassword(string $name, string $user, string $password, ?DbServer $server = null, array $options = []): ShellResult
    {
        $server = $this->requireServer($server);
        $this->assertName($user);

        return $this->query('ALTER LOGIN '.self::ident($user).' WITH PASSWORD = '.self::nstring($password).';', $server);
    }

    public function rootPassword(string $password): ShellResult
    {
        return new ShellResult(1, '', 'Change the sa password of SQL Server in its server settings.');
    }

    /** Backups need a folder shared with the server, which only the local container has. */
    public function canBackup(?DbServer $server): bool
    {
        return $this->isLocalContainer($server);
    }

    public function dumpExtension(): string
    {
        return 'bak';
    }

    public function importExtensions(): array
    {
        return ['bak'];
    }

    public function dumpScript(string $name, string $file, ?DbServer $server = null): string
    {
        throw new \LogicException('Use backupTask() for SQL Server.');
    }

    public function importScript(string $name, string $file, ?DbServer $server = null): string
    {
        throw new \LogicException('Use restoreSql() for SQL Server.');
    }

    public function backupSql(string $name, string $fileName): string
    {
        $this->assertName($name);

        return 'BACKUP DATABASE '.self::ident($name).' TO DISK = '.self::nstring(self::CONTAINER_BACKUP_DIR.'/'.$fileName).' WITH INIT, NAME = '.self::nstring($name.' backup').';';
    }

    public function restoreSql(string $name, string $fileName): string
    {
        $this->assertName($name);
        $n = self::ident($name);

        return 'IF DB_ID('.self::nstring($name).") IS NOT NULL ALTER DATABASE {$n} SET SINGLE_USER WITH ROLLBACK IMMEDIATE;\nGO\n"
            ."RESTORE DATABASE {$n} FROM DISK = ".self::nstring(self::CONTAINER_BACKUP_DIR.'/'.$fileName)." WITH REPLACE;\nGO\n"
            ."ALTER DATABASE {$n} SET MULTI_USER;";
    }
}
