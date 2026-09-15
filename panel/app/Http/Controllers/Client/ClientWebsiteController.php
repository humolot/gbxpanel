<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\WebsiteController;
use App\Http\Controllers\WebsiteSettingsController;
use App\Models\Setting;
use App\Models\Website;
use App\Services\ApacheManager;
use App\Services\FileManager;
use App\Services\FtpManager;
use App\Services\MysqlManager;
use App\Services\Shell;
use App\Services\SoftwareManager;
use App\Services\SslManager;
use App\Services\WebsiteTraffic;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Websites of the client: create within the package, start/stop, PHP version, SSL, logs, delete. */
class ClientWebsiteController extends ClientPanelController
{
    public function __construct(protected ApacheManager $apache) {}

    /** PHP versions installed on the server and allowed by the package. */
    protected function phpVersions(SoftwareManager $software): array
    {
        $package = $this->client()->package;

        return array_values(array_filter($software->phpVersions(), fn ($v) => ! $package || $package->allowsPhp($v)));
    }

    public function index(SoftwareManager $software, WebsiteTraffic $traffic)
    {
        $client = $this->client()->load('package');
        $sites = $client->websites()->withCount(['databases', 'ftpAccounts'])->orderBy('domain')->get();

        return view('client.websites', [
            'client' => $client,
            'sites' => $sites,
            'traffic' => $traffic->hourly($sites),
            'phpVersions' => $this->phpVersions($software),
            'defaultPhp' => config('gbx.default_php'),
            'sslEmail' => $client->email ?: Setting::get('ssl_email'),
            'canAdd' => $client->canAdd('websites', 'max_websites'),
        ]);
    }

    /** Domain and aliases must not be used by any other website (Apache would route them to the wrong site). */
    protected function assertFreeNames(array $names, ?Website $except = null): void
    {
        foreach (Website::query()->when($except, fn ($q) => $q->whereKeyNot($except->id))->get(['id', 'domain', 'aliases']) as $site) {
            $taken = array_intersect($names, array_merge([$site->domain], $site->aliasList()));
            if ($taken) {
                throw ValidationException::withMessages(['domain' => reset($taken).' is already used by another website on this server.']);
            }
        }
    }

    public function store(Request $request, SoftwareManager $software)
    {
        if ($error = $this->ensureCanAdd('websites', 'max_websites', 'website(s)')) {
            return $error;
        }
        $request->merge(['domain' => strtolower(trim((string) $request->input('domain')))]);
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253', 'regex:'.WebsiteController::DOMAIN_REGEX, 'not_regex:/^\*\./'],
            'aliases' => ['nullable', 'string', 'max:1000'],
            'php_version' => ['nullable', 'string', 'max:10'],
            'add_www' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $php = ($data['php_version'] ?? '') ?: null;
        if ($php !== null && ! in_array($php, $this->phpVersions($software), true)) {
            throw ValidationException::withMessages(['php_version' => 'This PHP version is not available in your package.']);
        }

        $aliases = array_filter(array_map(fn ($a) => strtolower(trim($a)), preg_split('/[\s,]+/', (string) ($data['aliases'] ?? ''))));
        if ($request->boolean('add_www') && ! str_starts_with($data['domain'], 'www.')) {
            $aliases[] = 'www.'.$data['domain'];
        }
        $aliases = array_values(array_unique(array_diff($aliases, [$data['domain']])));
        foreach ($aliases as $alias) {
            if (! preg_match(WebsiteController::DOMAIN_REGEX, $alias) || str_starts_with($alias, '*.')) {
                throw ValidationException::withMessages(['aliases' => "Invalid alias: {$alias}"]);
            }
        }
        $this->assertFreeNames(array_merge([$data['domain']], $aliases));

        // document root is always managed by the panel for clients
        $root = rtrim(config('gbx.paths.www'), '/').'/'.$data['domain'];
        if (Shell::test('[ -e '.Shell::arg($root).' ]')) {
            throw ValidationException::withMessages(['domain' => 'The folder for this domain already exists on the server. Contact support.']);
        }

        $site = Website::query()->create([
            'domain' => $data['domain'],
            'aliases' => implode(' ', $aliases),
            'root_path' => $root,
            'php_version' => $php,
            'notes' => $data['notes'] ?? null,
            'client_id' => $this->client()->id,
        ]);
        $this->apache->createRoot($site);
        $result = $this->apache->write($site);
        if ($result->failed()) {
            $site->delete();

            return $this->fail($result->message());
        }
        $this->audit('website', "Created website {$site->domain}", $root);

        return $this->ok('Website created');
    }

    public function status(Website $website)
    {
        $this->owned($website);
        $website->status = $website->status === 'active' ? 'stopped' : 'active';
        $result = $this->apache->write($website);
        if ($result->failed()) {
            return $this->fail($result->message());
        }
        $website->save();
        $this->audit('website', ($website->status === 'active' ? 'Started' : 'Stopped')." website {$website->domain}");

        return $this->ok($website->status === 'active' ? 'Website started' : 'Website stopped');
    }

    public function php(Request $request, Website $website, SoftwareManager $software)
    {
        $this->owned($website);
        $version = (string) $request->input('php_version', '');
        if ($version !== '' && ! in_array($version, $this->phpVersions($software), true)) {
            return $this->fail('This PHP version is not available in your package.');
        }
        $previous = $website->php_version;
        $website->php_version = $version ?: null;
        $result = $this->apache->write($website);
        if ($result->failed()) {
            $website->php_version = $previous;

            return $this->fail($result->message());
        }
        $website->save();
        $this->audit('website', "Changed PHP of {$website->domain} to ".($version ?: 'static'));

        return $this->ok('PHP version changed');
    }

    public function ssl(Request $request, Website $website, SslManager $ssl)
    {
        $this->owned($website);
        if (! ($this->client()->package?->allow_ssl ?? false)) {
            return $this->fail('SSL certificates are not included in your package.');
        }
        $data = $request->validate(['email' => ['required', 'email', 'max:190']]);
        try {
            $task = $ssl->issue($website, $data['email'], true);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->task($task, 'Requesting certificate from Let\'s Encrypt');
    }

    public function logs(Request $request, Website $website)
    {
        $this->owned($website);

        return app(WebsiteSettingsController::class)->logs($request, $website);
    }

    public function destroy(Request $request, Website $website, MysqlManager $mysql, FtpManager $ftp)
    {
        $this->owned($website);
        $this->apache->remove($website);

        if ($request->boolean('delete_databases')) {
            foreach ($website->databases()->where('client_id', $this->client()->id)->get() as $db) {
                \App\Services\Databases\Engines::get($db->engine)->drop($db->name, $db->username, $db->server);
                $db->delete();
            }
        }
        if ($request->boolean('delete_ftp')) {
            foreach ($website->ftpAccounts()->where('client_id', $this->client()->id)->get() as $account) {
                $ftp->delete($account->username);
                $account->delete();
            }
        }
        $www = rtrim(config('gbx.paths.www'), '/').'/';
        if ($request->boolean('delete_files') && str_starts_with($website->root_path, $www) && ! in_array($website->root_path, FileManager::PROTECTED, true)) {
            Shell::run('rm -rf -- '.Shell::arg($website->root_path), 300);
        }

        $domain = $website->domain;
        $website->delete();
        $this->audit('website', "Deleted website {$domain}");

        return $this->ok('Website deleted');
    }
}
