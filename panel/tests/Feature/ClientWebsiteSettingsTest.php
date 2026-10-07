<?php

namespace Tests\Feature;

use App\Http\Controllers\Client\ClientWebsiteSettingsController;
use App\Models\Client;
use App\Models\ClientPackage;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\Clients\ClientManager;
use App\Services\SystemStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientWebsiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function client(string $username = 'joao'): Client
    {
        ClientManager::forgetPortalCache();
        $package = ClientPackage::query()->create(['name' => 'P'.$username, 'max_websites' => 5, 'max_databases' => 5, 'max_ftp' => 5, 'disk_mb' => 0, 'bandwidth_mb' => 0]);

        return Client::query()->create(['username' => $username, 'name' => $username, 'password' => 'Client12345', 'package_id' => $package->id, 'status' => 'active']);
    }

    protected function site(Client $client, string $domain = 'joao.com'): Website
    {
        return Website::query()->create(['domain' => $domain, 'root_path' => '/www/wwwroot/'.$domain, 'client_id' => $client->id]);
    }

    public function test_the_client_opens_the_same_settings_screens_without_the_administrator_sections(): void
    {
        $joao = $this->client();
        $site = $this->site($joao);
        $this->actingAs($joao, 'client');

        $html = $this->getJson('/client/websites/'.$site->id.'/manage')->assertOk()->assertJsonPath('title', 'joao.com')->json('html');

        foreach (['domains', 'directory', 'access', 'rewrite', 'index', 'redirects', 'hotlink', 'maintenance'] as $section) {
            $this->assertStringContainsString('data-pane="'.$section.'"', $html, $section.' is available to the client');
        }
        foreach (['config', 'ssl', 'php', 'git', 'composer', 'proxy'] as $section) {
            $this->assertStringNotContainsString('data-pane="'.$section.'"', $html, $section.' stays with the administrator');
        }
        $this->assertStringContainsString('client/websites/'.$site->id.'/manage/domains', $html, 'the forms post to the client routes');
        $this->assertStringNotContainsString('Site directory', $html, 'the client cannot move the document root');
        $this->assertStringContainsString('Running directory', $html);
        $this->assertStringContainsString('smRewriteText', $html, 'the client edits .htaccess in a plain text box');
        $this->assertStringContainsString('Cross-site protection', $html);
    }

    public function test_the_client_changes_what_belongs_to_its_website(): void
    {
        $joao = $this->client();
        $site = $this->site($joao);
        $this->actingAs($joao, 'client');

        $this->postJson('/client/websites/'.$site->id.'/manage/directory', ['action' => 'open_basedir', 'enabled' => 1])->assertOk();
        $this->assertTrue((bool) $site->fresh()->setting('open_basedir'));

        $this->postJson('/client/websites/'.$site->id.'/manage/rewrite', ['content' => "RewriteEngine On\n"])->assertOk();
        $this->postJson('/client/websites/'.$site->id.'/manage/index', ['files' => "index.php\nindex.html"])->assertOk();
        $this->assertSame(['index.php', 'index.html'], $site->fresh()->indexFiles());

        $this->postJson('/client/websites/'.$site->id.'/manage/domains', ['action' => 'add', 'domains' => 'shop.joao.com'])->assertOk();
        $this->assertContains('shop.joao.com', $site->fresh()->aliasList());

        $this->postJson('/client/websites/'.$site->id.'/manage/maintenance', ['enabled' => 1, 'message' => 'Back soon'])->assertOk();
        $this->getJson('/client/websites/'.$site->id.'/manage/subdirs')->assertOk()->assertJsonStructure(['dirs']);
    }

    public function test_what_the_client_must_not_reach(): void
    {
        $joao = $this->client('joao');
        $maria = $this->client('maria');
        $site = $this->site($joao);
        $otherSite = $this->site($maria, 'maria.com');
        $adminSite = Website::query()->create(['domain' => 'admin.com', 'root_path' => '/www/wwwroot/admin.com']);
        $this->actingAs($joao, 'client');

        // websites of other clients and of the administrator stay invisible
        foreach ([$otherSite, $adminSite] as $foreign) {
            $this->getJson('/client/websites/'.$foreign->id.'/manage')->assertNotFound();
            $this->postJson('/client/websites/'.$foreign->id.'/manage/rewrite', ['content' => 'x'])->assertNotFound();
        }

        // sections the administrator keeps
        foreach (['proxy', 'git', 'composer', 'php', 'config'] as $section) {
            $this->postJson('/client/websites/'.$site->id.'/manage/'.$section, ['action' => 'save'])->assertNotFound();
        }

        // the document root cannot be moved: only the listed actions of a section are accepted
        $this->postJson('/client/websites/'.$site->id.'/manage/directory', ['root_path' => '/etc'])->assertStatus(403);
        $this->assertSame('/www/wwwroot/joao.com', $site->fresh()->root_path);

        // a wildcard alias would catch domains of other customers pointed at this server
        $this->postJson('/client/websites/'.$site->id.'/manage/domains', ['action' => 'add', 'domains' => '*.com'])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'Wildcard'));
        $this->postJson('/client/websites/'.$site->id.'/manage/domains', ['action' => 'add', 'domains' => 'maria.com'])->assertStatus(422);

        $this->assertSame(['subdirs', 'rewrite'], ClientWebsiteSettingsController::READABLE);
        $this->getJson('/client/websites/'.$site->id.'/manage/git-key')->assertNotFound();
    }

    public function test_the_server_address_can_be_set_by_hand(): void
    {
        $stats = app(SystemStats::class);
        $this->assertSame($stats->detectPublicIp(), $stats->publicIp(), 'without a setting the detected address is used');

        Setting::put('server_ip', '203.0.113.10');
        $this->assertSame('203.0.113.10', $stats->publicIp());

        Setting::put('server_ip', 'not an address');
        $this->assertSame($stats->detectPublicIp(), $stats->publicIp(), 'a broken value never replaces the detected one');

        $admin = User::query()->create(['name' => 'Admin', 'username' => 'admin', 'password' => 'Secret12345', 'role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin);

        $this->postJson('/settings/system', ['hostname' => 'gbx-dev', 'timezone' => 'UTC', 'server_ip' => '198.51.100.7'])->assertOk();
        $this->assertSame('198.51.100.7', Setting::get('server_ip'));
        $this->assertSame('198.51.100.7', app(SystemStats::class)->publicIp());

        $this->postJson('/settings/system', ['hostname' => 'gbx-dev', 'timezone' => 'UTC', 'server_ip' => '10.0.0.1.5'])->assertStatus(422)->assertJsonValidationErrors('server_ip');

        $this->postJson('/settings/system', ['hostname' => 'gbx-dev', 'timezone' => 'UTC', 'server_ip' => ''])->assertOk();
        $this->assertSame(app(SystemStats::class)->detectPublicIp(), app(SystemStats::class)->publicIp(), 'clearing the field goes back to detection');

        $this->get('/settings')->assertOk()->assertSee('Server address');
    }
}
