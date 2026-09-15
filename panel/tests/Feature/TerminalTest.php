<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TerminalManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TerminalTest extends TestCase
{
    use RefreshDatabase;

    protected function user(string $role): User
    {
        return User::query()->create(['name' => ucfirst($role), 'username' => $role, 'password' => 'Secret12345', 'role' => $role, 'is_active' => true]);
    }

    /** Listening socket that stands in for the gbx-terminal daemon. */
    protected function fakeDaemon(): mixed
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertNotFalse($server, $error);
        config(['gbx.terminal.port' => (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1)]);

        return $server;
    }

    public function test_token_is_signed_with_app_key_and_bound_to_client(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $user = $this->user('admin');

        $token = app(TerminalManager::class)->token($user, '203.0.113.5', 140, 40);
        [$payload, $signature] = explode('.', $token);

        $expected = TerminalManager::base64url(hash_hmac('sha256', $payload, str_repeat('k', 32), true));
        $this->assertSame($expected, $signature);

        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $this->assertSame($user->id, $claims['u']);
        $this->assertSame('203.0.113.5', $claims['ip']);
        $this->assertSame(140, $claims['c']);
        $this->assertGreaterThan(time(), $claims['e']);
        $this->assertLessThanOrEqual(time() + 60, $claims['e']);
        $this->assertSame(32, strlen($claims['r']));

        $this->assertNotSame($token, app(TerminalManager::class)->token($user, '203.0.113.5'));
    }

    public function test_token_endpoint_requires_running_daemon(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        fclose($server);
        config(['gbx.terminal.port' => $port]);

        $this->actingAs($this->user('admin'))
            ->postJson('/terminal/token')
            ->assertStatus(503)
            ->assertJsonPath('ok', false);
    }

    public function test_admin_receives_token_and_page_uses_live_mode(): void
    {
        $server = $this->fakeDaemon();
        $admin = $this->user('admin');

        $this->actingAs($admin)->postJson('/terminal/token', ['cols' => 100, 'rows' => 30])
            ->assertOk()
            ->assertJsonStructure(['ok', 'token', 'url']);

        $this->actingAs($admin)->get('/terminal')->assertOk()->assertSee('assets/vendor/xterm/xterm.js', false);
        $this->assertDatabaseHas('activity_logs', ['category' => 'terminal', 'action' => 'Opened terminal session']);

        fclose($server);
    }

    public function test_page_falls_back_to_command_mode_without_daemon(): void
    {
        config(['gbx.terminal.port' => 1]);

        $this->actingAs($this->user('admin'))->get('/terminal')
            ->assertOk()
            ->assertSee('gbx terminal')
            ->assertDontSee('assets/vendor/xterm/xterm.js', false);
    }

    public function test_only_admins_can_open_sessions(): void
    {
        $server = $this->fakeDaemon();

        $this->actingAs($this->user('operator'))->postJson('/terminal/token')->assertForbidden();
        $this->actingAs($this->user('viewer'))->postJson('/terminal/token')->assertForbidden();

        fclose($server);
    }
}
