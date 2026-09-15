<?php

namespace App\Services\Ai\Tools;

use App\Services\FileManager;
use App\Services\Shell;
use App\Services\SystemStats;

class FileTools extends ToolGroup
{
    public function __construct(protected FileManager $files) {}

    public function tools(): array
    {
        return [
            'list_directory' => self::tool('List a directory with type, size, permissions, owner and modification date.', self::params(['path' => self::str('Absolute directory path')], ['path']), false, false, fn ($a) => 'Listed '.self::labelArg($a, 'path')),
            'read_file' => self::tool('Read a text file (max 500 KB). Use start_line/max_lines for big files. Do not repeat secrets unless asked.', self::params(['path' => self::str('Absolute file path'), 'start_line' => self::int('First line, default 1'), 'max_lines' => self::int('Lines to return, default all')], ['path']), false, false, fn ($a) => 'Read file '.self::labelArg($a, 'path')),
            'search_in_files' => self::tool('Search text inside files of a directory (grep, recursive). Returns matching lines with file and line number.', self::params(['path' => self::str('Directory'), 'text' => self::str('Text to search (case-insensitive)'), 'file_pattern' => self::str('Only files matching, e.g. *.php or *.conf')], ['path', 'text']), false, false, fn ($a) => 'Searched "'.self::labelArg($a, 'text').'" in '.self::labelArg($a, 'path')),

            'write_file' => self::tool('Create or overwrite a text file with the full content. Ownership/mode of existing files are kept.', self::params(['path' => self::str('Absolute path'), 'content' => self::str('Full content'), 'owner' => self::str('Owner for new files, e.g. www-data:www-data'), 'mode' => self::str('Mode for new files, e.g. 644')], ['path', 'content']), true, true, fn ($a) => 'Write file '.self::labelArg($a, 'path')),
            'edit_file' => self::tool('Edit a file by replacing an exact text fragment (safer than rewriting the whole file). Fails if the fragment is missing or ambiguous.', self::params(['path' => self::str('Absolute path'), 'find' => self::str('Exact text to replace (include enough context to be unique)'), 'replace' => self::str('Replacement text'), 'replace_all' => self::bool('Replace every occurrence')], ['path', 'find', 'replace']), true, true, fn ($a) => 'Edit file '.self::labelArg($a, 'path')),
            'create_directory' => self::tool('Create a directory (with parents).', self::params(['path' => self::str('Absolute path'), 'owner' => self::str('Owner, e.g. www-data:www-data'), 'mode' => self::str('Mode, default 755')], ['path']), true, false, fn ($a) => 'Create directory '.self::labelArg($a, 'path')),
            'delete_path' => self::tool('Delete files or directories (recursive). System directories are protected.', self::params(['paths' => self::list('Absolute paths to delete')], ['paths']), true, false, fn ($a) => 'Delete '.implode(', ', self::strings($a['paths'] ?? []))),
            'move_or_copy_path' => self::tool('Move/rename or copy files and directories into a destination directory.', self::params(['paths' => self::list('Source paths'), 'destination' => self::str('Destination directory'), 'mode' => self::str('move or copy', ['move', 'copy'])], ['paths', 'destination', 'mode']), true, false, fn ($a) => ucfirst(self::labelArg($a, 'mode')).' to '.self::labelArg($a, 'destination')),
            'rename_path' => self::tool('Rename a file or directory in place.', self::params(['path' => self::str('Current absolute path'), 'new_name' => self::str('New name (no slashes)')], ['path', 'new_name']), true, false, fn ($a) => 'Rename '.self::labelArg($a, 'path').' to '.self::labelArg($a, 'new_name')),
            'set_permissions' => self::tool('chmod / chown files or directories.', self::params(['paths' => self::list('Absolute paths'), 'mode' => self::str('Octal mode, e.g. 755'), 'owner' => self::str('user:group, e.g. www-data:www-data'), 'recursive' => self::bool('Apply recursively')], ['paths', 'mode']), true, false, fn ($a) => 'Set permissions '.self::labelArg($a, 'mode').' on '.implode(', ', self::strings($a['paths'] ?? []))),
            'compress_files' => self::tool('Create a zip / tar.gz archive from items inside a directory.', self::params(['directory' => self::str('Directory containing the items'), 'names' => self::list('File or folder names inside the directory'), 'archive_name' => self::str('Archive file name, e.g. site.zip'), 'format' => self::str('zip, tar.gz or tar', ['zip', 'tar.gz', 'tar'])], ['directory', 'names', 'archive_name']), true, false, fn ($a) => 'Compress into '.self::labelArg($a, 'archive_name')),
            'extract_archive' => self::tool('Extract a zip / tar(.gz/.bz2/.xz) archive into a directory.', self::params(['path' => self::str('Archive path'), 'destination' => self::str('Destination directory')], ['path', 'destination']), true, false, fn ($a) => 'Extract '.self::labelArg($a, 'path')),
        ];
    }

