<?php

namespace App\Services;

class FileManager
{
    /** Paths that can never be deleted, moved or chmod'ed recursively. */
    public const PROTECTED = ['/', '/bin', '/boot', '/dev', '/etc', '/home', '/lib', '/lib32', '/lib64', '/opt', '/proc', '/root', '/run', '/sbin', '/srv', '/sys', '/tmp', '/usr', '/var', '/www', '/www/wwwroot', '/usr/local', '/usr/local/gbxpanel'];

    public const EDITABLE_MAX = 3 * 1048576;

    /** Normalize a path lexically (no symlink resolution). */
    public static function normalize(?string $path): string
    {
        $path = str_replace('\\', '/', trim((string) $path));
        if ($path === '' || $path[0] !== '/') {
            $path = '/'.$path;
        }
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);

                continue;
            }
            if (str_contains($segment, "\0")) {
                throw new \InvalidArgumentException('Invalid path');
            }
            $parts[] = $segment;
        }

        return '/'.implode('/', $parts);
    }

    public static function validName(string $name): bool
    {
        return $name !== '' && $name !== '.' && $name !== '..' && ! str_contains($name, '/') && ! str_contains($name, "\0") && mb_strlen($name) <= 255;
    }

    protected function guardDestructive(string $path): void
    {
        if (in_array(rtrim($path, '/') ?: '/', self::PROTECTED, true)) {
            throw new \InvalidArgumentException("The path {$path} is protected.");
        }
    }

    public function list(string $path): array
    {
        $path = self::normalize($path);

        if (Shell::simulating()) {
            return $this->fakeList($path);
        }

        $r = Shell::run('cd '.Shell::arg($path).' && find . -mindepth 1 -maxdepth 1 -printf '.Shell::arg('%f\t%y\t%m\t%u\t%g\t%s\t%T@\t%l\0'), 30);
        if ($r->failed()) {
            throw new \RuntimeException(trim($r->error) ?: 'Unable to open directory');
        }

        $items = [];
        foreach (explode("\0", $r->output) as $entry) {
            if ($entry === '') {
                continue;
            }
            $p = explode("\t", $entry);
            if (count($p) < 8) {
                continue;
            }
            $type = $p[1] === 'd' ? 'dir' : ($p[1] === 'l' ? 'link' : 'file');
            $items[] = [
                'name' => $p[0],
                'type' => $type,
                'perms' => str_pad($p[2], 3, '0', STR_PAD_LEFT),
                'owner' => $p[3],
                'group' => $p[4],
                'size' => (int) $p[5],
                'mtime' => (int) $p[6],
                'target' => $p[7] ?: null,
                'path' => rtrim($path, '/').'/'.$p[0],
            ];
        }

        usort($items, fn ($a, $b) => [$a['type'] !== 'dir', strtolower($a['name'])] <=> [$b['type'] !== 'dir', strtolower($b['name'])]);

        return $items;
    }

    public function isDir(string $path): bool
    {
        if (Shell::simulating()) {
            // domains like shop.com are folders, names with a file extension are files
            return ! preg_match('/\.(php\d?|phtml|js|mjs|css|html?|txt|json|xml|sql|zip|gz|tgz|tar|bz2|xz|log|sh|py|rb|env|md|ini|conf|cnf|ya?ml|jpe?g|png|gif|webp|svg|ico|exe|bin|pdf|csv)$/i', basename($path));
        }

        return Shell::test('test -d '.Shell::arg($path));
    }

    public function read(string $path): string
    {
        $path = self::normalize($path);
        if (Shell::simulating()) {
            return "<?php\n\n// Simulated file content for {$path}\necho 'Hello from GBX Panel';\n";
        }
        $size = (int) Shell::out('stat -c %s '.Shell::arg($path), 10);
        if ($size > self::EDITABLE_MAX) {
            throw new \RuntimeException('File is too large to edit online (max 3 MB).');
        }
        $content = Shell::readFile($path, self::EDITABLE_MAX);
        if ($content === null) {
            throw new \RuntimeException('Unable to read file');
        }

        return $content;
    }

    public function write(string $path, string $content): ShellResult
    {
        $path = self::normalize($path);
        $p = Shell::arg($path);

        // keep owner and mode of an existing file
        return Shell::run('if [ -e '.$p.' ]; then O=$(stat -c %u:%g '.$p.'); M=$(stat -c %a '.$p.'); cat > '.$p.' && chown $O '.$p.' && chmod $M '.$p.'; else cat > '.$p.'; fi', 60, $content);
    }

    public function create(string $dir, string $name, string $type): ShellResult
    {
        if (! self::validName($name)) {
            return new ShellResult(1, '', 'Invalid name');
        }
        $target = Shell::arg(self::normalize($dir.'/'.$name));
        $owner = $this->ownerOf($dir);

        $cmd = $type === 'dir' ? "mkdir {$target}" : "[ ! -e {$target} ] && touch {$target}";

        return Shell::run($cmd." && chown {$owner} {$target}", 20);
    }

    public function rename(string $path, string $newName): ShellResult
    {
        $path = self::normalize($path);
        $this->guardDestructive($path);
        if (! self::validName($newName)) {
            return new ShellResult(1, '', 'Invalid name');
        }

        return Shell::run('mv -n '.Shell::arg($path).' '.Shell::arg(dirname($path).'/'.$newName), 30);
    }

    public function delete(array $paths): ShellResult
    {
        $args = [];
        foreach ($paths as $path) {
            $path = self::normalize($path);
            $this->guardDestructive($path);
            $args[] = Shell::arg($path);
        }

        return Shell::run('rm -rf -- '.implode(' ', $args), 300);
    }

    public function copyOrMove(array $paths, string $destination, bool $move): ShellResult
    {
        $destination = self::normalize($destination);
        $args = [];
        foreach ($paths as $path) {
            $path = self::normalize($path);
            if ($move) {
                $this->guardDestructive($path);
            }
            if ($destination === $path || str_starts_with($destination.'/', $path.'/')) {
                return new ShellResult(1, '', 'Cannot copy or move a folder into itself');
            }
            $args[] = Shell::arg($path);
        }
        $cmd = $move ? 'mv -f -- ' : 'cp -a -- ';

        return Shell::run($cmd.implode(' ', $args).' '.Shell::arg($destination.'/'), 600);
    }

    public function chmod(array $paths, string $mode, string $owner, bool $recursive): ShellResult
    {
        if (! preg_match('/^[0-7]{3,4}$/', $mode)) {
            return new ShellResult(1, '', 'Invalid permission mode');
        }
        if ($owner !== '' && ! preg_match('/^[a-z_][a-z0-9_-]*(:[a-z_][a-z0-9_-]*)?$/i', $owner)) {
            return new ShellResult(1, '', 'Invalid owner');
        }
        $flag = $recursive ? '-R ' : '';
        $cmds = [];
        foreach ($paths as $path) {
            $path = self::normalize($path);
            if ($recursive) {
                $this->guardDestructive($path);
            }
            $p = Shell::arg($path);
            $cmds[] = "chmod {$flag}{$mode} {$p}".($owner !== '' ? " && chown {$flag}".Shell::arg($owner)." {$p}" : '');
        }

        return Shell::run(implode(' && ', $cmds), 300);
    }

    public function compress(string $dir, array $names, string $archive, string $format): ShellResult
    {
        $dir = self::normalize($dir);
        if (! self::validName($archive)) {
            return new ShellResult(1, '', 'Invalid archive name');
        }
        $files = [];
        foreach ($names as $name) {
            if (! self::validName($name)) {
                return new ShellResult(1, '', 'Invalid file name');
            }
            $files[] = Shell::arg($name);
        }
        $list = implode(' ', $files);
        $target = Shell::arg($archive);
        $cmd = match ($format) {
            'zip' => "zip -qr {$target} {$list}",
            'tar' => "tar -cf {$target} {$list}",
            default => "tar -czf {$target} {$list}",
        };

        return Shell::run('cd '.Shell::arg($dir).' && '.$cmd.' && chown '.$this->ownerOf($dir).' '.$target, 1800);
    }

    public function extract(string $path, string $destination): ShellResult
    {
        $path = self::normalize($path);
        $destination = self::normalize($destination);
        $p = Shell::arg($path);
        $d = Shell::arg($destination);
        $lower = strtolower($path);

        $cmd = match (true) {
            str_ends_with($lower, '.zip') => "unzip -oq {$p} -d {$d}",
            str_ends_with($lower, '.tar.gz'), str_ends_with($lower, '.tgz') => "tar -xzf {$p} -C {$d}",
            str_ends_with($lower, '.tar.bz2') => "tar -xjf {$p} -C {$d}",
            str_ends_with($lower, '.tar.xz') => "tar -xJf {$p} -C {$d}",
            str_ends_with($lower, '.tar') => "tar -xf {$p} -C {$d}",
            str_ends_with($lower, '.gz') => 'gunzip -kf '.$p,
            default => null,
        };
        if (! $cmd) {
            return new ShellResult(1, '', 'Unsupported archive format');
        }

        return Shell::run("mkdir -p {$d} && {$cmd}", 1800);
    }

    /** Move an uploaded temp file into place, owned like its parent directory. */
    public function placeUpload(string $tmpFile, string $dir, string $name): ShellResult
    {
        if (! self::validName($name)) {
            return new ShellResult(1, '', 'Invalid file name');
        }
        $dir = self::normalize($dir);
        $target = Shell::arg($dir.'/'.$name);

        return Shell::run('mv -f '.Shell::arg($tmpFile).' '.$target.' && chown '.$this->ownerOf($dir).' '.$target.' && chmod 644 '.$target, 120);
    }

    public function dirSize(string $path): string
    {
        if (Shell::simulating()) {
            return '128M';
        }

        return Shell::out('du -sh '.Shell::arg(self::normalize($path)).' 2>/dev/null | cut -f1', 120) ?: '-';
    }

    /** Stream a file through sudo cat. */
    public function stream(string $path): void
    {
        $path = self::normalize($path);
        if (Shell::simulating()) {
            echo "Simulated download of {$path}\n";

            return;
        }
        $cmd = ['/bin/bash', '-c', 'cat '.Shell::arg($path)];
        if (! Shell::isRoot()) {
            $cmd = array_merge(['sudo', '-n'], $cmd);
        }
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($proc)) {
            return;
        }
        while (! feof($pipes[1])) {
            echo fread($pipes[1], 65536);
            flush();
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }

    public function fileSize(string $path): int
    {
        return Shell::simulating() ? 64 : (int) Shell::out('stat -c %s '.Shell::arg(self::normalize($path)), 10);
    }

    protected function ownerOf(string $dir): string
    {
        if (Shell::simulating()) {
            return 'www-data:www-data';
        }
        $owner = Shell::out('stat -c %U:%G '.Shell::arg(self::normalize($dir)), 10);

        return Shell::arg($owner ?: 'root:root');
    }

    protected function fakeList(string $path): array
    {
        $now = time();
        $base = rtrim($path, '/');
        $items = $path === '/'
            ? [['bin', 'dir'], ['etc', 'dir'], ['home', 'dir'], ['root', 'dir'], ['usr', 'dir'], ['var', 'dir'], ['www', 'dir']]
            : [['assets', 'dir'], ['storage', 'dir'], ['vendor', 'dir'], ['.htaccess', 'file'], ['index.php', 'file'], ['composer.json', 'file'], ['backup.tar.gz', 'file'], ['README.md', 'file']];

        return array_map(fn ($i) => [
            'name' => $i[0], 'type' => $i[1], 'perms' => $i[1] === 'dir' ? '755' : '644', 'owner' => 'www-data', 'group' => 'www-data',
            'size' => $i[1] === 'dir' ? 4096 : crc32($i[0]) % 900000, 'mtime' => $now - crc32($i[0]) % 900000, 'target' => null, 'path' => $base.'/'.$i[0],
        ], $items);
    }
}
