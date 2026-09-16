<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\ApiRequest;
use App\Models\Client;
use App\Models\ClientPackage;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Api\ApiCatalog;
use App\Services\Api\WebhookManager;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ApiModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::query()->firstOrCreate(['username' => 'admin'], ['name' => 'Admin', 'password' => 'Secret12345', 'role' => 'admin', 'is_active' => true]);
    }

    /** @return array{0: ApiKey, 1: string} */
    protected function key(array $attributes = []): array
    {
        return ApiKey::issue($attributes + [
            'name' => 'Integration',
            'scopes' => ['websites', 'system:read', 'tasks:read'],
            'user_id' => $this->admin()->id,
            'rate_limit' => 120,
            'is_active' => true,
        ]);
    }

    protected function call_(string $method, string $uri, array $body = [], array $headers = [])
    {
        return $this->json($method, '/api/v1'.$uri, $body, $headers);
    }

    public function test_keys_are_stored_as_a_hash_and_checked_on_every_call(): void
    {
        [$key, $token] = $this->key();

        $this->assertStringStartsWith('gbx', $token);
        $this->assertSame(hash('sha256', $token), $key->token_hash);
        $this->assertNull(ApiKey::findByToken($key->prefix.'_wrong-secret'), 'a wrong secret never matches');
        $this->assertSame($key->id, ApiKey::findByToken($token)?->id);

        $this->call_('GET', '/whoami')->assertStatus(401)->assertJsonPath('error.code', 'unauthorized');
        $this->call_('GET', '/whoami', [], ['Authorization' => 'Bearer gbxnope_x'])->assertStatus(401);

        $ok = $this->call_('GET', '/whoami', [], ['Authorization' => 'Bearer '.$token])->assertOk();
        $ok->assertJsonPath('data.key.name', 'Integration')->assertJsonPath('data.scopes.0', 'websites');
        $this->assertNotNull($ok->headers->get('X-Request-Id'));
        $this->assertSame('120', $ok->headers->get('X-RateLimit-Limit'));

        // the call is written to the API log and counted on the key
        $this->assertSame('GET', ApiRequest::query()->latest('id')->value('method'));
        $this->assertSame(1, $key->fresh()->requests);

        $key->update(['is_active' => false]);
        $this->call_('GET', '/whoami', [], ['Authorization' => 'Bearer '.$token])->assertStatus(403)->assertJsonPath('error.code', 'key_disabled');

        $key->update(['is_active' => true, 'expires_at' => now()->subDay()]);
        $this->call_('GET', '/whoami', [], ['Authorization' => 'Bearer '.$token])->assertStatus(403)->assertJsonPath('error.code', 'key_expired');

        $key->update(['expires_at' => null, 'allowed_ips' => '203.0.113.5, 10.0.0.0/8']);
        $this->call_('GET', '/whoami', [], ['Authorization' => 'Bearer '.$token])->assertStatus(403)->assertJsonPath('error.code', 'ip_not_allowed');
        $this->assertTrue(ApiKey::ipMatches('10.1.2.3', '10.0.0.0/8'));
        $this->assertFalse(ApiKey::ipMatches('11.1.2.3', '10.0.0.0/8'));
        $this->assertTrue(ApiKey::ipMatches('127.0.0.1', '127.0.0.1'));
    }

    public function test_permissions_limit_what_a_key_can_do(): void
    {
        [$key, $token] = $this->key(['scopes' => ['websites:read']]);
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->assertTrue($key->hasScope('websites:read'));
        $this->assertFalse($key->hasScope('websites:write'));
        $this->assertTrue($this->key(['scopes' => ['websites']])[0]->hasScope('websites:write'), 'a group grants read and write');
        $this->assertTrue($this->key(['scopes' => ['websites:write']])[0]->hasScope('websites:read'), 'write includes read');
        $this->assertTrue($this->key(['scopes' => ['*']])[0]->hasScope('clients:write'));

        Website::query()->create(['domain' => 'example.com', 'root_path' => '/www/wwwroot/example.com']);
        $this->call_('GET', '/websites', [], $headers)->assertOk()->assertJsonPath('data.0.domain', 'example.com');
        $this->call_('POST', '/websites', ['domain' => 'shop.com'], $headers)
            ->assertStatus(403)->assertJsonPath('error.code', 'missing_scope')->assertJsonPath('error.required_scope', 'websites:write');
        $this->call_('GET', '/clients', [], $headers)->assertStatus(403);
        $this->call_('GET', '/nothing-here', [], $headers)->assertStatus(404)->assertJsonPath('error.code', 'not_found');
    }

    public function test_websites_are_listed_created_and_stopped_through_the_api(): void
    {
        [, $token] = $this->key(['scopes' => ['*']]);
        $headers = ['Authorization' => 'Bearer '.$token];

        $created = $this->call_('POST', '/websites', ['domain' => 'shop.com', 'add_www' => true, 'php_version' => ''], $headers)->assertOk();
        $created->assertJsonPath('data.website.domain', 'shop.com');
        $site = Website::query()->where('domain', 'shop.com')->firstOrFail();
        $this->assertSame('www.shop.com', $site->aliases);

        $this->call_('GET', '/websites/'.$site->id, [], $headers)->assertOk()->assertJsonPath('data.ssl.enabled', false)->assertJsonPath('data.document_root', $site->documentRoot());
        $this->call_('POST', '/websites/'.$site->id.'/stop', [], $headers)->assertOk();
        $this->assertSame('stopped', $site->fresh()->status);
        $this->call_('POST', '/websites/'.$site->id.'/stop', [], $headers)->assertOk()->assertJsonPath('message', 'The website is already stopped');

        $this->call_('PATCH', '/websites/'.$site->id, ['php_version' => '', 'notes' => 'through the api'], $headers)->assertOk();
        $this->assertSame('through the api', $site->fresh()->notes);

        // validation errors answer with the fields that failed
        $this->call_('POST', '/websites', ['domain' => 'not a domain'], $headers)
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['fields' => ['domain']]]);

        $this->call_('DELETE', '/websites/'.$site->id, [], $headers)->assertOk();
        $this->assertNull(Website::query()->find($site->id));
    }

    public function test_rate_limit_and_idempotency(): void
    {
        RateLimiter::clear('gbx-api:1');
        [$key, $token] = $this->key(['scopes' => ['*'], 'rate_limit' => 2]);
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->call_('GET', '/whoami', [], $headers)->assertOk();
        $this->call_('GET', '/whoami', [], $headers)->assertOk()->assertHeader('X-RateLimit-Remaining', '0');
        $limited = $this->call_('GET', '/whoami', [], $headers)->assertStatus(429);
        $limited->assertJsonPath('error.code', 'rate_limited');
        $this->assertNotNull($limited->headers->get('Retry-After'));

        RateLimiter::clear('gbx-api:'.$key->id);
        $idempotent = ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'abc-123'];
        $this->call_('POST', '/websites', ['domain' => 'once.com', 'php_version' => ''], $idempotent)->assertOk();
        $replay = $this->call_('POST', '/websites', ['domain' => 'once.com', 'php_version' => ''], $idempotent)->assertOk();
        $this->assertSame('true', $replay->headers->get('Idempotent-Replay'), 'the repeated call answers the stored result');
        $this->assertSame(1, Website::query()->where('domain', 'once.com')->count(), 'and the website is created only once');
    }

    public function test_a_key_can_be_bound_to_a_client(): void
    {
        $package = ClientPackage::query()->create(['name' => 'Basic', 'max_websites' => 5, 'max_databases' => 5, 'max_ftp' => 5, 'disk_mb' => 0, 'bandwidth_mb' => 0]);
        $client = Client::query()->create(['username' => 'joao', 'name' => 'Joao', 'password' => 'Client12345', 'package_id' => $package->id, 'status' => 'active']);
        [, $token] = $this->key(['scopes' => ['*'], 'client_id' => $client->id]);

        $this->call_('GET', '/whoami', [], ['Authorization' => 'Bearer '.$token])->assertOk()->assertJsonPath('data.client_id', $client->id);
        $this->call_('POST', '/websites', ['domain' => 'client-site.com', 'php_version' => '', 'client_id' => $client->id], ['Authorization' => 'Bearer '.$token])->assertOk();
        $this->assertSame($client->id, Website::query()->where('domain', 'client-site.com')->value('client_id'));
        $this->assertSame($client->id, \App\Models\ActivityLog::query()->where('category', 'website')->latest('id')->value('client_id'), 'what the key did is recorded for the client');
    }

    public function test_documentation_matches_the_registered_routes(): void
    {
        [, $token] = $this->key(['scopes' => ['*']]);
        $document = $this->call_('GET', '/openapi.json', [], ['Authorization' => 'Bearer '.$token])->assertOk()->json();

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertArrayHasKey('/websites', $document['paths']);
        $this->assertSame('Create a website', $document['paths']['/websites']['post']['summary']);
        $this->assertSame(['bearer' => ['websites:write']], $document['paths']['/websites']['post']['security'][0]);
        $this->assertCount(count(array_unique(array_column(ApiCatalog::endpoints(), 'path'))), $document['paths']);

        foreach (ApiCatalog::endpoints() as $endpoint) {
            [$class, $method] = explode('@', $endpoint['action']);
            $controller = 'App\\Http\\Controllers\\Api\\'.$class.'Controller';
            $this->assertTrue(method_exists($controller, $method), "{$controller}@{$method} is documented but does not exist");
            $this->assertTrue($endpoint['scope'] === null || array_key_exists($endpoint['scope'], ApiCatalog::SCOPES), 'unknown scope on '.$endpoint['path']);
        }
    }

    public function test_webhooks_are_signed_delivered_and_retried(): void
    {
        Http::fake(['https://hooks.example.com/*' => Http::response('ok', 200), 'https://broken.example.com/*' => Http::response('nope', 500)]);
        $webhook = Webhook::query()->create(['name' => 'Billing', 'url' => 'https://hooks.example.com/gbx', 'secret' => Webhook::newSecret(), 'events' => ['website.*'], 'is_active' => true]);

        $this->assertTrue($webhook->listensTo('website.created'));
        $this->assertFalse($webhook->listensTo('client.created'));

        app(WebhookManager::class)->dispatch('website.created', ['domain' => 'example.com']);
        $delivery = WebhookDelivery::query()->latest('id')->firstOrFail();
        $this->assertSame('sent', $delivery->status);

        Http::assertSent(function ($request) use ($webhook) {
            $signature = $request->header('X-GBX-Signature')[0] ?? '';
            $body = $request->body();

            return $request->url() === 'https://hooks.example.com/gbx'
                && ($request->header('X-GBX-Event')[0] ?? '') === 'website.created'
                && hash_equals($signature, 'sha256='.hash_hmac('sha256', $body, $webhook->secret))
                && json_decode($body, true)['data']['domain'] === 'example.com';
        });

        // an event nobody listens to costs nothing
        $this->assertSame(0, app(WebhookManager::class)->dispatch('client.created', ['username' => 'joao']));

        $failing = Webhook::query()->create(['name' => 'Broken', 'url' => 'https://broken.example.com/gbx', 'secret' => Webhook::newSecret(), 'events' => ['*'], 'is_active' => true]);
        app(WebhookManager::class)->dispatch('task.failed', ['id' => 1]);
        $failed = WebhookDelivery::query()->where('webhook_id', $failing->id)->latest('id')->firstOrFail();
        $this->assertSame('pending', $failed->status, 'a failed delivery waits for another attempt');
        $this->assertSame(1, $failed->attempts);
        $this->assertNotNull($failed->next_try_at);
        $this->assertSame(500, $failing->fresh()->last_status);

        $failed->update(['next_try_at' => now()->subMinute()]);
        $this->assertSame(1, app(WebhookManager::class)->retryPending());
        $this->assertSame(2, $failed->fresh()->attempts);
    }

    public function test_the_panel_page_manages_keys_and_webhooks(): void
    {
        $this->actingAs($this->admin());

        $this->get('/api-access')->assertOk()->assertSee('Create key');
        $this->get('/api-access?tab=docs')->assertOk()->assertSee('/api/v1/websites', false);

        $created = $this->postJson('/api-access/keys', ['name' => 'Deploy', 'scopes' => ['websites:write'], 'rate_limit' => 60])->assertOk();
        $token = $created->json('token');
        $key = ApiKey::query()->firstOrFail();
        $this->assertNotNull($token);
        $this->assertStringNotContainsString($token, (string) $key->token_hash);
        $this->call_('GET', '/websites', [], ['Authorization' => 'Bearer '.$token])->assertOk();

        $this->getJson('/api-access/keys')->assertOk()->assertJsonPath('data.0.name', 'Deploy')->assertJsonMissing(['token_hash']);

        // a new token replaces the old one, which stops working at once
        $rotated = $this->postJson('/api-access/keys/'.$key->id.'/rotate')->assertOk();
        $this->call_('GET', '/websites', [], ['Authorization' => 'Bearer '.$token])->assertStatus(401);
        $this->call_('GET', '/websites', [], ['Authorization' => 'Bearer '.$rotated->json('token')])->assertOk();

        $this->postJson('/api-access/keys', ['name' => 'Bad IPs', 'scopes' => ['websites:read'], 'allowed_ips' => 'not-an-ip'])->assertStatus(422)->assertJsonValidationErrors('allowed_ips');
        $this->postJson('/api-access/keys', ['name' => 'Bad scope', 'scopes' => ['everything']])->assertStatus(422);

        Http::fake(['https://hooks.example.com/*' => Http::response('', 204)]);
        $hook = $this->postJson('/api-access/webhooks', ['name' => 'Billing', 'url' => 'https://hooks.example.com/gbx', 'events' => ['client.created']])->assertOk();
        $this->assertStringStartsWith('whsec_', (string) $hook->json('secret'));
        $webhook = Webhook::query()->firstOrFail();
        $this->postJson('/api-access/webhooks/'.$webhook->id.'/test')->assertOk();
        $this->getJson('/api-access/webhooks/'.$webhook->id.'/deliveries')->assertOk()->assertJsonPath('data.0.event', 'ping');
        $this->getJson('/api-access/webhooks')->assertOk()->assertJsonMissing(['secret']);
        $this->deleteJson('/api-access/webhooks/'.$webhook->id)->assertOk();

        $this->getJson('/api-access/logs')->assertOk()->assertJsonPath('data.0.path', fn ($path) => str_starts_with((string) $path, 'api/v1/'));
    }

    public function test_only_administrators_open_the_api_page(): void
    {
        $this->actingAs(User::query()->create(['name' => 'Op', 'username' => 'op', 'password' => 'Secret12345', 'role' => 'user', 'is_active' => true]));
        $this->get('/api-access')->assertForbidden();
    }
}
