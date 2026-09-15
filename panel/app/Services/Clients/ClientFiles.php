<?php

namespace App\Services\Clients;

use App\Models\Client;
use App\Services\FileManager;
use App\Services\Shell;
use App\Services\ShellResult;

/**
 * File manager of the client sub-panel.
 *
 * Only the document roots of the client's websites are reachable. Paths are resolved through symbolic
 * links (readlink -m) before every operation, and the operations run as the website user instead of
 * root, so a link planted in a website cannot expose files the website itself could not read.
 * With isolation (option A) the same commands run as the client's own Linux user.
 */
class ClientFiles
{
    public const EDIT_MAX = 2097152;

    public function __construct(protected Client $client) {}

    public static function for(Client $client): static
    {
        return new static($client);
    }

    /** System user that owns and runs the websites of the client. */
    public function user(): string
    {
        return $this->client->isolated && $this->client->system_user ? $this->client->system_user : (string) config('gbx.web_user', 'www-data');
    }

    /** @return array<string, string> domain => document root */
    public function roots(): array
    {
        $www = rtrim((string) config('gbx.paths.www'), '/').'/';
        $roots = [];
        foreach ($this->client->websites()->orderBy('domain')->get(['domain', 'root_path']) as $site) {
            $root = FileManager::normalize((string) $site->root_path);
            if (str_starts_with($root.'/', $www) && $root !== rtrim($www, '/') && ! in_array($root, FileManager::PROTECTED, true)) {
                $roots[$site->domain] = $root;
            }
        }

        return $roots;
    }

