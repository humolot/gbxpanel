<?php

namespace App\Http\Controllers;

use App\Models\BackupStorage;
use App\Models\BackupTransfer;
use App\Models\CronJob;
use App\Models\MysqlDatabase;
use App\Models\Setting;
use App\Models\Website;
use App\Services\Backup\OAuthTokens;
use App\Services\Backup\StorageException;
use App\Services\Backup\StorageManager;
use App\Services\Backup\TransferManager;
use App\Services\BackupManager;
use App\Services\Databases\Engines;
use App\Services\Shell;
use App\Services\SystemStats;
use App\Services\TaskRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Backup page: local backups, remote storages (rclone) and the transfers between them.
 */
class BackupController extends Controller
{
    public function __construct(protected StorageManager $storages, protected TransferManager $transfers, protected BackupManager $backups) {}

    public function index(Request $request)
    {
        $tab = in_array($request->query('tab'), ['transfers', 'local', 'storage', 'settings'], true) ? $request->query('tab') : 'transfers';
        $this->transfers->refreshStale();

        return view('backup.index', [
            'tab' => $tab,
            'types' => $this->types(),
            'groups' => StorageManager::GROUPS,
            'storages' => BackupStorage::query()->orderBy('name')->get(),
            'rclone' => ['installed' => $this->storages->installed(), 'version' => $this->storages->version()],
            'settings' => $this->settings(),
            'redirectUri' => route('backup.google.callback'),
            'secureRequest' => $request->isSecure(),
            'backupRoot' => $this->backups->root(),
            'failed24h' => BackupTransfer::query()->where('status', 'failed')->where('updated_at', '>=', now()->subDay())->count(),
            'openAdd' => (bool) $request->query('add'),
        ]);
    }

    /** Storage types with their fields, for the forms. */
    protected function types(): array
    {
        $types = [];
        foreach (StorageManager::TYPES as $key => $type) {
            $types[] = [
                'key' => $key,
                'name' => $type['name'],
                'group' => $type['group'],
                'icon' => $type['icon'],
                'color' => $type['color'],
                'fields' => $type['fields'],
                'docs' => $type['docs'],
                'notes' => $type['notes'],
                'bucket' => (bool) ($type['bucket'] ?? false),
                'absolute' => (bool) ($type['absolute'] ?? false),
                'split' => (bool) ($type['split'] ?? false),
                'oauth' => $type['oauth'] ?? null,
                'authorize' => $type['authorize'] ?? null,
            ];
        }

        return $types;
    }

    public function settings(): array
    {
        return [
            'webhook' => (string) Setting::get('backup_webhook', ''),
            'webhook_success' => (bool) Setting::get('backup_webhook_success', false),
            'bwlimit' => (string) Setting::get('backup_bwlimit', ''),
            'split_mb' => (int) Setting::get('backup_split_mb', TransferManager::DEFAULT_SPLIT_MB),
            'max_parallel' => (int) Setting::get('backup_max_parallel', 2),
            'history_days' => (int) Setting::get('backup_history_days', 30),
        ];
    }

    public function saveSettings(Request $request)
    {
        $data = $request->validate([
            'webhook' => ['nullable', 'url:http,https', 'max:1000'],
            'bwlimit' => ['nullable', 'string', 'max:20', 'regex:/^\d+(\.\d+)?[KMG]?$/i'],
            'split_mb' => ['required', 'integer', 'min:64', 'max:20480'],
            'max_parallel' => ['required', 'integer', 'min:1', 'max:10'],
            'history_days' => ['required', 'integer', 'min:1', 'max:365'],
        ], ['bwlimit.regex' => 'Use a number with an optional K, M or G, for example 10M.']);

        Setting::put('backup_webhook', (string) ($data['webhook'] ?? ''));
        Setting::put('backup_webhook_success', $request->boolean('webhook_success'));
        Setting::put('backup_bwlimit', (string) ($data['bwlimit'] ?? ''));
        Setting::put('backup_split_mb', $data['split_mb']);
        Setting::put('backup_max_parallel', $data['max_parallel']);
        Setting::put('backup_history_days', $data['history_days']);
        $this->audit('backup', 'Updated the backup settings');

        return $this->ok('Settings saved');
    }

    public function install()
    {
        if ($this->storages->installed()) {
            return $this->fail('rclone is already installed.');
        }

        return $this->task(TaskRunner::dispatch('Install rclone', StorageManager::installScript(), 'software'), 'Installing rclone');
    }

