<?php

namespace Tests\Feature;

use App\Models\DatabaseRecycle;
use App\Models\DbServer;
use App\Models\MysqlDatabase;
use App\Models\Setting;
use App\Models\User;
use App\Services\Databases\Engines;
use App\Services\Databases\MongoEngine;
use App\Services\Databases\PostgresEngine;
use App\Services\Databases\RespClient;
use App\Services\Databases\SqlServerEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseEnginesTest extends TestCase
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
        foreach (array_keys(Engines::TABS) as $engine) {
            $this->get('/databases?engine='.$engine)->assertOk()->assertSee('db-tabs-nav', false);
        }
        $this->getJson('/databases/live?engine=pgsql')->assertOk()->assertJsonPath('locations.0.label', 'Localhost');
    }

    public function test_identifiers_and_quoting(): void
    {
        $this->assertTrue(PostgresEngine::validIdentifier('app_db'));
        $this->assertFalse(PostgresEngine::validIdentifier('App'));
        $this->assertFalse(PostgresEngine::validIdentifier('1abc'));
        $this->assertSame('"we""ird"', PostgresEngine::ident('we"ird'));
        $this->assertSame("'it''s'", PostgresEngine::sqlString("it's"));
        $this->assertSame('[a]]b]', SqlServerEngine::ident('a]b'));
        $this->assertSame("N'o''k'", SqlServerEngine::nstring("o'k"));
    }

    public function test_credentials_never_appear_in_generated_scripts(): void
    {
        $sql = new DbServer(['engine' => 'sqlserver', 'name' => 'Azure', 'host' => 'sql.example.net', 'port' => 1433, 'username' => 'sa', 'password' => 'Top$ecret#1']);
        $script = app(SqlServerEngine::class)->queryScript(app(SqlServerEngine::class)->backupSql('erp', 'erp_20260101_000000.bak'), $sql);
        $this->assertStringNotContainsString('Top$ecret#1', $script);
        $this->assertStringContainsString('SQLCMDPASSWORD="$(cat', $script);
        $this->assertStringContainsString('trap', $script);

        $mongo = new DbServer(['engine' => 'mongodb', 'host' => 'mongo.example.net', 'port' => 27017, 'username' => 'root', 'password' => 'Mongo$Pass']);
        $dump = app(MongoEngine::class)->dumpScript('catalog', '/www/backup/database/mongodb/catalog.archive.gz', $mongo);
        $this->assertStringNotContainsString('Mongo$Pass', $dump);
        $this->assertStringContainsString('mongodump --config=', $dump);

        $pg = new DbServer(['engine' => 'pgsql', 'host' => 'pg.example.net', 'port' => 5432, 'username' => 'postgres', 'password' => 'Pg$Pass']);
        $this->assertStringNotContainsString('Pg$Pass', app(PostgresEngine::class)->dumpScript('app', '/tmp/app.sql.gz', $pg));
    }

    public function test_create_databases_on_local_and_remote_servers(): void
    {
        $this->login();
        $server = DbServer::query()->create(['engine' => 'pgsql', 'name' => 'Reports', 'host' => '10.0.0.5', 'port' => 5432, 'username' => 'postgres', 'password' => 'x']);

        $this->postJson('/databases', ['engine' => 'pgsql', 'name' => 'App', 'username' => 'app', 'password' => 'Secret12345'])->assertStatus(422);
        $this->postJson('/databases', ['engine' => 'pgsql', 'name' => 'app', 'username' => 'app', 'password' => 'Secret12345'])->assertOk();
        // the same name may exist on another server
        $this->postJson('/databases', ['engine' => 'pgsql', 'server_id' => $server->id, 'name' => 'app', 'username' => 'app', 'password' => 'Secret12345'])->assertOk();
        $this->postJson('/databases', ['engine' => 'pgsql', 'name' => 'app', 'username' => 'app2', 'password' => 'Secret12345'])->assertStatus(422);
        $this->assertSame(2, MysqlDatabase::query()->engine('pgsql')->count());

        $this->postJson('/databases', ['engine' => 'sqlserver', 'name' => 'erp', 'username' => 'erp', 'password' => 'Secret12345'])->assertStatus(422);
        $this->postJson('/databases', ['engine' => 'mysql', 'name' => 'shop', 'username' => 'shop', 'password' => 'Secret12345', 'hosts' => "localhost\nbad host!"])->assertStatus(422);
        $this->postJson('/databases', ['engine' => 'mysql', 'name' => 'shop', 'username' => 'shop', 'password' => 'Secret12345', 'hosts' => '%'])->assertOk();
        $this->assertSame('%', MysqlDatabase::query()->engine('mysql')->first()->host);

        // removing a server forgets its records (the remote databases are not dropped)
        $this->deleteJson('/databases/servers/'.$server->id)->assertOk();
        $this->assertSame(1, MysqlDatabase::query()->engine('pgsql')->count());
    }

    public function test_recycle_bin_round_trip(): void
    {
        $this->login();
        $db = MysqlDatabase::query()->create(['engine' => 'mysql', 'name' => 'shop', 'username' => 'shop', 'password' => 'Secret12345', 'host' => 'localhost']);

        $this->deleteJson('/databases/'.$db->id, ['recycle' => 1])->assertOk()->assertJsonStructure(['task']);
        $this->assertDatabaseMissing('databases', ['id' => $db->id]);
        $item = DatabaseRecycle::query()->firstOrFail();
        $this->assertSame('shop', $item->name);
        $this->assertSame('Secret12345', $item->password);
        $this->assertStringContainsString('/recycle/mysql_shop_', $item->file);

        $this->postJson('/databases/recycle/'.$item->id.'/restore')->assertOk();
        $this->assertDatabaseHas('databases', ['engine' => 'mysql', 'name' => 'shop']);
        $this->assertDatabaseCount('database_recycle', 0);

        DatabaseRecycle::query()->create(['engine' => 'mysql', 'name' => 'old', 'file' => '/www/backup/database/recycle/mysql_old.sql.gz'])->forceFill(['created_at' => now()->subDays(8)])->save();
        $this->artisan('gbx:backup-databases --purge-recycle')->assertSuccessful();
        $this->assertDatabaseCount('database_recycle', 0);
    }

    public function test_permission_tools_backups_and_settings(): void
    {
        $this->login();
        $db = MysqlDatabase::query()->create(['engine' => 'mysql', 'name' => 'shop', 'username' => 'shop', 'password' => 'Secret12345', 'host' => 'localhost']);

        $this->postJson("/databases/{$db->id}/permission", ['access' => 'ips', 'ips' => "203.0.113.4\n10.0.0.%"])->assertOk();
        $this->assertSame('203.0.113.4,10.0.0.%', $db->refresh()->host);
        $this->postJson("/databases/{$db->id}/permission", ['access' => 'all'])->assertOk();
        $this->assertSame(['%'], $db->refresh()->hosts());

        $this->getJson("/databases/{$db->id}/tables")->assertOk()->assertJsonStructure(['tables' => [['name', 'engine', 'rows', 'size']]]);
        $this->postJson("/databases/{$db->id}/tables", ['action' => 'drop'])->assertStatus(422);
        $this->postJson("/databases/{$db->id}/backup")->assertOk()->assertJsonStructure(['task']);
        $this->postJson("/databases/{$db->id}/restore", ['file' => '../../etc/passwd'])->assertStatus(422);
        $this->getJson('/databases/backups/mysql/shadow')->assertStatus(422);

        $this->postJson('/databases/auto-backup', ['enabled' => 1, 'time' => '03:15', 'keep' => 5])->assertOk();
        $this->assertSame('03:15', Setting::get('db_backup_time'));
        $this->artisan('gbx:backup-databases --scheduled')->assertSuccessful();

        $this->postJson('/databases/root-password', ['engine' => 'mysql', 'password' => 'short'])->assertStatus(422);
        $this->postJson('/databases/bulk', ['ids' => [$db->id], 'action' => 'backup'])->assertOk();
    }

    public function test_admin_tools_cookie_gate(): void
    {
        $this->login();
        config(['gbx.tools.token' => 'abc123token']);

        $this->get('/databases/open/phpmyadmin?db=shop')
            ->assertRedirect('/phpmyadmin/?db=shop')
            ->assertPlainCookie('gbx_tools', 'abc123token');
        $this->get('/databases/open/adminer?engine=pgsql&db=app&user=app')->assertRedirect('/adminer/?pgsql=127.0.0.1&username=app&db=app');
        $this->get('/databases/open/evil')->assertNotFound();
        $this->postJson('/databases/tools-access', ['public' => 0])->assertOk()->assertJsonStructure(['task']);
    }

    public function test_redis_keys_and_resp_protocol(): void
    {
        $this->login();
        $this->assertSame("*2\r\n\$3\r\nGET\r\n\$3\r\nkey\r\n", RespClient::encode(['GET', 'key']));
        $info = RespClient::parseInfo("# Server\r\nredis_version:7.2.5\r\n# Keyspace\r\ndb0:keys=3,expires=1,avg_ttl=0\r\n");
        $this->assertSame('7.2.5', $info['server']['redis_version']);
        $this->assertSame('keys=3,expires=1,avg_ttl=0', $info['keyspace']['db0']);

        $this->postJson('/databases/redis/key', ['db' => 2, 'key' => 'user:1', 'type' => 'hash', 'value' => "name=Ana\nplan=pro", 'ttl' => 60])->assertOk();
        $this->getJson('/databases/redis/key?db=2&key=user:1')->assertOk()->assertJsonPath('item.value.plan', 'pro');
        $this->postJson('/databases/redis/key', ['db' => 2, 'key' => 'bad', 'type' => 'zset', 'value' => 'not-a-score'])->assertStatus(422);
        $this->getJson('/databases/redis/keys?db=2&pattern=user:*')->assertOk()->assertJsonCount(1, 'keys');
        $this->postJson('/databases/redis/delete', ['db' => 2, 'keys' => ['user:1']])->assertOk()->assertJsonPath('message', '1 key(s) deleted');
        $this->postJson('/databases/redis/config', ['maxmemory' => '512mb; FLUSHALL'])->assertStatus(422);
        $this->getJson('/databases/redis/overview')->assertOk()->assertJsonPath('overview.connected', true);
    }

    public function test_qdrant_collections(): void
    {
        $this->login();
        $this->postJson('/databases/qdrant/collections', ['name' => 'faq', 'size' => 384, 'distance' => 'Cosine'])->assertOk();
        $this->postJson('/databases/qdrant/collections', ['name' => 'bad name', 'size' => 384, 'distance' => 'Cosine'])->assertStatus(422);
        $this->postJson('/databases/qdrant/collections', ['name' => 'x', 'size' => 384, 'distance' => 'Hamming'])->assertStatus(422);
        $names = collect($this->getJson('/databases/qdrant/overview')->assertOk()->json('collections'))->pluck('name');
        $this->assertContains('faq', $names);
        $this->getJson('/databases/qdrant/collections/faq/points')->assertOk()->assertJsonStructure(['points']);
        $this->postJson('/databases/qdrant/collections/faq/snapshots')->assertOk();
        $this->deleteJson('/databases/qdrant/collections/faq')->assertOk();

        $this->postJson('/databases/qdrant/settings', ['action' => 'regenerate'])->assertOk();
        $this->assertSame(48, strlen((string) Setting::secret('qdrant_api_key')));
    }

    public function test_read_only_users_cannot_change_databases(): void
    {
        $this->login('viewer');
        $this->get('/databases?engine=redis')->assertOk();
        $this->postJson('/databases', ['engine' => 'mysql', 'name' => 'x', 'username' => 'x', 'password' => 'Secret12345'])->assertForbidden();
        $this->postJson('/databases/redis/flush', ['db' => 0])->assertForbidden();
        $this->postJson('/databases/qdrant/collections', ['name' => 'x', 'size' => 3, 'distance' => 'Dot'])->assertForbidden();
        $this->postJson('/databases/servers', ['engine' => 'mysql', 'host' => 'x', 'port' => 3306])->assertForbidden();
    }
}
