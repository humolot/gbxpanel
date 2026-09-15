<?php

namespace Tests\Feature;

use App\Models\CronJob;
use App\Models\CronScript;
use App\Models\MysqlDatabase;
use App\Models\Task;
use App\Models\User;
use App\Models\Website;
use App\Services\CronManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CronModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function login(string $role = 'admin'): User
    {
        $user = User::query()->create(['name' => ucfirst($role), 'username' => $role, 'password' => 'Secret12345', 'role' => $role, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_tabs_render_with_builtin_scripts(): void
    {
        $this->login();
        $this->assertGreaterThan(15, CronScript::query()->where('is_builtin', true)->count());
        foreach (['jobs', 'flows', 'scripts'] as $tab) {
            $this->get('/home/cron?tab='.$tab)->assertOk()->assertSee('db-tabs-nav', false);
        }
        $this->get('/home/cron?tab=scripts')->assertSee('Restart Apache')->assertSee('Service Management');
    }

    public function test_cycles_are_converted_to_cron(): void
    {
        $this->assertSame('*/5 * * * *', CronManager::cycle(['type' => 'minute_n', 'n' => 5])['cron']);
        $this->assertSame('15 */2 * * *', CronManager::cycle(['type' => 'hour_n', 'n' => 2, 'minute' => 15])['cron']);
        $this->assertSame('30 1 * * *', CronManager::cycle(['type' => 'day', 'hour' => 1, 'minute' => 30])['cron']);
        $this->assertSame('0 4 * * 0', CronManager::cycle(['type' => 'week', 'weekday' => 0, 'hour' => 4])['cron']);
        $this->assertSame('0 5 1 * *', CronManager::cycle(['type' => 'month', 'day' => 1, 'hour' => 5])['cron']);
        $this->assertSame('Every Sunday at 04:00', CronManager::describeCycle(CronManager::cycle(['type' => 'week', 'weekday' => 0, 'hour' => 4])));
        $this->expectException(\InvalidArgumentException::class);
        CronManager::cycle(['type' => 'custom', 'expr' => '* * * * * ; rm -rf /']);
    }

    public function test_shell_job_with_multiple_cycles(): void
    {
        $this->login();
        $cycles = json_encode([['type' => 'day', 'hour' => 2, 'minute' => 0], ['type' => 'day', 'hour' => 14, 'minute' => 0]]);

        $this->postJson('/home/cron', ['type' => 'shell', 'name' => 'Twice a day', 'cycles' => $cycles, 'command' => "echo one\r\necho two", 'run_as' => 'www-data'])
            ->assertOk()->assertJsonPath('ok', true);

        $job = CronJob::query()->firstOrFail();
        $this->assertSame('0 2 * * *', $job->schedule);
        $this->assertCount(2, $job->cycles);
        $this->assertSame("echo one\necho two", $job->command);

        $crontab = app(CronManager::class)->render();
        $this->assertStringContainsString('0 2 * * * root /usr/local/gbxpanel/cron/run '.$job->id.' www-data', $crontab);
        $this->assertStringContainsString('0 14 * * * root /usr/local/gbxpanel/cron/run '.$job->id.' www-data', $crontab);

        // forbidden commands and invalid cycles are refused
        $this->postJson('/home/cron', ['type' => 'shell', 'name' => 'x', 'cycles' => $cycles, 'command' => 'shutdown -h now'])->assertStatus(422);
        $this->postJson('/home/cron', ['type' => 'shell', 'name' => 'x', 'cycles' => json_encode([['type' => 'custom', 'expr' => 'bad']]), 'command' => 'echo'])->assertStatus(422);

        $this->getJson('/home/cron/'.$job->id)->assertOk()->assertJsonPath('job.cycles.1.cron', '0 14 * * *');
        $this->postJson('/home/cron/'.$job->id.'/toggle')->assertOk();
        $this->assertFalse($job->fresh()->is_active);
        $this->assertStringNotContainsString('/run '.$job->id.' ', app(CronManager::class)->render());
    }

    public function test_backup_jobs_compute_file_names_at_run_time(): void
    {
        $this->login();
        $site = Website::query()->create(['domain' => 'example.com', 'root_path' => '/www/wwwroot/example.com', 'php_version' => '8.4']);
        MysqlDatabase::query()->create(['engine' => 'mysql', 'name' => 'shop_db', 'username' => 'shop', 'password' => 'x', 'website_id' => $site->id]);
        $daily = json_encode([['type' => 'day', 'hour' => 3, 'minute' => 0]]);

        $this->postJson('/home/cron', ['type' => 'site_backup', 'name' => 'Sites', 'cycles' => $daily, 'website' => 'all', 'databases' => 1, 'keep' => 5, 'run_as' => 'deploy'])->assertOk();
        $this->postJson('/home/cron', ['type' => 'db_backup', 'name' => 'DBs', 'cycles' => $daily, 'engine' => 'all', 'database' => 'all', 'keep' => 7])->assertOk();

        $cron = app(CronManager::class);
        [$sites, $dbs] = CronJob::query()->orderBy('id')->get()->all();
        $this->assertSame('root', $sites->run_as, 'backup jobs always run as root');

        $script = $cron->script($sites);
        $this->assertStringNotContainsString(CronManager::STAMP, $script);
        $this->assertStringContainsString("STAMP=\$(date +%Y%m%d_%H%M%S)", $script);
        $this->assertStringContainsString("'/www/backup/site/example.com_'\"\$STAMP\"'.tar.gz'", $script);
        $this->assertStringContainsString('shop_db_\'"$STAMP"\'.sql.gz', $script);
        $this->assertStringContainsString('tail -n +6', $script);
        $this->assertStringContainsString('exit $FAILED', $script);

        $script = $cron->script($dbs);
        $this->assertStringContainsString('mysqldump', $script);
        $this->assertStringContainsString('tail -n +8', $script);
        $this->assertStringNotContainsString(CronManager::STAMP, $script);
    }

    public function test_flow_and_library_scripts(): void
    {
        $this->login();
        $check = CronScript::query()->where('name', 'Check a process')->firstOrFail();
        $restart = CronScript::query()->where('name', 'Keep a service running')->firstOrFail();

        $this->postJson('/home/cron', [
            'type' => 'flow', 'name' => 'Watch nginx', 'cycles' => json_encode([['type' => 'minute_n', 'n' => 5]]),
            'script_id' => $check->id, 'args' => 'nginx', 'condition' => 'not_contains', 'match' => 'running',
            'then_script_id' => $restart->id, 'then_args' => 'nginx',
        ])->assertOk();
        $flow = CronJob::query()->where('type', 'flow')->firstOrFail();
        $script = app(CronManager::class)->script($flow);
        $this->assertStringContainsString("bash -s -- 'nginx' 2>&1 <<'GBX_", $script);
        $this->assertStringContainsString("! printf \"%s\" \"\$OUT\" | grep -qF -- 'running'", $script);
        $this->assertStringContainsString($restart->content, $script);

        // a flow needs an action
        $this->postJson('/home/cron', ['type' => 'flow', 'name' => 'x', 'cycles' => json_encode([['type' => 'minute_n', 'n' => 5]]), 'script_id' => $check->id, 'condition' => 'always'])->assertStatus(422);

        // scripts in use cannot be deleted
        $this->deleteJson('/home/cron/scripts/'.$restart->id)->assertStatus(422);

        $this->postJson('/home/cron/scripts', ['name' => 'Hello', 'category' => 'custom', 'language' => 'bash', 'content' => 'echo "hi $1"'])->assertOk();
        $hello = CronScript::query()->where('name', 'Hello')->firstOrFail();
        $this->postJson('/home/cron/scripts', ['name' => 'Bad', 'category' => 'custom', 'language' => 'bash', 'content' => 'mkfs.ext4 /dev/sda'])->assertStatus(422);

        $this->postJson('/home/cron/scripts/'.$hello->id.'/run', ['args' => 'world'])->assertOk()->assertJsonStructure(['task' => ['id']]);
        $task = Task::query()->latest('id')->firstOrFail();
        $this->assertSame($hello->id, $task->meta['cron_script_id']);
        $this->assertNotNull($hello->fresh()->last_task_id);
        $this->getJson('/home/cron/scripts/'.$hello->id.'/log')->assertOk()->assertJsonPath('task.id', $task->id);

        $this->postJson('/home/cron/'.$flow->id.'/run')->assertOk();
        $this->assertNotNull($flow->fresh()->last_run_at);
    }

    public function test_export_import_and_bulk(): void
    {
        $this->login();
        $script = CronScript::query()->create(['name' => 'Custom check', 'category' => 'custom', 'language' => 'bash', 'content' => 'echo ok']);
        $daily = json_encode([['type' => 'day', 'hour' => 3, 'minute' => 0]]);
        $this->postJson('/home/cron', ['type' => 'script', 'name' => 'Run custom', 'cycles' => $daily, 'script_id' => $script->id])->assertOk();
        $this->postJson('/home/cron', ['type' => 'url', 'name' => 'Ping', 'cycles' => $daily, 'url' => 'https://example.com/cron', 'method' => 'GET'])->assertOk();

        $json = $this->get('/home/cron/export')->assertOk()->assertHeader('Content-Type', 'application/json')->getContent();
        $data = json_decode($json, true);
        $this->assertCount(2, $data['jobs']);
        $this->assertSame('Custom check', $data['scripts'][0]['name']);

        // import into an empty panel recreates the script and remaps the id
        CronJob::query()->delete();
        $script->delete();
        $this->post('/home/cron/import', ['file' => UploadedFile::fake()->createWithContent('cron.json', $json)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(2, CronJob::query()->count());
        $imported = CronJob::query()->where('type', 'script')->firstOrFail();
        $this->assertSame(CronScript::query()->where('name', 'Custom check')->value('id'), $imported->param('script_id'));

        $ids = CronJob::query()->pluck('id')->all();
        $this->postJson('/home/cron/bulk', ['ids' => $ids, 'action' => 'disable'])->assertOk();
        $this->assertSame(0, CronJob::query()->where('is_active', true)->count());
        $this->postJson('/home/cron/bulk', ['ids' => $ids, 'action' => 'delete'])->assertOk();
        $this->assertSame(0, CronJob::query()->count());
    }

    public function test_viewer_cannot_change_jobs(): void
    {
        $this->login('viewer');
        $this->get('/home/cron')->assertOk();
        $this->postJson('/home/cron', ['type' => 'shell', 'name' => 'x', 'schedule' => '* * * * *', 'command' => 'echo'])->assertStatus(403);
    }
}
