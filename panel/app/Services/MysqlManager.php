<?php

namespace App\Services;

use App\Models\DbServer;
use App\Models\Setting;
use App\Services\Databases\DatabaseEngine;

/**
 * MySQL / MariaDB. The local server is reached with the "mysql" client as root (unix socket
 * authentication, or /root/.my.cnf once a root password is set); remote servers through a
 * temporary client option file so passwords never appear on a command line.
 */
class MysqlManager extends DatabaseEngine
{
    public function key(): string
    {
        return 'mysql';
    }

    public function label(): string
    {
        return 'MySQL';
    }

    public function defaultPort(): int
    {
        return 3306;
    }

    public function defaultUser(): string
    {
        return 'root';
    }

    public function package(): ?string
    {
        return 'mysql';
    }

    protected function features(): array
    {
        return ['root', 'backup', 'permission', 'tools', 'admin_tool', 'charset'];
    }

    public static function quote(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }

    public function installed(): bool
    {
        return Shell::simulating() || Shell::commandExists('mysql');
    }

    public function service(): string
    {
        if (Shell::simulating()) {
            return 'mysql';
        }

        return Shell::test('systemctl list-unit-files mariadb.service | grep -q mariadb', false) ? 'mariadb' : 'mysql';
    }

    /** "--defaults-extra-file=..." for a remote server (must be the first client option). */
    protected function clientFile(DbServer $server): string
    {
        $esc = fn ($v) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v).'"';

