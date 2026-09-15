<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'admin', bool $twoFactor = false): array
    {
        $user = User::query()->create(['name' => 'Admin', 'username' => $role.'user', 'password' => 'Secret12345', 'role' => $role, 'is_active' => true]);
        $codes = [];
        if ($twoFactor) {
            [$codes, $hashes] = TwoFactor::makeRecoveryCodes();
            $user->forceFill(['two_factor_secret' => TwoFactor::generateSecret(), 'two_factor_recovery_codes' => $hashes, 'two_factor_confirmed_at' => now()])->save();
        }

        $this->get('/testentry'); // pass the security entrance

        return [$user->fresh(), $codes];
    }

    public function test_rfc6238_vectors_and_base32(): void
    {
        $secret = TwoFactor::base32Encode('12345678901234567890');
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
        $this->assertSame('12345678901234567890', TwoFactor::base32Decode(strtolower($secret)));

        // last six digits of the SHA-1 vectors in RFC 6238 appendix B
        $this->assertSame('287082', TwoFactor::code($secret, TwoFactor::step(59)));
        $this->assertSame('081804', TwoFactor::code($secret, TwoFactor::step(1111111109)));
        $this->assertSame('005924', TwoFactor::code($secret, TwoFactor::step(1234567890)));
        $this->assertSame('279037', TwoFactor::code($secret, TwoFactor::step(2000000000)));

        $step = TwoFactor::step(1234567890);
        $this->assertSame($step, TwoFactor::verify($secret, '005 924', null, 1234567890));
        $this->assertSame($step - 1, TwoFactor::verify($secret, TwoFactor::code($secret, $step - 1), null, 1234567890), 'one period of clock drift is accepted');
        $this->assertNull(TwoFactor::verify($secret, TwoFactor::code($secret, $step - 3), null, 1234567890));
        $this->assertNull(TwoFactor::verify($secret, '005924', $step, 1234567890), 'a code cannot be used twice');
        $this->assertNull(TwoFactor::verify($secret, 'abcdef'));
    }

    public function test_login_requires_the_code_when_enabled(): void
    {
        [$user] = $this->makeUser('admin', true);

        $this->post('/login', ['username' => $user->username, 'password' => 'Secret12345'])->assertRedirect('/login/two-factor');
        $this->assertGuest();
        $this->get('/')->assertRedirect('/login');
        $this->get('/login/two-factor')->assertOk()->assertSee('Two-factor authentication');

        $this->post('/login/two-factor', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();

        $code = TwoFactor::code($user->two_factor_secret, TwoFactor::step());
        $this->post('/login/two-factor', ['code' => $code, 'trust' => 1])->assertRedirect('/')->assertCookie(TwoFactor::TRUST_COOKIE);
        $this->assertAuthenticatedAs($user);
        $this->assertSame(TwoFactor::step(), $user->fresh()->two_factor_last_step);
    }

    public function test_code_replay_and_brute_force_are_blocked(): void
    {
        [$user] = $this->makeUser('admin', true);
        $code = TwoFactor::code($user->two_factor_secret, TwoFactor::step());
        $user->forceFill(['two_factor_last_step' => TwoFactor::step()])->save();

        $this->post('/login', ['username' => $user->username, 'password' => 'Secret12345']);
        $this->post('/login/two-factor', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();

        for ($i = 0; $i < 4; $i++) {
            $this->post('/login/two-factor', ['code' => '111111']);
        }
        $this->post('/login/two-factor', ['code' => '111111'])->assertRedirect('/login');
        $this->get('/login/two-factor')->assertRedirect('/login');
    }

    public function test_recovery_codes_work_once(): void
    {
        [$user, $codes] = $this->makeUser('admin', true);

        $this->post('/login', ['username' => $user->username, 'password' => 'Secret12345']);
        $this->post('/login/two-factor', ['recovery_code' => strtoupper($codes[0])])->assertRedirect('/')->assertSessionHas('warning');
        $this->assertAuthenticatedAs($user);
        $this->assertCount(9, $user->fresh()->two_factor_recovery_codes);

        $this->post('/logout');
        $this->get('/testentry');
        $this->post('/login', ['username' => $user->username, 'password' => 'Secret12345']);
        $this->post('/login/two-factor', ['recovery_code' => $codes[0]])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_trusted_browser_skips_the_code_until_2fa_is_reset(): void
    {
        [$user] = $this->makeUser('admin', true);
        $token = TwoFactor::trustToken($user);

        $this->withCookie(TwoFactor::TRUST_COOKIE, $token)->post('/login', ['username' => $user->username, 'password' => 'Secret12345'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);

        $this->assertTrue(TwoFactor::trusted($user, $token));
        $this->assertFalse(TwoFactor::trusted($user, $token.'x'));
        $user->forceFill(['two_factor_secret' => TwoFactor::generateSecret()])->save();
        $this->assertFalse(TwoFactor::trusted($user->fresh(), $token), 'a new secret revokes trusted browsers');
    }

    public function test_enrollment_flow_with_password_and_code(): void
    {
        [$user] = $this->makeUser();
        $this->actingAs($user);
        $this->get('/account/security')->assertOk()->assertSee('Set up authenticator');

        $this->postJson('/account/two-factor/setup', ['password' => 'wrong'])->assertStatus(422);
        $setup = $this->postJson('/account/two-factor/setup', ['password' => 'Secret12345'])->assertOk()->json();
        $this->assertStringStartsWith('otpauth://totp/GBX%20Panel:adminuser%40', $setup['uri']);
        $secret = str_replace(' ', '', $setup['secret']);
        $this->assertStringContainsString('secret='.$secret, $setup['uri']);
        $this->assertFalse($user->fresh()->hasTwoFactor(), 'nothing is saved before confirmation');

        $this->postJson('/account/two-factor/confirm', ['code' => '123456'])->assertStatus(422);
        $codes = $this->postJson('/account/two-factor/confirm', ['code' => TwoFactor::code($secret, TwoFactor::step())])->assertOk()->json('recovery_codes');
        $this->assertCount(10, $codes);
        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasTwoFactor());
        $this->assertNotSame($secret, DB::table('users')->where('id', $user->id)->value('two_factor_secret'), 'secret is encrypted at rest');
        $this->assertStringNotContainsString($codes[0], (string) DB::table('users')->where('id', $user->id)->value('two_factor_recovery_codes'));

        $new = $this->postJson('/account/two-factor/recovery-codes', ['password' => 'Secret12345'])->assertOk()->json('recovery_codes');
        $this->assertNotSame($codes, $new);
        $this->assertFalse(TwoFactor::useRecoveryCode($fresh->fresh(), $codes[0]));

        $this->postJson('/account/two-factor/disable', ['password' => 'Secret12345', 'code' => '000000'])->assertStatus(422);
        $this->postJson('/account/two-factor/disable', ['password' => 'Secret12345', 'code' => $new[1]])->assertOk();
        $this->assertFalse($user->fresh()->hasTwoFactor());
    }

    public function test_policy_forces_enrollment_and_admin_reset(): void
    {
        [$admin] = $this->makeUser('admin', true);
        [$viewer] = $this->makeUser('viewer');

        $this->actingAs($admin)->postJson('/accounts/two-factor-policy', ['required' => 1])->assertOk();
        $this->assertTrue(TwoFactor::required());

        $this->actingAs($viewer);
        $this->get('/websites')->assertRedirect('/account/security');
        $this->getJson('/docker/overview')->assertStatus(403)->assertJsonPath('redirect', route('account.security'));
        $this->get('/account/security')->assertOk()->assertSee('requires two-factor authentication');
        $this->postJson('/account/two-factor/setup', ['password' => 'Secret12345'])->assertOk();

        // admins can reset a lost device
        $this->actingAs($admin)->postJson('/accounts/'.$admin->id.'/two-factor-reset')->assertOk();
        $this->assertFalse($admin->fresh()->hasTwoFactor());
        $this->actingAs($viewer)->postJson('/accounts/'.$admin->id.'/two-factor-reset')->assertStatus(403);

        // the policy cannot be enabled by an admin without 2FA
        Setting::put('two_factor_required', false);
        $this->actingAs($admin->fresh())->postJson('/accounts/two-factor-policy', ['required' => 1])->assertStatus(422);
    }

    public function test_cli_disables_two_factor(): void
    {
        [$user] = $this->makeUser('admin', true);
        Artisan::call('gbx:user', ['action' => 'two-factor-off', '--user' => $user->username]);
        $this->assertStringContainsString('disabled for adminuser', Artisan::output());
        $this->assertFalse($user->fresh()->hasTwoFactor());
    }
}
