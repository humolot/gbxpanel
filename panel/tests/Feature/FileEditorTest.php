<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FileManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FileEditorTest extends TestCase
{
    use RefreshDatabase;

    protected const FILE = '/www/wwwroot/example.com/index.php';

    protected function user(string $role = 'admin'): User
    {
        $user = User::query()->create(['name' => ucfirst($role), 'username' => $role, 'password' => 'Secret12345', 'role' => $role, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_editor_page_renders_embedded_and_standalone(): void
    {
        $this->user();

        $this->get('/files/editor?embed=1&root=/www/wwwroot/example.com&open='.urlencode(self::FILE))
            ->assertOk()
            ->assertSee('assets/vendor/monaco/vs/loader.js', false)
            ->assertSee('ed-embed', false)
            ->assertDontSee('Back to Files');

        $this->get('/files/editor')->assertOk()->assertSee('Back to Files');
    }

    public function test_open_returns_content_and_metadata(): void
    {
        $this->user();

        $this->getJson('/files/open?path='.urlencode(self::FILE))
            ->assertOk()
            ->assertJsonPath('path', self::FILE)
            ->assertJsonPath('encoding', 'utf-8')
            ->assertJsonPath('eol', 'LF')
            ->assertJsonStructure(['content', 'mtime', 'size', 'perms', 'owner']);
    }

    public function test_save_detects_changes_made_on_the_server(): void
    {
        $this->user();
        $opened = $this->getJson('/files/open?path='.urlencode(self::FILE))->json();

        $first = $this->postJson('/files/write', ['path' => self::FILE, 'content' => "<?php echo 1;\n", 'encoding' => 'utf-8', 'mtime' => $opened['mtime']])
            ->assertOk()
            ->json();
        $this->assertGreaterThan($opened['mtime'], $first['mtime']);

        // a second editor still holding the original mtime must not overwrite silently
        $this->postJson('/files/write', ['path' => self::FILE, 'content' => "<?php echo 2;\n", 'mtime' => $opened['mtime']])
            ->assertStatus(409)
            ->assertJsonPath('conflict', true);

        $this->postJson('/files/write', ['path' => self::FILE, 'content' => "<?php echo 2;\n", 'mtime' => $opened['mtime'], 'force' => 1])->assertOk();
        $this->assertSame("<?php echo 2;\n", $this->getJson('/files/open?path='.urlencode(self::FILE))->json('content'));
        $this->assertDatabaseHas('activity_logs', ['category' => 'files', 'action' => 'Edited file', 'details' => self::FILE]);
    }

    public function test_encodings_and_bom_round_trip(): void
    {
        $files = app(FileManager::class);
        $path = '/www/wwwroot/example.com/legacy.txt';

        $files->save($path, 'Olá, ação', 'windows-1252');
        $opened = $files->open($path);
        $this->assertSame('windows-1252', $opened['encoding']);
        $this->assertSame('Olá, ação', $opened['content']);

        $files->save($path, 'con BOM', 'utf-8-bom');
        $opened = $files->open($path);
        $this->assertSame('utf-8-bom', $opened['encoding']);
        $this->assertSame('con BOM', $opened['content']);

        $this->expectException(\InvalidArgumentException::class);
        $files->save($path, 'x', 'utf-32');
    }

    public function test_binary_files_are_refused(): void
    {
        $this->user();

        $this->getJson('/files/open?path='.urlencode('/www/wwwroot/example.com/backup.tar.gz'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'This is a binary file and cannot be edited as text.');
    }

    public function test_search_in_file_contents_and_names(): void
    {
        $this->user();

        $this->getJson('/files/search?dir=/www/wwwroot/example.com&query=Greeter')
            ->assertOk()
            ->assertJsonStructure(['results' => [['path', 'line', 'text']], 'truncated']);

        $this->getJson('/files/search?dir=/www/wwwroot/example.com&query=app&mode=name')
            ->assertOk()
            ->assertJsonStructure(['results' => [['path', 'type']]]);

        $this->getJson('/files/search?dir=/&query=password')->assertStatus(422);
        $this->getJson('/files/search?dir=/www&query=')->assertStatus(422);
    }

    public function test_read_only_users_can_browse_but_not_save(): void
    {
        $this->user('viewer');

        $this->get('/files/editor')->assertOk()->assertSee('Read only');
        $this->getJson('/files/open?path='.urlencode(self::FILE))->assertOk();
        $this->postJson('/files/write', ['path' => self::FILE, 'content' => 'x'])->assertForbidden();
    }

    public function test_template_and_extensionless_files_are_editable(): void
    {
        $editable = fn (string $name) => (bool) preg_match('#'.FileManager::EDITABLE_PATTERN.'#i', $name);

        // reported by users: template files were not offered in the editor
        foreach (['header.tpl', 'page.phtml', 'layout.twig', 'mail.latte', 'app.blade.php', 'nginx.cfg', 'data.tsv'] as $name) {
            $this->assertTrue($editable($name), $name.' should open in the editor');
        }
        // files without an extension and dot files keep working
        foreach (['Makefile', 'Dockerfile.prod', '.env', '.env.local', '.htaccess', 'composer.lock'] as $name) {
            $this->assertTrue($editable($name), $name.' should open in the editor');
        }
        foreach (['photo.jpg', 'archive.zip', 'video.mp4', 'font.woff2', 'backup.sql.gz'] as $name) {
            $this->assertFalse($editable($name), $name.' is not a text file');
        }
    }

    public function test_both_file_managers_use_the_same_list_and_read_numeric_names_as_text(): void
    {
        $this->user();

        // the two file managers must never drift apart on what can be edited
        $admin = $this->get('/files')->assertOk()->getContent();
        $this->assertStringContainsString('new RegExp(', $admin);
        $this->assertStringNotContainsString("var editable = /", $admin, 'the pattern comes from the panel, not from the page');

        // a folder whose name is only digits ("2024") must be opened like any other:
        // reading it from the row as a number used to make the lookup fail
        $this->assertStringContainsString('rowName(', $admin);
        $this->assertStringNotContainsString(".data('name')", $admin);

        $listing = $this->getJson('/files/list?path=/www/wwwroot/example.com')->assertOk()->json('items');
        $names = array_column($listing, 'name');
        $this->assertContains('2024', $names);
        $this->assertSame('2024', $names[array_search('2024', $names, true)], 'names stay strings');
        $this->getJson('/files/list?path=/www/wwwroot/example.com/2024')->assertOk();
    }
}