    public function handle(string $name, array $a): mixed
    {
        switch ($name) {
            case 'list_directory':
                $items = $this->files->list((string) self::a($a, 'path', '/'));

                return ['count' => count($items), 'items' => array_map(fn ($i) => ['name' => $i['name'], 'type' => $i['type'], 'size' => $i['type'] === 'dir' ? null : SystemStats::bytes($i['size']), 'perms' => $i['perms'], 'owner' => $i['owner'].':'.$i['group'], 'modified' => date('Y-m-d H:i', $i['mtime'])] + ($i['target'] ? ['target' => $i['target']] : []), array_slice($items, 0, 400))];

            case 'read_file':
                $path = FileManager::normalize((string) self::a($a, 'path', ''));
                if (preg_match('#^/(proc|sys|dev)/#', $path) || in_array($path, ['/etc/shadow', '/etc/gshadow'], true)) {
                    return ['error' => 'This file cannot be read by the assistant.'];
                }
                if (! Shell::simulating() && $this->files->fileSize($path) > 512000 && ! isset($a['max_lines'])) {
                    return ['error' => 'File larger than 500 KB. Use start_line/max_lines, read_log or search_in_files.'];
                }
                if (isset($a['max_lines']) || isset($a['start_line'])) {
                    $start = max(1, (int) self::a($a, 'start_line', 1));
                    $count = min(2000, max(1, (int) self::a($a, 'max_lines', 200)));

                    return Shell::simulating() ? "<?php // simulated {$path}" : Shell::run('sed -n '.$start.','.($start + $count - 1).'p '.Shell::arg($path), 20)->output;
                }

                return $this->files->read($path);

            case 'search_in_files':
                $path = FileManager::normalize((string) $a['path']);
                $include = self::a($a, 'file_pattern') && preg_match('/^[\w.*?\-\[\]]+$/', self::a($a, 'file_pattern')) ? ' --include='.Shell::arg(self::a($a, 'file_pattern')) : '';
                if (Shell::simulating()) {
                    return "{$path}/config/app.php:12:    'debug' => true,";
                }

                return Shell::run('grep -rIin --exclude-dir=node_modules --exclude-dir=vendor --exclude-dir=.git'.$include.' -m 5 -F -- '.Shell::arg((string) $a['text']).' '.Shell::arg($path).' 2>/dev/null | cut -c1-300 | head -n 200', 120)->output ?: 'No matches.';

            case 'write_file':
                $path = FileManager::normalize((string) $a['path']);
                $exists = Shell::simulating() || Shell::fileExists($path);
                $result = $this->files->write($path, (string) $a['content']);
                if ($result->ok() && ! $exists) {
                    $owner = self::a($a, 'owner');
                    $mode = self::a($a, 'mode');
                    $this->files->chmod([$path], preg_match('/^[0-7]{3,4}$/', (string) $mode) ? $mode : '644', preg_match('/^[a-z_][\w-]*(:[a-z_][\w-]*)?$/i', (string) $owner) ? $owner : '', false);
                }

                return $this->shell($result, 'File written ('.strlen((string) $a['content']).' bytes)');

            case 'edit_file':
                $path = FileManager::normalize((string) $a['path']);
                $content = $this->files->read($path);
                $find = (string) $a['find'];
                $count = $find === '' ? 0 : substr_count($content, $find);
                if ($count === 0) {
                    return ['ok' => false, 'error' => 'The text to replace was not found in the file. Read the file again and copy the exact fragment.'];
                }
                if ($count > 1 && ! self::a($a, 'replace_all')) {
                    return ['ok' => false, 'error' => "The text appears {$count} times. Add more context to make it unique or set replace_all=true."];
                }
                $new = self::a($a, 'replace_all') ? str_replace($find, (string) $a['replace'], $content) : substr_replace($content, (string) $a['replace'], strpos($content, $find), strlen($find));

                return $this->shell($this->files->write($path, $new), "Replaced {$count} occurrence(s)");

            case 'create_directory':
                $path = FileManager::normalize((string) $a['path']);
                $mode = preg_match('/^[0-7]{3,4}$/', (string) self::a($a, 'mode', '755')) ? self::a($a, 'mode', '755') : '755';
                $cmd = 'mkdir -p '.Shell::arg($path).' && chmod '.$mode.' '.Shell::arg($path);
                if (($owner = self::a($a, 'owner')) && preg_match('/^[a-z_][\w-]*(:[a-z_][\w-]*)?$/i', $owner)) {
                    $cmd .= ' && chown '.Shell::arg($owner).' '.Shell::arg($path);
                }

                return $this->shell(Shell::run($cmd, 20), "Directory {$path} ready");

            case 'delete_path':
                return $this->shell($this->files->delete(self::strings($a['paths'] ?? [])), 'Deleted');

            case 'move_or_copy_path':
                return $this->shell($this->files->copyOrMove(self::strings($a['paths'] ?? []), (string) $a['destination'], self::a($a, 'mode') === 'move'), self::a($a, 'mode') === 'move' ? 'Moved' : 'Copied');

            case 'rename_path':
                return $this->shell($this->files->rename((string) $a['path'], (string) $a['new_name']), 'Renamed');

            case 'set_permissions':
                return $this->shell($this->files->chmod(self::strings($a['paths'] ?? []), (string) $a['mode'], (string) self::a($a, 'owner', ''), (bool) self::a($a, 'recursive', false)), 'Permissions updated');

            case 'compress_files':
                return $this->shell($this->files->compress((string) $a['directory'], self::strings($a['names'] ?? []), (string) $a['archive_name'], (string) self::a($a, 'format', 'zip')), 'Archive created');

            case 'extract_archive':
                return $this->shell($this->files->extract((string) $a['path'], (string) $a['destination']), 'Archive extracted');
        }

        return ['error' => "Unknown tool {$name}"];
    }
}
