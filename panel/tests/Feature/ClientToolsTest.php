<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientPackage;
use App\Models\MysqlDatabase;
use App\Models\Task;
use App\Models\Website;
use App\Services\Clients\ClientFiles;
use App\Services\Clients\ClientManager;
use App\Services\Clients\PhpMyAdminSignon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ClientToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function client(string $username = 'joao'): Client
    {
        ClientManager::forgetPortalCache();
        $package = ClientPackage::query()->create(['name' => 'P'.$username, 'max_websites' => 5, 'max_databases' => 5, 'max_ftp' => 5, 'disk_mb' => 0, 'bandwidth_mb' => 0]);

        return Client::query()->create(['username' => $username, 'name' => $username, 'password' => 'Client12345', 'package_id' => $package->id, 'status' => 'active']);
    }

    public function test_file_paths_stay_inside_the_client_websites(): void
    {
        $joao = $this->client('joao');
        $maria = $this->client('maria');
        Website::query()->create(['domain' => 'joao.com', 'root_path' => '/www/wwwroot/joao.com', 'client_id' => $joao->id]);
        Website::query()->create(['domain' => 'maria.com', 'root_path' => '/www/wwwroot/maria.com', 'client_id' => $maria->id]);
        Website::query()->create(['domain' => 'odd.com', 'root_path' => '/etc', 'client_id' => $joao->id]);

        $files = ClientFiles::for($joao);
        $this->assertSame(['joao.com' => '/www/wwwroot/joao.com'], $files->roots(), 'roots outside the web folder are ignored');
        $this->assertSame('/www/wwwroot/joao.com/wp-content', $files->resolve('/www/wwwroot/joao.com/wp-content/'));

        foreach (['/www/wwwroot/maria.com', '/www/wwwroot/joao.com/../maria.com/index.php', '/etc/passwd', '/www/wwwroot', '/www/wwwroot/joao.company'] as $bad) {
            try {
                $files->resolve($bad);
                $this->fail('Accepted '.$bad);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertFalse(ClientFiles::validName('../x'));
        $this->assertFalse(ClientFiles::validName("a\nb"));
        $this->assertTrue(ClientFiles::validName('.htaccess'));

        $this->actingAs($joao, 'client');
        $this->get('/client/files')->assertOk()->assertSee('joao.com')->assertDontSee('maria.com');
        $this->getJson('/client/files/list?path=/www/wwwroot/joao.com')->assertOk()->assertJsonPath('root', '/www/wwwroot/joao.com');
        $this->getJson('/client/files/list?path=/www/wwwroot/maria.com')->assertStatus(422);
        $this->getJson('/client/files/read?path=/etc/shadow')->assertStatus(422);
        $this->get('/client/files/download?path=/www/wwwroot/maria.com/wp-config.php')->assertNotFound();
        $this->postJson('/client/files/delete', ['paths' => ['/www/wwwroot/joao.com']])->assertStatus(422)->assertJsonPath('message', 'The root folder of a website cannot be changed here.');
        $this->postJson('/client/files/create', ['dir' => '/www/wwwroot/joao.com', 'name' => '../evil', 'type' => 'file'])->assertStatus(422);
        $this->postJson('/client/files/paste', ['paths' => ['/www/wwwroot/joao.com/index.php'], 'destination' => '/www/wwwroot/maria.com', 'mode' => 'copy'])->assertStatus(422);
        $this->postJson('/client/files/extract', ['path' => '/www/wwwroot/joao.com/backup.zip', 'destination' => '/www/wwwroot/joao.com'])->assertOk();
        $this->postJson('/client/files/write', ['path' => '/www/wwwroot/joao.com/index.php', 'content' => '<?php echo 1;'])->assertOk();
        $this->post('/client/files/upload', ['dir' => '/www/wwwroot/joao.com', 'files' => [UploadedFile::fake()->create('photo.jpg', 20)]], ['Accept' => 'application/json'])->assertOk();

        $this->getJson('/client/files/list?path=/www/wwwroot/joao.com')->assertJsonPath('items.0.name', 'wp-content')->assertJsonPath('items.0.path', '/www/wwwroot/joao.com/wp-content');
    }

    public function test_code_editor_uses_the_client_routes(): void
    {
        $joao = $this->client('joao');
        $maria = $this->client('maria');
        Website::query()->create(['domain' => 'joao.com', 'root_path' => '/www/wwwroot/joao.com', 'client_id' => $joao->id]);
        Website::query()->create(['domain' => 'maria.com', 'root_path' => '/www/wwwroot/maria.com', 'client_id' => $maria->id]);
        $this->actingAs($joao, 'client');

        $page = $this->get('/client/files/editor?embed=1&root=/www/wwwroot/maria.com')->assertOk()->assertSee('vs/loader.js', false);
        $html = $page->getContent();
        $this->assertStringContainsString('client\/files\/open', $html);
        $this->assertStringContainsString('root:"\/www\/wwwroot\/joao.com"', str_replace(' ', '', $html), 'a root of another client falls back to the first website');
        $this->assertStringNotContainsString('maria.com', $html);

        $this->getJson('/client/files/open?path=/www/wwwroot/maria.com/index.php')->assertStatus(422);
        $this->getJson('/client/files/search?dir=/www/wwwroot/maria.com&query=DB_PASSWORD')->assertStatus(422);
        $this->getJson('/client/files/search?dir=/www/wwwroot/joao.com&query=hello')->assertOk()->assertJsonPath('dir', '/www/wwwroot/joao.com');

        $opened = $this->getJson('/client/files/open?path=/www/wwwroot/joao.com/index.php')->assertOk()->assertJsonPath('path', '/www/wwwroot/joao.com/index.php')->json();
        $saved = $this->postJson('/client/files/write', ['path' => '/www/wwwroot/joao.com/index.php', 'content' => '<?php echo 2;', 'encoding' => 'utf-8', 'mtime' => $opened['mtime']])->assertOk()->json();
        $this->postJson('/client/files/write', ['path' => '/www/wwwroot/joao.com/index.php', 'content' => 'old tab', 'encoding' => 'utf-8', 'mtime' => $opened['mtime'] - 10])->assertStatus(409);
        $this->postJson('/client/files/write', ['path' => '/www/wwwroot/maria.com/index.php', 'content' => 'x', 'encoding' => 'utf-8'])->assertStatus(422);
        $this->assertGreaterThan(0, $saved['mtime']);

        $this->post('/client/files/upload', ['dir' => '/www/wwwroot/joao.com', 'file' => UploadedFile::fake()->create('logo.png', 5)], ['Accept' => 'application/json'])->assertOk();
    }

    public function test_database_tools_are_scoped_and_imports_do_not_run_as_root(): void
    {
        $joao = $this->client('joao');
        $maria = $this->client('maria');
        $db = MysqlDatabase::query()->create(['name' => 'joao1_shop', 'username' => 'joao1_shop', 'password' => 'Secret12345', 'client_id' => $joao->id]);
        $other = MysqlDatabase::query()->create(['name' => 'maria2_shop', 'username' => 'maria2_shop', 'password' => 'x', 'client_id' => $maria->id]);
        $this->actingAs($joao, 'client');

        $this->getJson('/client/databases/'.$other->id.'/backups')->assertNotFound();
        $this->get('/client/databases/'.$other->id.'/export')->assertNotFound();
        $this->get('/client/databases/'.$other->id.'/phpmyadmin')->assertNotFound();

        $this->getJson('/client/databases/'.$db->id.'/backups')->assertOk()->assertJsonPath('keep', 5);
        $this->get('/client/databases/'.$db->id.'/export')->assertOk()->assertDownload();

        $this->postJson('/client/databases/'.$db->id.'/backup')->assertOk();
        $this->assertStringContainsString("tail -n +6 | xargs -r rm -f --", Task::query()->latest('id')->value('script'));

        $this->post('/client/databases/'.$db->id.'/import', ['file' => UploadedFile::fake()->createWithContent('dump.sql', 'DROP DATABASE mysql;')], ['Accept' => 'application/json'])->assertOk();
        $script = Task::query()->latest('id')->value('script');
        $this->assertStringContainsString("mysql --defaults-extra-file='/root/.gbx-secrets/", $script);
        $this->assertStringContainsString("'joao1_shop'", $script);
        $this->assertStringNotContainsString('Secret12345', $script, 'the password never appears in the script');
        $this->assertSame($joao->id, Task::query()->latest('id')->value('client_id'));

        $this->postJson('/client/databases/'.$db->id.'/restore', ['file' => 'maria2_shop_20260915_101010.sql.gz'])->assertNotFound();
        $this->get('/client/databases/'.$db->id.'/download?file=maria2_shop_20260915_101010.sql.gz')->assertNotFound();
        $this->postJson('/client/databases/'.$db->id.'/restore', ['file' => 'joao1_shop_20260915_101010.sql.gz'])->assertOk();

        config(['gbx.tools.token' => 'tools-token']);
        $this->get('/client/databases/'.$db->id.'/phpmyadmin')->assertRedirect('/phpmyadmin/index.php?server=2&db=joao1_shop')->assertCookie('gbx_tools', 'tools-token', false);
        $block = PhpMyAdminSignon::configBlock();
        $this->assertStringContainsString("['auth_type'] = 'signon'", $block);
        $this->assertStringContainsString("['AllowRoot'] = false", $block);
    }
}
