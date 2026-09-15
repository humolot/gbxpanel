<?php

namespace Tests\Feature;

use App\Models\AiAction;
use App\Models\CronJob;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\Models\Website;
use App\Services\Ai\ServerTools;
use App\Services\ApacheManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function user(string $role = 'admin'): User
    {
        $user = User::query()->create(['name' => ucfirst($role), 'username' => $role, 'password' => 'Secret12345', 'role' => $role, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    protected function run_tool(string $name, array $args, ?User $user = null): array
    {
        $result = app(ServerTools::class)->execute($name, $args, $user ?? auth()->user());
        $decoded = json_decode($result, true);

        return is_array($decoded) ? $decoded : ['raw' => $result];
    }

    public function test_every_tool_has_a_valid_schema_and_label(): void
    {
        $tools = app(ServerTools::class);
        $catalog = $tools->catalog();

        $this->assertGreaterThan(80, count($catalog));
        foreach ($catalog as $name => $spec) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]{2,63}$/', $name);
            $this->assertNotEmpty($spec['description'], $name);
            $this->assertSame('object', $spec['parameters']['type'], $name);
            $properties = (array) $spec['parameters']['properties'];
            foreach ($spec['parameters']['required'] as $required) {
                $this->assertArrayHasKey($required, $properties, "{$name} requires missing {$required}");
            }
            $this->assertIsString($tools->label($name, []));
        }
    }

    public function test_tools_are_filtered_by_role(): void
    {
        $tools = app(ServerTools::class);
        $names = fn (User $u) => collect($tools->definitions($u))->pluck('function.name');

        $admin = $names($this->user('admin'));
        $operator = $names(User::query()->create(['name' => 'Op', 'username' => 'op', 'password' => 'Secret12345', 'role' => 'operator']));
        $viewer = $names(User::query()->create(['name' => 'View', 'username' => 'view', 'password' => 'Secret12345', 'role' => 'viewer']));

        foreach (['run_command', 'write_file', 'edit_file', 'docker_exec', 'git_deploy', 'reboot_server', 'update_ssh_settings'] as $adminOnly) {
            $this->assertTrue($admin->contains($adminOnly), $adminOnly);
            $this->assertFalse($operator->contains($adminOnly), $adminOnly);
        }
        $this->assertTrue($operator->contains('docker_compose_deploy'));
        $this->assertTrue($viewer->contains('get_memory_usage'));
        $this->assertFalse($viewer->contains('open_port'));
        $this->assertFalse($viewer->contains('backup_website'));
    }

    public function test_backups_and_task_status(): void
    {
        $this->user();
        Website::query()->create(['domain' => 'shop.com', 'root_path' => '/www/wwwroot/shop.com', 'php_version' => '8.4']);

        $result = $this->run_tool('backup_website', ['domain' => 'shop.com']);
        $this->assertTrue($result['queued']);
        $this->assertStringContainsString('tar -czf', Task::query()->findOrFail($result['task_id'])->script);

        $status = $this->run_tool('get_task_status', ['task_id' => $result['task_id']]);
        $this->assertSame('success', $status['status']);

        $this->assertArrayHasKey('error', $this->run_tool('restore_archive', ['file' => '/etc/passwd', 'destination' => '/tmp/x']));
    }

    public function test_edit_file_requires_unique_fragment(): void
    {
        $this->user();

        $this->assertTrue($this->run_tool('edit_file', ['path' => '/www/wwwroot/a/index.php', 'find' => "echo 'Hello", 'replace' => "echo 'Hi"])['ok']);
        $missing = $this->run_tool('edit_file', ['path' => '/www/wwwroot/a/index.php', 'find' => 'does-not-exist', 'replace' => 'x']);
        $this->assertFalse($missing['ok']);
    }

    public function test_query_database_is_read_only(): void
    {
        $this->user();

        $this->assertArrayHasKey('error', $this->run_tool('query_database', ['database' => 'shop', 'sql' => 'DELETE FROM users']));
        $this->assertArrayHasKey('error', $this->run_tool('query_database', ['database' => 'shop', 'sql' => 'SELECT 1; DROP TABLE users']));
        $this->assertArrayHasKey('error', $this->run_tool('query_database', ['database' => 'shop', 'sql' => "SELECT * FROM users INTO OUTFILE '/tmp/x'"]));
        $this->assertArrayHasKey('raw', $this->run_tool('query_database', ['database' => 'shop', 'sql' => 'SELECT id FROM users']));
    }

    public function test_cron_supervisor_and_pm2(): void
    {
        $this->user();

        $created = $this->run_tool('manage_cron_job', ['action' => 'create', 'name' => 'Backup', 'schedule' => '0 3 * * *', 'command' => 'echo ok']);
        $this->assertTrue($created['ok']);
        $this->run_tool('manage_cron_job', ['action' => 'update', 'id' => $created['id'], 'schedule' => '*/10 * * * *']);
        $this->assertSame('*/10 * * * *', CronJob::query()->find($created['id'])->schedule);
        $this->assertFalse($this->run_tool('manage_cron_job', ['action' => 'create', 'name' => 'x', 'schedule' => 'bad', 'command' => 'x'])['ok'] ?? false);

        $this->assertTrue($this->run_tool('save_supervisor_program', ['name' => 'shop-queue', 'command' => 'php artisan queue:work', 'directory' => '/www/wwwroot/shop.com', 'numprocs' => 2])['ok']);
        $this->assertFalse($this->run_tool('save_supervisor_program', ['name' => 'gbxpanel-worker', 'command' => 'x'])['ok']);
        $this->assertFalse($this->run_tool('save_supervisor_program', ['name' => 'Bad Name!', 'command' => 'x'])['ok']);

        $this->assertTrue($this->run_tool('pm2_start', ['name' => 'api', 'script' => 'dist/server.js', 'cwd' => '/www/wwwroot/api', 'env' => ['PORT' => '3000']])['ok']);
    }

    public function test_deploy_tools_validate_input(): void
    {
        $this->user();

        $this->assertArrayHasKey('error', $this->run_tool('git_deploy', ['repository' => 'file:///etc', 'path' => '/www/wwwroot/app']));
        $this->assertArrayHasKey('error', $this->run_tool('git_deploy', ['repository' => 'https://github.com/humolot/gbxpanel.git', 'path' => '/etc']));
        $ok = $this->run_tool('git_deploy', ['repository' => 'https://github.com/humolot/gbxpanel.git', 'path' => '/www/wwwroot/app', 'commands' => ['composer install --no-dev']]);
        $this->assertStringContainsString('git clone', Task::query()->findOrFail($ok['task_id'])->script);

        $this->assertArrayHasKey('error', $this->run_tool('docker_compose_deploy', ['project' => 'Bad Name', 'compose' => "services:\n  a:\n    image: nginx"]));
        $this->assertFalse($this->run_tool('docker_compose_deploy', ['project' => 'kuma', 'compose' => 'version: 3'])['ok']);
        $deploy = $this->run_tool('docker_compose_deploy', ['project' => 'kuma', 'compose' => "services:\n  kuma:\n    image: louislam/uptime-kuma:1\n    ports:\n      - \"127.0.0.1:3001:3001\""]);
        $this->assertStringContainsString('docker compose up -d', Task::query()->findOrFail($deploy['task_id'])->script);
    }

    public function test_website_reverse_proxy(): void
    {
        $this->user();

        $result = $this->run_tool('create_website', ['domain' => 'status.example.com', 'proxy_target' => 'http://127.0.0.1:3001']);
        $this->assertTrue($result['ok']);

        $site = Website::query()->where('domain', 'status.example.com')->firstOrFail();
        $this->assertSame('http://127.0.0.1:3001', $site->proxy_target);
        $this->assertNull($site->php_version);
        $vhost = app(ApacheManager::class)->render($site);
        $this->assertStringContainsString('ProxyPass / http://127.0.0.1:3001/', $vhost);
        $this->assertStringContainsString('ws://127.0.0.1:3001', $vhost);

        $this->assertFalse($this->run_tool('create_website', ['domain' => 'bad.example.com', 'proxy_target' => 'file:///etc'])['ok']);
    }

    public function test_firewall_protects_panel_and_ssh_ports(): void
    {
        $this->user();

        $this->assertFalse($this->run_tool('close_port', ['port' => (string) config('gbx.port')])['ok']);
        $this->assertFalse($this->run_tool('close_port', ['port' => '22'])['ok']);
        $this->assertTrue($this->run_tool('close_port', ['port' => '80'])['ok']);
    }

    public function test_databases_and_ftp_accounts(): void
    {
        $this->user();

        $db = $this->run_tool('create_database', ['name' => 'shop']);
        $this->assertTrue($db['ok']);
        $this->assertNotEmpty($db['password']);
        $this->assertTrue($this->run_tool('delete_database', ['name' => 'shop'])['ok']);

        $ftp = $this->run_tool('manage_ftp_account', ['action' => 'create', 'username' => 'shopftp', 'path' => '/www/wwwroot/shop.com']);
        $this->assertTrue($ftp['ok']);
        $this->assertTrue($this->run_tool('manage_ftp_account', ['action' => 'delete', 'username' => 'shopftp'])['ok']);
    }

    public function test_approve_all_runs_every_pending_action(): void
    {
        $admin = $this->user();
        Setting::putSecret('ai_api_key', 'test-key');

        Http::fakeSequence('api.venice.ai/*')
            ->push(['choices' => [['index' => 0, 'finish_reason' => 'tool_calls', 'message' => ['role' => 'assistant', 'content' => 'Opening ports.', 'tool_calls' => [
                ['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'open_port', 'arguments' => '{"port":"8080","protocol":"tcp"}']],
                ['id' => 'c2', 'type' => 'function', 'function' => ['name' => 'open_port', 'arguments' => '{"port":"8443","protocol":"tcp"}']],
            ]]]]])
            ->push(['choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'Both ports are open.']]]]);

        $response = $this->postJson('/ai/send', ['message' => 'open 8080 and 8443']);
        $response->assertOk()->assertJsonPath('pending', 2);

        $this->postJson('/ai/conversations/'.$response->json('conversation.id').'/actions', ['decision' => 'approve'])
            ->assertOk()->assertJsonPath('pending', 0);

        $this->assertSame(2, AiAction::query()->where('status', 'done')->count());
        Http::assertSentCount(2);
    }
}
