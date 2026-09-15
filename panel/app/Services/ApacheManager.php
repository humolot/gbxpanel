<?php

namespace App\Services;

use App\Models\Website;

class ApacheManager
{
    public function configPath(Website $site): string
    {
        return rtrim(config('gbx.paths.apache_sites'), '/').'/gbx-'.$site->domain.'.conf';
    }

    public function stopPage(): string
    {
        return rtrim(config('gbx.root'), '/').'/pages/stopped';
    }

    public function certPaths(Website $site): array
    {
        if ($site->ssl_provider === 'letsencrypt') {
            return ['cert' => "/etc/letsencrypt/live/{$site->domain}/fullchain.pem", 'key' => "/etc/letsencrypt/live/{$site->domain}/privkey.pem"];
        }

        return ['cert' => $site->sslDir().'/fullchain.pem', 'key' => $site->sslDir().'/privkey.pem'];
    }

    public function render(Website $site): string
    {
        $stopped = $site->status === 'stopped';
        $root = $stopped ? $this->stopPage() : $site->documentRoot();
        $aliases = implode(' ', $site->aliasList());
        $index = implode(' ', array_filter(array_map(fn ($f) => preg_replace('/[^\w.\-]/', '', $f), $site->indexFiles())));

        $body = "    ServerName {$site->domain}";
        if ($aliases !== '') {
            $body .= "\n    ServerAlias {$aliases}";
        }
        $body .= <<<CONF

    DocumentRoot {$root}
    DirectoryIndex {$index}

    <Directory {$root}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <DirectoryMatch "/\.(git|svn)">
        Require all denied
    </DirectoryMatch>
    <FilesMatch "^\.(env|git|htpasswd|user\.ini)">
        Require all denied
    </FilesMatch>
CONF;

        if (! $stopped) {
            $body .= $this->renderFeatures($site);
        }

        $body .= "\n\n    ErrorLog {$site->logPath('error')}";
        if ($site->setting('access_log', true)) {
            $body .= "\n    CustomLog {$site->logPath('access')} combined";
        }

        $http = "<VirtualHost *:80>\n{$body}\n";
        $http .= "\n    Alias /.well-known/acme-challenge/ {$site->root_path}/.well-known/acme-challenge/";
        if ($site->ssl_enabled && $site->force_https) {
            $http .= <<<CONF

    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
    RewriteRule ^(.*)$ https://%{HTTP_HOST}$1 [R=301,L]
CONF;
        }
        $http .= "\n</VirtualHost>\n";

        $conf = "# Managed by GBX Panel. Manual changes may be overwritten.\n# Website: {$site->domain}\n\n".$http;

        if ($site->ssl_enabled) {
            $paths = $this->certPaths($site);
            $conf .= <<<CONF

<IfModule mod_ssl.c>
<VirtualHost *:443>
{$body}

    Protocols h2 http/1.1
    SSLEngine on
    SSLCertificateFile {$paths['cert']}
    SSLCertificateKeyFile {$paths['key']}
    Header always set Strict-Transport-Security "max-age=31536000"
</VirtualHost>
</IfModule>

CONF;
        }

        return $conf;
    }

    /** Access rules, redirects, hotlink protection, maintenance, reverse proxies and PHP. */
    protected function renderFeatures(Website $site): string
    {
        $out = '';
        $acme = '    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/';

        // maintenance mode: 503 page for everyone except the allowed addresses
        if ($site->setting('maintenance.enabled')) {
            $page = $site->privateDir().'/public';
            $out .= "\n\n    # Maintenance mode\n    Alias /.gbx-maintenance.html {$page}/maintenance.html\n"
                ."    <Directory {$page}>\n        Require all granted\n    </Directory>\n"
                ."    ErrorDocument 503 /.gbx-maintenance.html\n    RewriteEngine On\n{$acme}\n"
                ."    RewriteCond %{REQUEST_URI} !^/\.gbx-maintenance\.html$\n";
            foreach ((array) $site->setting('maintenance.allowed_ips', []) as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    $out .= '    RewriteCond %{REMOTE_ADDR} !^'.preg_quote($ip)."$\n";
                }
            }
            $out .= '    RewriteRule ^ - [R=503,L]';
        }

        // password protected paths
        $auth = array_filter((array) $site->setting('auth', []), fn ($r) => ! empty($r['id']) && ! empty($r['path']));
        foreach ($auth as $rule) {
            $path = self::cleanPath($rule['path']);
            $name = preg_replace('/[^\w .\-]/', '', $rule['name'] ?? '') ?: 'Restricted area';
            $out .= "\n\n    <Location \"{$path}\">\n        AuthType Basic\n        AuthName \"{$name}\"\n"
                ."        AuthUserFile {$site->privateDir()}/auth-{$rule['id']}.htpasswd\n        Require valid-user\n    </Location>";
        }
        if ($auth) {
            $out .= "\n    <Location \"/.well-known/acme-challenge/\">\n        AuthType None\n        Require all granted\n    </Location>";
        }

