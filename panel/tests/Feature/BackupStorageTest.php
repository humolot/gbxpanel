<?php

namespace Tests\Feature;

use App\Models\BackupStorage;
use App\Models\BackupTransfer;
use App\Models\CronJob;
use App\Models\MysqlDatabase;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\Models\Website;
use App\Services\Backup\OAuthTokens;
use App\Services\Backup\StorageException;
use App\Services\Backup\StorageManager;
use App\Services\Backup\TransferManager;
use App\Services\CronManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function login(string $role = 'admin'): User
    {
        $user = User::query()->create(['name' => ucfirst($role), 'username' => $role, 'password' => 'Secret12345', 'role' => $role, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    protected function storage(array $attributes = []): BackupStorage
    {
        return BackupStorage::query()->create($attributes + [
            'type' => 'aws',
            'name' => 'S3 offsite',
            'folder' => 'servers/web1',
            'credentials' => ['access_key_id' => 'AKIA123', 'secret_access_key' => 'super-secret', 'region' => 'us-east-1', 'bucket' => 'gbx-backups'],
            'is_active' => true,
        ]);
    }

    public function test_storage_is_created_through_the_form_and_secrets_stay_out_of_reach(): void
    {
        $this->login();
        $this->post('/backup/storages', [
            'type' => 'aws',
            'name' => 'S3 offsite',
            'folder' => 'servers/web1/',
            'credentials' => ['access_key_id' => 'AKIA123', 'secret_access_key' => 'super-secret', 'region' => 'us-east-1', 'bucket' => 'gbx-backups'],
        ])->assertOk();

        $storage = BackupStorage::query()->firstOrFail();
        $this->assertSame('servers/web1', $storage->folder);
        $this->assertStringNotContainsString('super-secret', (string) $storage->getRawOriginal('credentials'), 'credentials are stored encrypted');
        $this->assertSame('', $storage->maskedCredentials()['secret_access_key'], 'secrets are never sent back to the browser');
        $this->assertSame(['secret_access_key'], $storage->storedSecrets());

        $list = $this->getJson('/backup/storages')->assertOk();
        $list->assertJsonPath('data.0.root', 'gbx1:gbx-backups/servers/web1');
        $this->assertStringNotContainsString('super-secret', $list->getContent());

        // an unchanged secret field keeps the stored value
        $this->putJson('/backup/storages/'.$storage->id, [
            'name' => 'S3 offsite',
            'folder' => 'servers/web1',
            'credentials' => ['access_key_id' => 'AKIA123', 'secret_access_key' => '', 'region' => 'us-east-1', 'bucket' => 'gbx-backups'],
        ])->assertOk();
        $this->assertSame('super-secret', $storage->fresh()->credential('secret_access_key'));

        $this->postJson('/backup/storages', ['type' => 'aws', 'name' => 'Broken', 'credentials' => ['access_key_id' => 'A', 'secret_access_key' => 'B', 'region' => 'us-east-1', 'bucket' => 'name with spaces']])
            ->assertStatus(422)->assertJsonValidationErrors('credentials.bucket');
        $this->postJson('/backup/storages', ['type' => 'aws', 'name' => 'Broken', 'folder' => '../../etc', 'credentials' => ['access_key_id' => 'A', 'secret_access_key' => 'B', 'region' => 'us-east-1', 'bucket' => 'gbx']])
            ->assertStatus(422)->assertJsonValidationErrors('folder');
    }

    public function test_rclone_configuration_matches_every_kind_of_destination(): void
    {
        $manager = app(StorageManager::class);

        $s3 = $this->storage();
        $config = $manager->config($s3);
        $this->assertStringContainsString('[gbx'.$s3->id.']', $config);
        $this->assertStringContainsString("type = s3\nprovider = AWS", $config);
        $this->assertStringContainsString('no_check_bucket = true', $config);
        $this->assertSame('gbx'.$s3->id.':gbx-backups/servers/web1/site/example.com', $manager->path($s3, 'site/example.com'));

        $spaces = $this->storage(['type' => 'digitalocean', 'name' => 'Spaces', 'credentials' => ['access_key_id' => 'k', 'secret_access_key' => 's', 'region' => 'nyc3', 'bucket' => 'b']]);
        $this->assertStringContainsString('endpoint = nyc3.digitaloceanspaces.com', $manager->config($spaces));

        $r2 = $this->storage(['type' => 'r2', 'name' => 'R2', 'credentials' => ['account_id' => 'abc123', 'access_key_id' => 'k', 'secret_access_key' => 's', 'bucket' => 'b']]);
        $this->assertStringContainsString('endpoint = https://abc123.r2.cloudflarestorage.com', $manager->config($r2));
        $this->assertStringContainsString('region = auto', $manager->config($r2));

        // passwords are stored in the format rclone expects, never in clear text
        $ftp = $this->storage(['type' => 'ftp', 'name' => 'Offsite FTP', 'folder' => '/backups', 'credentials' => ['host' => 'ftp.example.com', 'user' => 'backup', 'pass' => 'Secret12345', 'tls' => 'explicit']]);
        $ftpConfig = $manager->config($ftp);
        $this->assertStringNotContainsString('Secret12345', $ftpConfig);
        $this->assertStringContainsString('explicit_tls = true', $ftpConfig);
        $this->assertSame('gbx'.$ftp->id.':/backups', $manager->root($ftp));
        $this->assertTrue((bool) ($ftp->definition()['split'] ?? false), 'FTP has no resumable uploads: archives are sent in parts');

        $drive = $this->storage(['type' => 'drive', 'name' => 'Drive', 'folder' => 'gbx', 'credentials' => ['client_id' => 'id', 'client_secret' => 'secret', 'scope' => 'drive.file', 'token' => '{"access_token":"a","refresh_token":"r"}']]);
        $this->assertStringContainsString('scope = drive.file', $manager->config($drive));
        $this->assertStringContainsString('token = {"access_token":"a","refresh_token":"r"}', $manager->config($drive));

        foreach (['../escape', 'a/../../b', "bad\nname"] as $bad) {
            $this->assertThrows(fn () => StorageManager::cleanPath($bad), \InvalidArgumentException::class);
        }
    }

    public function test_scheduled_backups_upload_to_the_storage(): void
    {
        $this->login();
        $storage = $this->storage();
        Website::query()->create(['domain' => 'example.com', 'root_path' => '/www/wwwroot/example.com']);

        $this->postJson('/home/cron', [
            'name' => 'Nightly backup',
            'type' => 'site_backup',
            'cycles' => [['type' => 'day', 'hour' => 3, 'minute' => 0]],
            'website' => 'all',
            'databases' => 1,
            'keep' => 3,
            'storage' => $storage->id,
            'storage_move' => 1,
            'remote_keep' => 30,
        ])->assertOk();

        $job = CronJob::query()->firstOrFail();
        $this->assertSame($storage->id, (int) $job->params['storage']);
        $script = app(CronManager::class)->script($job);
        $this->assertStringContainsString('gbx:backup-push --storage='.$storage->id.' --category=site', $script);
        $this->assertStringContainsString("--label='example.com' --keep=30 --move", $script);
        $this->assertStringContainsString('/www/backup/site/example.com_\'"$STAMP"\'.tar.gz', $script, 'the file name is resolved when the job runs');
        $this->assertStringContainsString('FAILED=1', $script);

        // the storage cannot disappear under a job that uses it
        $this->deleteJson('/backup/storages/'.$storage->id)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Nightly backup'));
    }

    public function test_manual_website_backup_uploads_the_archive_and_its_dumps(): void
    {
        $this->login();
        $storage = $this->storage();
        $site = Website::query()->create(['domain' => 'shop.com', 'root_path' => '/www/wwwroot/shop.com']);
        MysqlDatabase::query()->create(['name' => 'shop_db', 'username' => 'shop_db', 'password' => 'x', 'website_id' => $site->id]);

        $this->postJson('/websites/'.$site->id.'/backups', ['databases' => 1, 'storage_id' => $storage->id, 'delete_local' => 1])->assertOk();

        $task = Task::query()->where('type', 'backup')->latest('id')->firstOrFail();
        $files = collect($task->meta['upload']['files']);
        $this->assertTrue($task->meta['upload']['delete_local']);
        $this->assertCount(2, $files, 'the archive and the database dump are both uploaded');
        $this->assertSame('database/mysql/shop_db', 'database/'.$files->firstWhere('category', 'database')['label']);

        // the task finished in simulation, so the transfers were queued by the task hook
        $transfers = BackupTransfer::query()->get();
        $this->assertCount(2, $transfers);
        $this->assertSame('success', $transfers->first()->status);
        $this->assertStringStartsWith('site/shop.com/shop.com_', $transfers->firstWhere('category', 'site')->remote_path);
        $this->assertStringStartsWith('database/mysql/shop_db/shop_db_', $transfers->firstWhere('category', 'database')->remote_path);
    }

    public function test_transfers_are_listed_retried_and_kept_inside_the_backup_folder(): void
    {
        $this->login();
        $storage = $this->storage();
        $transfers = app(TransferManager::class);

        $this->assertThrows(fn () => $transfers->queueUpload($storage, '/etc/passwd', 'site', 'example.com'), \InvalidArgumentException::class);

        $transfer = $transfers->queueUpload($storage, '/www/backup/site/example.com_20260915_030000.tar.gz', 'site', 'example.com', ['keep' => 10, 'delete_local' => true]);
        $this->assertSame('site/example.com/example.com_20260915_030000.tar.gz', $transfer->remote_path);
        $transfers->execute($transfer);
        $this->assertSame('success', $transfer->fresh()->status);

        $this->getJson('/backup/transfers')->assertOk()->assertJsonPath('data.0.file', 'example.com_20260915_030000.tar.gz')->assertJsonPath('data.0.storage', 'S3 offsite');
        $this->getJson('/backup/transfers/'.$transfer->id.'/log')->assertOk()->assertJsonPath('status', 'success');
        $this->postJson('/backup/transfers/'.$transfer->id.'/retry')->assertOk();
        $this->postJson('/backup/transfers/'.$transfer->id.'/cancel')->assertStatus(422);
        $this->deleteJson('/backup/transfers/'.$transfer->id)->assertOk();
        $this->assertSame(0, BackupTransfer::query()->count());

        // a backup that came from a storage lands next to the local backups of the same kind
        $this->assertSame('/www/backup/database/shop_20260915_030000.sql.gz', $transfers->localTarget('database/mysql/shop/shop_20260915_030000.sql.gz'));
        $this->assertSame('/www/backup/site/shop.com_20260915_030000.tar.gz', $transfers->localTarget('site/shop.com/shop.com_20260915_030000.tar.gz.parts'));
    }

    public function test_remote_backups_are_browsed_downloaded_and_restored(): void
    {
        $this->login();
        $storage = $this->storage();
        Website::query()->create(['domain' => 'example.com', 'root_path' => '/www/wwwroot/example.com']);

        $this->getJson('/backup/storages/'.$storage->id.'/browse')->assertOk()->assertJsonPath('items.0.name', 'database');
        $browse = $this->getJson('/backup/storages/'.$storage->id.'/browse?path=site/example.com')->assertOk();
        $file = $browse->json('items.1');
        $this->assertSame('site', $browse->json('items.0.restore.type'));
        $this->assertSame('example.com', $file['restore']['label']);

        $this->postJson('/backup/storages/'.$storage->id.'/fetch', ['path' => $file['path'], 'restore' => 1])->assertOk();
        $transfer = BackupTransfer::query()->latest('id')->firstOrFail();
        $this->assertSame('download', $transfer->direction);
        $this->assertSame('restore_site', $transfer->after['action']);

        $this->postJson('/backup/storages/'.$storage->id.'/fetch', ['path' => 'other/unknown.tar.gz', 'restore' => 1])->assertStatus(422);
        $this->getJson('/backup/storages/'.$storage->id.'/browse?path=../../etc')->assertStatus(422);
        $this->get('/backup/storages/'.$storage->id.'/download?path=../etc/shadow')->assertNotFound();
    }

    public function test_google_drive_tokens_and_settings(): void
    {
        $this->login();

        $token = OAuthTokens::parsePasted("Paste the following into your remote machine --->\n".'{"access_token":"ya29.x","token_type":"Bearer","refresh_token":"1//r","expiry":"2026-09-15T10:00:00Z"}'."\n<---End paste");
        $this->assertSame('1//r', json_decode($token, true)['refresh_token']);
        $this->assertThrows(fn () => OAuthTokens::parsePasted('{"access_token":"a"}'), StorageException::class);
        $this->assertThrows(fn () => OAuthTokens::parsePasted('no token here'), StorageException::class);
        $this->assertStringContainsString(urlencode('https://www.googleapis.com/auth/drive.file'), OAuthTokens::googleAuthUrl('id', 'https://panel.test/backup/google/callback', 'drive.file', 'state'));

        // connecting from an http page cannot work: Google refuses the redirect
        $this->postJson('/backup/google/start', ['name' => 'Drive', 'client_id' => 'id', 'client_secret' => 'secret'])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'HTTPS'));

        $this->postJson('/backup/storages', ['type' => 'drive', 'name' => 'Drive', 'credentials' => ['client_id' => 'id', 'client_secret' => 's', 'scope' => 'drive.file']])
            ->assertStatus(422)->assertJsonValidationErrors('credentials.token');

        $this->postJson('/backup/settings', ['webhook' => 'https://hooks.example.com/backup', 'bwlimit' => '10M', 'split_mb' => 512, 'max_parallel' => 3, 'history_days' => 45])->assertOk();
        $this->assertSame('10M', Setting::get('backup_bwlimit'));
        $this->assertSame(512 * 1048576, app(TransferManager::class)->partBytes());
        $this->postJson('/backup/settings', ['bwlimit' => 'fast', 'split_mb' => 512, 'max_parallel' => 3, 'history_days' => 45])->assertStatus(422)->assertJsonValidationErrors('bwlimit');
    }

    public function test_only_administrators_reach_the_backup_page(): void
    {
        $this->login('user');
        $this->get('/backup')->assertForbidden();
        $this->getJson('/backup/storages')->assertForbidden();
    }
}
