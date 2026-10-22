<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use App\Services\PhpManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHP publishes patch releases every month; the panel installs them per version.
 */
class PhpUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function login(string $role = 'admin'): User
    {
        $user = User::query()->create(['name' => ucfirst($role), 'username' => $role, 'password' => 'Secret12345', 'role' => $role, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_package_versions_are_read_and_compared(): void
    {
        $this->assertSame('8.4.15', PhpManager::shortVersion('8.4.15-1+ubuntu24.04.1+deb.sury.org+1'));
        $this->assertSame('8.3.12', PhpManager::shortVersion('1:8.3.12-1'));
        $this->assertSame('7.4.33', PhpManager::shortVersion('7.4.33-9+deb.sury.org~bullseye+1'));

        $updates = app(PhpManager::class)->updates(['8.4', '8.3']);
        $this->assertTrue($updates['8.4']['update'], 'a newer release is offered');
        $this->assertFalse($updates['8.3']['update'], 'a version already at the newest release is left alone');
        $this->assertSame([], app(PhpManager::class)->updates([]));

        $this->assertSame('8.4', PhpManager::panelVersion(), 'read from the PHP binary the panel runs with');
    }

    public function test_the_update_script_only_touches_what_is_installed(): void
    {
        $script = app(PhpManager::class)->updateScript(['8.3']);

        $this->assertStringContainsString('flock -w 900 9', $script, 'package tasks wait for the apt lock');
        $this->assertStringContainsString('--only-upgrade', $script, 'nothing new is installed');
        $this->assertStringContainsString("'php8.3' 'php8.3-*' 'libapache2-mod-php8.3'", $script, 'only the packages of that version');
        $this->assertStringContainsString('$2 == "installed"', $script, 'packages that are only half removed are left out');
        $this->assertStringContainsString('systemctl restart php8.3-fpm', $script);
        $this->assertStringNotContainsString('gbxpanel-fpm', $script, 'this version does not run the panel');
        $this->assertStringContainsString('exit $FAILED', $script, 'a version that fails fails the task');

        // reported by a user: a transitional package that became virtual (php8.4-json) has no
        // installation candidate, and apt then refused the whole command and upgraded nothing
        $this->assertStringContainsString('apt-cache policy', $script);
        $this->assertStringContainsString("''|'(none)')", $script, 'packages the repository dropped are skipped');

        // the version the panel runs on reloads its own services, detached, and only when it changed
        $panel = app(PhpManager::class)->updateScript([PhpManager::panelVersion()]);
        $this->assertStringContainsString('setsid nohup bash -c', $panel);
        $this->assertStringContainsString('systemctl reload gbxpanel-fpm || systemctl restart gbxpanel-fpm', $panel, 'a graceful reload keeps the request being served alive');
        $this->assertStringContainsString('supervisorctl restart gbxpanel-worker', $panel);
        $this->assertStringContainsString('case "$CHANGED" in', $panel, 'nothing is reloaded when the version did not change');

        $this->assertThrows(fn () => app(PhpManager::class)->updateScript(['9.9']), \InvalidArgumentException::class);
    }

    public function test_the_page_lists_the_releases_and_starts_the_update(): void
    {
        $this->login();

        $this->get('/home/software')->assertOk()->assertSee('PHP updates');
        $page = $this->getJson('/home/software/php-updates')->assertOk()->assertJsonPath('panel_version', '8.4')->json('data');
        $this->assertTrue($page['8.4']['update']);
        $this->assertSame('8.4.15', $page['8.4']['candidate']);

        $this->postJson('/home/software/php-update', ['versions' => ['8.3']])->assertOk();
        $task = Task::query()->latest('id')->firstOrFail();
        $this->assertSame('Update PHP 8.3', $task->title);
        $this->assertSame(['8.3'], $task->meta['php_versions']);

        $this->postJson('/home/software/php-update', ['versions' => 'all'])->assertOk();
        $this->assertSame(['8.4', '8.3'], Task::query()->latest('id')->value('meta')['php_versions']);

        // versions that are not installed here are refused
        $this->postJson('/home/software/php-update', ['versions' => ['8.1']])->assertStatus(422);
        $this->postJson('/home/software/php-update', ['versions' => []])->assertStatus(422);
    }

    public function test_the_api_reports_and_updates_php(): void
    {
        [$key, $token] = \App\Models\ApiKey::issue([
            'name' => 'Deploy', 'scopes' => ['*'], 'user_id' => $this->login()->id, 'rate_limit' => 120, 'is_active' => true,
        ]);
        $headers = ['Authorization' => 'Bearer '.$token];

        $data = $this->json('GET', '/api/v1/system/php', [], $headers)->assertOk()->assertJsonPath('data.panel_version', '8.4')->json('data');
        $this->assertSame('8.4.15', $data['releases']['8.4']['candidate']);

        $this->json('POST', '/api/v1/system/php/update', ['versions' => ['8.3']], $headers)->assertOk();
        $this->assertSame('Update PHP 8.3', Task::query()->latest('id')->value('title'));
        $this->assertNotNull($key->fresh()->last_used_at);
    }

    public function test_only_administrators_update_php(): void
    {
        $this->login('user');
        $this->postJson('/home/software/php-update', ['versions' => 'all'])->assertForbidden();
    }
}
