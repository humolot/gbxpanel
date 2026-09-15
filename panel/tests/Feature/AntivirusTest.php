<?php

namespace Tests\Feature;

use App\Models\MalwareDetection;
use App\Models\MalwareScan;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\Ai\ServerTools;
use App\Services\ClamAvManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AntivirusTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Simulation mode flags files containing the EICAR marker. The full EICAR
     * string is not used because desktop antivirus software (e.g. Windows
     * Defender) blocks it while the test suite is running.
     */
    protected const EICAR = 'test payload: EICAR-STANDARD-ANTIVIRUS-TEST-FILE';

    protected function admin(): User
    {
        $user = User::query()->create(['name' => 'Admin', 'username' => 'admin', 'password' => 'Secret12345', 'role' => 'admin', 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    /** Real temporary file (fake()->createWithContent can arrive empty on Windows). */
    protected function upload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'gbx');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'application/octet-stream', null, true);
    }

    public function test_clamd_responses_and_scan_output_are_parsed(): void
    {
        $av = app(ClamAvManager::class);

        $this->assertSame(ClamAvManager::CLEAN, $av->parseClamdResponse('stream: OK', 'clamd')['code']);
        $infected = $av->parseClamdResponse('stream: Eicar-Test-Signature FOUND', 'clamd');
        $this->assertSame(ClamAvManager::INFECTED, $infected['code']);
        $this->assertSame('Eicar-Test-Signature', $infected['signature']);
        $this->assertSame(ClamAvManager::ERROR, $av->parseClamdResponse('INSTREAM size limit exceeded. ERROR', 'clamd')['code']);

        $found = ClamAvManager::parseOutput("/www/wwwroot/a/shell.php: Php.Webshell-1 FOUND\n/www/wwwroot/a/ok.php: OK\n/www/wwwroot/b c/x.js: Js.Miner.Generic FOUND\n");
        $this->assertCount(2, $found);
        $this->assertSame('/www/wwwroot/b c/x.js', $found[1]['path']);
        $this->assertSame('Js.Miner.Generic', $found[1]['signature']);
    }

    public function test_infected_upload_is_blocked_and_clean_upload_passes(): void
    {
        $this->admin();

        $this->post('/files/upload', ['dir' => '/www/wwwroot/site', 'file' => $this->upload('eicar.com', self::EICAR)], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('malware.signature', 'Eicar-Test-Signature');
        $this->assertDatabaseHas('malware_detections', ['path' => '/www/wwwroot/site/eicar.com', 'status' => 'blocked', 'source' => 'upload']);

        $this->post('/files/upload', ['dir' => '/www/wwwroot/site', 'file' => $this->upload('index.php', '<?php echo "ok";')], ['Accept' => 'application/json'])
            ->assertOk();

        Setting::put('av_scan_uploads', 0);
        $this->post('/files/upload', ['dir' => '/www/wwwroot/site', 'file' => $this->upload('eicar2.com', self::EICAR)], ['Accept' => 'application/json'])
            ->assertOk();
    }

    public function test_website_scan_records_detections_and_quarantine_flow(): void
    {
        $this->admin();
        $site = Website::query()->create(['domain' => 'shop.com', 'root_path' => '/www/wwwroot/shop.com']);

        $this->postJson('/security/antivirus/scan/website/'.$site->id)->assertOk()->assertJsonStructure(['scan_id', 'task' => ['id']]);

        $scan = MalwareScan::query()->firstOrFail();
        $this->assertSame('infected', $scan->status);
        $this->assertSame(1, $scan->infected_count);

        $detection = MalwareDetection::query()->where('malware_scan_id', $scan->id)->firstOrFail();
        $this->assertSame('detected', $detection->status);

        $this->postJson('/security/antivirus/detections', ['ids' => [$detection->id], 'action' => 'quarantine'])->assertOk();
        $detection->refresh();
        $this->assertSame('quarantined', $detection->status);
        $this->assertStringStartsWith('/www/quarantine/', $detection->quarantine_path);

        $this->postJson('/security/antivirus/detections', ['ids' => [$detection->id], 'action' => 'restore'])->assertOk();
        $this->assertSame('restored', $detection->fresh()->status);

        $this->postJson('/security/antivirus/detections', ['ids' => [$detection->id], 'action' => 'ignore'])->assertStatus(422);

        $this->get('/security/antivirus')->assertOk()->assertSee('Php.Webshell.Generic-1');
    }

    public function test_auto_quarantine_and_pseudo_filesystems(): void
    {
        $this->admin();
        Setting::put('av_auto_quarantine', 1);

        app(ClamAvManager::class)->scanPath('/www/wwwroot');
        $this->assertSame('quarantined', MalwareDetection::query()->firstOrFail()->status);

        $this->postJson('/security/antivirus/scan', ['path' => '/proc/1'])->assertStatus(422);
    }

    public function test_ai_tools_scan_and_handle_detections(): void
    {
        $user = $this->admin();
        $tools = app(ServerTools::class);

        $scan = json_decode($tools->execute('scan_for_malware', ['path' => '/www/wwwroot/shop.com'], $user), true);
        $this->assertArrayHasKey('scan_id', $scan);

        $result = json_decode($tools->execute('get_malware_scan', ['scan_id' => $scan['scan_id']], $user), true);
        $this->assertSame('infected', $result['status']);

        $id = $result['detections'][0]['id'];
        $handled = json_decode($tools->execute('handle_malware_detections', ['ids' => [$id], 'action' => 'delete'], $user), true);
        $this->assertTrue($handled['ok']);
        $this->assertSame('deleted', MalwareDetection::query()->find($id)->status);

        $file = json_decode($tools->execute('scan_for_malware', ['path' => '/www/wwwroot/shop.com/uploads/shell.php'], $user), true);
        $this->assertSame('infected', $file['status']);

        $this->assertFalse(collect($tools->definitions(User::query()->create(['name' => 'V', 'username' => 'v', 'password' => 'Secret12345', 'role' => 'viewer'])))->pluck('function.name')->contains('handle_malware_detections'));
    }
}
