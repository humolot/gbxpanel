<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Task;
use App\Models\Website;
use App\Services\ApacheManager;
use App\Services\BackupManager;
use App\Services\FileManager;
use App\Services\GitDeployer;
use App\Services\Shell;
use App\Services\SoftwareManager;
use App\Services\SslManager;
use App\Services\TaskRunner;
use App\Services\WebsiteTraffic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Website settings modal (Websites > Conf), the usage/log modal, backups and bulk actions.
 */
class WebsiteSettingsController extends Controller
{
    public const SECTIONS = ['domains', 'directory', 'access', 'rewrite', 'index', 'config', 'ssl', 'php', 'git', 'composer', 'redirects', 'proxy', 'hotlink', 'maintenance'];

    public function __construct(protected ApacheManager $apache) {}

    /* ================================================================ modal */

    public function manage(Website $website, SoftwareManager $software, SslManager $ssl, GitDeployer $git)
    {
        $html = view('websites.manage', [
            'site' => $website,
            'phpVersions' => $software->phpVersions(),
            'certificate' => $ssl->certificateInfo($website),
            'sslEmail' => Setting::get('ssl_email', auth()->user()->email),
            'dnsZone' => app(\App\Services\Dns\DnsManager::class)->zoneFor($website->domain),
            'templates' => config('rewrite'),
            'hasToken' => $website->setting('git.auth') === 'token' && $git->hasToken($website),
            'openBasedir' => (bool) $website->setting('open_basedir', false),
            'deployments' => Task::query()->where('type', 'deploy')->where('meta->website_id', $website->id)->latest('id')->limit(8)->get(['id', 'title', 'status', 'created_at', 'finished_at']),
            'readOnly' => ! auth()->user()->canWrite(),
        ])->render();

        return $this->ok('ok', ['html' => $html, 'title' => $website->domain, 'created' => $website->created_at?->format('Y-m-d H:i:s')]);
    }

    /** Data loaded on demand by the modal tabs. */
    public function data(Request $request, Website $website, string $section, GitDeployer $git)
    {
        return match ($section) {
            'subdirs' => $this->ok('ok', ['dirs' => $this->subdirectories($website)]),
            'rewrite' => $this->ok('ok', ['content' => $this->readHtaccess($website), 'path' => $website->documentRoot().'/.htaccess']),
            'git-key' => $this->ok('ok', ['key' => $git->publicKey($website)]),
            'composer' => $this->ok('ok', $this->composerInfo($website)),
            default => $this->fail('Unknown section', 404),
        };
    }

    public function update(Request $request, Website $website, string $section, GitDeployer $git)
    {
        $action = (string) $request->input('action', 'save');

        return match ($section) {
            'domains' => $this->domains($request, $website, $action),
            'directory' => $this->directory($request, $website, $action),
            'access' => $this->access($request, $website, $action),
            'rewrite' => $this->rewrite($request, $website),
            'index' => $this->indexFiles($request, $website),
            'php' => $this->php($request, $website),
            'git' => $this->git($request, $website, $action, $git),
            'composer' => $this->composer($request, $website),
            'redirects' => $this->redirects($request, $website, $action),
            'proxy' => $this->proxies($request, $website, $action),
            'hotlink' => $this->hotlink($request, $website),
            'maintenance' => $this->maintenance($request, $website),
            default => $this->fail('Unknown section', 404),
        };
    }

    /** Regenerate the vhost; settings are rolled back when Apache rejects the result. */
    protected function apply(Website $site, string $message, string $audit): JsonResponse
    {
        $original = $site->getRawOriginal();
        $result = $this->apache->write($site);
        if ($result->failed()) {
            $site->setRawAttributes($original);
            $this->apache->write($site);

            return $this->fail($result->message());
        }
        $site->save();
        $this->audit('website', $audit, $site->domain);

        return $this->ok($message);
    }

    protected function rules(Website $site, string $key): array
    {
        return array_values((array) $site->setting($key, []));
    }

    /* ============================================================ domains */

