<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\ClientPackage;
use App\Models\ClientUsage;
use App\Models\FtpAccount;
use App\Models\MysqlDatabase;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\Models\Website;
use App\Services\Clients\ClientManager;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::query()->create(['name' => 'Admin', 'username' => 'admin', 'password' => 'Secret12345', 'role' => 'admin', 'is_active' => true]);
    }

    protected function package(array $attrs = []): ClientPackage
    {
        return ClientPackage::query()->create($attrs + ['name' => 'Basic', 'max_websites' => 2, 'max_databases' => 2, 'max_ftp' => 2, 'disk_mb' => 100, 'bandwidth_mb' => 1000, 'allow_ssl' => true]);
    }

    protected function client(string $username = 'joao', ?ClientPackage $package = null): Client
    {
        ClientManager::forgetPortalCache();

        return Client::query()->create(['username' => $username, 'name' => ucfirst($username), 'email' => $username.'@example.com', 'password' => 'Client12345', 'package_id' => ($package ?? $this->package())->id, 'status' => 'active']);
    }

    protected function site(Client $client, string $domain): Website
    {
        return Website::query()->create(['domain' => $domain, 'root_path' => '/www/wwwroot/'.$domain, 'client_id' => $client->id]);
    }

    public function test_portal_is_public_only_while_clients_exist_and_enabled(): void
    {
        $this->get('/client/login')->assertNotFound(); // no clients yet: hidden behind the entrance
        $this->client();
        $this->get('/client/login')->assertOk()->assertSee('Client area');

        Setting::put('client_portal', false);
        ClientManager::forgetPortalCache();
        $this->get('/client/login')->assertNotFound();
    }

    public function test_client_signs_in_and_cannot_reach_the_admin_panel(): void
    {
        $client = $this->client();
        $this->post('/client/login', ['username' => 'joao', 'password' => 'wrong'])->assertSessionHasErrors('username');
        $this->post('/client/login', ['username' => 'joao', 'password' => 'Client12345'])->assertRedirect(route('client.home'));
        $this->assertAuthenticatedAs($client, 'client');
        $this->assertGuest('web');

        $this->get('/client')->assertOk()->assertSee('Overview');
        $this->getJson('/client/overview')->assertOk()->assertJsonPath('counts.websites', [0, 2]);
        $this->get('/websites')->assertNotFound(); // admin area stays behind the security entrance
        $this->getJson('/docker/overview')->assertNotFound();
        $this->assertSame($client->id, ActivityLog::query()->where('action', 'Client signed in')->value('client_id'));
    }

    public function test_client_two_factor_challenge(): void
    {
        $client = $this->client();
        $client->forceFill(['two_factor_secret' => TwoFactor::generateSecret(), 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => []])->save();

        $this->post('/client/login', ['username' => 'joao', 'password' => 'Client12345'])->assertRedirect(route('client.login.two-factor'));
        $this->assertGuest('client');
        $this->post('/client/login/two-factor', ['code' => TwoFactor::code($client->fresh()->two_factor_secret, TwoFactor::step())])->assertRedirect(route('client.home'));
        $this->assertAuthenticatedAs($client, 'client');
    }

    public function test_resources_of_other_clients_are_invisible(): void
    {
        $joao = $this->client('joao');
        $maria = $this->client('maria');
        $mariaSite = $this->site($maria, 'maria.com');
        $mariaFtp = FtpAccount::query()->create(['username' => 'mariaftp', 'password' => 'x', 'path' => '/www/wwwroot/maria.com', 'website_id' => $mariaSite->id, 'client_id' => $maria->id]);
        $mariaDb = MysqlDatabase::query()->create(['name' => 'maria2_shop', 'username' => 'maria2_shop', 'password' => 'x', 'client_id' => $maria->id]);
        $adminSite = Website::query()->create(['domain' => 'admin.com', 'root_path' => '/www/wwwroot/admin.com']);

        $this->actingAs($joao, 'client');
        $this->get('/client/websites')->assertOk()->assertDontSee('maria.com')->assertDontSee('admin.com');
        $this->postJson('/client/websites/'.$mariaSite->id.'/status')->assertNotFound();
        $this->postJson('/client/websites/'.$adminSite->id.'/php', ['php_version' => ''])->assertNotFound();
        $this->deleteJson('/client/websites/'.$mariaSite->id)->assertNotFound();
        $this->getJson('/client/websites/'.$mariaSite->id.'/logs')->assertNotFound();
        $this->postJson('/client/ftp/'.$mariaFtp->id.'/password', ['password' => 'Abcdef12345'])->assertNotFound();
        $this->getJson('/client/databases/'.$mariaDb->id.'/credentials')->assertNotFound();
        $this->deleteJson('/client/databases/'.$mariaDb->id)->assertNotFound();
        $this->postJson('/client/ftp', ['username' => 'joaoftp', 'password' => 'Abcdef12345', 'website_id' => $mariaSite->id])->assertNotFound();

        $task = Task::query()->create(['title' => 'x', 'script' => 'x', 'status' => 'success', 'client_id' => $maria->id]);
        $this->getJson('/client/tasks/'.$task->id)->assertNotFound();
        $this->getJson('/client/tasks')->assertJsonCount(0, 'data');
    }

    public function test_websites_respect_package_limits_and_names_of_other_sites(): void
    {
        $client = $this->client('joao', $this->package(['max_websites' => 1]));
        Website::query()->create(['domain' => 'taken.com', 'aliases' => 'www.taken.com', 'root_path' => '/www/wwwroot/taken.com']);
        $this->actingAs($client, 'client');

        $this->postJson('/client/websites', ['domain' => 'shop.com', 'aliases' => 'www.taken.com', 'php_version' => ''])->assertStatus(422)->assertJsonValidationErrors('domain');
        $this->postJson('/client/websites', ['domain' => '*.shop.com', 'php_version' => ''])->assertStatus(422);
        $this->postJson('/client/websites', ['domain' => 'shop.com', 'add_www' => 1, 'php_version' => ''])->assertOk();
        $site = Website::query()->where('domain', 'shop.com')->firstOrFail();
        $this->assertSame($client->id, $site->client_id);
        $this->assertSame('/www/wwwroot/shop.com', $site->root_path);
        $this->assertSame('www.shop.com', $site->aliases);

        $this->postJson('/client/websites', ['domain' => 'second.com', 'php_version' => ''])->assertStatus(422)->assertJsonPath('message', 'Your package allows 1 website(s).');
    }

    public function test_databases_get_a_client_prefix_and_ftp_stays_inside_the_site(): void
    {
        $client = $this->client('joao');
        $site = $this->site($client, 'shop.com');
        $this->actingAs($client, 'client');

        $res = $this->postJson('/client/databases', ['name' => 'store', 'website_id' => $site->id])->assertOk();
        $name = 'joao'.$client->id.'_store';
        $res->assertJsonPath('database.name', $name);
        $this->assertTrue(MysqlDatabase::query()->where('name', $name)->where('client_id', $client->id)->exists());
        $this->postJson('/client/databases', ['name' => 'store'])->assertStatus(422);

        $this->postJson('/client/ftp', ['username' => 'joaoftp', 'password' => 'Abcdef12345', 'website_id' => $site->id, 'folder' => '../../etc'])->assertStatus(422);
        $this->postJson('/client/ftp', ['username' => 'joaoftp', 'password' => 'Abcdef12345', 'website_id' => $site->id, 'folder' => 'public'])->assertOk();
        $this->assertSame('/www/wwwroot/shop.com/public', FtpAccount::query()->where('username', 'joaoftp')->value('path'));
    }

    public function test_suspension_stops_resources_and_blocks_sign_in(): void
    {
        $this->actingAs($this->admin());
        $client = $this->client();
        $site = $this->site($client, 'shop.com');
        $ftp = FtpAccount::query()->create(['username' => 'shopftp', 'password' => 'x', 'path' => '/www/wwwroot/shop.com', 'client_id' => $client->id, 'is_active' => true]);
        $stopped = $this->site($client, 'old.com');
        $stopped->update(['status' => 'stopped']);

        $this->postJson('/clients/'.$client->id.'/suspend', ['reason' => 'Invoice overdue'])->assertOk();
        $this->assertSame('stopped', $site->fresh()->status);
        $this->assertFalse($ftp->fresh()->is_active);
        $this->assertSame('suspended', $client->fresh()->status);

        $this->post('/client/login', ['username' => 'joao', 'password' => 'Client12345'])->assertSessionHasErrors('username');

        $this->postJson('/clients/'.$client->id.'/unsuspend')->assertOk();
        $this->assertSame('active', $site->fresh()->status);
        $this->assertTrue($ftp->fresh()->is_active);
        $this->assertSame('stopped', $stopped->fresh()->status, 'sites stopped before the suspension stay stopped');
    }

    public function test_admin_manages_accounts_packages_resources_and_impersonation(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $this->get('/clients')->assertOk();

        $this->postJson('/clients/packages', ['name' => 'Pro', 'max_websites' => 5, 'max_databases' => 5, 'max_ftp' => 5, 'disk_mb' => 5120, 'bandwidth_mb' => 0, 'php_versions' => ['8.4'], 'allow_ssl' => 1])->assertOk();
        $package = ClientPackage::query()->where('name', 'Pro')->firstOrFail();
        $this->assertSame(['8.4'], $package->php_versions);

        $this->postJson('/clients', ['username' => 'Bad User', 'name' => 'x', 'password' => 'Client12345'])->assertStatus(422);
        $this->postJson('/clients', ['username' => 'acme', 'name' => 'ACME', 'password' => 'Client12345', 'package_id' => $package->id])->assertOk();
        $client = Client::query()->where('username', 'acme')->firstOrFail();
        $this->getJson('/clients/list')->assertOk()->assertJsonPath('data.0.bandwidth_h', '0 B / Unlimited');

        $site = Website::query()->create(['domain' => 'acme.com', 'root_path' => '/www/wwwroot/acme.com']);
        $db = MysqlDatabase::query()->create(['name' => 'acme_db', 'username' => 'acme_db', 'password' => 'x', 'website_id' => $site->id]);
        $this->postJson('/clients/'.$client->id.'/resources', ['resource' => 'websites', 'ids' => [$site->id], 'attach' => 1])->assertOk();
        $this->assertSame($client->id, $site->fresh()->client_id);
        $this->assertSame($client->id, $db->fresh()->client_id, 'linked databases follow the website');
        $this->getJson('/clients/'.$client->id.'/resources')->assertJsonPath('data.websites.0.mine', true);

        $this->post('/clients/'.$client->id.'/login')->assertRedirect(route('client.home'));
        $this->assertAuthenticatedAs($client, 'client');
        $this->get('/client/websites')->assertOk()->assertSee('acme.com')->assertSee('Back to admin');
        $this->post('/client/logout')->assertRedirect(route('clients.index'));
        $this->assertGuest('client');
        $this->assertAuthenticatedAs($admin, 'web');

        $this->deleteJson('/clients/packages/'.$package->id)->assertStatus(422);
        $this->deleteJson('/clients/'.$client->id)->assertOk();
        $this->assertNull($site->fresh()->client_id);
        $this->assertNull($db->fresh()->client_id);

        $operator = User::query()->create(['name' => 'Op', 'username' => 'op', 'password' => 'Secret12345', 'role' => 'operator', 'is_active' => true]);
        $this->actingAs($operator)->get('/clients')->assertForbidden();
    }

    public function test_usage_accounting_limits_and_expiration(): void
    {
        $client = $this->client('joao', $this->package(['disk_mb' => 1, 'bandwidth_mb' => 0]));
        $this->site($client, 'shop.com');
        $manager = app(ClientManager::class);

        $manager->countHour(strtotime('2026-09-15 10:00:00'));
        $row = ClientUsage::query()->where('client_id', $client->id)->firstOrFail();
        $this->assertGreaterThan(0, $row->bandwidth);

        $manager->refresh(true);
        $client->refresh();
        $this->assertGreaterThan(1048576, $client->disk_used, 'simulated disk usage exceeds 1 MB');
        $this->assertSame('Your disk quota is full. Free space or upgrade your package.', $manager->blockedReason($client));
        $this->actingAs($client, 'client')->postJson('/client/databases', ['name' => 'x'])->assertStatus(422)->assertJsonPath('message', 'Your disk quota is full. Free space or upgrade your package.');

        $client->update(['expires_at' => now()->subDay()->toDateString()]);
        $manager->refresh();
        $this->assertSame('suspended', $client->fresh()->status);
        $this->assertStringContainsString('Expired on', $client->fresh()->suspended_reason);
    }
}
