<?php

namespace App\Http\Controllers;

use App\Models\FtpAccount;
use App\Models\MysqlDatabase;
use App\Models\Setting;
use App\Models\Website;
use App\Services\ApacheManager;
use App\Services\BackupManager;
use App\Services\FileManager;
use App\Services\FtpManager;
use App\Services\MysqlManager;
use App\Services\Shell;
use App\Services\SoftwareManager;
use App\Services\SslManager;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WebsiteController extends Controller
{
    public const DOMAIN_REGEX = '/^(?=.{1,253}$)(\*\.)?([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    public function __construct(protected ApacheManager $apache) {}

    public function index(SoftwareManager $software, BackupManager $backups)
    {
        $websites = Website::query()->withCount(['databases', 'ftpAccounts'])->orderBy('domain')->get();
        $files = array_column($backups->list('site'), 'file');

        return view('websites.index', [
            'websites' => $websites,
            'backupCounts' => $websites->mapWithKeys(fn ($w) => [$w->id => count(array_filter($files, fn ($f) => str_starts_with($f, 'site/'.$w->domain.'_')))]),
            'phpVersions' => $software->phpVersions(),
            'defaultPhp' => config('gbx.default_php'),
            'wwwRoot' => config('gbx.paths.www'),
            'mysql' => $software->mysqlInstalled(),
        ]);
    }

    protected function parseAliases(?string $aliases, string $domain): string
    {
        $list = array_unique(array_filter(array_map(fn ($a) => strtolower(trim($a)), preg_split('/[\s,]+/', (string) $aliases))));
        foreach ($list as $alias) {
            if (! preg_match(self::DOMAIN_REGEX, $alias) || $alias === $domain) {
                throw ValidationException::withMessages(['aliases' => "Invalid alias: {$alias}"]);
            }
        }

        return implode(' ', $list);
    }

    public function store(Request $request, MysqlManager $mysql, FtpManager $ftp)
    {
        $request->merge(['domain' => strtolower(trim((string) $request->input('domain')))]);
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253', 'regex:'.self::DOMAIN_REGEX, 'unique:websites,domain'],
            'aliases' => ['nullable', 'string', 'max:2000'],
            'root_path' => ['nullable', 'string', 'max:255'],
            'php_version' => ['nullable', 'string'],
            'proxy_target' => ['nullable', 'string', 'max:255'],
            'add_www' => ['nullable', 'boolean'],
            'create_database' => ['nullable', 'boolean'],
            'create_ftp' => ['nullable', 'boolean'],
            'create_dns' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $phpVersions = app(SoftwareManager::class)->phpVersions();
        if (! empty($data['php_version']) && ! in_array($data['php_version'], $phpVersions, true)) {
            throw ValidationException::withMessages(['php_version' => 'This PHP version is not installed.']);
        }
        if (! ApacheManager::validProxyTarget($data['proxy_target'] ?? null)) {
            throw ValidationException::withMessages(['proxy_target' => 'Use a URL like http://127.0.0.1:3000']);
        }

        $aliases = $data['aliases'] ?? '';
        if ($request->boolean('add_www') && ! str_starts_with($data['domain'], 'www.')) {
            $aliases .= ' www.'.$data['domain'];
        }

        $root = FileManager::normalize(($data['root_path'] ?? null) ?: rtrim(config('gbx.paths.www'), '/').'/'.$data['domain']);
        if (in_array($root, FileManager::PROTECTED, true)) {
            throw ValidationException::withMessages(['root_path' => 'This directory cannot be used as a document root.']);
        }

        $site = Website::query()->create([
            'domain' => $data['domain'],
            'aliases' => $this->parseAliases($aliases, $data['domain']),
            'root_path' => $root,
            'php_version' => ($data['php_version'] ?? null) ?: null,
            'proxy_target' => ($data['proxy_target'] ?? null) ?: null,
            'notes' => $data['notes'] ?? null,
        ]);

        $this->apache->createRoot($site);
        $result = $this->apache->write($site);
        if ($result->failed()) {
            $site->delete();

            return $this->fail($result->message());
        }

        $extra = [];
        $base = substr(preg_replace('/[^a-z0-9]/', '_', $site->domain), 0, 24);

        if ($request->boolean('create_database')) {
            $name = $this->uniqueDbName($base);
            $password = Str::password(20, symbols: false);
            $r = $mysql->create($name, $name, $password);
            if ($r->ok()) {
                MysqlDatabase::query()->create(['name' => $name, 'username' => $name, 'password' => $password, 'website_id' => $site->id]);
                $extra['database'] = ['name' => $name, 'username' => $name, 'password' => $password];
            } else {
                $extra['warnings'][] = 'Database was not created: '.$r->message();
            }
        }

        if ($request->boolean('create_ftp')) {
            $username = $this->uniqueFtpName($base);
            $password = Str::password(16, symbols: false);
            $r = $ftp->create($username, $password, $root);
            if ($r->ok()) {
                FtpAccount::query()->create(['username' => $username, 'password' => $password, 'path' => $root, 'website_id' => $site->id]);
                $extra['ftp'] = ['username' => $username, 'password' => $password];
            } else {
                $extra['warnings'][] = 'FTP account was not created: '.$r->message();
            }
        }

        $dns = app(\App\Services\Dns\DnsManager::class);
        if ($request->boolean('create_dns') && $dns->zoneFor($site->domain)) {
            // A/AAAA records of the domain and its aliases in the DNS API accounts
            $extra['dns'] = $dns->pointToServer(array_merge([$site->domain], $site->aliasList()));
        }

        $this->audit('website', "Created website {$site->domain}", $root);

        return $this->ok('Website created', $extra + ['id' => $site->id]);
    }

    protected function uniqueDbName(string $base): string
    {
        $name = $base;
        $i = 1;
        while (MysqlDatabase::query()->engine('mysql')->whereNull('server_id')->where('name', $name)->exists()) {
            $name = substr($base, 0, 20).'_'.$i++;
        }

        return $name;
    }

    protected function uniqueFtpName(string $base): string
    {
        $name = $base;
        $i = 1;
        while (FtpAccount::query()->where('username', $name)->exists()) {
            $name = substr($base, 0, 26).'_'.$i++;
        }

        return $name;
    }

    /** Site settings live in the Conf modal of the website list. */
    public function show(Website $website)
    {
        return redirect()->route('websites.index', ['conf' => $website->id]);
    }

    public function update(Request $request, Website $website)
    {
        $data = $request->validate([
            'aliases' => ['nullable', 'string', 'max:2000'],
            'root_path' => ['required', 'string', 'max:255'],
            'php_version' => ['nullable', 'string'],
            'proxy_target' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        if (! empty($data['php_version']) && ! in_array($data['php_version'], app(SoftwareManager::class)->phpVersions(), true)) {
            throw ValidationException::withMessages(['php_version' => 'This PHP version is not installed.']);
        }
        if (! ApacheManager::validProxyTarget($data['proxy_target'] ?? null)) {
            throw ValidationException::withMessages(['proxy_target' => 'Use a URL like http://127.0.0.1:3000']);
        }
        $root = FileManager::normalize($data['root_path']);
        if (in_array($root, FileManager::PROTECTED, true)) {
            throw ValidationException::withMessages(['root_path' => 'This directory cannot be used as a document root.']);
        }

        $original = $website->getAttributes();
        $website->fill([
            'aliases' => $this->parseAliases($data['aliases'] ?? '', $website->domain),
            'root_path' => $root,
            'php_version' => ($data['php_version'] ?? null) ?: null,
            'proxy_target' => ($data['proxy_target'] ?? null) ?: null,
            'notes' => $data['notes'] ?? null,
        ]);

        if ($website->isDirty('root_path')) {
            Shell::run('mkdir -p '.Shell::arg($root).' && chown '.Shell::arg(config('gbx.web_user').':'.config('gbx.web_user')).' '.Shell::arg($root));
        }

        $result = $this->apache->write($website);
        if ($result->failed()) {
            $website->setRawAttributes($original);

            return $this->fail($result->message());
        }
        $website->save();
        $this->audit('website', "Updated {$website->domain}");

        return $this->ok('Website updated', ['reload' => true]);
    }

    public function status(Website $website)
    {
        $website->status = $website->status === 'active' ? 'stopped' : 'active';
        $result = $this->apache->write($website);
        if ($result->failed()) {
            return $this->fail($result->message());
        }
        $website->save();
        $this->audit('website', ($website->status === 'active' ? 'Started' : 'Stopped')." website {$website->domain}");

        return $this->ok($website->status === 'active' ? 'Website started' : 'Website stopped', ['status' => $website->status]);
    }

    public function destroy(Request $request, Website $website, MysqlManager $mysql, FtpManager $ftp)
    {
        $this->apache->remove($website);

        if ($request->boolean('delete_databases')) {
            foreach ($website->databases as $db) {
                \App\Services\Databases\Engines::get($db->engine)->drop($db->name, $db->username, $db->server);
                $db->delete();
            }
        }
        if ($request->boolean('delete_ftp')) {
            foreach ($website->ftpAccounts as $account) {
                $ftp->delete($account->username);
                $account->delete();
            }
        }
        if ($request->boolean('delete_files') && ! in_array($website->root_path, FileManager::PROTECTED, true)
            && str_starts_with($website->root_path, rtrim(config('gbx.paths.www'), '/').'/')) {
            Shell::run('rm -rf -- '.Shell::arg($website->root_path), 300);
        }

        $domain = $website->domain;
        $website->delete();
        $this->audit('website', "Deleted website {$domain}");

        return $this->ok('Website deleted');
    }

    public function config(Website $website)
    {
        return $this->ok('ok', ['content' => $this->apache->read($website), 'path' => $this->apache->configPath($website)]);
    }

    public function saveConfig(Request $request, Website $website)
    {
        $content = (string) $request->input('content');
        if (trim($content) === '' || ! str_contains($content, '<VirtualHost')) {
            return $this->fail('The configuration must contain a <VirtualHost> block.');
        }

        return $this->result($this->apache->apply($website, str_replace("\r\n", "\n", $content)), 'Configuration saved and Apache reloaded', 'website', $website->domain);
    }

    public function sslIssue(Request $request, Website $website, SslManager $ssl)
    {
        $data = $request->validate(['email' => ['required', 'email'], 'include_aliases' => ['nullable', 'boolean'], 'method' => ['nullable', 'in:http,dns'], 'wildcard' => ['nullable', 'boolean']]);
        Setting::put('ssl_email', $data['email']);
        $method = $data['method'] ?? 'http';

        try {
            $task = $ssl->issue($website, $data['email'], $request->boolean('include_aliases', true), $method, $method === 'dns' && $request->boolean('wildcard'));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->task($task, 'Requesting certificate from Let\'s Encrypt');
    }

    public function sslCustom(Request $request, Website $website, SslManager $ssl)
    {
        $data = $request->validate(['certificate' => ['required', 'string'], 'private_key' => ['required', 'string']]);

        return $this->result($ssl->saveCustom($website, $data['certificate'], $data['private_key']), 'Custom certificate installed', 'ssl', $website->domain);
    }

    public function sslDisable(Website $website, SslManager $ssl)
    {
        return $this->result($ssl->disable($website), 'SSL disabled', 'ssl', $website->domain);
    }

    public function sslForce(Request $request, Website $website)
    {
        if (! $website->ssl_enabled) {
            return $this->fail('Enable SSL first.');
        }
        $website->force_https = $request->boolean('enabled');
        $result = $this->apache->write($website);
        if ($result->failed()) {
            return $this->fail($result->message());
        }
        $website->save();

        return $this->ok($website->force_https ? 'HTTPS redirect enabled' : 'HTTPS redirect disabled');
    }
}
