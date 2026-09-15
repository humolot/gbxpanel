<?php

namespace Tests\Feature;

use App\Models\DnsProvider;
use App\Models\DnsZone;
use App\Models\Task;
use App\Models\User;
use App\Models\Website;
use App\Services\Dns\DnsManager;
use App\Services\Dns\Providers\CloudflareProvider;
use App\Services\Dns\Providers\DigitalOceanProvider;
use App\Services\Dns\Providers\GoDaddyProvider;
use App\Services\Dns\Providers\HetznerProvider;
use App\Services\Dns\Providers\LinodeProvider;
use App\Services\Dns\Providers\NamecheapProvider;
use App\Services\Dns\Providers\PorkbunProvider;
use App\Services\Dns\Providers\VultrProvider;
use App\Services\SslManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DnsModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function login(string $role = 'admin'): User
    {
        $user = User::query()->create(['name' => ucfirst($role), 'username' => $role, 'password' => 'Secret12345', 'role' => $role, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    protected function cloudflareZone(): DnsZone
    {
        $provider = DnsProvider::query()->create(['type' => 'cloudflare', 'alias' => 'Main', 'credentials' => ['api_token' => 'cf-token'], 'is_active' => true]);

        return DnsZone::query()->create(['provider_id' => $provider->id, 'name' => 'example.com', 'external_id' => 'zone1', 'manageable' => true]);
    }

    protected const A = ['type' => 'A', 'name' => 'www', 'content' => '203.0.113.10', 'ttl' => 1, 'priority' => null, 'proxied' => true];

    public function test_pages_render(): void
    {
        $this->login();
        $this->get('/dns')->assertOk()->assertSee('Connect a DNS provider');
        $this->get('/dns?tab=providers')->assertOk()->assertSee('Integrate DNS Provider API', false)->assertSee('Namecheap');
    }

    public function test_cloudflare_token_zones_and_records(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/user/tokens/verify' => Http::response(['success' => true, 'result' => ['status' => 'active']]),
            'api.cloudflare.com/client/v4/zones?*' => Http::response(['success' => true, 'result' => [['id' => 'zone1', 'name' => 'Example.com', 'status' => 'active']], 'result_info' => ['total_pages' => 1]]),
            'api.cloudflare.com/client/v4/zones/zone1/dns_records?*' => Http::response(['success' => true, 'result' => [
                ['id' => 'r1', 'type' => 'A', 'name' => 'example.com', 'content' => '1.2.3.4', 'ttl' => 1, 'proxied' => true],
                ['id' => 'r2', 'type' => 'TXT', 'name' => '_dmarc.example.com', 'content' => '"v=DMARC1; p=none"', 'ttl' => 3600],
                ['id' => 'r3', 'type' => 'CAA', 'name' => 'example.com', 'content' => '0 issue letsencrypt.org', 'data' => ['flags' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org'], 'ttl' => 3600],
            ], 'result_info' => ['total_pages' => 1]]),
            'api.cloudflare.com/client/v4/zones/zone1/dns_records' => Http::response(['success' => true, 'result' => ['id' => 'new']]),
            'api.cloudflare.com/client/v4/zones/zone1/dns_records/r1' => Http::response(['success' => false, 'errors' => [['code' => 81057, 'message' => 'Record already exists.']]], 400),
        ]);

        $driver = new CloudflareProvider(['api_token' => 'cf-token']);
        $this->assertSame('API token', $driver->verify());
        $this->assertSame('example.com', $driver->zones()[0]['name']);
        $records = $driver->records(['id' => 'zone1', 'name' => 'example.com']);
        $this->assertSame(['id' => 'r1', 'type' => 'A', 'name' => '@', 'content' => '1.2.3.4', 'ttl' => 1, 'priority' => null, 'proxied' => true], $records[0]);
        $this->assertSame('_dmarc', $records[1]['name']);
        $this->assertSame('v=DMARC1; p=none', $records[1]['content']);
        $this->assertSame('0 issue "letsencrypt.org"', $records[2]['content']);

        $driver->create(['id' => 'zone1', 'name' => 'example.com'], self::A);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->hasHeader('Authorization', 'Bearer cf-token')
            && $r['name'] === 'www.example.com' && $r['proxied'] === true && $r['ttl'] === 1);

        try {
            $driver->update(['id' => 'zone1', 'name' => 'example.com'], 'r1', self::A);
            $this->fail('Error not raised');
        } catch (\App\Services\Dns\DnsException $e) {
            $this->assertStringContainsString('Record already exists. [81057]', $e->getMessage());
        }
    }

    public function test_namecheap_keeps_other_hosts_when_setting_the_list(): void
    {
        $hosts = '<?xml version="1.0"?><ApiResponse Status="OK"><CommandResponse Type="namecheap.domains.dns.getHosts"><DomainDNSGetHostsResult Domain="shop.co.uk" EmailType="MX" IsUsingOurDNS="true">'
            .'<host HostId="11" Name="@" Type="A" Address="1.1.1.1" MXPref="10" TTL="1800" />'
            .'<host HostId="12" Name="@" Type="MX" Address="mail.shop.co.uk." MXPref="5" TTL="1800" />'
            .'<host HostId="13" Name="old" Type="CNAME" Address="shop.co.uk." MXPref="10" TTL="1800" />'
            .'</DomainDNSGetHostsResult></CommandResponse></ApiResponse>';
        Http::fake(function (Request $request) use ($hosts) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $command = $query['Command'] ?? $request['Command'];

            return Http::response($command === 'namecheap.domains.dns.getHosts' ? $hosts : '<ApiResponse Status="OK"><CommandResponse/></ApiResponse>');
        });

        $driver = new NamecheapProvider(['api_user' => 'john', 'api_key' => 'nc-key', 'client_ip' => '198.51.100.7']);
        $zone = ['id' => '1', 'name' => 'shop.co.uk'];
        $this->assertSame('mail.shop.co.uk', $driver->records($zone)[1]['content']);

        $driver->delete($zone, '13');
        Http::assertSent(function (Request $r) {
            return $r->method() === 'POST' && $r['Command'] === 'namecheap.domains.dns.setHosts' && $r['SLD'] === 'shop' && $r['TLD'] === 'co.uk'
                && $r['ClientIp'] === '198.51.100.7' && $r['HostName1'] === '@' && $r['Address1'] === '1.1.1.1'
                && $r['RecordType2'] === 'MX' && $r['MXPref2'] === 5 && $r['Address2'] === 'mail.shop.co.uk.' && $r['EmailType'] === 'MX'
                && ! isset($r['HostName3']);
        });
    }

    public function test_namecheap_ip_whitelist_error_is_explained(): void
    {
        Http::fake(['api.namecheap.com/*' => Http::response('<ApiResponse Status="ERROR"><Errors><Error Number="1011150">Parameter RequestIP is invalid</Error></Errors></ApiResponse>')]);
        $this->expectExceptionMessage('Add 198.51.100.7 to Profile > Tools > Namecheap API Access > Whitelisted IPs');
        (new NamecheapProvider(['api_user' => 'john', 'api_key' => 'k', 'client_ip' => '198.51.100.7']))->verify();
    }

    public function test_godaddy_replaces_only_the_type_and_name_set(): void
    {
        Http::fake([
            'api.godaddy.com/v1/domains/example.com/records/A/www' => Http::sequence()->push([['data' => '1.1.1.1', 'ttl' => 600], ['data' => '2.2.2.2', 'ttl' => 600]])->push([]),
            'api.godaddy.com/v1/domains/example.com/records' => Http::response([['type' => 'A', 'name' => 'www', 'data' => '1.1.1.1', 'ttl' => 600], ['type' => 'A', 'name' => 'www', 'data' => '2.2.2.2', 'ttl' => 600]]),
        ]);
        $driver = new GoDaddyProvider(['api_key' => 'key', 'api_secret' => 'secret']);
        $zone = ['id' => '1', 'name' => 'example.com'];
        $id = GoDaddyProvider::idOf('A', 'www', '1.1.1.1');
        $this->assertSame($id, $driver->records($zone)[0]['id']);

        $driver->update($zone, $id, ['type' => 'A', 'name' => 'www', 'content' => '9.9.9.9', 'ttl' => 300, 'priority' => null, 'proxied' => null]);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->hasHeader('Authorization', 'sso-key key:secret')
            && $r->data() === [['data' => '9.9.9.9', 'ttl' => 600], ['data' => '2.2.2.2', 'ttl' => 600]]);
    }

    public function test_other_providers_normalize_records(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/domains/example.com/records?*' => Http::response(['domain_records' => [
                ['id' => 1, 'type' => 'MX', 'name' => '@', 'data' => 'mail.example.com', 'priority' => 10, 'ttl' => 1800],
                ['id' => 2, 'type' => 'CAA', 'name' => '@', 'data' => 'letsencrypt.org', 'flags' => 0, 'tag' => 'issue', 'ttl' => 3600],
                ['id' => 3, 'type' => 'SOA', 'name' => '@', 'data' => '1800', 'ttl' => 1800],
            ], 'links' => []]),
            'api.digitalocean.com/v2/domains/example.com/records' => Http::response(['domain_record' => ['id' => 9]], 201),
            'dns.hetzner.com/api/v1/records?*' => Http::response(['records' => [['id' => 'h1', 'type' => 'MX', 'name' => '@', 'value' => '10 mail.example.com.', 'ttl' => 3600], ['id' => 'h2', 'type' => 'TXT', 'name' => '@', 'value' => '"hello world"']]]),
            'api.linode.com/v4/domains/7/records?*' => Http::response(['data' => [['id' => 5, 'type' => 'A', 'name' => '', 'target' => '1.2.3.4', 'ttl_sec' => 0, 'priority' => 0]], 'pages' => 1]),
            'api.vultr.com/v2/domains/example.com/records?*' => Http::response(['records' => [['id' => 'v1', 'type' => 'TXT', 'name' => '', 'data' => '"v=spf1 -all"', 'ttl' => 300, 'priority' => -1]], 'meta' => ['links' => ['next' => '']]]),
            'api.vultr.com/v2/domains/example.com/records' => Http::response(['record' => ['id' => 'v2']], 201),
            'api.porkbun.com/api/json/v3/dns/retrieve/example.com' => Http::response(['status' => 'SUCCESS', 'records' => [['id' => '77', 'name' => 'blog.example.com', 'type' => 'CNAME', 'content' => 'example.com', 'ttl' => '600', 'prio' => '0']]]),
            'api.porkbun.com/api/json/v3/dns/create/example.com' => Http::response(['status' => 'SUCCESS', 'id' => 78]),
        ]);
        $zone = ['id' => 'example.com', 'name' => 'example.com'];

        $do = new DigitalOceanProvider(['api_token' => 't']);
        $records = $do->records($zone);
        $this->assertCount(2, $records, 'SOA is hidden');
        $this->assertSame('0 issue "letsencrypt.org"', $records[1]['content']);
        $do->create($zone, ['type' => 'CNAME', 'name' => 'blog', 'content' => 'example.net', 'ttl' => 1, 'priority' => null, 'proxied' => null]);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'digitalocean') && $r->method() === 'POST' && $r['data'] === 'example.net.' && $r['ttl'] === 30);

        $hetzner = (new HetznerProvider(['api_token' => 't']))->records(['id' => 'z', 'name' => 'example.com']);
        $this->assertSame([10, 'mail.example.com'], [$hetzner[0]['priority'], $hetzner[0]['content']]);
        $this->assertSame('hello world', $hetzner[1]['content']);

        $this->assertSame('@', (new LinodeProvider(['api_token' => 't']))->records(['id' => '7', 'name' => 'example.com'])[0]['name']);
        $this->assertSame(3600, LinodeProvider::roundTtl(1000));

        $vultr = new VultrProvider(['api_key' => 't']);
        $this->assertSame('v=spf1 -all', $vultr->records($zone)[0]['content']);
        $vultr->create($zone, ['type' => 'TXT', 'name' => '@', 'content' => 'google-site-verification=abc', 'ttl' => 1, 'priority' => null, 'proxied' => null]);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'vultr') && $r->method() === 'POST' && $r['data'] === '"google-site-verification=abc"' && $r['name'] === '');

        $porkbun = new PorkbunProvider(['api_key' => 'pk1', 'secret_key' => 'sk1']);
        $this->assertSame('blog', $porkbun->records($zone)[0]['name']);
        $porkbun->create($zone, ['type' => 'MX', 'name' => '@', 'content' => 'mx.example.com', 'ttl' => 300, 'priority' => 20, 'proxied' => null]);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'porkbun.com/api/json/v3/dns/create') && $r['apikey'] === 'pk1' && $r['name'] === '' && $r['prio'] === '20' && $r['ttl'] === '600');
    }

    public function test_provider_accounts_are_verified_encrypted_and_admin_only(): void
    {
        $this->login();
        Http::fake([
            'api.digitalocean.com/v2/account' => Http::response(['account' => ['email' => 'ops@example.com']]),
            'api.digitalocean.com/v2/domains?*' => Http::response(['domains' => [['name' => 'example.com'], ['name' => 'shop.io']], 'links' => []]),
        ]);

        $this->postJson('/dns/providers', ['type' => 'namecheap', 'credentials' => ['api_user' => 'john']])->assertStatus(422)->assertJsonValidationErrors('credentials.api_key');
        $this->postJson('/dns/providers', ['type' => 'digitalocean', 'alias' => 'DO', 'credentials' => ['api_token' => 'dop_v1_secret']])->assertOk()->assertJsonPath('message', 'DNS API added: 2 domain(s) found');

        $provider = DnsProvider::query()->firstOrFail();
        $this->assertSame('ops@example.com', $provider->account);
        $this->assertStringNotContainsString('dop_v1_secret', DB::table('dns_providers')->value('credentials'));
        $this->assertStringNotContainsString('dop_v1_secret', json_encode($this->getJson('/dns/providers')->json()));
        $this->assertSame(['example.com', 'shop.io'], DnsZone::query()->orderBy('name')->pluck('name')->all());

        // blank secret on update keeps the stored token
        $this->putJson('/dns/providers/'.$provider->id, ['alias' => 'Droplets', 'credentials' => ['api_token' => '']])->assertOk();
        $this->assertSame('dop_v1_secret', $provider->fresh()->credentials['api_token']);

        Http::fake(['api.vultr.com/*' => Http::response(['error' => 'Invalid API token.', 'status' => 401], 401)]);
        $this->postJson('/dns/providers', ['type' => 'vultr', 'credentials' => ['api_key' => 'wrong']])->assertStatus(422)->assertJsonPath('message', 'Vultr: Invalid API token. (HTTP 401)');
        $this->assertSame(1, DnsProvider::query()->count());

        $operator = User::query()->create(['name' => 'Op', 'username' => 'op', 'password' => 'Secret12345', 'role' => 'operator', 'is_active' => true]);
        $this->actingAs($operator)->postJson('/dns/providers', ['type' => 'digitalocean', 'credentials' => ['api_token' => 'x']])->assertStatus(403);
        $this->actingAs($operator)->getJson('/dns/zones')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_record_validation_and_crud_endpoints(): void
    {
        $this->login();
        $zone = $this->cloudflareZone();
        $dns = app(DnsManager::class);

        $this->assertSame(['type' => 'CNAME', 'name' => 'blog', 'content' => 'example.net', 'ttl' => 1, 'priority' => null, 'proxied' => true],
            $dns->normalize($zone, ['type' => 'cname', 'name' => 'Blog.Example.com.', 'content' => 'Example.NET.', 'ttl' => 1, 'proxied' => '1']));
        $this->assertSame(10, $dns->normalize($zone, ['type' => 'MX', 'name' => '@', 'content' => 'mail.example.com'])['priority']);
        foreach ([['type' => 'A', 'content' => '999.1.1.1'], ['type' => 'AAAA', 'content' => '1.2.3.4'], ['type' => 'A', 'name' => 'bad name', 'content' => '1.1.1.1'], ['type' => 'SRV', 'content' => 'x'], ['type' => 'TXT', 'content' => "a\nb"]] as $bad) {
            try {
                $dns->normalize($zone, $bad);
                $this->fail('Accepted '.json_encode($bad));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone1/dns_records?*' => Http::response(['success' => true, 'result' => [], 'result_info' => ['total_pages' => 1]]),
            'api.cloudflare.com/client/v4/zones/zone1/dns_records' => Http::response(['success' => true, 'result' => ['id' => 'x']]),
            'api.cloudflare.com/client/v4/zones/zone1/dns_records/abc' => Http::response(['success' => true, 'result' => ['id' => 'abc']]),
        ]);
        $this->getJson('/dns/zones/'.$zone->id.'/records')->assertOk()->assertJsonPath('proxy', true)->assertJsonPath('zone.name', 'example.com');
        $this->postJson('/dns/zones/'.$zone->id.'/records', ['type' => 'A', 'name' => 'www', 'content' => 'nope'])->assertStatus(422)->assertJsonPath('message', 'Enter an IPv4 address.');
        $this->postJson('/dns/zones/'.$zone->id.'/records', ['type' => 'A', 'name' => 'www', 'content' => '203.0.113.5', 'ttl' => 300])->assertOk();
        $this->putJson('/dns/zones/'.$zone->id.'/records/abc', ['type' => 'A', 'name' => 'www', 'content' => '203.0.113.6', 'ttl' => 300])->assertOk();
        $this->deleteJson('/dns/zones/'.$zone->id.'/records/abc')->assertOk();
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/dns_records/abc'));

        $this->login('viewer');
        $this->postJson('/dns/zones/'.$zone->id.'/records', ['type' => 'A', 'name' => 'x', 'content' => '1.1.1.1'])->assertStatus(403);
    }

    public function test_point_to_server_and_website_creation(): void
    {
        $this->login();
        $zone = $this->cloudflareZone();
        $dns = app(DnsManager::class);
        $this->assertSame($zone->id, $dns->zoneFor('shop.example.com')?->id);
        $this->assertNull($dns->zoneFor('example.org'));

        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone1/dns_records?*' => Http::response(['success' => true, 'result' => [
                ['id' => 'apex', 'type' => 'A', 'name' => 'example.com', 'content' => '198.51.100.1', 'ttl' => 1, 'proxied' => true],
                ['id' => 'w', 'type' => 'CNAME', 'name' => 'www.example.com', 'content' => 'example.com', 'ttl' => 1, 'proxied' => true],
            ], 'result_info' => ['total_pages' => 1]]),
            'api.cloudflare.com/client/v4/zones/zone1/dns_records/apex' => Http::response(['success' => true, 'result' => []]),
            'api.cloudflare.com/client/v4/zones/zone1/dns_records' => Http::response(['success' => true, 'result' => []]),
        ]);

        $messages = $dns->pointToServer(['example.com', 'www.example.com', 'blog.example.com', 'other.org'], false);
        $this->assertSame([
            'other.org: no DNS zone managed by the panel',
            'example.com: A changed from 198.51.100.1 to 127.0.0.1',
            'www.example.com: has a CNAME record, left unchanged',
            'blog.example.com: A 127.0.0.1 created in Main (Cloudflare)',
        ], $messages);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['content'] === '127.0.0.1' && $r['proxied'] === true);

        $this->postJson('/websites', ['domain' => 'shop.example.com', 'php_version' => '', 'create_dns' => 1])->assertOk()
            ->assertJsonPath('dns.0', 'shop.example.com: A 127.0.0.1 created in Main (Cloudflare)');
        $this->getJson('/dns/match?domain=shop.example.com')->assertJsonPath('zone.name', 'example.com');
    }

    public function test_wildcard_ssl_uses_the_dns_hook_and_challenge_command(): void
    {
        $this->login();
        $zone = $this->cloudflareZone();
        $site = Website::query()->create(['domain' => 'example.com', 'aliases' => 'www.example.com example.org', 'root_path' => '/www/wwwroot/example.com']);

        $this->postJson('/websites/'.$site->id.'/ssl/letsencrypt', ['email' => 'admin@example.com', 'method' => 'dns', 'wildcard' => 1])->assertOk();
        $script = Task::query()->latest('id')->value('script');
        $this->assertStringContainsString("--manual --preferred-challenges dns --manual-auth-hook '".SslManager::DNS_HOOK." auth'", $script);
        $this->assertStringContainsString("-d 'example.com' -d '*.example.com' --non-interactive", $script);
        $this->assertStringNotContainsString("-d 'www.example.com'", $script, 'covered by the wildcard');
        $this->assertStringContainsString('skipping aliases outside the managed DNS zones: example.org', $script);

        $other = Website::query()->create(['domain' => 'example.org', 'root_path' => '/www/wwwroot/example.org']);
        $this->postJson('/websites/'.$other->id.'/ssl/letsencrypt', ['email' => 'admin@example.com', 'method' => 'dns'])->assertStatus(422);

        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone1/dns_records' => Http::response(['success' => true, 'result' => ['id' => 't1']]),
            'api.cloudflare.com/client/v4/zones/zone1/dns_records?*' => Http::response(['success' => true, 'result' => [
                ['id' => 't1', 'type' => 'TXT', 'name' => '_acme-challenge.example.com', 'content' => '"token-123"', 'ttl' => 60],
                ['id' => 't2', 'type' => 'TXT', 'name' => '_acme-challenge.example.com', 'content' => '"other"', 'ttl' => 60],
            ], 'result_info' => ['total_pages' => 1]]),
            'api.cloudflare.com/client/v4/zones/zone1/dns_records/t1' => Http::response(['success' => true, 'result' => []]),
            'cloudflare-dns.com/*' => Http::response(['Answer' => [['data' => '"token-123"']]]),
            'dns.google/*' => Http::response(['Answer' => [['data' => '"token-123"']]]),
        ]);

        $this->assertSame(0, Artisan::call('gbx:dns-challenge', ['action' => 'auth', '--domain' => '*.example.com', '--value' => 'token-123', '--wait' => 0]));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['type'] === 'TXT' && $r['name'] === '_acme-challenge.example.com' && $r['content'] === '"token-123"');

        $this->assertSame(0, Artisan::call('gbx:dns-challenge', ['action' => 'cleanup', '--domain' => 'example.com', '--value' => 'token-123']));
        $this->assertStringContainsString('Removed 1 TXT record(s)', Artisan::output());
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/t2'));

        $this->assertTrue(app(DnsManager::class)->txtVisible('_acme-challenge.example.com', 'token-123'));
    }
}