    protected function domains(Request $request, Website $site, string $action): JsonResponse
    {
        $aliases = $site->aliasList();

        if ($action === 'remove') {
            $domain = strtolower((string) $request->input('domain'));
            if ($domain === $site->domain) {
                return $this->fail('The primary domain cannot be removed.');
            }
            $site->aliases = implode(' ', array_values(array_diff($aliases, [$domain])));

            return $this->apply($site, "Removed {$domain}", "Removed domain {$domain}");
        }

        $request->validate(['domains' => ['required', 'string', 'max:5000']]);
        $added = [];
        foreach (preg_split('/[\s,]+/', strtolower((string) $request->input('domains'))) as $domain) {
            $domain = trim($domain);
            if ($domain === '') {
                continue;
            }
            if (str_contains($domain, ':')) {
                throw ValidationException::withMessages(['domains' => "Custom ports are not supported ({$domain}). Apache serves websites on ports 80 and 443."]);
            }
            if (! preg_match(WebsiteController::DOMAIN_REGEX, $domain)) {
                throw ValidationException::withMessages(['domains' => "Invalid domain: {$domain}"]);
            }
            $taken = Website::query()->where('id', '!=', $site->id)->get()->first(fn ($w) => $w->domain === $domain || in_array($domain, $w->aliasList(), true));
            if ($taken) {
                throw ValidationException::withMessages(['domains' => "{$domain} already belongs to {$taken->domain}."]);
            }
            if ($domain !== $site->domain && ! in_array($domain, $aliases, true)) {
                $aliases[] = $domain;
                $added[] = $domain;
            }
        }
        if (! $added) {
            return $this->fail('No new domains to add.');
        }
        $site->aliases = implode(' ', $aliases);

        return $this->apply($site, count($added).' domain(s) added. Point their DNS to this server.', 'Added domains '.implode(', ', $added));
    }

    /* ========================================================== directory */

    protected function directory(Request $request, Website $site, string $action): JsonResponse
    {
        if ($action === 'access_log') {
            $site->putSetting('access_log', $request->boolean('enabled'));

            return $this->apply($site, $request->boolean('enabled') ? 'Access log enabled' : 'Access log disabled', 'Changed access log');
        }

        if ($action === 'open_basedir') {
            $enabled = $request->boolean('enabled');
            $ini = $site->documentRoot().'/.user.ini';
            $result = $enabled
                ? Shell::writeFile($ini, "open_basedir={$site->root_path}/:/tmp/:/var/tmp/:/usr/share/php/\n", '0644', 'root:root')
                : Shell::run('rm -f '.Shell::arg($ini));
            if ($result->failed()) {
                return $this->fail($result->message());
            }
            $site->putSetting('open_basedir', $enabled)->save();
            $this->audit('website', ($enabled ? 'Enabled' : 'Disabled').' open_basedir', $site->domain);

            return $this->ok($enabled ? 'Cross-site protection enabled (PHP applies it within 5 minutes)' : 'Cross-site protection disabled');
        }

        if ($action === 'run_path') {
            $run = trim((string) $request->input('run_path', '/'), '/');
            if ($run !== '' && (str_contains($run, '..') || ! preg_match('#^[\w.\-/]+$#', $run))) {
                return $this->fail('Invalid running directory.');
            }
            if ($run !== '' && ! Shell::simulating() && ! Shell::test('test -d '.Shell::arg($site->root_path.'/'.$run))) {
                return $this->fail("The folder {$site->root_path}/{$run} does not exist.");
            }
            $site->putSetting('run_path', $run);

            return $this->apply($site, 'Running directory set to /'.$run, 'Changed running directory to /'.$run);
        }

        $request->validate(['root_path' => ['required', 'string', 'max:255']]);
        $root = FileManager::normalize($request->input('root_path'));
        if (in_array($root, FileManager::PROTECTED, true)) {
            return $this->fail('This directory cannot be used as a document root.');
        }
        if ($root !== $site->root_path) {
            Shell::run('mkdir -p '.Shell::arg($root).' && chown '.Shell::arg(config('gbx.web_user').':'.config('gbx.web_user')).' '.Shell::arg($root));
        }
        $site->root_path = $root;

        return $this->apply($site, 'Site directory saved', "Changed site directory to {$root}");
    }

    protected function subdirectories(Website $site): array
    {
        if (Shell::simulating()) {
            return ['public', 'dist', 'build', 'web'];
        }
        $out = Shell::out('cd '.Shell::arg($site->root_path)." 2>/dev/null && find . -mindepth 1 -maxdepth 2 -type d -not -path '*/.*' -not -path './vendor*' -not -path './node_modules*' -not -path './storage*' 2>/dev/null | sed 's#^\\./##' | sort | head -n 300", 20);

        return array_values(array_filter(explode("\n", $out)));
    }

