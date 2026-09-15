<?php

namespace App\Services;

use App\Models\Task;
use App\Models\Website;

/**
 * Backups stored under /www/backup/{site,database,path}.
 */
class BackupManager
{
    public function __construct(protected MysqlManager $mysql) {}

    public function root(): string
    {
        return rtrim(config('gbx.paths.backup'), '/');
    }

    /** Resolve a backup file and make sure it stays inside the backup directory. */
    public function resolve(string $file): string
    {
        $path = FileManager::normalize(str_starts_with($file, '/') ? $file : $this->root().'/'.$file);
        if (! str_starts_with($path, $this->root().'/') || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Backups must be inside '.$this->root());
        }

        return $path;
    }

    public function list(?string $type = null): array
    {
        if (Shell::simulating()) {
            return [
                ['file' => 'site/example.com_20260915_030000.tar.gz', 'type' => 'site', 'size' => '184 MB', 'date' => '2026-09-15 03:00'],
                ['file' => 'database/example_com_20260915_030000.sql.gz', 'type' => 'database', 'size' => '12 MB', 'date' => '2026-09-15 03:00'],
            ];
        }

        $dir = $this->root().($type && in_array($type, ['site', 'database', 'path'], true) ? '/'.$type : '');
        $out = Shell::out('find '.Shell::arg($dir)." -type f -printf '%P\t%s\t%T@\n' 2>/dev/null | sort -t$'\t' -k3 -rn | head -n 300", 30);
        $rows = [];
        foreach (array_filter(explode("\n", $out)) as $line) {
            [$file, $size, $time] = array_pad(explode("\t", $line), 3, 0);
            $file = ($type ? $type.'/' : '').$file;
            $rows[] = ['file' => $file, 'type' => explode('/', $file)[0], 'size' => SystemStats::bytes((int) $size, 1), 'date' => date('Y-m-d H:i', (int) $time)];
        }

        return $rows;
    }

    public function backupWebsite(Website $site, bool $withDatabases = true, array $exclude = []): Task
    {
        $stamp = date('Ymd_His');
        $target = $this->root().'/site/'.$site->domain.'_'.$stamp.'.tar.gz';
        $excludes = '';
        foreach (array_merge(['node_modules', '.cache'], $exclude) as $pattern) {
            if (preg_match('/^[\w.*\/-]+$/', (string) $pattern)) {
                $excludes .= ' --exclude='.Shell::arg((string) $pattern);
            }
        }

        $script = "set -e\nmkdir -p ".Shell::arg($this->root().'/site')."\n"
            .'tar -czf '.Shell::arg($target).$excludes.' -C '.Shell::arg(dirname($site->root_path)).' '.Shell::arg(basename($site->root_path))."\n"
            .'echo "Files: $(du -h '.Shell::arg($target).' | cut -f1) '.$target.'"';

        if ($withDatabases) {
            foreach ($site->databases as $db) {
                $script .= "\n".$this->mysql->backupScript($db->name);
            }
        }

        return TaskRunner::dispatch("Backup website {$site->domain}", $script, 'backup', ['website_id' => $site->id, 'file' => $target]);
    }

    public function backupDatabase(string $name): Task
    {
        return TaskRunner::dispatch("Backup database {$name}", $this->mysql->backupScript($name), 'backup');
    }

    public function backupPath(string $path, ?string $label = null): Task
    {
        $path = FileManager::normalize($path);
        if ($path === '/') {
            throw new \InvalidArgumentException('Refusing to archive the whole filesystem.');
        }
        $label = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', $label ?: basename($path)) ?: 'backup';
        $target = $this->root().'/path/'.$label.'_'.date('Ymd_His').'.tar.gz';

        $script = "set -e\nmkdir -p ".Shell::arg($this->root().'/path')."\n"
            .'tar -czf '.Shell::arg($target).' --exclude=node_modules -C '.Shell::arg(dirname($path)).' '.Shell::arg(basename($path))."\n"
            .'ls -lh '.Shell::arg($target);

        return TaskRunner::dispatch("Backup {$path}", $script, 'backup', ['file' => $target]);
    }

    public function restoreDatabase(string $name, string $file): Task
    {
        return TaskRunner::dispatch("Restore database {$name}", $this->mysql->importScript($name, $this->resolve($file)), 'backup');
    }

    /** Extract a .tar.gz / .zip backup into a directory. */
    public function restoreArchive(string $file, string $destination): Task
    {
        $file = $this->resolve($file);
        $destination = FileManager::normalize($destination);
        if (in_array($destination, ['/', '/etc', '/usr', '/bin', '/boot', '/lib', '/sbin', '/proc', '/sys', '/dev'], true)) {
            throw new \InvalidArgumentException('Refusing to restore into '.$destination);
        }
        $f = Shell::arg($file);
        $d = Shell::arg($destination);
        $extract = str_ends_with($file, '.zip') ? "unzip -oq {$f} -d {$d}" : "tar -xzf {$f} -C {$d}";

        return TaskRunner::dispatch('Restore '.basename($file), "set -e\nmkdir -p {$d}\n{$extract}\necho 'Restored into {$destination}'", 'backup');
    }

    public function delete(string $file): ShellResult
    {
        return Shell::run('rm -f -- '.Shell::arg($this->resolve($file)), 30);
    }
}
