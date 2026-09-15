<?php

namespace App\Http\Controllers;

use App\Models\DbServer;
use App\Models\Setting;
use App\Services\Databases\RedisManager;
use App\Services\FileManager;
use App\Services\Shell;
use Illuminate\Http\Request;

class RedisController extends Controller
{
    public function __construct(protected RedisManager $redis) {}

    protected function server(Request $request): ?DbServer
    {
        $id = $request->input('server_id');

        return $id ? DbServer::query()->where('engine', 'redis')->findOrFail($id) : null;
    }

    protected function db(Request $request): int
    {
        return max(0, min(255, (int) $request->input('db', 0)));
    }

    public function overview(Request $request)
    {
        return $this->ok('ok', ['overview' => $this->redis->overview($this->server($request))]);
    }

    /** Remember the password of the local Redis (set outside the panel). */
    public function connect(Request $request)
    {
        $data = $request->validate(['password' => ['nullable', 'string', 'max:500']]);
        Setting::putSecret('redis_password', $data['password'] ?? null);
        $overview = $this->redis->overview();
        if (! $overview['connected']) {
            Setting::putSecret('redis_password', null);

            return $this->fail($overview['error']);
        }

        return $this->ok('Connected to Redis');
    }

    public function keys(Request $request)
    {
        $data = $request->validate(['pattern' => ['nullable', 'string', 'max:200'], 'cursor' => ['nullable', 'string', 'max:30']]);

        return $this->ok('ok', $this->redis->keys($this->db($request), (string) ($data['pattern'] ?? '*'), (string) ($data['cursor'] ?? '0'), $this->server($request)));
    }

    public function show(Request $request)
    {
        $request->validate(['key' => ['required', 'string', 'max:1024']]);

        return $this->ok('ok', ['item' => $this->redis->get($this->db($request), (string) $request->input('key'), $this->server($request))]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:1024'],
            'type' => ['required', 'in:'.implode(',', RedisManager::TYPES)],
            'value' => ['present', 'nullable', 'string', 'max:5000000'],
            'ttl' => ['nullable', 'integer', 'min:0'],
        ]);
        $this->redis->set($this->db($request), $data['key'], $data['type'], (string) $data['value'], (int) ($data['ttl'] ?? 0), $this->server($request));
        $this->audit('database', 'Redis: saved key', 'db'.$this->db($request).' '.$data['key']);

        return $this->ok('Key saved');
    }

    public function destroy(Request $request)
    {
        $data = $request->validate(['keys' => ['required', 'array', 'min:1', 'max:1000'], 'keys.*' => ['string', 'max:1024']]);
        $count = $this->redis->delete($this->db($request), $data['keys'], $this->server($request));
        $this->audit('database', "Redis: deleted {$count} key(s)", 'db'.$this->db($request));

        return $this->ok("{$count} key(s) deleted");
    }

    public function expire(Request $request)
    {
        $data = $request->validate(['key' => ['required', 'string', 'max:1024'], 'ttl' => ['required', 'integer', 'min:0']]);
        $this->redis->expire($this->db($request), $data['key'], (int) $data['ttl'], $this->server($request));

        return $this->ok($data['ttl'] > 0 ? "Key expires in {$data['ttl']} s" : 'Expiration removed');
    }

    public function flush(Request $request)
    {
        $this->redis->flush($this->db($request), $this->server($request));
        $this->audit('database', 'Redis: flushed db'.$this->db($request));

        return $this->ok('Database db'.$this->db($request).' flushed');
    }

    public function configure(Request $request)
    {
        $data = $request->validate([
            'maxmemory' => ['nullable', 'regex:/^\d+(kb|mb|gb|k|m|g)?$/i'],
            'maxmemory_policy' => ['nullable', 'in:noeviction,allkeys-lru,allkeys-lfu,allkeys-random,volatile-lru,volatile-lfu,volatile-random,volatile-ttl'],
            'appendonly' => ['nullable', 'in:yes,no'],
            'change_password' => ['nullable', 'boolean'],
            'requirepass' => ['nullable', 'string', 'max:500'],
        ]);
        $server = $this->server($request);
        $settings = array_filter([
            'maxmemory' => $data['maxmemory'] ?? null,
            'maxmemory-policy' => $data['maxmemory_policy'] ?? null,
            'appendonly' => $data['appendonly'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
        if ($request->boolean('change_password')) {
            $settings['requirepass'] = (string) ($data['requirepass'] ?? '');
        }
        $this->redis->configure($settings, $server);
        if ($server && array_key_exists('requirepass', $settings)) {
            $server->update(['password' => $settings['requirepass'] ?: null]);
        }
        $this->audit('database', 'Redis: changed configuration', implode(', ', array_keys($settings)));

        return $this->ok('Redis configuration saved');
    }

    public function backup()
    {
        return $this->task($this->redis->backup(), 'Backup started');
    }

    public function backups()
    {
        return $this->ok('ok', ['backups' => $this->redis->backups()]);
    }

    public function restore(Request $request)
    {
        $task = $this->redis->restore((string) $request->input('file'));
        $this->audit('database', 'Redis: restore '.$request->input('file'));

        return $this->task($task, 'Restore started');
    }

    public function download(string $file, FileManager $files)
    {
        $path = $this->redis->backupPath($file);

        return response()->streamDownload(fn () => $files->stream($path), $file, ['Content-Type' => 'application/octet-stream']);
    }

    public function deleteBackup(string $file)
    {
        return $this->result(Shell::run('rm -f '.Shell::arg($this->redis->backupPath($file))), 'Backup deleted', 'database', $file);
    }
}