    /* ======================================================= limit access */

    protected function access(Request $request, Website $site, string $action): JsonResponse
    {
        $type = $request->input('type') === 'deny' ? 'deny' : 'auth';
        $rules = $this->rules($site, $type);

        if ($action === 'delete') {
            $id = (string) $request->input('id');
            $site->putSetting($type, array_values(array_filter($rules, fn ($r) => ($r['id'] ?? '') !== $id)));
            $response = $this->apply($site, 'Rule removed', "Removed {$type} rule");
            if ($type === 'auth' && $response->getData()->ok) {
                Shell::run('rm -f '.Shell::arg($site->privateDir()."/auth-{$id}.htpasswd"));
            }

            return $response;
        }

        if ($type === 'deny') {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:60'],
                'path' => ['required', 'string', 'max:200'],
                'extensions' => ['required', 'string', 'max:200'],
            ]);
            if (ApacheManager::extensions($data['extensions']) === '') {
                throw ValidationException::withMessages(['extensions' => 'List file extensions such as php|jsp|sh']);
            }
            $rules[] = ['id' => Str::lower(Str::random(8)), 'name' => $data['name'], 'path' => ApacheManager::cleanPath($data['path']), 'extensions' => ApacheManager::extensions($data['extensions'])];
            $site->putSetting('deny', $rules);

