<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Website;
use App\Services\ApacheManager;
use App\Services\WebsiteTraffic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebsiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function login(string $role = 'admin'): User
    {
        $user = User::query()->create(['name' => ucfirst($role), 'username' => $role, 'password' => 'Secret12345', 'role' => $role, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    protected function site(array $attributes = []): Website
    {
        return Website::query()->create($attributes + [
            'domain' => 'shop.example.com',
            'aliases' => 'www.shop.example.com',
            'root_path' => '/www/wwwroot/shop.example.com',
            'php_version' => '8.4',
            'status' => 'active',
        ]);
    }

    protected function section(Website $site, string $section, array $data)
    {
        return $this->postJson("/websites/{$site->id}/manage/{$section}", $data);
    }

    public function test_vhost_renders_every_feature(): void
    {
        $site = $this->site([
            'settings' => [
                'run_path' => 'public',
                'index_files' => ['index.html', 'index.php'],
                'auth' => [['id' => 'abc12345', 'name' => 'Admin', 'path' => '/admin', 'user' => 'boss']],
                'deny' => [['id' => 'd1', 'name' => 'Uploads', 'path' => '/uploads', 'extensions' => 'php|phtml']],
                'redirects' => [
                    ['id' => 'r1', 'type' => 'path', 'source' => '/old', 'target' => 'https://shop.example.com/new', 'code' => 301, 'keep_path' => true, 'enabled' => true],
                    ['id' => 'r2', 'type' => 'path', 'source' => '/off', 'target' => 'https://example.org', 'code' => 302, 'enabled' => false],
                ],
                'proxies' => [['id' => 'p1', 'name' => 'API', 'path' => '/api', 'target' => 'http://127.0.0.1:4000', 'websocket' => true, 'enabled' => true]],
                'hotlink' => ['enabled' => true, 'extensions' => 'jpg|png', 'allowed' => ['cdn.example.net'], 'allow_empty' => false],
                'maintenance' => ['enabled' => true, 'allowed_ips' => ['203.0.113.10', 'not-an-ip']],
                'access_log' => false,
            ],
        ]);

        $conf = app(ApacheManager::class)->render($site);

        $this->assertStringContainsString('DocumentRoot /www/wwwroot/shop.example.com/public', $conf);
        $this->assertStringContainsString('DirectoryIndex index.html index.php', $conf);
        $this->assertStringContainsString('AuthUserFile /usr/local/gbxpanel/sites/shop.example.com/auth-abc12345.htpasswd', $conf);
        $this->assertStringContainsString('<LocationMatch "^/uploads/.*\.(php|phtml)$">', $conf);
        $this->assertStringContainsString('RewriteRule ^/old(/.*)?$ https://shop.example.com/new$1 [R=301,L]', $conf);
        $this->assertStringNotContainsString('/off', $conf);
        $this->assertStringContainsString('ProxyPass /api http://127.0.0.1:4000', $conf);
        $this->assertStringContainsString('RewriteRule ^/api/?(.*) ws://127.0.0.1:4000/$1 [P,L]', $conf);
        $this->assertStringContainsString('(shop\.example\.com|www\.shop\.example\.com|cdn\.example\.net)', $conf);
        $this->assertStringNotContainsString('RewriteCond %{HTTP_REFERER} !^$', $conf);
        $this->assertStringContainsString('RewriteCond %{REMOTE_ADDR} !^203\.0\.113\.10$', $conf);
        $this->assertStringNotContainsString('not-an-ip', $conf);
        $this->assertStringContainsString('RewriteRule ^ - [R=503,L]', $conf);
        // PHP stays active because the proxy only covers /api
        $this->assertStringContainsString('php8.4-fpm.sock', $conf);
        $this->assertStringNotContainsString('CustomLog', $conf);

        $site->status = 'stopped';
        $stopped = app(ApacheManager::class)->render($site);
        $this->assertStringNotContainsString('ProxyPass /api', $stopped);
        $this->assertStringContainsString('/pages/stopped', $stopped);
    }

    public function test_root_proxy_replaces_php_and_is_mirrored_in_proxy_target(): void
    {
        $this->login();
        $site = $this->site();

        $this->section($site, 'proxy', ['name' => 'Node', 'path' => '/', 'target' => 'http://127.0.0.1:3000/', 'websocket' => 1])->assertOk();
        $site->refresh();
        $this->assertSame('http://127.0.0.1:3000', $site->proxy_target);
        $this->assertStringNotContainsString('php8.4-fpm.sock', app(ApacheManager::class)->render($site));

        $this->section($site, 'proxy', ['action' => 'delete', 'id' => 'root'])->assertOk();
        $this->assertNull($site->refresh()->proxy_target);
        $this->assertSame([], $site->proxies());
    }

    public function test_domains_are_validated(): void
    {
        $this->login();
        $site = $this->site();
        $this->site(['domain' => 'other.example.com', 'aliases' => 'taken.example.com', 'root_path' => '/www/wwwroot/other']);

        $this->section($site, 'domains', ['action' => 'add', 'domains' => "api.example.com\nimg.example.com"])->assertOk();
        $this->assertSame(['www.shop.example.com', 'api.example.com', 'img.example.com'], $site->refresh()->aliasList());

        $this->section($site, 'domains', ['action' => 'add', 'domains' => 'x.example.com:8080'])->assertStatus(422);
        $this->section($site, 'domains', ['action' => 'add', 'domains' => 'taken.example.com'])->assertStatus(422)->assertJsonPath('message', 'taken.example.com already belongs to other.example.com.');
        $this->section($site, 'domains', ['action' => 'remove', 'domain' => 'shop.example.com'])->assertStatus(422);
        $this->section($site, 'domains', ['action' => 'remove', 'domain' => 'img.example.com'])->assertOk();
        $this->assertNotContains('img.example.com', $site->refresh()->aliasList());
    }

    public function test_access_rules_redirects_and_maintenance(): void
    {
        $this->login();
        $site = $this->site();

        $this->section($site, 'access', ['type' => 'auth', 'name' => 'Admin', 'path' => 'admin area/"x', 'user' => 'boss', 'password' => 'secret123'])->assertOk();
        $rule = $site->refresh()->setting('auth.0');
        $this->assertSame('/adminarea/x', $rule['path']);
        $this->assertArrayNotHasKey('password', $rule);

        $this->section($site, 'access', ['type' => 'deny', 'name' => 'Uploads', 'path' => '/uploads', 'extensions' => 'php, PHTML;sh'])->assertOk();
        $this->assertSame('php|phtml|sh', $site->refresh()->setting('deny.0.extensions'));

        $this->section($site, 'redirects', ['type' => 'domain', 'source' => 'unknown.com', 'target' => 'https://x.com', 'code' => 301])->assertStatus(422);
        $this->section($site, 'redirects', ['type' => 'path', 'source' => '/old', 'target' => 'javascript:alert(1)', 'code' => 301])->assertStatus(422);
        $this->section($site, 'redirects', ['type' => 'path', 'source' => '/old', 'target' => 'https://shop.example.com/new', 'code' => 301, 'keep_path' => 1])->assertOk();
        $id = $site->refresh()->setting('redirects.0.id');
        $this->section($site, 'redirects', ['action' => 'toggle', 'id' => $id])->assertOk();
        $this->assertFalse($site->refresh()->setting('redirects.0.enabled'));

        $this->section($site, 'maintenance', ['enabled' => 1, 'allowed_ips' => 'bad-ip'])->assertStatus(422);
        $this->section($site, 'maintenance', ['enabled' => 1, 'message' => 'Back soon', 'allowed_ips' => "203.0.113.5\n2001:db8::1"])->assertOk();
        $this->assertSame(['203.0.113.5', '2001:db8::1'], $site->refresh()->setting('maintenance.allowed_ips'));
    }

    public function test_directory_git_and_composer_input_is_validated(): void
    {
        $this->login();
        $site = $this->site();

        $this->section($site, 'directory', ['action' => 'run_path', 'run_path' => '../etc'])->assertStatus(422);
        $this->section($site, 'directory', ['action' => 'run_path', 'run_path' => 'public'])->assertOk();
        $this->assertSame('/www/wwwroot/shop.example.com/public', $site->refresh()->documentRoot());
        $this->section($site, 'directory', ['root_path' => '/etc'])->assertStatus(422);
        $this->section($site, 'index', ['files' => "index.php\n../evil"])->assertStatus(422);

        $this->section($site, 'git', ['repo' => 'file:///etc/passwd', 'auth' => 'public', 'branch' => 'main'])->assertStatus(422);
        $this->section($site, 'git', ['repo' => 'https://github.com/owner/app.git', 'auth' => 'ssh', 'branch' => 'main'])->assertStatus(422);
        $this->section($site, 'git', ['repo' => 'https://github.com/owner/app.git', 'auth' => 'public', 'branch' => '--upload-pack=x'])->assertStatus(422);
        $this->section($site, 'git', ['action' => 'test', 'repo' => 'https://github.com/owner/app.git', 'auth' => 'public'])->assertOk()->assertJsonStructure(['branches']);
        $this->section($site, 'git', ['repo' => 'https://github.com/owner/app.git', 'auth' => 'public', 'branch' => 'main', 'deploy' => 1])->assertOk()->assertJsonStructure(['task']);
        $this->assertSame('main', $site->refresh()->setting('git.branch'));

        $this->section($site, 'composer', ['command' => 'require', 'package' => 'bad package; rm -rf /'])->assertStatus(422);
        $this->section($site, 'composer', ['command' => 'exec'])->assertStatus(422);
        $this->section($site, 'composer', ['command' => 'require', 'package' => 'guzzlehttp/guzzle:^7.9', 'no_dev' => 1])->assertOk()->assertJsonStructure(['task']);
    }

    public function test_expiration_bulk_actions_and_expire_command(): void
    {
        $this->login();
        $site = $this->site();
        $other = $this->site(['domain' => 'old.example.com', 'aliases' => null, 'root_path' => '/www/wwwroot/old.example.com', 'expires_at' => now()->subDays(2)]);

        $this->postJson("/websites/{$site->id}/meta", ['notes' => 'Main shop', 'expires_at' => '2030-01-31'])->assertOk();
        $this->assertSame('2030-01-31', $site->refresh()->expires_at->toDateString());

        $this->artisan('gbx:expire-websites')->assertSuccessful();
        $this->assertSame('stopped', $other->refresh()->status);
        $this->assertSame('active', $site->refresh()->status);

        $this->postJson('/websites/bulk', ['ids' => [$site->id, $other->id], 'action' => 'start'])->assertOk();
        $this->assertSame('active', $other->refresh()->status);
        $this->postJson('/websites/bulk', ['ids' => [$site->id], 'action' => 'delete'])->assertStatus(422);
    }

    public function test_modal_usage_logs_and_backups(): void
    {
        $this->login();
        $site = $this->site();

        $this->getJson("/websites/{$site->id}/manage")->assertOk()->assertJsonStructure(['html', 'title', 'created']);
        $this->get("/websites/{$site->id}")->assertRedirect('/websites?conf='.$site->id);
        $this->getJson('/websites/stats')->assertOk()->assertJsonCount(24, "sites.{$site->id}.hours");

        $report = $this->getJson("/websites/{$site->id}/usage?range=7d")->assertOk()->json('report');
        $this->assertGreaterThan(0, $report['total']);
        $this->assertSame($report['total'], array_sum($report['status']));

        $this->getJson("/websites/{$site->id}/logs?type=error&filter=AH00126")->assertOk()->assertJsonPath('path', '/www/wwwlogs/shop.example.com-error.log');

        $this->postJson("/websites/{$site->id}/backups/restore", ['file' => 'site/other.example.com_20260101_000000.tar.gz'])->assertStatus(422);
        $this->postJson("/websites/{$site->id}/backups/restore", ['file' => 'site/shop.example.com_20260101_000000.tar.gz'])->assertOk();
    }

    public function test_combined_log_lines_are_parsed(): void
    {
        $site = $this->site();
        $line = '198.51.100.7 - - ['.date('d/M/Y:H:i:s O').'] "GET /products?page=2 HTTP/1.1" 404 512 "https://google.com/" "Mozilla/5.0 (compatible; Googlebot/2.1)"';
        $this->assertSame(1, preg_match(WebsiteTraffic::LOG_REGEX, $line, $m));
        $this->assertSame(['198.51.100.7', 'GET', '/products?page=2', '404', '512'], [$m[1], $m[3], $m[4], $m[5], $m[6]]);
    }

    public function test_read_only_users_cannot_change_settings(): void
    {
        $this->login('viewer');
        $site = $this->site();

        $this->getJson("/websites/{$site->id}/manage")->assertOk();
        $this->section($site, 'maintenance', ['enabled' => 1])->assertForbidden();
        $this->postJson('/websites/bulk', ['ids' => [$site->id], 'action' => 'stop'])->assertForbidden();
    }
}
