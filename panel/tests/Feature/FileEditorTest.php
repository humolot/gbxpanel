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
}
