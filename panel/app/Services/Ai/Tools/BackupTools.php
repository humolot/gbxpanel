<?php

namespace App\Services\Ai\Tools;

use App\Models\MysqlDatabase;
use App\Models\Website;
use App\Services\BackupManager;

class BackupTools extends ToolGroup
{
    public function __construct(protected BackupManager $backups) {}

    public function tools(): array
    {
        return [
            'list_backups' => self::tool('List backup files in /www/backup (site, database, path).', self::params(['type' => self::str('Filter by type', ['site', 'database', 'path'])]), false, false, fn () => 'Listed backups'),
            'backup_website' => self::tool('Create a tar.gz backup of a website document root, optionally with its linked databases. Background task.', self::params(['domain' => self::str('Website domain'), 'include_databases' => self::bool('Also dump linked databases, default true'), 'exclude' => self::list('Extra patterns to exclude, e.g. storage/logs')], ['domain']), true, false, fn ($a) => 'Backup website '.self::labelArg($a, 'domain')),
            'backup_database' => self::tool('Dump a MySQL database to a compressed .sql.gz file. Background task.', self::params(['name' => self::str('Database name')], ['name']), true, false, fn ($a) => 'Backup database '.self::labelArg($a, 'name')),
            'backup_path' => self::tool('Create a tar.gz backup of any directory (configs, app folders...). Background task.', self::params(['path' => self::str('Directory or file to back up'), 'label' => self::str('Name prefix for the archive')], ['path']), true, false, fn ($a) => 'Backup '.self::labelArg($a, 'path')),
            'restore_database' => self::tool('Import a .sql / .sql.gz / .zip backup into a database (overwrites tables with the same name). Background task.', self::params(['name' => self::str('Target database'), 'file' => self::str('Backup file (relative to /www/backup or absolute inside it)')], ['name', 'file']), true, false, fn ($a) => 'Restore '.self::labelArg($a, 'file').' into '.self::labelArg($a, 'name')),
            'restore_archive' => self::tool('Extract a site/path backup archive into a directory (files with the same name are overwritten). Background task.', self::params(['file' => self::str('Backup archive inside /www/backup'), 'destination' => self::str('Destination directory')], ['file', 'destination']), true, false, fn ($a) => 'Restore '.self::labelArg($a, 'file').' to '.self::labelArg($a, 'destination')),
            'delete_backup' => self::tool('Delete a backup file from /www/backup.', self::params(['file' => self::str('Backup file')], ['file']), true, false, fn ($a) => 'Delete backup '.self::labelArg($a, 'file')),
        ];
    }

    public function handle(string $name, array $a): mixed
    {
        switch ($name) {
            case 'list_backups':
                return $this->backups->list(self::a($a, 'type'));

            case 'backup_website':
                $site = Website::query()->where('domain', strtolower((string) $a['domain']))->first();
                if (! $site) {
                    return ['error' => 'Website not found. Use list_websites.'];
                }

                return $this->queued($this->backups->backupWebsite($site, (bool) self::a($a, 'include_databases', true), self::strings($a['exclude'] ?? [])), "The backup of {$site->domain}");

            case 'backup_database':
                return $this->queued($this->backups->backupDatabase((string) $a['name']), "The backup of {$a['name']}");

            case 'backup_path':
                return $this->queued($this->backups->backupPath((string) $a['path'], self::a($a, 'label')), "The backup of {$a['path']}");

            case 'restore_database':
                if (! MysqlDatabase::query()->where('name', $a['name'])->exists()) {
                    return ['error' => 'Database is not managed by the panel. Create it first with create_database.'];
                }

                return $this->queued($this->backups->restoreDatabase((string) $a['name'], (string) $a['file']), 'The database restore');

            case 'restore_archive':
                return $this->queued($this->backups->restoreArchive((string) $a['file'], (string) $a['destination']), 'The restore');

            case 'delete_backup':
                return $this->shell($this->backups->delete((string) $a['file']), 'Backup deleted');
        }

        return ['error' => "Unknown tool {$name}"];
    }
}