    /* ============================================================= storages */

    public function storageList()
    {
        return $this->ok('ok', ['data' => BackupStorage::query()->orderBy('name')->get()->map(fn (BackupStorage $s) => $this->storageRow($s))->all()]);
    }

    protected function storageRow(BackupStorage $storage): array
    {
        return [
            'id' => $storage->id,
            'type' => $storage->type,
            'type_name' => $storage->typeName(),
            'name' => $storage->name,
            'label' => $storage->label(),
            'root' => $this->storages->root($storage),
            'folder' => $storage->folder,
            'is_active' => $storage->is_active,
            'bwlimit' => $storage->bwlimit,
            'account' => $storage->account,
            'used' => $storage->used_bytes !== null ? SystemStats::bytes((int) $storage->used_bytes, 1) : null,
            'total' => $storage->total_bytes !== null ? SystemStats::bytes((int) $storage->total_bytes, 1) : null,
            'checked_at' => $storage->checked_at?->toDateTimeString(),
            'last_error' => $storage->last_error,
            'credentials' => $storage->maskedCredentials(),
            'stored' => $storage->storedSecrets(),
            'transfers' => $storage->transfers()->count(),
        ];
    }

    /**
     * Values for the fields of a type. Secrets that are left empty keep the stored value.
     *
     * @return array{0: array, 1: array} model attributes and credentials
     */
    protected function storageData(Request $request, ?BackupStorage $storage = null): array
    {
        $data = $request->validate([
            'type' => [$storage ? 'nullable' : 'required', Rule::in(array_keys(StorageManager::TYPES))],
            'name' => ['required', 'string', 'max:60'],
            'folder' => ['nullable', 'string', 'max:255'],
            'bwlimit' => ['nullable', 'string', 'max:20', 'regex:/^\d+(\.\d+)?[KMG]?$/i'],
            'is_active' => ['nullable', 'boolean'],
            'credentials' => ['nullable', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:20000'],
        ], ['bwlimit.regex' => 'Use a number with an optional K, M or G, for example 10M.']);

        $type = $storage?->type ?? $data['type'];
        $definition = StorageManager::TYPES[$type];
        $input = (array) ($data['credentials'] ?? []);
        $old = (array) ($storage?->credentials ?? []);
        $credentials = [];
        $errors = [];

        foreach ($definition['fields'] as $key => $field) {
            $kind = $field['type'] ?? 'text';
            $value = (string) ($input[$key] ?? '');
            $value = $kind === 'secret_text' || $kind === 'token' ? trim($value) : trim(str_replace(["\r", "\n"], '', $value));

            if ($kind === 'checkbox') {
                $credentials[$key] = filter_var($value, FILTER_VALIDATE_BOOL) ? '1' : '';

                continue;
            }
            if ($value === '' && in_array($kind, StorageManager::secretTypes(), true) && isset($old[$key])) {
                $value = (string) $old[$key]; // unchanged secret
            }
            if ($kind === 'select' && $value === '') {
                $value = (string) array_key_first($field['options']);
            }
            if ($kind === 'select' && ! array_key_exists($value, $field['options'])) {
                $errors['credentials.'.$key] = 'Choose one of the listed options for '.$field['label'].'.';
            }
            if ($value === '' && ! empty($field['default'])) {
                $value = (string) $field['default'];
            }
            if ($value === '' && ! empty($field['required'])) {
                $errors['credentials.'.$key] = $field['label'].' is required.';
            }
            $credentials[$key] = $value;
        }

        $this->checkCredentials($type, $credentials, $errors);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $folder = (string) ($data['folder'] ?? '');
        $absolute = ! empty($definition['absolute']) && str_starts_with(trim($folder), '/');
        try {
            $folder = ($absolute ? '/' : '').StorageManager::cleanPath($folder);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['folder' => $e->getMessage()]);
        }

        return [[
            'name' => $data['name'],
            'folder' => $folder === '/' ? '' : $folder,
            'bwlimit' => $data['bwlimit'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ] + ($storage ? [] : ['type' => $type]), array_filter($credentials, fn ($v) => $v !== '')];
    }

    /** Checks that do not fit a simple field rule. */
    protected function checkCredentials(string $type, array &$credentials, array &$errors): void
    {
        $definition = StorageManager::TYPES[$type];
        $check = function (string $key, string $pattern, string $message) use (&$credentials, &$errors) {
            if (($credentials[$key] ?? '') !== '' && ! preg_match($pattern, $credentials[$key])) {
                $errors['credentials.'.$key] = $message;
            }
        };
        $check('region', '/^[a-zA-Z0-9-]{2,40}$/', 'The region contains invalid characters.');
        $check('bucket', '/^[A-Za-z0-9][A-Za-z0-9._-]{1,62}$/', 'The bucket name contains invalid characters.');
        $check('host', '/^[A-Za-z0-9.:_-]{1,190}$/', 'The host contains invalid characters.');
        $check('port', '/^\d{1,5}$/', 'The port must be a number.');
        $check('account_id', '/^[a-f0-9]{16,64}$/i', 'The Cloudflare account ID is a hexadecimal value.');
        $check('endpoint', '#^(https?://)?[A-Za-z0-9.:_-]{3,190}(/.*)?$#', 'The endpoint must be a host name or a URL.');
        $check('url', '#^https?://[^\s]{3,}$#', 'The URL must start with http:// or https://.');

        if (($definition['backend'] ?? '') === 'sftp' && ($credentials['pass'] ?? '') === '' && ($credentials['key_pem'] ?? '') === '') {
            $errors['credentials.pass'] = 'Enter the password or a private key.';
        }
        if (($credentials['key_pem'] ?? '') !== '' && ! str_contains($credentials['key_pem'], 'PRIVATE KEY')) {
            $errors['credentials.key_pem'] = 'That does not look like a private key.';
        }
        if (($credentials['service_account_credentials'] ?? '') !== '') {
            $json = json_decode($credentials['service_account_credentials'], true);
            if (! is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
                $errors['credentials.service_account_credentials'] = 'Paste the complete JSON key of a service account.';
            }
        }

        // OAuth backends: a token pasted from rclone authorize, or the one stored by "Connect"
        if (! empty($definition['authorize'])) {
            $token = (string) ($credentials['token'] ?? '');
            if ($token === '') {
                $errors['credentials.token'] = ! empty($definition['oauth'])
                    ? 'Use Connect with Google, or paste the token of the rclone authorize command.'
                    : 'Paste the answer of the rclone authorize command.';
            } elseif (! str_starts_with($token, '{')) {
                try {
                    $credentials['token'] = OAuthTokens::parsePasted($token);
                } catch (StorageException $e) {
                    $errors['credentials.token'] = $e->getMessage();
                }
            }
        }
        if ($type === 'onedrive' && ($credentials['token'] ?? '') !== '' && ($credentials['drive_id'] ?? '') === '' && ! Shell::simulating()) {
            try {
                $credentials = array_merge($credentials, OAuthTokens::oneDriveInfo($credentials['token']));
            } catch (StorageException $e) {
                $errors['credentials.drive_id'] = $e->getMessage();
            }
        }
    }

    /** Save a storage only when rclone can actually write to it. */
    protected function saveStorage(BackupStorage $storage, string $action): \Illuminate\Http\JsonResponse
    {
        try {
            $account = $this->storages->test($storage);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        $storage->fill(['account' => mb_substr($account, 0, 190), 'checked_at' => now(), 'last_error' => null])->save();
        $this->audit('backup', $action.' storage '.$storage->label());

        return $this->ok($action.' storage '.$storage->name, ['storage' => $this->storageRow($storage->fresh())]);
    }

    public function storageStore(Request $request)
    {
        [$attributes, $credentials] = $this->storageData($request);

        return $this->saveStorage(new BackupStorage($attributes + ['credentials' => $credentials]), 'Added');
    }

    public function storageUpdate(Request $request, BackupStorage $storage)
    {
        [$attributes, $credentials] = $this->storageData($request, $storage);
        $storage->fill($attributes + ['credentials' => $credentials]);

        return $this->saveStorage($storage, 'Updated');
    }

    public function storageToggle(BackupStorage $storage)
    {
        $storage->update(['is_active' => ! $storage->is_active]);

        return $this->ok($storage->name.' is now '.($storage->is_active ? 'enabled' : 'disabled'));
    }

    public function storageTest(BackupStorage $storage)
    {
        try {
            $account = $this->storages->test($storage);
        } catch (\Throwable $e) {
            $storage->update(['last_error' => mb_substr($e->getMessage(), 0, 500)]);

            return $this->fail($e->getMessage(), 422);
        }
        $storage->update(['account' => mb_substr($account, 0, 190), 'checked_at' => now(), 'last_error' => null]);

        return $this->ok('The panel can write to '.$storage->name.'.');
    }

    public function storageUsage(BackupStorage $storage)
    {
        try {
            $about = $this->storages->about($storage);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        $storage->update(['used_bytes' => $about['used'] ?? null, 'total_bytes' => $about['total'] ?? null, 'checked_at' => now()]);

        return $this->ok('Usage updated', ['usage' => [
            'used' => SystemStats::bytes((int) ($about['used'] ?? 0), 1),
            'total' => isset($about['total']) && $about['total'] ? SystemStats::bytes((int) $about['total'], 1) : null,
        ]]);
    }

    public function storageDestroy(BackupStorage $storage)
    {
        $jobs = CronJob::query()->whereIn('type', ['site_backup', 'db_backup', 'path_backup'])->get()
            ->filter(fn (CronJob $job) => (int) (($job->params ?? [])['storage'] ?? 0) === $storage->id);
        if ($jobs->isNotEmpty()) {
            return $this->fail('This storage is used by the scheduled task(s): '.$jobs->pluck('name')->implode(', ').'. Change them first.');
        }
        if ((int) Setting::get('db_backup_storage', 0) === $storage->id) {
            return $this->fail('This storage is used by the automatic database backup (Databases > Automatic backup). Change it first.');
        }
        if ($storage->transfers()->whereIn('status', BackupTransfer::ACTIVE)->exists()) {
            return $this->fail('A transfer of this storage is still running.');
        }
        $name = $storage->label();
        $storage->delete();
        $this->audit('backup', 'Removed storage '.$name);

        return $this->ok('Storage removed. The backups already sent to it are not deleted.');
    }

    /* =============================================================== browse */

    public function browse(Request $request, BackupStorage $storage)
    {
        try {
            $path = StorageManager::cleanPath((string) $request->query('path'));
            $items = $this->storages->browse($storage, $path);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok('ok', [
            'path' => $path,
            'root' => $this->storages->root($storage),
            'items' => array_map(fn (array $item) => $item + [
                'size_text' => $item['dir'] ? '-' : SystemStats::bytes($item['size'], 1),
                'date' => $item['time'] ? date('Y-m-d H:i', $item['time']) : '-',
                'restore' => $item['dir'] && ! $item['parts'] ? null : $this->restoreTarget($item['path']),
            ], $items),
        ]);
    }

    /** Website or database a remote backup belongs to, so it can be restored in one step. */
    protected function restoreTarget(string $path): ?array
    {
        $parts = explode('/', $path);
        $name = preg_replace('/\.parts$/', '', (string) end($parts));

        if (($parts[0] ?? '') === 'site' && isset($parts[1])) {
            $site = Website::query()->where('domain', $parts[1])->first();

            return $site ? ['type' => 'site', 'id' => $site->id, 'label' => $site->domain] : null;
        }
        if (($parts[0] ?? '') === 'database' && isset($parts[2]) && Engines::isDatabaseEngine($parts[1])) {
            $db = MysqlDatabase::query()->where('engine', $parts[1])->where('name', $parts[2])->first();
            $extension = Engines::get($parts[1])->dumpExtension();

            return $db && str_ends_with($name, '.'.$extension) ? ['type' => 'database', 'id' => $db->id, 'label' => $db->name] : null;
        }

        return null;
    }

    public function download(Request $request, BackupStorage $storage)
    {
        try {
            $path = StorageManager::cleanPath((string) $request->query('path'));
        } catch (\InvalidArgumentException) {
            abort(404);
        }
        abort_if($path === '', 404);
        $this->audit('backup', 'Downloaded '.$path.' from '.$storage->name);
        $name = preg_replace('/\.parts$/', '', basename($path));
        $storages = $this->storages;

        return response()->streamDownload(fn () => $storages->stream($storage, $path), $name, ['Content-Type' => 'application/octet-stream']);
    }

    /** Bring a backup back to this server, optionally restoring it right away. */
    public function fetch(Request $request, BackupStorage $storage)
    {
        $data = $request->validate(['path' => ['required', 'string', 'max:1024'], 'restore' => ['nullable', 'boolean']]);
        try {
            $path = StorageManager::cleanPath($data['path']);
            $after = [];
            if ($request->boolean('restore')) {
                $target = $this->restoreTarget($path);
                if (! $target) {
                    return $this->fail('This backup does not match a website or database of this server. Download it and restore it by hand.');
                }
                $after = $target['type'] === 'site'
                    ? ['action' => 'restore_site', 'website_id' => $target['id']]
                    : ['action' => 'restore_db', 'database_id' => $target['id']];
            }
            $transfer = $this->transfers->queueDownload($storage, $path, $after);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        $this->transfers->launch($transfer);
        $this->audit('backup', 'Download '.$path.' from '.$storage->name, $request->boolean('restore') ? 'with restore' : null);

        return $this->ok($request->boolean('restore') ? 'Download started; the restore starts when it finishes.' : 'Download started.', ['transfer' => $transfer->id]);
    }

    public function deleteFile(Request $request, BackupStorage $storage)
    {
        $data = $request->validate(['path' => ['required', 'string', 'max:1024']]);
        try {
            $this->storages->delete($storage, $data['path']);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        $this->audit('backup', 'Deleted '.$data['path'].' from '.$storage->name);

        return $this->ok('Backup deleted at the destination');
    }

    /* ============================================================ transfers */

    public function transferList(Request $request)
    {
        $this->transfers->refreshStale();
        $query = BackupTransfer::query()->with('storage')->latest('id');
        if (in_array($request->query('status'), ['active', 'failed', 'success'], true)) {
            $status = $request->query('status');
            $status === 'active' ? $query->whereIn('status', BackupTransfer::ACTIVE) : $query->where('status', $status);
        }

        return $this->ok('ok', [
            'data' => $query->limit(100)->get()->map(fn (BackupTransfer $t) => [
                'id' => $t->id,
                'direction' => $t->direction,
                'status' => $t->status,
                'file' => $t->fileName(),
                'category' => $t->category,
                'label' => $t->label,
                'remote_path' => $t->remote_path,
                'storage' => $t->storage?->name,
                'storage_id' => $t->storage_id,
                'size' => $t->size ? SystemStats::bytes((int) $t->size, 1) : '-',
                'parts' => $t->parts,
                'progress' => $t->progress,
                'message' => $t->message,
                'attempts' => $t->attempts,
                'started_at' => $t->started_at?->toDateTimeString(),
                'finished_at' => $t->finished_at?->toDateTimeString(),
                'created_at' => $t->created_at?->toDateTimeString(),
            ])->all(),
            'active' => BackupTransfer::query()->whereIn('status', BackupTransfer::ACTIVE)->count(),
        ]);
    }

    public function transferLog(BackupTransfer $transfer)
    {
        $file = $transfer->logFile();
        $content = is_file($file) ? (string) file_get_contents($file) : '';

        return $this->ok('ok', ['log' => mb_scrub(mb_substr($content, -200000), 'UTF-8') ?: 'The transfer has not written anything yet.', 'status' => $transfer->status]);
    }

    public function transferRetry(BackupTransfer $transfer)
    {
        try {
            $this->transfers->retry($transfer);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return $this->ok('Transfer started again');
    }

    public function transferCancel(BackupTransfer $transfer)
    {
        if (! $transfer->isActive()) {
            return $this->fail('This transfer is not running.');
        }
        $this->transfers->cancel($transfer);

        return $this->ok('Transfer canceled');
    }

    public function transferDestroy(BackupTransfer $transfer)
    {
        if ($transfer->isActive()) {
            return $this->fail('Cancel the transfer before removing it from the history.');
        }
        @unlink($transfer->logFile());
        $transfer->delete();

        return $this->ok('Removed from the history');
    }

    /* ======================================================== local backups */

    public function localList(Request $request)
    {
        $type = in_array($request->query('type'), ['site', 'database', 'path'], true) ? $request->query('type') : null;

        return $this->ok('ok', ['data' => $this->backups->list($type)]);
    }

    /** Send a backup that is already on this server to a storage. */
    public function upload(Request $request)
    {
        $data = $request->validate([
            'file' => ['required', 'string', 'max:1024'],
            'storage_id' => ['required', 'integer'],
            'delete_local' => ['nullable', 'boolean'],
            'keep' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);
        $storage = BackupStorage::query()->where('is_active', true)->find($data['storage_id']);
        if (! $storage) {
            return $this->fail('Choose an enabled storage.');
        }
        try {
            $file = $this->backups->resolve($data['file']);
            [$category, $label] = $this->classify($file);
            $transfer = $this->transfers->queueUpload($storage, $file, $category, $label, [
                'delete_local' => $request->boolean('delete_local'),
                'keep' => $data['keep'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
        $this->transfers->launch($transfer);
        $this->audit('backup', 'Upload '.basename($file).' to '.$storage->name);

        return $this->ok('Upload started', ['transfer' => $transfer->id]);
    }

    /**
     * Category and series of a local backup file, so it lands in the same folder as the
     * scheduled backups of the same website or database.
     *
     * @return array{0: string, 1: string}
     */
    public function classify(string $file): array
    {
        $root = $this->backups->root();
        $relative = ltrim(Str::after($file, $root), '/');
        $name = basename($file);
        $series = preg_replace('/_\d{8}_\d{6}.*$/', '', $name);

        if (str_starts_with($relative, 'site/')) {
            return ['site', $series];
        }
        if (str_starts_with($relative, 'path/')) {
            return ['path', $series];
        }
        foreach (array_keys(Engines::DATABASE_ENGINES) as $engine) {
            if (str_starts_with($file, Engines::get($engine)->backupDir().'/')) {
                return ['database', $engine.'/'.$series];
            }
        }

        return ['other', $series];
    }

    /* =========================================================== Google OAuth */

    /** Start "Connect with Google": the fields of the form are kept in the session until the callback. */
    public function googleStart(Request $request)
    {
        $data = $request->validate([
            'storage_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:60'],
            'folder' => ['nullable', 'string', 'max:255'],
            'bwlimit' => ['nullable', 'string', 'max:20'],
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:255'],
            'scope' => ['nullable', Rule::in(['drive.file', 'drive'])],
            'root_folder_id' => ['nullable', 'string', 'max:190'],
            'team_drive' => ['nullable', 'string', 'max:190'],
        ]);
        $storage = ! empty($data['storage_id']) ? BackupStorage::query()->where('type', 'drive')->find($data['storage_id']) : null;
        $secret = trim((string) ($data['client_secret'] ?? '')) ?: (string) $storage?->credential('client_secret');
        if ($secret === '') {
            return $this->fail('Enter the Client Secret of the Google OAuth client.');
        }
        if (! $request->isSecure()) {
            return $this->fail('Google only accepts an HTTPS redirect. Open the panel through a domain with SSL, or use the paste code method.');
        }

        $state = Str::random(40);
        $request->session()->put('gbx_google_backup', [
            'state' => $state,
            'storage_id' => $storage?->id,
            'name' => $data['name'],
            'folder' => (string) ($data['folder'] ?? ''),
            'bwlimit' => (string) ($data['bwlimit'] ?? ''),
            'credentials' => [
                'client_id' => trim($data['client_id']),
                'client_secret' => $secret,
                'scope' => $data['scope'] ?? 'drive.file',
                'root_folder_id' => (string) ($data['root_folder_id'] ?? ''),
                'team_drive' => (string) ($data['team_drive'] ?? ''),
            ],
        ]);

        return $this->ok('ok', ['url' => OAuthTokens::googleAuthUrl(trim($data['client_id']), route('backup.google.callback'), $data['scope'] ?? 'drive.file', $state)]);
    }

    public function googleCallback(Request $request)
    {
        $pending = (array) $request->session()->pull('gbx_google_backup');
        $back = route('backup.index', ['tab' => 'storage']);
        if (! $pending || ! hash_equals((string) ($pending['state'] ?? ''), (string) $request->query('state'))) {
            return redirect($back)->with('error', 'The Google authorization expired. Open the form and try again.');
        }
        if ($request->query('error')) {
            return redirect($back)->with('error', 'Google returned: '.$request->query('error'));
        }

        try {
            $token = OAuthTokens::googleExchange($pending['credentials']['client_id'], $pending['credentials']['client_secret'], (string) $request->query('code'), route('backup.google.callback'));
            $storage = $pending['storage_id'] ? BackupStorage::query()->findOrFail($pending['storage_id']) : new BackupStorage(['type' => 'drive']);
            $storage->fill([
                'name' => $pending['name'],
                'folder' => StorageManager::cleanPath($pending['folder']),
                'bwlimit' => $pending['bwlimit'] ?: null,
                'is_active' => true,
                'credentials' => array_merge((array) $storage->credentials, $pending['credentials'], ['token' => $token]),
            ]);
            $storage->save();
            $storage->update(['account' => mb_substr($this->storages->test($storage), 0, 190), 'checked_at' => now(), 'last_error' => null]);
        } catch (\Throwable $e) {
            return redirect($back)->with('error', 'Google Drive was not connected: '.$e->getMessage());
        }
        $this->audit('backup', 'Connected Google Drive storage '.$storage->name);

        return redirect($back)->with('success', 'Google Drive connected as '.$storage->name.'.');
    }
}
