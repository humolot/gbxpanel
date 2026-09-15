<?php

namespace App\Services;

/**
 * MySQL / MariaDB management through the local "mysql" client as root
 * (unix socket authentication, default on Ubuntu/Debian).
 */
class MysqlManager
{
    public static function validIdentifier(string $name): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_]{1,64}$/', $name);
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

    public function query(string $sql, int $timeout = 60): ShellResult
    {
        return Shell::run('mysql --batch --skip-column-names --default-character-set=utf8mb4', $timeout, $sql);
    }

    public function version(): ?string
    {
        if (Shell::simulating()) {
            return '8.0.45';
        }
        $r = $this->query('SELECT VERSION();');

        return $r->ok() ? trim($r->output) : null;
    }

    /** Databases with size (bytes) and table count. */
    public function databases(): array
    {
        if (Shell::simulating()) {
            return ['shop_db' => ['size' => 48203776, 'tables' => 42], 'blog' => ['size' => 9031680, 'tables' => 12]];
        }

        $sql = "SELECT s.SCHEMA_NAME, COALESCE(SUM(t.DATA_LENGTH + t.INDEX_LENGTH),0), COUNT(t.TABLE_NAME)
                FROM information_schema.SCHEMATA s LEFT JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = s.SCHEMA_NAME
                WHERE s.SCHEMA_NAME NOT IN ('mysql','information_schema','performance_schema','sys')
                GROUP BY s.SCHEMA_NAME;";
        $rows = [];
        foreach ($this->query($sql)->lines() as $line) {
            $p = explode("\t", $line);
            $rows[$p[0]] = ['size' => (int) ($p[1] ?? 0), 'tables' => (int) ($p[2] ?? 0)];
        }

        return $rows;
    }

    public function create(string $name, string $user, string $password, string $charset = 'utf8mb4', string $host = 'localhost'): ShellResult
    {
        $this->assertNames($name, $user);
        $collation = $charset === 'utf8mb4' ? 'utf8mb4_unicode_ci' : ($charset === 'utf8' ? 'utf8_general_ci' : 'latin1_swedish_ci');
        $h = self::quote($host);
        $u = self::quote($user);
        $pw = self::quote($password);

        $sql = "CREATE DATABASE `{$name}` CHARACTER SET {$charset} COLLATE {$collation};\n"
            ."CREATE USER IF NOT EXISTS {$u}@{$h} IDENTIFIED BY {$pw};\n"
            ."GRANT ALL PRIVILEGES ON `{$name}`.* TO {$u}@{$h};\n"
            .'FLUSH PRIVILEGES;';

        return $this->query($sql);
    }

    public function drop(string $name, ?string $user, string $host = 'localhost'): ShellResult
    {
        $this->assertNames($name, $user ?? 'x');
        $sql = "DROP DATABASE IF EXISTS `{$name}`;\n";
        if ($user) {
            $sql .= 'DROP USER IF EXISTS '.self::quote($user).'@'.self::quote($host).";\n";
        }

        return $this->query($sql.'FLUSH PRIVILEGES;');
    }

    public function changePassword(string $user, string $password, string $host = 'localhost'): ShellResult
    {
        $this->assertNames('x', $user);

        return $this->query('ALTER USER '.self::quote($user).'@'.self::quote($host).' IDENTIFIED BY '.self::quote($password).'; FLUSH PRIVILEGES;');
    }

    public function backupDir(): string
    {
        return rtrim(config('gbx.paths.backup'), '/').'/database';
    }

    public function backupScript(string $name): string
    {
        $this->assertNames($name, 'x');
        $dir = Shell::arg($this->backupDir());
        $file = $this->backupDir().'/'.$name.'_'.date('Ymd_His').'.sql.gz';

        return "set -e\nmkdir -p {$dir}\nmysqldump --single-transaction --routines --triggers --default-character-set=utf8mb4 ".Shell::arg($name).' | gzip > '.Shell::arg($file)."\nls -lh ".Shell::arg($file);
    }

    public function importScript(string $name, string $file): string
    {
        $this->assertNames($name, 'x');
        $f = Shell::arg($file);
        $db = Shell::arg($name);

        return "set -e\ncase {$f} in *.gz) gunzip -c {$f} | mysql {$db} ;; *.zip) unzip -p {$f} | mysql {$db} ;; *) mysql {$db} < {$f} ;; esac\necho 'Import completed'";
    }

    public function backups(): array
    {
        if (Shell::simulating()) {
            return [['name' => 'shop_db_20260914_020000.sql.gz', 'size' => 5242880, 'time' => time() - 3600]];
        }

        $out = Shell::out('find '.Shell::arg($this->backupDir())." -maxdepth 1 -type f -printf '%f\t%s\t%T@\n' 2>/dev/null | sort -r", 15);
        $rows = [];
        foreach (array_filter(explode("\n", $out)) as $line) {
            [$n, $s, $t] = array_pad(explode("\t", $line), 3, 0);
            $rows[] = ['name' => $n, 'size' => (int) $s, 'time' => (int) $t];
        }

        return $rows;
    }

    protected function assertNames(string $name, string $user): void
    {
        if (! self::validIdentifier($name) || ! self::validIdentifier($user)) {
            throw new \InvalidArgumentException('Names may only contain letters, numbers and underscore (max 64).');
        }
    }
}
