<?php

namespace App\Services\Databases;

use App\Models\DbServer;
use App\Models\Setting;
use App\Services\Shell;
use App\Services\ShellResult;

/**
 * MongoDB through mongosh. Scripts (which contain the connection string) are written to a
 * root-only temporary file; dumps use mongodump/mongorestore with a --config file.
 */
class MongoEngine extends DatabaseEngine
{
    public const CONF = '/etc/mongod.conf';

    public function key(): string
    {
        return 'mongodb';
    }

    public function label(): string
    {
        return 'MongoDB';
    }

    public function defaultPort(): int
    {
        return 27017;
    }

    public function defaultUser(): string
    {
        return 'root';
    }

    public function package(): ?string
    {
        return 'mongodb';
    }

    public function service(): ?string
    {
        return 'mongod';
    }

    protected function features(): array
    {
        return ['root', 'backup', 'tools', 'security'];
    }

    public function installed(): bool
    {
        return Shell::simulating() ? false : Shell::commandExists('mongosh') && Shell::commandExists('mongod');
    }

    public function uri(?DbServer $server = null): string
    {
        if ($server) {
            $auth = $server->username ? rawurlencode($server->username).':'.rawurlencode((string) $server->password).'@' : '';

            return "mongodb://{$auth}{$server->host}:{$server->port}/admin?authSource=admin&serverSelectionTimeoutMS=8000";
        }
        $password = Setting::secret('mongodb_root_password');

        return 'mongodb://'.($password && $this->authEnabled() ? 'root:'.rawurlencode($password).'@' : '').'127.0.0.1:27017/admin?serverSelectionTimeoutMS=8000';
    }

    /** Run a mongosh script; the script receives "m" (connection) and "admin". */
    public function run(string $js, ?DbServer $server = null, int $timeout = 60, ?string $uri = null): ShellResult
    {
        if (Shell::simulating()) {
            return new ShellResult(0, '', '');
        }
        $file = Shell::secretFile('const m = new Mongo('.json_encode($uri ?? $this->uri($server)).");\nconst admin = m.getDB('admin');\n".$js."\n", '.js');

        return Shell::run('mongosh --nodb --quiet '.Shell::arg($file).'; rc=$?; rm -f '.Shell::arg($file).'; exit $rc', $timeout);
    }

    protected function json(string $js, ?DbServer $server = null): ?array
    {
        $r = $this->run($js, $server);
        if ($r->failed()) {
            return null;
        }
        $data = json_decode(trim((string) strrchr("\n".trim($r->output), "\n")), true);

        return is_array($data) ? $data : null;
    }

    public function version(?DbServer $server = null): ?string
    {
        if (Shell::simulating()) {
            return '8.0.3';
        }
        $r = $this->run('print(admin.runCommand({buildInfo: 1}).version);', $server, 20);

        return $r->ok() && preg_match('/\d+\.\d+\.\d+/', $r->output, $m) ? $m[0] : null;
    }

    public function databases(?DbServer $server = null): array
    {
        if (Shell::simulating()) {
            return ['catalog' => ['size' => 20971520, 'tables' => null]];
        }
        $rows = [];
        $data = $this->json("print(JSON.stringify(admin.runCommand({listDatabases: 1}).databases.filter(d => !['admin','local','config'].includes(d.name)).map(d => ({name: d.name, size: d.sizeOnDisk}))));", $server) ?? [];
        foreach ($data as $db) {
            $rows[$db['name']] = ['size' => (int) $db['size'], 'tables' => null];
        }

        return $rows;
    }

    public function create(string $name, string $user, string $password, array $options = [], ?DbServer $server = null): ShellResult
    {
        $this->assertName($name, $user);
        $n = json_encode($name);
        $u = json_encode($user);
        $p = json_encode($password);

        return $this->run("const d = m.getDB({$n});\nif (d.getUser({$u})) { d.updateUser({$u}, {pwd: {$p}}); } else { d.createUser({user: {$u}, pwd: {$p}, roles: [{role: 'readWrite', db: {$n}}, {role: 'dbAdmin', db: {$n}}]}); }\nprint('ok');", $server);
    }

    public function drop(string $name, ?string $user, ?DbServer $server = null): ShellResult
    {
        $this->assertName($name);
        $n = json_encode($name);
        $js = "const d = m.getDB({$n});\nd.dropDatabase();\n";
        if ($user) {
            $u = json_encode($user);
            $js .= "if (d.getUser({$u})) d.dropUser({$u});\n";
        }

        return $this->run($js."print('ok');", $server);
    }

