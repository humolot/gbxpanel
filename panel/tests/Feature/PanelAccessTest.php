<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(array $attributes = []): User
    {
        return User::query()->create($attributes + [
            'name' => 'Admin',
            'username' => 'admin',
            'password' => 'Secret12345',
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    public function test_panel_is_hidden_without_security_entrance(): void
    {
        $this->get('/')->assertNotFound();
        $this->get('/login')->assertNotFound();
    }

    public function test_bound_domain_hides_panel_on_other_hosts(): void
    {
        config(['gbx.domain' => 'panel.example.com']);

        $this->get('http://142.93.121.40/testentry')->assertNotFound();
        $this->get('http://panel.example.com/testentry')->assertRedirect();
    }

    public function test_entrance_unlocks_login_page(): void
    {
        $this->get('/testentry')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Sign in');
    }

    public function test_login_requires_entrance_session(): void
    {
        $this->admin();

        $this->post('/login', ['username' => 'admin', 'password' => 'Secret12345'])->assertNotFound();
        $this->assertGuest();
    }

    public function test_admin_can_sign_in_and_open_every_page(): void
    {
        $this->admin();
        $this->get('/testentry');

        $this->post('/login', ['username' => 'admin', 'password' => 'Secret12345'])->assertRedirect('/');
        $this->assertAuthenticated();

        foreach (['/', '/home/monitor', '/home/processes', '/home/services', '/home/software', '/home/cron', '/websites', '/ftp', '/databases', '/docker', '/security', '/security/antivirus', '/files', '/logs', '/terminal', '/accounts', '/ai', '/settings'] as $page) {
            $this->get($page)->assertOk();
        }
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->admin();
        $this->get('/testentry');

        $this->post('/login', ['username' => 'admin', 'password' => 'nope'])->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_read_only_user_cannot_change_anything(): void
    {
        $viewer = $this->admin(['username' => 'viewer', 'role' => 'viewer']);

        $this->actingAs($viewer)->getJson('/websites')->assertOk();
        $this->actingAs($viewer)->postJson('/websites', ['domain' => 'example.com'])->assertForbidden();
        $this->actingAs($viewer)->get('/terminal')->assertForbidden();
    }

    public function test_operator_cannot_open_admin_areas(): void
    {
        $operator = $this->admin(['username' => 'ops', 'role' => 'operator']);

        $this->actingAs($operator)->get('/settings')->assertForbidden();
        $this->actingAs($operator)->get('/accounts')->assertForbidden();
        $this->actingAs($operator)->get('/websites')->assertOk();
    }

    public function test_website_can_be_created_in_simulation(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/websites', ['domain' => 'example.com', 'php_version' => '8.4', 'add_www' => 1])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('websites', ['domain' => 'example.com', 'aliases' => 'www.example.com', 'root_path' => '/www/wwwroot/example.com']);
    }

    public function test_invalid_domain_and_protected_root_are_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/websites', ['domain' => 'bad domain'])->assertUnprocessable();
        $this->actingAs($admin)->postJson('/websites', ['domain' => 'ok.com', 'root_path' => '/etc'])->assertUnprocessable();
    }
}
