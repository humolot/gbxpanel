<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use App\Services\Apt;
use App\Services\PanelManager;
use App\Services\PhpManager;
use App\Services\SoftwareManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Package tasks must wait for whoever is holding the apt lock (automatic updates, a shell
 * session, another panel task) instead of failing with "Could not get lock".
 */
class AptLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_preamble_waits_instead_of_failing(): void
    {
        $preamble = Apt::preamble();

        $this->assertStringContainsString('DPkg::Lock::Timeout "900"', $preamble, 'apt itself waits for the system lock');
        $this->assertStringContainsString('/etc/apt/apt.conf.d/99gbx-lock-timeout', $preamble);
        $this->assertStringContainsString('exec 9>/var/lock/gbx-apt.lock', $preamble);
        $this->assertStringContainsString('flock -w 900 9', $preamble, 'two package tasks of the panel never run together');
        $this->assertStringContainsString('fuser /var/lib/dpkg/lock-frontend', $preamble, 'the log says what is holding the lock');
        $this->assertStringContainsString('exit 100', $preamble);

        $this->assertStringContainsString('DPkg::Lock::Timeout "60"', Apt::preamble(60));
        $this->assertStringContainsString('flock -w 3600', Apt::preamble(9999), 'the wait is kept inside sane limits');
    }

    public function test_every_package_script_of_the_panel_is_guarded(): void
    {
        $user = User::query()->create(['name' => 'Admin', 'username' => 'admin', 'password' => 'Secret12345', 'role' => 'admin', 'is_active' => true]);
        $this->actingAs($user);

        app(SoftwareManager::class)->install('redis', null);
        $install = (string) Task::query()->latest('id')->value('script');
        $this->assertStringStartsWith('export DEBIAN_FRONTEND=noninteractive', $install);
        $this->assertStringContainsString('flock -w 900 9', $install);
        $this->assertStringContainsString('apt-get', $install);

        app(SoftwareManager::class)->uninstall('redis', null);
        $this->assertStringContainsString('flock -w 900 9', (string) Task::query()->latest('id')->value('script'));

        foreach (['upgrade' => app(PanelManager::class)->updateSystemScript(), 'dist-upgrade' => app(PanelManager::class)->updateSystemScript(true)] as $command => $script) {
            $this->assertStringContainsString('flock -w 900 9', $script);
            $this->assertStringContainsString('apt-get -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold '.$command, $script);
        }

        $this->assertStringContainsString('flock -w 900 9', app(PhpManager::class)->installExtensionScript('8.3', 'redis'));

        // helpers that install a missing tool in passing cannot take the panel lock (they run
        // inside another task), but they must still let apt wait for the system one
        $site = \App\Models\Website::query()->create(['domain' => 'ssl.test', 'root_path' => '/www/wwwroot/ssl.test']);
        app(\App\Services\SslManager::class)->issue($site, 'admin@ssl.test', false, 'http', false);
        $this->assertStringContainsString('DPkg::Lock::Timeout=900', (string) Task::query()->latest('id')->value('script'));
        $this->assertStringContainsString('DPkg::Lock::Timeout=900', \App\Services\Backup\StorageManager::installScript());
    }
}