            return $this->apply($site, 'Deny rule added', 'Added deny rule '.$data['name']);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'path' => ['required', 'string', 'max:200'],
            'user' => ['required', 'string', 'max:40', 'regex:/^[\w.\-@]+$/'],
            'password' => ['required', 'string', 'min:6', 'max:128'],
        ]);
        $path = ApacheManager::cleanPath($data['path']);
        if (collect($rules)->contains('path', $path)) {
            throw ValidationException::withMessages(['path' => "{$path} is already protected."]);
        }
        $id = Str::lower(Str::random(8));
        ApacheManager::ensurePrivateDir($site);
        Shell::writeFile($site->privateDir()."/auth-{$id}.htpasswd", $data['user'].':'.password_hash($data['password'], PASSWORD_BCRYPT)."\n", '0640', 'root:'.config('gbx.web_user'))
            ->throw('Unable to write the password file');

        $rules[] = ['id' => $id, 'name' => $data['name'], 'path' => $path, 'user' => $data['user']];
        $site->putSetting('auth', $rules);

        return $this->apply($site, "{$path} is now password protected", "Added password protection for {$path}");
    }

    /* ======================================================== URL rewrite */

    protected function readHtaccess(Website $site): string
    {
        if (Shell::simulating()) {
            return (string) cache()->get('gbx.sim.htaccess.'.$site->id, '');
        }

        return (string) Shell::readFile($site->documentRoot().'/.htaccess', 262144);
    }

    protected function rewrite(Request $request, Website $site): JsonResponse
    {
        $content = str_replace("\r\n", "\n", (string) $request->input('content', ''));
        if (strlen($content) > 262144) {
            return $this->fail('The rules are too large.');
        }
        $file = $site->documentRoot().'/.htaccess';

        if (Shell::simulating()) {
            cache()->put('gbx.sim.htaccess.'.$site->id, $content, 86400);
            $this->audit('website', 'Saved rewrite rules', $site->domain);

            return $this->ok('Rewrite rules saved');
        }

        // .htaccess errors only show up at request time: compare the response before and after
        $probe = fn () => trim(Shell::out('curl -s -o /dev/null -m 10 -w "%{http_code}" -H '.Shell::arg('Host: '.$site->domain).' http://127.0.0.1/', 15));
        $before = $probe();
        $f = Shell::arg($file);
        Shell::run("[ -f {$f} ] && cp -p {$f} {$f}.gbx-bak || rm -f {$f}.gbx-bak");
        $owner = config('gbx.web_user').':'.config('gbx.web_user');
        Shell::writeFile($file, $content === '' ? '' : rtrim($content)."\n", '0644', $owner)->throw('Unable to write .htaccess');
        $after = $probe();

        if ($after === '500' && $before !== '500') {
            Shell::run("if [ -f {$f}.gbx-bak ]; then mv {$f}.gbx-bak {$f}; else rm -f {$f}; fi");

            return $this->fail('The rules caused an Internal Server Error (500) and were reverted. Check the syntax and the required Apache modules.');
        }
        Shell::run("rm -f {$f}.gbx-bak");
        $this->audit('website', 'Saved rewrite rules', $site->domain);

        return $this->ok('Rewrite rules saved'.($after ? " (site responds with HTTP {$after})" : ''));
    }

    /* ==================================================== default document */

    protected function indexFiles(Request $request, Website $site): JsonResponse
    {
        $files = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $request->input('files'))))));
        foreach ($files as $file) {
            if (! preg_match('/^[\w.\-]+$/', $file)) {
                throw ValidationException::withMessages(['files' => "Invalid file name: {$file}"]);
            }
        }
        if (! $files) {
            throw ValidationException::withMessages(['files' => 'Add at least one file name.']);
        }
        $site->putSetting('index_files', array_slice($files, 0, 20));

        return $this->apply($site, 'Default documents saved', 'Changed default documents');
    }

    /* ================================================================ PHP */

    protected function php(Request $request, Website $site): JsonResponse
    {
        $version = (string) $request->input('php_version', '');
        if ($version !== '' && ! in_array($version, app(SoftwareManager::class)->phpVersions(), true)) {
            return $this->fail('This PHP version is not installed.');
        }
        $site->php_version = $version ?: null;

        return $this->apply($site, $version ? "Switched to PHP {$version}" : 'Switched to a static site (no PHP)', 'Changed PHP version to '.($version ?: 'static'));
    }

    /* ================================================================ Git */

    protected function git(Request $request, Website $site, string $action, GitDeployer $git): JsonResponse
    {
        if ($action === 'deploy') {
            return $this->task($git->deploy($site), 'Deployment started');
        }

        $data = $request->validate([
            'repo' => ['required', 'string', 'max:300'],
            'auth' => ['required', 'in:public,token,ssh'],
            'token' => ['nullable', 'string', 'max:500'],
            'branch' => ['nullable', 'string', 'max:120'],
            'script' => ['nullable', 'string', 'max:10000'],
        ]);
        if (! GitDeployer::validRepository($data['repo'])) {
            throw ValidationException::withMessages(['repo' => 'Use https://host/owner/repo.git or git@host:owner/repo.git']);
        }
        if ($data['auth'] === 'ssh' && str_starts_with($data['repo'], 'https://')) {
            throw ValidationException::withMessages(['repo' => 'SSH keys need an SSH URL such as git@github.com:owner/repo.git']);
        }

        if ($action === 'test') {
            $token = $data['token'] ?? null;
            if ($data['auth'] === 'token' && ! $token && ! $git->hasToken($site)) {
                return $this->fail('Enter the access token.');
            }
            if ($data['auth'] === 'token' && ! $token) {
                $token = Shell::simulating() ? 'saved' : Shell::out('cat '.Shell::arg($git->tokenPath($site)), 10);
            }

            return $this->ok('Connection successful', ['branches' => $git->branches($site, $data['repo'], $data['auth'], $token)]);
        }

        if (empty($data['branch']) || ! GitDeployer::validBranch($data['branch'])) {
            throw ValidationException::withMessages(['branch' => 'Choose a branch (use Test connection).']);
        }
        if ($data['auth'] === 'token') {
            if (! empty($data['token'])) {
                $git->saveToken($site, $data['token']);
            } elseif (! $git->hasToken($site)) {
                throw ValidationException::withMessages(['token' => 'Enter the access token.']);
            }
        } else {
            $git->saveToken($site, null);
        }

        $site->putSetting('git', array_merge((array) $site->setting('git', []), [
            'repo' => $data['repo'],
            'branch' => $data['branch'],
            'auth' => $data['auth'],
            'script' => str_replace("\r\n", "\n", (string) ($data['script'] ?? '')),
        ]))->save();
        $this->audit('website', 'Configured Git deployment', $site->domain.' '.$data['repo'].' '.$data['branch']);

        if ($request->boolean('deploy')) {
            return $this->task($git->deploy($site), 'Saved. Deployment started');
        }

        return $this->ok('Git settings saved');
    }

    /* =========================================================== Composer */

    protected function composerDir(Website $site, ?string $dir): string
    {
        $dir = trim((string) $dir, '/');
        if ($dir !== '' && (str_contains($dir, '..') || ! preg_match('#^[\w.\-/]+$#', $dir))) {
            throw ValidationException::withMessages(['dir' => 'Invalid folder.']);
        }

        return rtrim($site->root_path, '/').($dir === '' ? '' : '/'.$dir);
    }

    protected function composerInfo(Website $site): array
    {
        if (Shell::simulating()) {
            return ['found' => true, 'dir' => $site->root_path, 'name' => 'laravel/laravel', 'php' => $site->php_version, 'require' => ['php' => '^8.2', 'laravel/framework' => '^12.0'], 'lock' => true, 'vendor' => true];
        }
        $json = Shell::readFile($site->root_path.'/composer.json', 262144);
        $data = $json ? json_decode($json, true) : null;

        return [
            'found' => is_array($data),
            'dir' => $site->root_path,
            'name' => $data['name'] ?? null,
            'php' => $site->php_version,
            'require' => $data['require'] ?? [],
            'lock' => Shell::fileExists($site->root_path.'/composer.lock'),
            'vendor' => Shell::test('test -d '.Shell::arg($site->root_path.'/vendor')),
        ];
    }

    protected function composer(Request $request, Website $site): JsonResponse
    {
        $data = $request->validate([
            'command' => ['required', 'in:install,update,dump-autoload,require,remove,validate,outdated,diagnose'],
            'package' => ['nullable', 'string', 'max:200'],
            'dir' => ['nullable', 'string', 'max:200'],
        ]);
        $dir = $this->composerDir($site, $data['dir'] ?? '');
        $args = [$data['command']];

        if (in_array($data['command'], ['require', 'remove'], true)) {
            $package = trim((string) ($data['package'] ?? ''));
            if (! preg_match('#^[a-z0-9_.\-]+/[a-z0-9_.\-]+(:[\w.^~*|<>=@ \-]+)?$#i', $package)) {
                throw ValidationException::withMessages(['package' => 'Use vendor/package or vendor/package:^1.0']);
            }
            $args[] = Shell::arg($package);
        }
        if ($request->boolean('no_dev') && in_array($data['command'], ['install', 'update', 'require', 'remove', 'dump-autoload'], true)) {
            $args[] = '--no-dev';
        }
        if ($request->boolean('optimize') && in_array($data['command'], ['install', 'update', 'require', 'remove', 'dump-autoload'], true)) {
            $args[] = $data['command'] === 'dump-autoload' ? '--optimize' : '--optimize-autoloader';
        }
        if ($request->boolean('ignore_platform')) {
            $args[] = '--ignore-platform-reqs';
        }

        $php = $site->php_version ? 'php'.$site->php_version : 'php';
        $d = Shell::arg($dir);
        $script = "set -e\ncd {$d}\n[ -f composer.json ] || { echo 'composer.json not found in {$dir}'; exit 1; }\n"
            ."command -v composer >/dev/null || { echo 'Composer is not installed (Home > Software).'; exit 1; }\n"
            ."export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_HOME=/root/.config/composer COMPOSER_NO_INTERACTION=1\n"
            ."PHP_BIN=\$(command -v {$php} || command -v php)\n"
            ."echo \"Using \$(\$PHP_BIN -r 'echo \"PHP \".PHP_VERSION;') in {$dir}\"\n"
            ."\$PHP_BIN \$(command -v composer) ".implode(' ', $args)." --no-interaction --ansi 2>&1\n"
            .'chown -R '.Shell::arg(config('gbx.web_user').':'.config('gbx.web_user'))." {$d}\n";

        $this->audit('website', 'composer '.implode(' ', $args), $site->domain);

        return $this->task(TaskRunner::dispatch("composer {$data['command']} ({$site->domain})", $script, 'composer', ['website_id' => $site->id]), 'Composer started');
    }

    /* ========================================================== redirects */

    protected function redirects(Request $request, Website $site, string $action): JsonResponse
    {
        if ($action === 'not_found') {
            $site->putSetting('redirect_404', $request->boolean('enabled'));

            return $this->apply($site, $request->boolean('enabled') ? '404 pages redirect to the home page' : '404 redirect disabled', 'Changed 404 redirect');
        }

        $rules = $this->rules($site, 'redirects');
        $id = (string) $request->input('id');

        if ($action === 'delete' || $action === 'toggle') {
            $rules = $action === 'delete'
                ? array_values(array_filter($rules, fn ($r) => $r['id'] !== $id))
                : array_map(fn ($r) => $r['id'] === $id ? array_merge($r, ['enabled' => empty($r['enabled'])]) : $r, $rules);
            $site->putSetting('redirects', $rules);

            return $this->apply($site, $action === 'delete' ? 'Redirect removed' : 'Redirect updated', ucfirst($action).' redirect');
        }

        $data = $request->validate([
            'type' => ['required', 'in:domain,path'],
            'source' => ['required', 'string', 'max:255'],
            'target' => ['required', 'string', 'max:500'],
            'code' => ['required', 'in:301,302,307,308'],
        ]);
        $source = strtolower(trim($data['source']));
        if ($data['type'] === 'domain' && ! in_array($source, array_merge([$site->domain], $site->aliasList()), true)) {
            throw ValidationException::withMessages(['source' => 'Choose one of the domains of this website (add it in Domain Manager first).']);
        }
        if ($data['type'] === 'path') {
            $source = ApacheManager::cleanPath($data['source']);
        }
        if (! filter_var($data['target'], FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $data['target'])) {
            throw ValidationException::withMessages(['target' => 'Use a full URL such as https://example.com/new']);
        }

        $rule = ['id' => $id ?: Str::lower(Str::random(8)), 'type' => $data['type'], 'source' => $source, 'target' => $data['target'], 'code' => (int) $data['code'], 'keep_path' => $request->boolean('keep_path'), 'enabled' => true];
        $rules = $id ? array_map(fn ($r) => $r['id'] === $id ? $rule : $r, $rules) : array_merge($rules, [$rule]);
        $site->putSetting('redirects', $rules);

        return $this->apply($site, 'Redirect saved', "Saved redirect {$source}");
    }

    /* ====================================================== reverse proxy */

    protected function proxies(Request $request, Website $site, string $action): JsonResponse
    {
        $rules = array_map(fn ($r) => $r + ['id' => $r['path'] === '/' ? 'root' : Str::lower(Str::random(8))], $site->proxies());
        $id = (string) $request->input('id');

        if ($action === 'delete' || $action === 'toggle') {
            $rules = $action === 'delete'
                ? array_values(array_filter($rules, fn ($r) => $r['id'] !== $id))
                : array_map(fn ($r) => $r['id'] === $id ? array_merge($r, ['enabled' => ! ($r['enabled'] ?? true)]) : $r, $rules);
        } else {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:60'],
                'path' => ['required', 'string', 'max:200'],
                'target' => ['required', 'string', 'max:255'],
            ]);
            if (! ApacheManager::validProxyTarget($data['target']) || $data['target'] === '') {
                throw ValidationException::withMessages(['target' => 'Use a URL like http://127.0.0.1:3000']);
            }
            $path = rtrim(ApacheManager::cleanPath($data['path']), '/') ?: '/';
            if (collect($rules)->where('path', $path)->where('id', '!=', $id)->isNotEmpty()) {
                throw ValidationException::withMessages(['path' => "A proxy for {$path} already exists."]);
            }
            $rule = ['id' => $path === '/' ? 'root' : ($id ?: Str::lower(Str::random(8))), 'name' => $data['name'], 'path' => $path, 'target' => rtrim($data['target'], '/'), 'websocket' => $request->boolean('websocket'), 'enabled' => true];
            $rules = $id ? array_map(fn ($r) => $r['id'] === $id ? $rule : $r, $rules) : array_merge($rules, [$rule]);
        }

        // the "/" rule is mirrored in the proxy_target column used by the website list and the AI tools
        $root = collect($rules)->first(fn ($r) => $r['path'] === '/' && ($r['enabled'] ?? true));
        $site->proxy_target = $root['target'] ?? null;
        $site->putSetting('proxies', array_values($rules));

        return $this->apply($site, 'Reverse proxy saved', 'Changed reverse proxy rules');
    }

    /* ============================================================ hotlink */

    protected function hotlink(Request $request, Website $site): JsonResponse
    {
        $data = $request->validate([
            'extensions' => ['nullable', 'string', 'max:300'],
            'allowed' => ['nullable', 'string', 'max:2000'],
        ]);
        $allowed = [];
        foreach (preg_split('/[\s,]+/', strtolower((string) ($data['allowed'] ?? ''))) as $domain) {
            if ($domain === '') {
                continue;
            }
            if (! preg_match(WebsiteController::DOMAIN_REGEX, $domain)) {
                throw ValidationException::withMessages(['allowed' => "Invalid domain: {$domain}"]);
            }
            $allowed[] = $domain;
        }
        $site->putSetting('hotlink', [
            'enabled' => $request->boolean('enabled'),
            'extensions' => ApacheManager::extensions((string) ($data['extensions'] ?? '')) ?: 'jpg|jpeg|png|gif|webp|svg|mp4|mp3',
            'allowed' => $allowed,
            'allow_empty' => $request->boolean('allow_empty'),
        ]);

        return $this->apply($site, $request->boolean('enabled') ? 'Hotlink protection enabled' : 'Hotlink protection disabled', 'Changed hotlink protection');
    }

    /* ======================================================== maintenance */

    protected function maintenance(Request $request, Website $site): JsonResponse
    {
        $data = $request->validate([
            'message' => ['nullable', 'string', 'max:500'],
            'allowed_ips' => ['nullable', 'string', 'max:2000'],
        ]);
        $ips = [];
        foreach (preg_split('/[\s,]+/', (string) ($data['allowed_ips'] ?? '')) as $ip) {
            if ($ip === '') {
                continue;
            }
            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                throw ValidationException::withMessages(['allowed_ips' => "Invalid IP address: {$ip}"]);
            }
            $ips[] = $ip;
        }
        $site->putSetting('maintenance', ['enabled' => $request->boolean('enabled'), 'message' => (string) ($data['message'] ?? ''), 'allowed_ips' => $ips]);

        return $this->apply($site, $request->boolean('enabled') ? 'Maintenance mode enabled' : 'Maintenance mode disabled', ($request->boolean('enabled') ? 'Enabled' : 'Disabled').' maintenance mode');
    }

    /* ============================================================ backups */

    public function backups(Website $website, BackupManager $backups)
    {
        $prefix = 'site/'.$website->domain.'_';
        $rows = array_values(array_filter($backups->list('site'), fn ($b) => str_starts_with($b['file'], $prefix)));

        return $this->ok('ok', ['backups' => $rows, 'databases' => $website->databases()->pluck('name')]);
    }

    public function backupCreate(Request $request, Website $website, BackupManager $backups)
    {
        $data = $request->validate(['storage_id' => ['nullable', 'integer'], 'delete_local' => ['nullable', 'boolean']]);
        $upload = [];
        if (! empty($data['storage_id'])) {
            $storage = \App\Models\BackupStorage::query()->where('is_active', true)->find((int) $data['storage_id']);
            if (! $storage) {
                return $this->fail('Choose an enabled storage.');
            }
            $upload = ['storage_id' => $storage->id, 'delete_local' => $request->boolean('delete_local'), 'keep' => 30];
        }

        return $this->task($backups->backupWebsite($website, $request->boolean('databases', true), [], $upload), $upload ? 'Backup started; it is uploaded when it finishes.' : 'Backup started');
    }

    protected function siteBackup(Website $site, BackupManager $backups, string $file): string
    {
        if (! str_starts_with($file, 'site/'.$site->domain.'_')) {
            throw new \InvalidArgumentException('This backup does not belong to '.$site->domain);
        }

        return $backups->resolve($file);
    }

    public function backupRestore(Request $request, Website $website, BackupManager $backups)
    {
        $file = (string) $request->input('file');
        $this->siteBackup($website, $backups, $file);
        $this->audit('backup', "Restore {$file}", $website->domain);

        return $this->task($backups->restoreArchive($file, dirname($website->root_path)), 'Restore started');
    }

    public function backupDelete(Request $request, Website $website, BackupManager $backups)
    {
        $file = (string) $request->input('file');
        $this->siteBackup($website, $backups, $file);

        return $this->result($backups->delete($file), 'Backup deleted', 'backup', $file);
    }

    public function backupDownload(Request $request, Website $website, BackupManager $backups, FileManager $files)
    {
        $path = $this->siteBackup($website, $backups, (string) $request->query('file'));
        $this->audit('backup', 'Downloaded backup', $path);

        return response()->streamDownload(fn () => $files->stream($path), basename($path), ['Content-Type' => 'application/gzip']);
    }

    /* ======================================================= usage and logs */

    public function stats(WebsiteTraffic $traffic)
    {
        return $this->ok('ok', ['sites' => $traffic->hourly(Website::query()->get())]);
    }

    public function usage(Request $request, Website $website, WebsiteTraffic $traffic)
    {
        $range = in_array($request->query('range'), ['today', '24h', '7d', 'all'], true) ? $request->query('range') : '24h';

        return $this->ok('ok', ['report' => $traffic->report($website, $range)]);
    }

    public function logs(Request $request, Website $website)
    {
        $type = $request->query('type') === 'access' ? 'access' : 'error';
        $lines = min(5000, max(50, (int) $request->query('lines', 500)));
        $filter = trim((string) $request->query('filter', ''));

        if (Shell::simulating()) {
            $content = $type === 'access'
                ? implode("\n", array_slice(explode("\n", app(WebsiteTraffic::class)->sampleLog($website)), 0, 200))
                : "[Mon Sep 15 09:12:44.103822 2026] [proxy_fcgi:error] [pid 8812] [client 203.0.113.9:51122] AH01071: Got error 'PHP message: PHP Warning: Undefined variable \$user in /www/wwwroot/{$website->domain}/index.php on line 12'\n[Mon Sep 15 09:30:02.551201 2026] [core:error] [pid 8820] [client 203.0.113.14:40210] AH00126: Invalid URI in request GET /../../etc/passwd HTTP/1.1";
            if ($filter !== '') {
                $content = implode("\n", array_filter(explode("\n", $content), fn ($l) => stripos($l, $filter) !== false));
            }
        } else {
            $cmd = 'tail -n '.$lines.' '.Shell::arg($website->logPath($type)).' 2>/dev/null';
            if ($filter !== '') {
                $cmd = 'tail -n 50000 '.Shell::arg($website->logPath($type)).' 2>/dev/null | grep -iF -- '.Shell::arg($filter).' | tail -n '.$lines;
            }
            $content = Shell::run($cmd, 30)->output;
        }

        return $this->ok('ok', ['content' => mb_scrub($content, 'UTF-8'), 'path' => $website->logPath($type)]);
    }

    public function clearLog(Request $request, Website $website)
    {
        $type = $request->input('type') === 'access' ? 'access' : 'error';

        return $this->result(Shell::run('[ -f '.Shell::arg($website->logPath($type)).' ] && truncate -s 0 '.Shell::arg($website->logPath($type)).' || true'), ucfirst($type).' log cleared', 'website', $website->domain);
    }

    /* ======================================================= list helpers */

    public function meta(Request $request, Website $website)
    {
        $data = $request->validate([
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
        ]);
        $website->fill($data)->save();
        $this->audit('website', 'Updated '.implode(', ', array_keys($data)), $website->domain);

        return $this->ok(array_key_exists('expires_at', $data) ? ($data['expires_at'] ? 'Expiration set to '.$website->expires_at->toDateString() : 'The website no longer expires') : 'Remark saved');
    }

    public function bulk(Request $request, BackupManager $backups)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'action' => ['required', 'in:start,stop,backup'],
        ]);
        $done = 0;
        $errors = [];
        foreach (Website::query()->whereIn('id', $data['ids'])->get() as $site) {
            if ($data['action'] === 'backup') {
                $backups->backupWebsite($site);
                $done++;

                continue;
            }
            $status = $data['action'] === 'start' ? 'active' : 'stopped';
            if ($site->status === $status) {
                continue;
            }
            $site->status = $status;
            $result = $this->apache->write($site);
            if ($result->ok()) {
                $site->save();
                $done++;
            } else {
                $errors[] = $site->domain.': '.$result->message();
            }
        }
        $this->audit('website', 'Bulk '.$data['action'], $done.' website(s)');

        if ($errors) {
            return $this->fail(implode("\n", $errors), 422, ['done' => $done]);
        }

        return $this->ok(match ($data['action']) {
            'backup' => "{$done} backup(s) started",
            'start' => "{$done} website(s) started",
            default => "{$done} website(s) stopped",
        });
    }
}