        // deny access to file types below a path
        foreach ((array) $site->setting('deny', []) as $rule) {
            $ext = self::extensions((string) ($rule['extensions'] ?? ''));
            if ($ext === '') {
                continue;
            }
            $path = rtrim(self::cleanPath($rule['path'] ?? '/'), '/');
            $out .= "\n\n    <LocationMatch \"^".preg_quote($path)."/.*\\.({$ext})$\">\n        Require all denied\n    </LocationMatch>";
        }

        // redirects
        foreach ((array) $site->setting('redirects', []) as $rule) {
            if (empty($rule['enabled']) || empty($rule['source']) || empty($rule['target'])) {
                continue;
            }
            $code = in_array((int) ($rule['code'] ?? 301), [301, 302, 307, 308], true) ? (int) $rule['code'] : 301;
            $target = preg_replace('/[\s"\\\\]/', '', $rule['target']);
            $keep = ! empty($rule['keep_path']);
            $out .= "\n\n    RewriteEngine On\n{$acme}\n";
            if (($rule['type'] ?? 'path') === 'domain') {
                $out .= '    RewriteCond %{HTTP_HOST} ^'.preg_quote(strtolower($rule['source']))."$ [NC]\n";
                $out .= $keep ? '    RewriteRule ^/?(.*)$ '.rtrim($target, '/')."/\$1 [R={$code},L]" : "    RewriteRule ^ {$target} [R={$code},L]";
            } else {
                $source = rtrim(self::cleanPath($rule['source']), '/');
                $out .= $keep
                    ? '    RewriteRule ^'.preg_quote($source).'(/.*)?$ '.rtrim($target, '/')."\$1 [R={$code},L]"
                    : '    RewriteRule ^'.preg_quote($source)."/?$ {$target} [R={$code},L]";
            }
        }
        if ($site->setting('redirect_404')) {
            $out .= "\n\n    ErrorDocument 404 ".($site->ssl_enabled ? 'https' : 'http')."://{$site->domain}/";
        }

        // hotlink protection
        if ($site->setting('hotlink.enabled')) {
            $ext = self::extensions((string) $site->setting('hotlink.extensions', 'jpg|jpeg|png|gif|webp|svg|mp4|mp3')) ?: 'jpg|jpeg|png|gif|webp';
            $allowed = array_unique(array_filter(array_merge([$site->domain], $site->aliasList(), (array) $site->setting('hotlink.allowed', []))));
            $hosts = implode('|', array_map(fn ($d) => preg_quote(strtolower($d)), $allowed));
            $out .= "\n\n    # Hotlink protection\n    RewriteEngine On\n";
            if ($site->setting('hotlink.allow_empty', true)) {
                $out .= "    RewriteCond %{HTTP_REFERER} !^$\n";
            }
            $out .= "    RewriteCond %{HTTP_REFERER} !^https?://([^/]+\\.)?({$hosts})(:[0-9]+)?(/|$) [NC]\n";
            $out .= "    RewriteRule \\.({$ext})$ - [F,NC,L]";
        }

        // reverse proxies, longest path first; a proxy for "/" replaces PHP
        $proxies = array_values(array_filter($site->proxies(), fn ($p) => ($p['enabled'] ?? true) && ! empty($p['target']) && self::validProxyTarget($p['target'])));
        usort($proxies, fn ($a, $b) => strlen(self::cleanPath($b['path'] ?? '/')) <=> strlen(self::cleanPath($a['path'] ?? '/')));
        $rootProxy = false;
        if ($proxies) {
            $out .= "\n\n    ProxyPreserveHost On\n    ProxyRequests Off\n    ProxyTimeout 300\n    RequestHeader set X-Forwarded-Proto expr=%{REQUEST_SCHEME}\n"
                ."    ProxyPass /.well-known/acme-challenge/ !\n";
            if ($site->setting('maintenance.enabled')) {
                $out .= "    ProxyPass /.gbx-maintenance.html !\n";
            }
            foreach ($proxies as $p) {
                $path = rtrim(self::cleanPath($p['path'] ?? '/'), '/') ?: '/';
                $target = rtrim($p['target'], '/');
                if (! empty($p['websocket'])) {
                    $prefix = $path === '/' ? '' : preg_quote($path);
                    $out .= "    RewriteEngine On\n    RewriteCond %{HTTP:Upgrade} =websocket [NC]\n    RewriteRule ^{$prefix}/?(.*) ".preg_replace('#^http#', 'ws', $target)."/\$1 [P,L]\n";
                }
                if ($path === '/') {
                    $rootProxy = true;
                    $out .= "    ProxyPass / {$target}/\n    ProxyPassReverse / {$target}/\n";
                } else {
                    $out .= "    ProxyPass {$path} {$target}\n    ProxyPassReverse {$path} {$target}\n";
                }
            }
            $out = rtrim($out, "\n");
        }