        return Shell::secretFile("[client]\nhost={$esc($server->host)}\nport={$server->port}\nuser={$esc($server->username)}\npassword={$esc($server->password)}\n", '.cnf');
    }

    public function query(string $sql, int $timeout = 60, ?DbServer $server = null): ShellResult
    {
        if (! $server) {
            return Shell::run('mysql --batch --skip-column-names --default-character-set=utf8mb4', $timeout, $sql);
        }
        if (Shell::simulating()) {
            return new ShellResult(0, '', '');
        }
        $file = $this->clientFile($server);

        return Shell::run('mysql --defaults-extra-file='.Shell::arg($file).' --connect-timeout=10 --batch --skip-column-names --default-character-set=utf8mb4; rc=$?; rm -f '.Shell::arg($file).'; exit $rc', $timeout, $sql);
    }

    public function version(?DbServer $server = null): ?string
    {
        if (Shell::simulating()) {
            return '8.0.45';
        }
        $r = $this->query('SELECT VERSION();', 20, $server);

        return $r->ok() ? trim($r->output) : null;
    }

    public function isMariaDb(?DbServer $server = null): bool
    {
        return str_contains(strtolower((string) $this->version($server)), 'mariadb');
    }

    public function databases(?DbServer $server = null): array
    {
        if (Shell::simulating()) {
            return $server ? ['app_remote' => ['size' => 7340032, 'tables' => 9]] : ['shop_db' => ['size' => 48203776, 'tables' => 42], 'blog' => ['size' => 9031680, 'tables' => 12]];
        }

        $sql = "SELECT s.SCHEMA_NAME, COALESCE(SUM(t.DATA_LENGTH + t.INDEX_LENGTH),0), COUNT(t.TABLE_NAME)
                FROM information_schema.SCHEMATA s LEFT JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = s.SCHEMA_NAME
                WHERE s.SCHEMA_NAME NOT IN ('mysql','information_schema','performance_schema','sys')
                GROUP BY s.SCHEMA_NAME;";
        $rows = [];
        foreach ($this->query($sql, 60, $server)->lines() as $line) {
            $p = explode("\t", $line);
            $rows[$p[0]] = ['size' => (int) ($p[1] ?? 0), 'tables' => (int) ($p[2] ?? 0)];
        }

        return $rows;
    }

    protected function collation(string $charset): string
    {
        return match ($charset) {
            'utf8' => 'utf8_general_ci',
            'latin1' => 'latin1_swedish_ci',
            default => 'utf8mb4_unicode_ci',
        };
    }

    public function create(string $name, string $user, string $password, array $options = [], ?DbServer $server = null): ShellResult
    {
        $this->assertName($name, $user);
        $charset = in_array($options['charset'] ?? 'utf8mb4', ['utf8mb4', 'utf8', 'latin1'], true) ? ($options['charset'] ?? 'utf8mb4') : 'utf8mb4';
        $hosts = $options['hosts'] ?? ['localhost'];

        $sql = "CREATE DATABASE `{$name}` CHARACTER SET {$charset} COLLATE {$this->collation($charset)};\n".$this->grantSql($name, $user, $password, $hosts).'FLUSH PRIVILEGES;';

        return $this->query($sql, 60, $server);
    }

    protected function grantSql(string $name, string $user, string $password, array $hosts): string
    {
        $sql = '';
        foreach ($hosts as $host) {
            $h = self::quote($host);
            $u = self::quote($user);
            $sql .= "CREATE USER IF NOT EXISTS {$u}@{$h} IDENTIFIED BY ".self::quote($password).";\n"
                ."ALTER USER {$u}@{$h} IDENTIFIED BY ".self::quote($password).";\n"
                ."GRANT ALL PRIVILEGES ON `{$name}`.* TO {$u}@{$h};\n";
        }

        return $sql;
    }

    public function drop(string $name, ?string $user, ?DbServer $server = null, array $hosts = ['localhost']): ShellResult
    {
        $this->assertName($name);
        $sql = "DROP DATABASE IF EXISTS `{$name}`;\n";
        if ($user) {
            $this->assertName($user);
            foreach ($this->userHosts($user, $server) ?: $hosts as $host) {
                $sql .= 'DROP USER IF EXISTS '.self::quote($user).'@'.self::quote($host).";\n";
            }
        }

        return $this->query($sql.'FLUSH PRIVILEGES;', 60, $server);
    }

    public function changePassword(string $name, string $user, string $password, ?DbServer $server = null, array $options = []): ShellResult
    {
        $this->assertName($user);
        $sql = '';
        foreach ($this->userHosts($user, $server) ?: ($options['hosts'] ?? ['localhost']) as $host) {
            $sql .= 'ALTER USER '.self::quote($user).'@'.self::quote($host).' IDENTIFIED BY '.self::quote($password).";\n";
        }

        return $this->query($sql.'FLUSH PRIVILEGES;', 60, $server);
    }

    /** Hosts the user currently exists for. */
    public function userHosts(string $user, ?DbServer $server = null): array
    {
        if (Shell::simulating()) {
            return [];
        }

        return $this->query('SELECT Host FROM mysql.user WHERE User = '.self::quote($user).';', 20, $server)->lines();
    }

    /** Replace the hosts a database user may connect from (localhost, %, IP addresses). */
    public function setHosts(string $name, string $user, string $password, array $hosts, ?DbServer $server = null): ShellResult
    {
        $this->assertName($name, $user);
        $sql = '';
        foreach (array_diff($this->userHosts($user, $server), $hosts) as $old) {
            $sql .= 'DROP USER IF EXISTS '.self::quote($user).'@'.self::quote($old).";\n";
        }

        return $this->query($sql.$this->grantSql($name, $user, $password, $hosts).'FLUSH PRIVILEGES;', 60, $server);
    }

    public function rootPassword(string $password): ShellResult
    {
        $sql = $this->isMariaDb()
            ? 'ALTER USER root@localhost IDENTIFIED VIA unix_socket OR mysql_native_password USING PASSWORD('.self::quote($password).');'
            : 'ALTER USER root@localhost IDENTIFIED WITH caching_sha2_password BY '.self::quote($password).';';
        $result = $this->query($sql.' FLUSH PRIVILEGES;');
        if ($result->failed()) {
            return $result;
        }
        // keep the panel (and root's shell) able to connect without a prompt
        Shell::writeFile('/root/.my.cnf', "[client]\nuser=root\npassword=\"".str_replace(['\\', '"'], ['\\\\', '\\"'], $password)."\"\n", '0600', 'root:root');
        Setting::putSecret('mysql_root_password', $password);

        return $result;
    }

    public function tables(string $name, ?DbServer $server = null): array
    {
        $this->assertName($name);
        if (Shell::simulating()) {
            return [
                ['name' => 'users', 'engine' => 'InnoDB', 'rows' => 1840, 'size' => 3276800, 'collation' => 'utf8mb4_unicode_ci', 'updated' => '2026-09-15 08:12:00'],
                ['name' => 'orders', 'engine' => 'InnoDB', 'rows' => 92810, 'size' => 41058304, 'collation' => 'utf8mb4_unicode_ci', 'updated' => '2026-09-15 09:40:11'],
                ['name' => 'sessions', 'engine' => 'MyISAM', 'rows' => 312, 'size' => 196608, 'collation' => 'utf8mb4_unicode_ci', 'updated' => null],
            ];
        }
        $sql = 'SELECT TABLE_NAME, IFNULL(ENGINE,\'VIEW\'), IFNULL(TABLE_ROWS,0), IFNULL(DATA_LENGTH+INDEX_LENGTH,0), IFNULL(TABLE_COLLATION,\'\'), IFNULL(UPDATE_TIME,\'\') FROM information_schema.TABLES WHERE TABLE_SCHEMA = '.self::quote($name).' ORDER BY TABLE_NAME;';
        $rows = [];
        foreach ($this->query($sql, 60, $server)->lines() as $line) {
            $p = array_pad(explode("\t", $line), 6, '');
            $rows[] = ['name' => $p[0], 'engine' => $p[1], 'rows' => (int) $p[2], 'size' => (int) $p[3], 'collation' => $p[4], 'updated' => $p[5] ?: null];
        }

        return $rows;
    }

    /** optimize | repair | analyze | innodb | myisam on the given tables. */
    public function tableAction(string $name, string $action, array $tables, ?DbServer $server = null): ShellResult
    {
        $this->assertName($name);
        $list = [];
        foreach ($tables as $table) {
            if (! preg_match('/^[\w$\-]{1,64}$/', (string) $table)) {
                throw new \InvalidArgumentException('Invalid table name.');
            }
            $list[] = "`{$name}`.`{$table}`";
        }
        if (! $list) {
            return new ShellResult(1, '', 'Select at least one table.');
        }

        $sql = match ($action) {
            'optimize' => 'OPTIMIZE TABLE '.implode(', ', $list).';',
            'repair' => 'REPAIR TABLE '.implode(', ', $list).';',
            'analyze' => 'ANALYZE TABLE '.implode(', ', $list).';',
            'innodb', 'myisam' => implode("\n", array_map(fn ($t) => "ALTER TABLE {$t} ENGINE=".($action === 'innodb' ? 'InnoDB' : 'MyISAM').';', $list)),
            default => throw new \InvalidArgumentException('Unknown action.'),
        };

        return $this->query($sql, 1800, $server);
    }

    public function dumpExtension(): string
    {
        return 'sql.gz';
    }

    public function importExtensions(): array
    {
        return ['sql', 'gz', 'zip'];
    }

    public function dumpScript(string $name, string $file, ?DbServer $server = null): string
    {
        $this->assertName($name);
        $opt = '';
        $script = '';
        if ($server) {
            $cnf = $this->clientFile($server);
            $opt = '--defaults-extra-file='.Shell::arg($cnf).' ';
            $script = $this->cleanup($cnf);
        }

        return $script.'mysqldump '.$opt.'--single-transaction --routines --triggers --default-character-set=utf8mb4 '.Shell::arg($name).' | gzip > '.Shell::arg($file);
    }

    public function importScript(string $name, string $file, ?DbServer $server = null): string
    {
        $this->assertName($name);
        $f = Shell::arg($file);
        $mysql = 'mysql';
        $script = "set -e\n";
        if ($server) {
            $cnf = $this->clientFile($server);
            $mysql .= ' --defaults-extra-file='.Shell::arg($cnf);
            $script .= $this->cleanup($cnf);
        }
        $mysql .= ' '.Shell::arg($name);

        return $script."case {$f} in *.gz) gunzip -c {$f} | {$mysql} ;; *.zip) unzip -p {$f} | {$mysql} ;; *) {$mysql} < {$f} ;; esac\necho 'Import completed'";
    }

    /** Backwards compatible list of every MySQL dump. */
    public function backups(?string $database = null): array
    {
        return parent::backups($database);
    }
}
