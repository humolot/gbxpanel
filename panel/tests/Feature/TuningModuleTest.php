<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MysqlManager;
use App\Services\Tuning\ApacheTuner;
use App\Services\Tuning\MysqlTuner;
use App\Services\Tuning\PhpTuner;
use App\Services\Tuning\Tuner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TuningModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function login(string $role = 'admin'): User
    {
        $user = User::query()->create(['name' => ucfirst($role), 'username' => $role, 'password' => 'Secret12345', 'role' => $role, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_values_are_proposed_from_the_memory_of_the_server(): void
    {
        $php = new PhpTuner('8.3');
        $small = $php->suggest(2048);
        $large = $php->suggest(16384);

        // the measured size of a worker (38 MB in simulation) decides how many fit
        $this->assertSame((string) (int) round(2048 * 0.5 / 38), $small['pm.max_children']);
        $this->assertGreaterThan((int) $small['pm.max_children'], (int) $large['pm.max_children']);
        $this->assertSame('ondemand', $small['pm'], 'a small server keeps workers only while they are used');
        $this->assertSame('dynamic', $large['pm']);
        $this->assertGreaterThanOrEqual((int) $small['pm.min_spare_servers'], (int) $small['pm.max_spare_servers']);

        $apache = (new ApacheTuner)->suggest(4096);
        $this->assertSame(0, (int) $apache['MaxRequestWorkers'] % (int) $apache['ThreadsPerChild'], 'connections are a multiple of the threads per process');
        $this->assertGreaterThanOrEqual((int) $apache['MaxRequestWorkers'] / (int) $apache['ThreadsPerChild'], (int) $apache['ServerLimit']);

        $mysql = (new MysqlTuner(app(MysqlManager::class)))->suggest(8192);
        $this->assertSame('2867M', $mysql['innodb_buffer_pool_size'], 'about a third of the memory, the rest is for PHP and the system');
        $this->assertSame($mysql['tmp_table_size'], $mysql['max_heap_table_size']);
        $this->assertSame('1', $mysql['skip_name_resolve']);

        $this->assertSame(2048, Tuner::planRamMb('1-2'));
        $this->assertSame(Tuner::totalRamMb(), Tuner::planRamMb('auto'));
        $this->assertSame(1536, Tuner::toMb('1.5G'));
        $this->assertSame('2G', Tuner::mb(2048));
    }

    public function test_wrong_values_are_refused_before_anything_is_written(): void
    {
        $php = new PhpTuner('8.3');

        $this->assertThrows(fn () => $php->apply(['pm.max_children' => 'many']), \InvalidArgumentException::class);
        $this->assertThrows(fn () => $php->apply(['pm' => 'turbo']), \InvalidArgumentException::class);
        $this->assertThrows(fn () => $php->apply(['pm.max_children' => '99999']), \InvalidArgumentException::class);
        $this->assertThrows(fn () => $php->apply([]), \InvalidArgumentException::class);

        // more idle workers than workers would keep PHP-FPM from starting
        $this->assertTrue($php->apply(['pm.max_children' => '10', 'pm.max_spare_servers' => '40'])->failed());

        $mysql = new MysqlTuner(app(MysqlManager::class));
        $this->assertThrows(fn () => $mysql->apply(['innodb_buffer_pool_size' => '512 megs']), \InvalidArgumentException::class);
        $this->assertThrows(fn () => $mysql->apply(['innodb_flush_log_at_trx_commit' => '7']), \InvalidArgumentException::class);
    }

    public function test_the_generated_configuration_is_what_the_service_expects(): void
    {
        $mysql = new MysqlTuner(app(MysqlManager::class));
        $written = $mysql->apply(['innodb_buffer_pool_size' => '2G', 'slow_query_log' => '1', 'tmp_table_size' => '64M', 'max_heap_table_size' => '16M'])->output;

        $this->assertStringContainsString('[mysqld]', $written);
        $this->assertStringContainsString('innodb_buffer_pool_size = 2G', $written);
        $this->assertStringContainsString('slow_query_log = ON', $written);
        $this->assertStringContainsString("max_heap_table_size = 64M", $written, 'the memory table limit follows the temporary table size');
        $this->assertStringContainsString('Written by GBX Panel', $written);
        $this->assertSame('/etc/mysql/conf.d/gbx-tuning.cnf', $mysql->file(), 'the files of the distribution are never edited');

        $php = new PhpTuner('8.3');
        $script = $php->apply(['pm.max_children' => '30', 'opcache.memory_consumption' => '256', 'opcache.jit_buffer_size' => '64'])->output;
        $this->assertStringContainsString('/etc/php/8.3/fpm/pool.d/www.conf', $script);
        $this->assertStringContainsString('pm.max_children = 30', $script);
        $this->assertStringContainsString('opcache.memory_consumption = 256M', $script);
        $this->assertStringContainsString('opcache.jit = tracing', $script, 'the JIT only works when the mode is set as well');

        $apache = (new ApacheTuner)->apply(['MaxRequestWorkers' => '500', 'ThreadsPerChild' => '25', 'ServerLimit' => '2'])->output;
        $this->assertStringContainsString('mpm_event.conf', $apache);
        $this->assertStringContainsString('ServerLimit 20', $apache, 'the number of processes is raised to serve the requested connections');
    }

    public function test_the_page_shows_settings_status_and_advice(): void
    {
        $this->login();

        $response = $this->getJson('/tuning/php8.3')->assertOk();
        $response->assertJsonPath('label', 'PHP 8.3');
        $this->assertSame('20', $response->json('groups')['Workers']['pm.max_children']['value']);
        $this->assertSame(38, $response->json('process_mb'));
        $this->assertNotEmpty($response->json('suggestions.4-8'));
        $this->assertSame(['exec', 'system', 'passthru', 'shell_exec', 'proc_open', 'popen'], $response->json('functions.current'));

        $mysql = $this->getJson('/tuning/mysql')->assertOk();
        $this->assertTrue($mysql->json('restartable'));
        $this->assertStringContainsString('buffer pool', strtolower((string) $mysql->json('status.advice.0.text')));
        $this->assertSame('Buffer pool hit rate', $mysql->json('status.rows.5.label'));

        $this->getJson('/tuning/apache')->assertOk()->assertJsonPath('groups.Connections.KeepAlive.value', 'On');
        $this->getJson('/tuning/php9.9')->assertNotFound();
        $this->getJson('/tuning/redis')->assertNotFound();

        $this->postJson('/tuning/php8.3', ['values' => ['pm.max_children' => '40']])->assertOk();
        $this->postJson('/tuning/php8.3', ['values' => ['pm.max_children' => 'lots']])->assertStatus(422);
        $this->postJson('/tuning/mysql/functions', ['functions' => ['exec']])->assertStatus(422);
        $this->postJson('/tuning/php8.3/functions', ['functions' => ['exec', 'system', 'not a function']])->assertStatus(422);
        $this->postJson('/tuning/php8.3/functions', ['functions' => ['exec', 'system']])->assertOk();
        $this->postJson('/tuning/apache/restart')->assertStatus(422);
    }

    public function test_the_panel_never_blocks_the_functions_it_needs_itself(): void
    {
        $php = new PhpTuner('8.3');
        $result = $php->saveDisabledFunctions(['exec', 'putenv', 'system', 'escapeshellarg']);

        $this->assertStringContainsString('disable_functions = exec,system', $result->output);
        $this->assertStringNotContainsString('putenv', $result->output, 'the panel runs on this PHP version and needs these');
        $this->assertStringNotContainsString('escapeshellarg', $result->output);
    }

    public function test_only_administrators_change_performance_settings(): void
    {
        $this->login('user');
        $this->getJson('/tuning/php8.3')->assertForbidden();
        $this->postJson('/tuning/mysql', ['values' => ['max_connections' => '500']])->assertForbidden();
    }
}
