<?php

namespace App\Services\Databases;

use App\Models\DbServer;
use App\Models\Setting;
use App\Services\Shell;
use App\Services\ShellResult;

/**
 * PostgreSQL. Local commands run as the "postgres" system user (peer authentication);
 * remote servers use a temporary PGPASSFILE.
 */
class PostgresEngine extends DatabaseEngine
{
    public function key(): string
    {
        return 'pgsql';
    }

    public function label(): string
    {
        return 'PostgreSQL';
    }

    public function defaultPort(): int
    {
        return 5432;
    }

    public function defaultUser(): string
    {
        return 'postgres';
    }

    public function package(): ?string
    {
        return 'postgresql';
    }

    public function service(): ?string
    {
        return 'postgresql';
    }

    protected function features(): array
    {
        return ['root', 'backup', 'tools', 'admin_tool'];
    }

    public static function validIdentifier(string $name): bool
    {
        // lowercase only: PostgreSQL folds unquoted identifiers
        return (bool) preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $name);
    }

    public static function ident(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    public function installed(): bool
    {
        return Shell::simulating() || Shell::commandExists('psql') && Shell::test('id postgres >/dev/null 2>&1');
    }

    /** psql command prefix and the temporary files it needs. @return array{0: string, 1: list<string>} */
    protected function client(string $binary, ?DbServer $server, ?string $database = null): array
    {
        $db = $database ? ' -d '.Shell::arg($database) : '';
        if (! $server) {
            return ['runuser -u postgres -- '.$binary.$db, []];
        }
        $escape = fn ($v) => str_replace(['\\', ':'], ['\\\\', '\\:'], (string) $v);
        $pass = Shell::secretFile("{$escape($server->host)}:{$server->port}:*:{$escape($server->username)}:{$escape($server->password)}\n");

        return ['PGPASSFILE='.Shell::arg($pass).' PGCONNECT_TIMEOUT=10 '.$binary.' -h '.Shell::arg($server->host).' -p '.(int) $server->port.' -U '.Shell::arg((string) $server->username).' -w'.($database ? $db : ' -d postgres'), [$pass]];
    }

    public function query(string $sql, ?DbServer $server = null, ?string $database = null, int $timeout = 60): ShellResult
    {
        if (Shell::simulating()) {
            return new ShellResult(0, '', '');
        }
        [$psql, $files] = $this->client('psql', $server, $database);
        $cleanup = $files ? '; rc=$?; rm -f '.implode(' ', array_map(fn ($f) => Shell::arg($f), $files)).'; exit $rc' : '';

        return Shell::run('cd /tmp && '.$psql.' -X -q -A -t -F "|" -v ON_ERROR_STOP=1'.$cleanup, $timeout, $sql);
    }

    public function version(?DbServer $server = null): ?string
    {
        if (Shell::simulating()) {
            return $server ? '15.8' : '16.4';
        }
        $r = $this->query('SHOW server_version;', $server, null, 20);

        return $r->ok() && trim($r->output) !== '' ? trim(strtok($r->output, " \n")) : null;
    }

    public function databases(?DbServer $server = null): array
    {
        if (Shell::simulating()) {
            return $server ? ['analytics' => ['size' => 104857600, 'tables' => null]] : ['app_pg' => ['size' => 18350080, 'tables' => null], 'reports' => ['size' => 8421376, 'tables' => null]];
        }
        $rows = [];
        foreach ($this->query("SELECT datname, pg_database_size(datname) FROM pg_database WHERE datistemplate = false AND datname <> 'postgres' ORDER BY datname;", $server)->lines() as $line) {
            [$name, $size] = array_pad(explode('|', $line), 2, 0);
            $rows[$name] = ['size' => (int) $size, 'tables' => null];
        }

        return $rows;
    }

    public function create(string $name, string $user, string $password, array $options = [], ?DbServer $server = null): ShellResult
    {
        $this->assertName($name, $user);
        $u = self::ident($user);
        $sql = "DO \$\$BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = ".self::sqlString($user).") THEN CREATE ROLE {$u} LOGIN; END IF; END\$\$;\n"
            ."ALTER ROLE {$u} WITH LOGIN PASSWORD ".self::sqlString($password).";\n"
            .'CREATE DATABASE '.self::ident($name)." OWNER {$u} ENCODING 'UTF8';\n"
            .'REVOKE ALL ON DATABASE '.self::ident($name)." FROM PUBLIC;\n";

        return $this->query($sql, $server);
    }

    public function drop(string $name, ?string $user, ?DbServer $server = null): ShellResult
    {
        $this->assertName($name);
        $sql = "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ".self::sqlString($name)." AND pid <> pg_backend_pid();\n"
            .'DROP DATABASE IF EXISTS '.self::ident($name).";\n";
        if ($user && $user !== 'postgres') {
            $this->assertName($user);
            $sql .= 'DROP ROLE IF EXISTS '.self::ident($user).";\n";
        }

        return $this->query($sql, $server);
    }

    public function changePassword(string $name, string $user, string $password, ?DbServer $server = null, array $options = []): ShellResult
    {
        $this->assertName($user);

        return $this->query('ALTER ROLE '.self::ident($user).' WITH PASSWORD '.self::sqlString($password).';', $server);
    }

    public function rootPassword(string $password): ShellResult
    {
        $result = $this->query("ALTER ROLE postgres WITH PASSWORD ".self::sqlString($password).';');
        if ($result->ok()) {
            Setting::putSecret('pgsql_root_password', $password);
        }

        return $result;
    }

    public function tables(string $name, ?DbServer $server = null): array
    {
        $this->assertName($name);
        if (Shell::simulating()) {
            return [
                ['name' => 'public.accounts', 'engine' => 'table', 'rows' => 5210, 'size' => 1269760, 'collation' => '', 'updated' => null],
                ['name' => 'public.events', 'engine' => 'table', 'rows' => 480120, 'size' => 88473600, 'collation' => '', 'updated' => null],
            ];
        }
        $rows = [];
        $sql = 'SELECT schemaname || \'.\' || relname, n_live_tup, pg_total_relation_size(relid), COALESCE(to_char(GREATEST(last_vacuum, last_autovacuum), \'YYYY-MM-DD HH24:MI\'), \'\') FROM pg_stat_user_tables ORDER BY 1;';
        foreach ($this->query($sql, $server, $name)->lines() as $line) {
            $p = array_pad(explode('|', $line), 4, '');
            $rows[] = ['name' => $p[0], 'engine' => 'table', 'rows' => (int) $p[1], 'size' => (int) $p[2], 'collation' => '', 'updated' => $p[3] ?: null];
        }

        return $rows;
    }

    /** vacuum | analyze for the whole database. */
    public function maintenance(string $name, string $action, ?DbServer $server = null): ShellResult
    {
        $this->assertName($name);

        return $this->query($action === 'analyze' ? 'ANALYZE;' : 'VACUUM (ANALYZE);', $server, $name, 3600);
    }

    public function dumpExtension(): string
    {
        return 'sql.gz';
    }

    public function importExtensions(): array
    {
        return ['sql', 'gz', 'dump'];
    }

    public function dumpScript(string $name, string $file, ?DbServer $server = null): string
    {
        $this->assertName($name);
        [$dump, $files] = $this->client('pg_dump', $server, $name);

        return ($files ? $this->cleanup(...$files) : '')."cd /tmp\n{$dump} --no-password | gzip > ".Shell::arg($file);
    }

    public function importScript(string $name, string $file, ?DbServer $server = null): string
    {
        $this->assertName($name);
        [$psql, $files] = $this->client('psql', $server, $name);
        [$restore, $files2] = $this->client('pg_restore', $server, $name);
        $f = Shell::arg($file);

        // root reads the file and streams it, so the postgres user needs no access to the upload folder
        return "set -e\n".(($files || $files2) ? $this->cleanup(...$files, ...$files2) : '')."cd /tmp\n"
            ."case {$f} in *.gz) gunzip -c {$f} | {$psql} -v ON_ERROR_STOP=1 -q ;; *.dump) {$restore} --no-owner --clean --if-exists < {$f} ;; *) {$psql} -v ON_ERROR_STOP=1 -q < {$f} ;; esac\necho 'Import completed'";
    }
}
