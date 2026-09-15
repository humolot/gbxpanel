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
        $root = $site->status === 'stopped' ? $this->stopPage() : $site->root_path;
        $aliases = implode(' ', $site->aliasList());
        $errorLog = $site->logPath('error');
        $accessLog = $site->logPath('access');

        $php = '';
        if ($site->proxy_target && $site->status !== 'stopped') {
            // reverse proxy to a local app (Node.js, Docker, Python...) with WebSocket support
            $target = rtrim($site->proxy_target, '/');
            $ws = preg_replace('#^http#', 'ws', $target);
            $php = <<<CONF

    ProxyPreserveHost On
    ProxyRequests Off
    ProxyTimeout 300
    RequestHeader set X-Forwarded-Proto expr=%{REQUEST_SCHEME}
    RewriteEngine On
    RewriteCond %{HTTP:Upgrade} =websocket [NC]
    RewriteRule ^/?(.*) {$ws}/$1 [P,L]
    ProxyPass /.well-known/acme-challenge/ !
    ProxyPass / {$target}/
    ProxyPassReverse / {$target}/
CONF;
        } elseif ($site->php_version && $site->status !== 'stopped') {
            $php = <<<CONF

    <FilesMatch \.php$>
        <If "-f %{REQUEST_FILENAME}">
            SetHandler "proxy:unix:/run/php/php{$site->php_version}-fpm.sock|fcgi://localhost"
        </If>
    </FilesMatch>
CONF;
        }

        $body = <<<CONF
    ServerName {$site->domain}
CONF;
        if ($aliases !== '') {
            $body .= "\n    ServerAlias {$aliases}";
        }
        $body .= <<<CONF

    DocumentRoot {$root}
    DirectoryIndex index.php index.html index.htm

    <Directory {$root}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <DirectoryMatch "/\.(git|svn)">
        Require all denied
    </DirectoryMatch>
    <FilesMatch "^\.(env|git|htpasswd)">
        Require all denied
    </FilesMatch>
{$php}

    ErrorLog {$errorLog}
    CustomLog {$accessLog} combined
CONF;

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

    /** Write vhost, enable it and reload Apache. Rolls back when configtest fails. */
    public function write(Website $site): ShellResult
    {
        if ($site->proxy_target) {
            Shell::run('a2enmod -q proxy proxy_http proxy_wstunnel headers rewrite >/dev/null 2>&1', 30);
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