        if ($site->php_version && ! $rootProxy) {
            $out .= <<<CONF


    <FilesMatch \.php$>
        <If "-f %{REQUEST_FILENAME}">
            SetHandler "proxy:unix:/run/php/php{$site->php_version}-fpm.sock|fcgi://localhost"
        </If>
    </FilesMatch>
CONF;
        }

        return $out;
    }

    /** URL path for Location and rewrite rules: leading slash, safe characters only. */
    public static function cleanPath(string $path): string
    {
        $path = '/'.ltrim((string) preg_replace('#[^\w.\-/~@%+]#', '', str_replace('\\', '/', $path)), '/');

        return (string) preg_replace('#/+#', '/', $path);
    }

    /** "php, jsp|phtml" -> "php|jsp|phtml" */
    public static function extensions(string $list): string
    {
        return implode('|', array_unique(array_filter(array_map(fn ($e) => strtolower((string) preg_replace('/[^a-z0-9]/i', '', $e)), preg_split('/[\s,|;]+/', $list)))));
    }

    /** Private per-site directory: root owned, readable by Apache (htpasswd) but not listable by others. */
    public static function ensurePrivateDir(Website $site): void
    {
        $dir = Shell::arg($site->privateDir());
        Shell::run("mkdir -p {$dir} && chown root:".Shell::arg(config('gbx.web_user'))." {$dir} && chmod 750 {$dir}", 20);
    }

    /** Files a rendered vhost depends on (maintenance page). */
    public function writeSupportFiles(Website $site): void
    {
        if (! $site->setting('maintenance.enabled')) {
            return;
        }
        $dir = $site->privateDir();
        $html = view('pages.maintenance', ['domain' => $site->domain, 'message' => (string) $site->setting('maintenance.message', '')])->render();
        self::ensurePrivateDir($site);
        Shell::run('mkdir -p '.Shell::arg($dir.'/public').' && chmod 755 '.Shell::arg($dir.'/public'));
        Shell::writeFile($dir.'/public/maintenance.html', $html, '0644');
    }

    /** Write vhost, enable it and reload Apache. Rolls back when configtest fails. */
    public function write(Website $site): ShellResult
    {
        $this->writeSupportFiles($site);
        if ($site->proxies() || $site->setting('maintenance.enabled') || $site->setting('redirects') || $site->setting('hotlink.enabled')) {
            Shell::run('a2enmod -q proxy proxy_http proxy_wstunnel headers rewrite >/dev/null 2>&1', 30);
        }
        if ($site->setting('auth')) {
            Shell::run('a2enmod -q auth_basic authn_file authz_user >/dev/null 2>&1', 30);
        }

        return $this->apply($site, $this->render($site));
    }

    public static function validProxyTarget(?string $target): bool
    {
        return $target === null || $target === '' || (bool) preg_match('#^https?://[a-z0-9.\-]+(:\d{1,5})?(/[\w.\-/]*)?$#i', $target);
    }

    public function apply(Website $site, string $content): ShellResult
    {
        $path = $this->configPath($site);
        $p = Shell::arg($path);
        $name = Shell::arg(basename($path));

        Shell::run('mkdir -p '.Shell::arg(config('gbx.paths.logs')).' && [ -f '.$p.' ] && cp '.$p.' '.$p.'.bak || true');
        Shell::writeFile($path, $content)->throw('Unable to write vhost');

        $test = Shell::run("a2ensite {$name} >/dev/null && apache2ctl configtest 2>&1", 30);
        if ($test->failed() && ! str_contains($test->output.$test->error, 'Syntax OK')) {
            Shell::run('if [ -f '.$p.'.bak ]; then mv '.$p.'.bak '.$p.'; else a2dissite '.$name.' >/dev/null; rm -f '.$p.'; fi');

            return new ShellResult(1, $test->output, 'Apache configuration test failed: '.trim($test->output.$test->error));
        }

        return Shell::run('rm -f '.$p.'.bak && systemctl reload apache2', 30);
    }

    public function read(Website $site): string
    {
        return Shell::readFile($this->configPath($site)) ?? $this->render($site);
    }

    public function remove(Website $site): ShellResult
    {
        $path = $this->configPath($site);

        return Shell::run('a2dissite '.Shell::arg(basename($path)).' >/dev/null 2>&1; rm -f '.Shell::arg($path).' && systemctl reload apache2', 30);
    }

    public function createRoot(Website $site): ShellResult
    {
        $root = Shell::arg($site->root_path);
        $user = Shell::arg(config('gbx.web_user').':'.config('gbx.web_user'));
        $index = view('pages.default-site', ['domain' => $site->domain])->render();

        $result = Shell::run("mkdir -p {$root}/.well-known/acme-challenge && [ -n \"\$(ls -A {$root} | grep -v .well-known)\" ] || cat > {$root}/index.html", 30, $index);
        Shell::run("chown -R {$user} {$root} && chmod 755 {$root}");

        return $result;
    }

    public function status(): array
    {
        return app(ServiceManager::class)->status('apache2');
    }
}
