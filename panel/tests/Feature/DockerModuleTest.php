<?php

namespace Tests\Feature;

use App\Models\DockerNote;
use App\Models\DockerRegistry;
use App\Models\DockerTemplate;
use App\Models\Task;
use App\Models\User;
use App\Services\Docker\AppStore;
use App\Services\Docker\DaemonConfig;
use App\Services\DockerManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DockerModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function login(string $role = 'admin'): User
    {
        $user = User::query()->create(['name' => ucfirst($role), 'username' => $role, 'password' => 'Secret12345', 'role' => $role, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_every_tab_renders(): void
    {
        $this->login();
        foreach (['overview', 'containers', 'apps', 'hub', 'images', 'compose', 'networks', 'volumes', 'registries', 'settings'] as $tab) {
            $this->get('/docker?tab='.$tab)->assertOk()->assertSee('db-tabs-nav', false);
        }
    }

    public function test_lists_are_normalized(): void
    {
        $this->login();
        $this->getJson('/docker/overview')->assertOk()->assertJsonPath('counts.containers', 6)->assertJsonPath('counts.compose', 1)->assertJsonPath('disk.Images.size', '4.839GB');

        $containers = $this->getJson('/docker/containers')->assertOk()->json('data');
        $api = collect($containers)->firstWhere('name', 'evolution_api');
        $this->assertSame('172.19.0.2', $api['ip']);
        $this->assertSame('evolution-api', $api['project']);
        $this->assertSame([['host_ip' => '0.0.0.0', 'host_port' => 8080, 'port' => 8080, 'proto' => 'tcp']], $api['ports'], 'IPv4 and IPv6 bindings are merged');
        $this->assertArrayNotHasKey('log_path', $api);
        $postgres = collect($containers)->firstWhere('name', 'evolution_postgres');
        $this->assertNull($postgres['ports'][0]['host_port']);

        $images = $this->getJson('/docker/images')->assertOk()->json('data');
        $this->assertSame(['gowa'], collect($images)->firstWhere('name', 'ghcr.io/aldinokemal/go-whatsapp-web-multidevice:latest')['containers']);
        $this->assertSame('<none>', collect($images)->firstWhere('tags', [])['name']);

        $networks = $this->getJson('/docker/networks')->assertOk()->json('data');
        $this->assertTrue(collect($networks)->firstWhere('name', 'host')['builtin']);
        $volumes = $this->getJson('/docker/volumes')->assertOk()->json('data');
        $this->assertSame(['qdrant'], collect($volumes)->firstWhere('name', 'qdrant_storage')['containers']);

        $this->getJson('/docker/stats')->assertOk()->assertJsonPath('stats.9cc7d7934236.cpu', 0.8);
    }

    public function test_viewer_does_not_see_secrets_or_change_anything(): void
    {
        $this->login('viewer');
        $env = $this->getJson('/docker/containers/detail/gowa')->assertOk()->json('container.env');
        $this->assertContains('APP_SECRET=********', $env);
        $this->getJson('/docker/compose/evolution-api')->assertOk();
        $this->postJson('/docker/containers/action', ['id' => 'gowa', 'action' => 'stop'])->assertStatus(403);
        $this->getJson('/docker/containers/inspect?id=gowa')->assertStatus(403);
    }

    public function test_run_script_escapes_everything(): void
    {
        $docker = app(DockerManager::class);
        $script = $docker->runScript([
            'image' => 'nginx:alpine', 'name' => 'web', 'ports' => "127.0.0.1:8080:80\n443", 'env' => "A=1 ; rm -rf /\nB=\$(id)",
            'volumes' => 'data:/data', 'labels' => 'team=web', 'network' => 'app_net', 'cpus' => '1.5', 'memory' => '512m',
            'command' => 'npm run "start prod"; reboot', 'pull' => false,
        ]);
        $this->assertStringNotContainsString('docker pull', $script);
        $this->assertStringContainsString("-e 'A=1 ; rm -rf /' -e 'B=\$(id)'", $script);
        $this->assertStringContainsString("--network 'app_net'", $script);
        $this->assertStringContainsString("'nginx:alpine' 'npm' 'run' 'start prod' ';' 'reboot'", $script);
        $this->assertStringContainsString('--cpus 1.5 ', $script);

        foreach ([['ports' => '8080:80; id'], ['name' => 'bad name'], ['memory' => '1tb'], ['env' => '1BAD=x']] as $bad) {
            try {
                $docker->runScript(['image' => 'nginx'] + $bad);
                $this->fail('Accepted '.json_encode($bad));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_network_and_volume_commands(): void
    {
        $docker = app(DockerManager::class);
        $cmd = $docker->createNetworkCommand(['name' => 'app_net', 'driver' => 'bridge', 'subnet' => '172.30.0.0/16', 'gateway' => '172.30.0.1', 'ipv6' => true, 'subnet6' => 'fd00:30::/64', 'internal' => true, 'labels' => 'team=web']);
        $this->assertSame("docker network create --driver bridge --subnet '172.30.0.0/16' --gateway '172.30.0.1' --ipv6 --subnet 'fd00:30::/64' --internal --label 'team=web' 'app_net'", $cmd);

        $this->expectException(\InvalidArgumentException::class);
        $docker->createNetworkCommand(['name' => 'x', 'driver' => 'bridge', 'subnet' => '300.1.1.0/16']);
    }

    public function test_volume_command_and_removals(): void
    {
        $this->login();
        $cmd = app(DockerManager::class)->createVolumeCommand(['name' => 'nfs_data', 'driver' => 'local', 'options' => "type=nfs\no=addr=10.0.0.5,rw", 'labels' => '']);
        $this->assertSame("docker volume create --driver 'local' --opt 'type=nfs' --opt 'o=addr=10.0.0.5,rw' 'nfs_data'", $cmd);

        $this->postJson('/docker/volumes', ['name' => 'bad name'])->assertStatus(422);
        $this->postJson('/docker/networks', ['name' => 'app_net', 'driver' => 'macvlan'])->assertStatus(422)->assertJsonPath('message', 'Enter the parent network interface, e.g. eth0');
        $this->postJson('/docker/networks/remove', ['names' => ['bridge']])->assertStatus(422);
    }

    public function test_registry_credentials_are_encrypted_and_never_on_the_command_line(): void
    {
        $this->login();
        $this->postJson('/docker/registries', ['name' => 'GHCR', 'url' => 'https://ghcr.io/', 'username' => 'octo', 'password' => 'ghp_secret_token', 'namespace' => 'my-org'])->assertOk();
        $registry = DockerRegistry::query()->firstOrFail();
        $this->assertNotSame('ghp_secret_token', DB::table('docker_registries')->value('password'));
        $this->assertSame('ghp_secret_token', $registry->password);
        $this->assertSame('ghcr.io/my-org/app:1.0', $registry->reference('app:1.0'));
        $this->assertStringNotContainsString('ghp_secret', json_encode($this->getJson('/docker/registries')->json()));

        // blank password on update keeps the stored one
        $this->putJson('/docker/registries/'.$registry->id, ['name' => 'GHCR', 'url' => 'ghcr.io', 'username' => 'octo', 'password' => ''])->assertOk();
        $this->assertSame('ghp_secret_token', $registry->fresh()->password);

        $this->postJson('/docker/images/pull', ['image' => 'app:1.0', 'registry_id' => $registry->id])->assertOk();
        $task = Task::query()->latest('id')->firstOrFail();
        $this->assertStringContainsString("docker pull 'ghcr.io/my-org/app:1.0'", $task->script);
        $this->assertStringContainsString('--password-stdin < "$GBX_SECRET"', $task->script);
        $this->assertStringNotContainsString('ghp_secret_token', $task->script);

        $this->postJson('/docker/images/push', ['image' => 'app:1.0', 'target' => 'app:1.0', 'registry_id' => $registry->id])->assertOk();
        $this->assertStringContainsString("docker push 'ghcr.io/my-org/app:1.0'", Task::query()->latest('id')->value('script'));
    }

    public function test_app_install_builds_env_and_rejects_bad_values(): void
    {
        $this->login();
        $store = app(AppStore::class);
        $this->assertGreaterThan(30, count($store->apps()));
        foreach ($store->apps() as $slug => $app) {
            $this->assertMatchesRegularExpression('/^services:/m', $app['compose'], $slug);
            $this->assertStringContainsString('${', $app['compose'], $slug);
            foreach (array_keys($app['fields']) as $key) {
                $this->assertStringContainsString('${'.$key.'}', $app['compose'], "{$slug} uses {$key}");
            }
        }

        $prepared = $store->prepare($store->app('redis'), ['APP_PORT' => '6390', 'REDIS_PASSWORD' => 'p@ss word!'], false);
        $this->assertStringContainsString("BIND_IP=127.0.0.1\n", $prepared['env']);
        $this->assertStringContainsString("REDIS_PASSWORD='p@ss word!'\n", $prepared['env']);
        $generated = $store->prepare($store->app('redis'), ['APP_PORT' => '6391'], true);
        $this->assertMatchesRegularExpression('/REDIS_PASSWORD=[A-Za-z0-9]{24}\n/', $generated['env']);
        $this->assertStringContainsString('BIND_IP=0.0.0.0', $generated['env']);

        $this->postJson('/docker/apps/redis/install', ['project' => 'cache', 'fields' => ['APP_PORT' => '8080']])->assertStatus(422)->assertJsonValidationErrors('APP_PORT');
        $this->postJson('/docker/apps/redis/install', ['project' => 'cache', 'fields' => ['REDIS_PASSWORD' => "a'b\ncdefgh"]])->assertStatus(422);
        $this->postJson('/docker/apps/redis/install', ['project' => 'Bad Name'])->assertStatus(422);
        $this->postJson('/docker/apps/redis/install', ['project' => 'cache', 'fields' => ['APP_PORT' => '6399']])->assertOk()->assertJsonStructure(['task' => ['id']]);
        $this->assertStringContainsString("docker compose -p 'cache'", Task::query()->latest('id')->value('script'));
    }

    public function test_compose_projects_and_templates(): void
    {
        $this->login();
        $this->getJson('/docker/compose')->assertOk()->assertJsonPath('data.0.name', 'evolution-api')->assertJsonPath('data.0.app_name', 'Evolution API');
        $this->getJson('/docker/compose/evolution-api')->assertOk()->assertJsonCount(3, 'containers');
        $this->getJson('/docker/compose/missing')->assertStatus(404);

        $this->postJson('/docker/compose', ['name' => 'web', 'content' => "version: '3'"])->assertStatus(422);
        $this->postJson('/docker/compose', ['name' => 'evolution-api', 'content' => "services:\n  a:\n    image: nginx"])->assertStatus(422);
        $this->postJson('/docker/compose', ['name' => 'web', 'content' => "services:\n  web:\n    image: nginx", 'note' => 'Landing page'])->assertOk()->assertJsonStructure(['task']);
        $this->assertSame('Landing page', DockerNote::map('project')['web']);

        $this->postJson('/docker/compose/evolution-api/action', ['action' => 'update'])->assertOk();
        $script = Task::query()->latest('id')->value('script');
        $this->assertStringContainsString("docker compose -p 'evolution-api' -f '/www/docker/evolution-api/compose.yaml' pull", $script);

        $this->postJson('/docker/compose/evolution-api/file', ['which' => 'env', 'content' => "A=1\n", 'apply' => 1])->assertOk()->assertJsonStructure(['task']);

        $this->postJson('/docker/compose/remove', ['names' => ['evolution-api'], 'files' => 1, 'volumes' => 1])->assertOk();
        $script = Task::query()->latest('id')->value('script');
        $this->assertStringContainsString('down --remove-orphans -v', $script);
        $this->assertStringContainsString("rm -rf '/www/docker/evolution-api'", $script);

        $this->postJson('/docker/compose/templates', ['name' => 'Nginx', 'content' => "services:\n  web:\n    image: nginx"])->assertOk();
        $this->postJson('/docker/compose/templates', ['name' => 'Bad', 'content' => 'nope'])->assertStatus(422);
        $this->assertSame(1, DockerTemplate::query()->count());
    }

    public function test_daemon_config_merge_keeps_unknown_keys(): void
    {
        $merged = DaemonConfig::merge(
            ['data-root' => '/data/docker', 'log-opts' => ['max-size' => '10m', 'compress' => 'true'], 'registry-mirrors' => ['https://old.example']],
            ['registry_mirrors' => "https://mirror.gcr.io\n", 'insecure_registries' => '', 'log_driver' => 'json-file', 'log_max_size' => '50M', 'log_max_file' => '5', 'live_restore' => true, 'ipv6' => false, 'iptables' => true]
        );
        $this->assertSame([
            'data-root' => '/data/docker',
            'log-opts' => ['compress' => 'true', 'max-size' => '50m', 'max-file' => '5'],
            'registry-mirrors' => ['https://mirror.gcr.io'],
            'log-driver' => 'json-file',
            'live-restore' => true,
        ], $merged);

        $this->expectException(\InvalidArgumentException::class);
        DaemonConfig::merge([], ['registry_mirrors' => 'ftp://x']);
    }

    public function test_docker_hub_search_and_tags(): void
    {
        $this->login();
        Http::fake([
            'hub.docker.com/v2/search/*' => Http::response(['count' => 1, 'results' => [['repo_name' => 'nginx', 'short_description' => 'Official build', 'star_count' => 10, 'pull_count' => 99, 'is_official' => true]]]),
            'hub.docker.com/v2/repositories/library/nginx/tags*' => Http::response(['results' => [['name' => 'alpine'], ['name' => 'latest']]]),
        ]);
        $this->getJson('/docker/hub/search?q=nginx')->assertOk()->assertJsonPath('results.0.official', true)->assertJsonPath('total', 1);
        $this->getJson('/docker/hub/tags?repo=nginx')->assertOk()->assertJsonPath('tags', ['alpine', 'latest']);
        $this->getJson('/docker/hub/tags?repo=../../etc')->assertStatus(422);
    }
}