    protected static function inside(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, '/').'/');
    }

    public static function validName(string $name): bool
    {
        return $name !== '' && $name !== '.' && $name !== '..' && strlen($name) <= 255 && ! preg_match('#[/\\\\\x00-\x1f]#', $name);
    }

    /** Website root that contains the path, or an exception. */
    public function rootOf(string $path): string
    {
        foreach ($this->roots() as $root) {
            if (self::inside($path, $root)) {
                return $root;
            }
        }
        throw new \InvalidArgumentException('This location is outside your websites.');
    }

    /**
     * Canonical path (symbolic links resolved) that must stay inside the same website root.
     */
    public function resolve(string $path): string
    {
        $path = FileManager::normalize($path);
        $root = $this->rootOf($path);
        if (Shell::simulating()) {
            return $path;
        }
        $out = Shell::run('readlink -m -- '.Shell::arg($path).'; readlink -m -- '.Shell::arg($root), 10)->lines();
        [$real, $realRoot] = [trim($out[0] ?? ''), trim($out[1] ?? '')];
        if ($real === '' || $realRoot === '' || ! self::inside($real, $realRoot)) {
            throw new \InvalidArgumentException('This location is outside your websites.');
        }

        return $real;
    }

    /**
     * Path of an entry whose own name must not be followed (delete, rename, move a link itself):
     * the parent folder is resolved, the name is kept.
     */
    public function resolveEntry(string $path): string
    {
        $path = FileManager::normalize($path);
        if (in_array($path, $this->roots(), true)) {
            throw new \InvalidArgumentException('The root folder of a website cannot be changed here.');
        }
        $name = basename($path);
        if (! self::validName($name)) {
            throw new \InvalidArgumentException('Invalid name.');
        }

        return rtrim($this->resolve(dirname($path)), '/').'/'.$name;
    }

    /** Run a command as the website user. */
    protected function run(string $command, int $timeout = 60, ?string $input = null): ShellResult
    {
        return Shell::run('runuser -u '.Shell::arg($this->user()).' -- bash -c '.Shell::arg($command), $timeout, $input);
    }

    protected function checked(ShellResult $result): ShellResult
    {
        if ($result->failed()) {
            throw new \RuntimeException(trim($result->error ?: $result->output) ?: 'The operation failed.');
        }

        return $result;
    }

    /* =============================================================== reading */

    /** @return list<array{name: string, type: string, size: int, mtime: int, perms: string, target: ?string}> */
    public function list(string $dir): array
    {
        $dir = $this->resolve($dir);
        if (Shell::simulating()) {
            return [
                ['name' => 'wp-content', 'type' => 'dir', 'size' => 4096, 'mtime' => time() - 86400, 'perms' => '755', 'target' => null],
                ['name' => 'uploads', 'type' => 'dir', 'size' => 4096, 'mtime' => time() - 7200, 'perms' => '755', 'target' => null],
                ['name' => 'index.php', 'type' => 'file', 'size' => 405, 'mtime' => time() - 3600, 'perms' => '644', 'target' => null],
                ['name' => 'wp-config.php', 'type' => 'file', 'size' => 3120, 'mtime' => time() - 600, 'perms' => '640', 'target' => null],
                ['name' => '.htaccess', 'type' => 'file', 'size' => 523, 'mtime' => time() - 99000, 'perms' => '644', 'target' => null],
                ['name' => 'backup.zip', 'type' => 'file', 'size' => 18234120, 'mtime' => time() - 400000, 'perms' => '644', 'target' => null],
            ];
        }
        $out = $this->checked($this->run('find '.Shell::arg($dir)." -mindepth 1 -maxdepth 1 -printf '%y\\t%s\\t%T@\\t%m\\t%l\\t%f\\n' 2>/dev/null | head -n 5000", 30))->output;
        $items = [];
        foreach (explode("\n", $out) as $line) {
            $p = explode("\t", $line, 6);
            if (count($p) < 6 || ! self::validName($p[5])) {
                continue;
            }
            $items[] = ['name' => $p[5], 'type' => $p[0] === 'd' ? 'dir' : ($p[0] === 'l' ? 'link' : 'file'), 'size' => (int) $p[1], 'mtime' => (int) $p[2], 'perms' => $p[3], 'target' => $p[0] === 'l' ? $p[4] : null];
        }
        usort($items, fn ($a, $b) => [$a['type'] !== 'dir', strtolower($a['name'])] <=> [$b['type'] !== 'dir', strtolower($b['name'])]);

        return $items;
    }

    public function read(string $path): string
    {
        $path = $this->resolve($path);
        if (Shell::simulating()) {
            return "<?php\n// ".basename($path)."\necho 'Hello';\n";
        }
        $size = (int) trim($this->run('stat -c %s -- '.Shell::arg($path), 10)->output);
        if ($size > self::EDIT_MAX) {
            throw new \RuntimeException('The file is too large to edit online (max 2 MB). Download it instead.');
        }
        $content = $this->checked($this->run('cat -- '.Shell::arg($path), 30))->output;
        if (str_contains($content, "\0")) {
            throw new \RuntimeException('Binary files cannot be edited.');
        }

        return $content;
    }

    /** Stream a file to the browser as the website user. */
    public function stream(string $path): void
    {
        if (Shell::simulating()) {
            echo 'Simulated download of '.basename($path)."\n";

            return;
        }
        $cmd = ['/bin/bash', '-c', 'runuser -u '.Shell::arg($this->user()).' -- cat -- '.Shell::arg($path)];
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
        return Shell::simulating() ? 64 : (int) trim($this->run('stat -c %s -- '.Shell::arg($path), 10)->output);
    }

    /* =============================================================== writing */

    public function write(string $path, string $content): void
    {
        $path = $this->resolveEntry($path);
        $this->checked($this->run('[ ! -L '.Shell::arg($path).' ] && cat > '.Shell::arg($path), 60, $content));
    }

    public function create(string $dir, string $name, string $type): void
    {
        if (! self::validName($name)) {
            throw new \InvalidArgumentException('Invalid name.');
        }
        $target = rtrim($this->resolve($dir), '/').'/'.$name;
        $t = Shell::arg($target);
        $this->checked($this->run("[ ! -e {$t} ] && [ ! -L {$t} ] || { echo 'A file or folder with this name already exists' >&2; exit 1; }; ".($type === 'dir' ? "mkdir -- {$t}" : "touch -- {$t}")));
    }

    public function rename(string $path, string $name): void
    {
        if (! self::validName($name)) {
            throw new \InvalidArgumentException('Invalid name.');
        }
        $from = $this->resolveEntry($path);
        $to = dirname($from).'/'.$name;
        $this->checked($this->run('[ ! -e '.Shell::arg($to).' ] || { echo "A file or folder with this name already exists" >&2; exit 1; }; mv -T -- '.Shell::arg($from).' '.Shell::arg($to)));
    }

    public function delete(array $paths): void
    {
        $targets = array_map(fn ($p) => Shell::arg($this->resolveEntry((string) $p)), $paths);
        if ($targets) {
            $this->checked($this->run('rm -rf -- '.implode(' ', $targets), 300));
        }
    }

    public function copyOrMove(array $paths, string $destination, bool $move): void
    {
        $dest = $this->resolve($destination);
        foreach ($paths as $path) {
            $from = $this->resolveEntry((string) $path);
            if (self::inside($dest, $from)) {
                throw new \InvalidArgumentException('A folder cannot be copied or moved into itself.');
            }
            $to = $dest.'/'.basename($from);
            // -P / mv keep symbolic links as links (their targets are never copied)
            $this->checked($this->run(($move ? 'mv -n -T -- ' : 'cp -a -n -P -T -- ').Shell::arg($from).' '.Shell::arg($to), 600));
        }
    }

    /** Move an uploaded temporary file into a folder of the client. */
    public function upload(string $tmpFile, string $dir, string $name): void
    {
        if (! self::validName($name)) {
            throw new \InvalidArgumentException('Invalid file name: '.$name);
        }
        $target = rtrim($this->resolve($dir), '/').'/'.$name;
        if (Shell::simulating()) {
            return;
        }
        $user = Shell::arg($this->user());
        // root copies the upload to a private staging file owned by the website user, which then renames it
        // (rename replaces an existing link instead of writing through it)
        $stage = trim(Shell::out('f=$(mktemp /tmp/gbx-upload-XXXXXX) && install -m 0644 -o '.$user.' -g '.$user.' '.Shell::arg($tmpFile).' "$f" && echo "$f"', 300));
        if ($stage === '' || ! str_starts_with($stage, '/tmp/gbx-upload-')) {
            throw new \RuntimeException('Unable to store the upload.');
        }
        try {
            $this->checked($this->run('[ ! -d '.Shell::arg($target).' ] && mv -f -T -- '.Shell::arg($stage).' '.Shell::arg($target), 300));
        } finally {
            Shell::run('rm -f -- '.Shell::arg($stage), 10);
        }
    }

    public function extract(string $archive, string $destination): void
    {
        $file = $this->resolve($archive);
        $dest = $this->resolve($destination);
        $f = Shell::arg($file);
        $d = Shell::arg($dest);
        $cmd = match (true) {
            (bool) preg_match('/\.zip$/i', $file) => "unzip -o -q {$f} -d {$d}",
            (bool) preg_match('/\.(tar\.gz|tgz)$/i', $file) => "tar -xzf {$f} -C {$d} --no-same-owner --no-same-permissions",
            (bool) preg_match('/\.tar$/i', $file) => "tar -xf {$f} -C {$d} --no-same-owner --no-same-permissions",
            default => throw new \InvalidArgumentException('Supported archives: .zip, .tar.gz, .tgz, .tar'),
        };
        $this->checked($this->run($cmd, 900));
        $this->removeEscapingLinks($dest);
    }

    public function compress(string $dir, array $names, string $archive): void
    {
        foreach (array_merge($names, [$archive]) as $name) {
            if (! self::validName((string) $name)) {
                throw new \InvalidArgumentException('Invalid name.');
            }
        }
        $dir = $this->resolve($dir);
        $list = implode(' ', array_map(fn ($n) => Shell::arg($n), $names));
        $a = Shell::arg($archive);
        $cmd = preg_match('/\.zip$/i', $archive)
            ? "zip -r -q -y {$a} -- {$list}"          // -y stores links as links
            : "tar -czf {$a} -- {$list}";
        $this->checked($this->run('cd '.Shell::arg($dir)." && [ ! -e {$a} ] && {$cmd}", 900));
    }

    /** Delete symbolic links under a folder that point outside the website (for example from an archive). */
    public function removeEscapingLinks(string $dir): int
    {
        if (Shell::simulating()) {
            return 0;
        }
        $root = trim(Shell::out('readlink -m -- '.Shell::arg($this->rootOf($dir)), 10));
        $removed = 0;
        // names are passed as arguments (never parsed by a shell): each link prints its target, then its path
        $lines = explode("\n", trim(Shell::run('find '.Shell::arg($dir)." -type l -exec readlink -m -- {} ';' -print 2>/dev/null", 120)->output));
        for ($i = 0; $i + 1 < count($lines); $i += 2) {
            [$target, $link] = [trim($lines[$i]), $lines[$i + 1]];
            if ($link !== '' && ! self::inside($target, $root)) {
                Shell::run('rm -f -- '.Shell::arg($link), 10);
                $removed++;
            }
        }

        return $removed;
    }
}