    public function changePassword(string $name, string $user, string $password, ?DbServer $server = null, array $options = []): ShellResult
    {
        $this->assertName($name, $user);

        return $this->run('m.getDB('.json_encode($name).').updateUser('.json_encode($user).', {pwd: '.json_encode($password)."});\nprint('ok');", $server);
    }

    public function rootPassword(string $password): ShellResult
    {
        $p = json_encode($password);
        $result = $this->run("if (admin.getUser('root')) { admin.updateUser('root', {pwd: {$p}}); } else { admin.createUser({user: 'root', pwd: {$p}, roles: ['root']}); }\nprint('ok');");
        if ($result->ok()) {
            Setting::putSecret('mongodb_root_password', $password);
        }

        return $result;
    }

    public function authEnabled(): bool
    {
        if (Shell::simulating()) {
            return (bool) Setting::get('mongodb_auth_sim', false);
        }

        return Shell::test('grep -A3 -E "^security:" '.Shell::arg(self::CONF).' | grep -qE "^\s+authorization:\s*[\'\"]?enabled"');
    }

    /** Turn access control on (creating the root user first) or off, then restart mongod. */
    public function setAuth(bool $enabled): ShellResult
    {
        if ($enabled && ! Setting::secret('mongodb_root_password')) {
            $created = $this->rootPassword(\Illuminate\Support\Str::password(24, symbols: false));
            if ($created->failed()) {
                return $created;
            }
        }
        if (Shell::simulating()) {
            Setting::put('mongodb_auth_sim', $enabled);

            return new ShellResult(0, '', '');
        }
        $conf = Shell::arg(self::CONF);
        $value = $enabled ? 'enabled' : 'disabled';

        return Shell::run("set -e\ncp {$conf} {$conf}.gbx-bak\n"
            ."sed -i -E '/^\\s+authorization:/d' {$conf}\n"
            ."if grep -qE '^security:' {$conf}; then sed -i -E 's/^security:.*/security:\\n  authorization: {$value}/' {$conf}; else printf '\\nsecurity:\\n  authorization: {$value}\\n' >> {$conf}; fi\n"
            ."systemctl restart mongod\nsleep 2\nsystemctl is-active --quiet mongod || { mv {$conf}.gbx-bak {$conf}; systemctl restart mongod; echo 'mongod failed to start, configuration restored'; exit 1; }", 90);
    }

    public function tables(string $name, ?DbServer $server = null): array
    {
        $this->assertName($name);
        if (Shell::simulating()) {
            return [['name' => 'products', 'engine' => 'collection', 'rows' => 12840, 'size' => 15728640, 'collation' => '', 'updated' => null]];
        }
        $data = $this->json('const d = m.getDB('.json_encode($name).");\nprint(JSON.stringify(d.getCollectionNames().map(c => { let s = {}; try { s = d.getCollection(c).aggregate([{\$collStats: {storageStats: {}}}]).toArray()[0].storageStats; } catch (e) {} return {name: c, rows: s.count || 0, size: (s.size || 0) + (s.totalIndexSize || 0)}; })));", $server) ?? [];

        return array_map(fn ($c) => ['name' => $c['name'], 'engine' => 'collection', 'rows' => (int) $c['rows'], 'size' => (int) $c['size'], 'collation' => '', 'updated' => null], $data);
    }

    public function dumpExtension(): string
    {
        return 'archive.gz';
    }

    public function importExtensions(): array
    {
        return ['gz', 'archive'];
    }

    protected function toolConfig(?DbServer $server): string
    {
        return Shell::secretFile('uri: '.json_encode($this->uri($server))."\n", '.yaml');
    }

    public function dumpScript(string $name, string $file, ?DbServer $server = null): string
    {
        $this->assertName($name);
        $cfg = $this->toolConfig($server);

        return $this->cleanup($cfg).'mongodump --config='.Shell::arg($cfg).' --db='.Shell::arg($name).' --archive='.Shell::arg($file).' --gzip --quiet';
    }

    public function importScript(string $name, string $file, ?DbServer $server = null): string
    {
        $this->assertName($name);
        $cfg = $this->toolConfig($server);
        $f = Shell::arg($file);

        return "set -e\n".$this->cleanup($cfg)
            ."case {$f} in *.gz) GZ=--gzip ;; *) GZ= ;; esac\n"
            .'mongorestore --config='.Shell::arg($cfg)." --archive={$f} \$GZ --drop --nsInclude=".Shell::arg($name.'.*')."\necho 'Import completed'";
    }
}
