<?php

namespace App\Services\Databases;

use App\Models\DbServer;
use App\Services\Shell;
use App\Services\ShellResult;

/**
 * Common contract for engines that host named databases with their own user:
 * MySQL/MariaDB, PostgreSQL, MongoDB and SQL Server. Every method accepts an optional
 * remote server; null means the server installed on this machine.
 */
abstract class DatabaseEngine
{
    /** Timestamp used in new dump names instead of now (cron jobs pass a placeholder resolved at run time). */
    public static ?string $stampOverride = null;

    /** Short key used in URLs and the databases.engine column. */
    abstract public function key(): string;

    abstract public function label(): string;

    abstract public function defaultPort(): int;

    abstract public function defaultUser(): string;

    /** Software catalog entry that installs the local server (null when it cannot be installed here). */
    abstract public function package(): ?string;

    abstract public function installed(): bool;

    public function service(): ?string
    {
        return null;
    }

    abstract public function version(?DbServer $server = null): ?string;

    /** @return array<string, array{size: int|null, tables: int|null}> */
    abstract public function databases(?DbServer $server = null): array;

    abstract public function create(string $name, string $user, string $password, array $options = [], ?DbServer $server = null): ShellResult;

    abstract public function drop(string $name, ?string $user, ?DbServer $server = null): ShellResult;

    abstract public function changePassword(string $name, string $user, string $password, ?DbServer $server = null, array $options = []): ShellResult;

    abstract public function rootPassword(string $password): ShellResult;

    /** Shell script that writes a compressed dump of $name to $file. */
    abstract public function dumpScript(string $name, string $file, ?DbServer $server = null): string;

    /** Shell script that loads $file (a dump or an uploaded file) into $name. */
    abstract public function importScript(string $name, string $file, ?DbServer $server = null): string;

    /** File extension of dumps written by dumpScript(). */
    abstract public function dumpExtension(): string;

    /** Upload extensions accepted by importScript(). */
    abstract public function importExtensions(): array;

    public function testConnection(DbServer $server): ShellResult
    {
        $version = $this->version($server);

        return $version ? new ShellResult(0, $version, '') : new ShellResult(1, '', 'Could not connect to '.$server->host.':'.$server->port.'. Check the address, credentials and that this server is allowed to connect.');
    }

    /** Whether dumps can be made for databases on this server. */
    public function canBackup(?DbServer $server): bool
    {
        return $this->supports('backup');
    }

    /** Optional features shown in the UI: root, permission, tools, backup, admin_tool, security, remote_only. */
    public function supports(string $feature): bool
    {
        return in_array($feature, $this->features(), true);
    }

    protected function features(): array
    {
        return ['root', 'backup'];
    }

    /** Tables or collections with size, for the Tools dialog. */
    public function tables(string $name, ?DbServer $server = null): array
    {
        return [];
    }

    /* ------------------------------------------------------------ backups */

    public function backupRoot(): string
    {
        return rtrim(config('gbx.paths.backup'), '/').'/database';
    }

    /** MySQL dumps stay in /www/backup/database for compatibility; other engines use a subfolder. */
    public function backupDir(): string
    {
        return $this->backupRoot().($this->key() === 'mysql' ? '' : '/'.$this->key());
    }

    public function newBackupFile(string $name): string
    {
        return $this->backupDir().'/'.$name.'_'.(self::$stampOverride ?? date('Ymd_His')).'.'.$this->dumpExtension();
    }

    public function backupScript(string $name, ?DbServer $server = null): string
    {
        $this->assertName($name);
        $file = $this->newBackupFile($name);

        return "set -e\nmkdir -p ".Shell::arg($this->backupDir())."\n"
            .$this->dumpScript($name, $file, $server)."\n"
            .'echo "Backup: $(du -h '.Shell::arg($file).' | cut -f1) '.$file.'"';
    }

    /**
     * Backup script stored in a cron job: it is executed many times, so it must not depend on
     * temporary credential files. Local servers of most engines need none.
     */
    public function scheduledBackupScript(string $name): string
    {
        return $this->backupScript($name);
    }

    /** @return list<array{name: string, size: int, time: int}> */
    public function backups(?string $database = null): array
    {
        if (Shell::simulating()) {
            $name = $database ?: 'shop_db';

            return [['name' => $name.'_'.date('Ymd', time() - 86400).'_023000.'.$this->dumpExtension(), 'size' => 5242880, 'time' => time() - 86400]];
        }

        $pattern = ($database ? $database.'_' : '').'*.'.$this->dumpExtension();
        $out = Shell::out('find '.Shell::arg($this->backupDir()).' -maxdepth 1 -type f -name '.Shell::arg($pattern)." -printf '%f\\t%s\\t%T@\\n' 2>/dev/null | sort -t\$'\\t' -k3 -rn", 15);
        $rows = [];
        foreach (array_filter(explode("\n", $out)) as $line) {
            [$n, $s, $t] = array_pad(explode("\t", $line), 3, 0);
            if ($database && ! preg_match('/^'.preg_quote($database, '/').'_\d{8}_\d{6}\./', $n)) {
                continue;
            }
            $rows[] = ['name' => $n, 'size' => (int) $s, 'time' => (int) $t];
        }

        return $rows;
    }

    /** Resolve a backup file name of this engine; rejects anything that is not a dump. */
    public function backupPath(string $file): string
    {
        if (! preg_match('/^[A-Za-z0-9_\-]+_\d{8}_\d{6}\.'.preg_quote($this->dumpExtension(), '/').'$/', $file)) {
            throw new \InvalidArgumentException('Invalid backup file.');
        }

        return $this->backupDir().'/'.$file;
    }

    /* ------------------------------------------------------------ helpers */

    public static function validIdentifier(string $name): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_]{1,63}$/', $name);
    }

    protected function assertName(string ...$names): void
    {
        foreach ($names as $name) {
            if (! static::validIdentifier($name)) {
                throw new \InvalidArgumentException('Names may only contain letters, numbers and underscore (max 63).');
            }
        }
    }

    /** Single-quoted SQL string literal. */
    public static function sqlString(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    /** Shell lines that delete temporary secret files when the script ends. */
    protected function cleanup(string ...$files): string
    {
        return 'trap '.Shell::arg('rm -f '.implode(' ', array_map(fn ($f) => Shell::arg($f), $files))).' EXIT'."\n";
    }
}
