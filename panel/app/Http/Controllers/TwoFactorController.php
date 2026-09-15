<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Account security: two-factor authentication of the signed-in user. */
class TwoFactorController extends Controller
{
    /** Account whose two-factor authentication is managed (panel user here, client in the sub-panel). */
    protected function account(Request $request): \Illuminate\Database\Eloquent\Model
    {
        return $request->user();
    }

    /** View data that differs between the panel and the client sub-panel. */
    protected function viewContext(): array
    {
        return ['layout' => 'layouts.app', 'routePrefix' => '', 'required' => TwoFactor::required(), 'lostHint' => null];
    }

    protected function isRequired(): bool
    {
        return TwoFactor::required();
    }

    /** Sign out other sessions of the account after enabling two-factor authentication. */
    protected function endOtherSessions(\Illuminate\Database\Eloquent\Model $account, Request $request): void
    {
        DB::table('sessions')->where('user_id', $account->id)->where('id', '!=', $request->session()->getId())->delete();
    }

    public function show(Request $request)
    {
        $user = $this->account($request);

        return view('account.security', $this->viewContext() + [
            'user' => $user,
            'enabled' => $user->hasTwoFactor(),
            'recoveryLeft' => count($user->two_factor_recovery_codes ?? []),
            'trustDays' => TwoFactor::TRUST_DAYS,
        ]);
    }

    protected function checkPassword(Request $request): ?\Illuminate\Http\JsonResponse
    {
        $request->validate(['password' => ['required', 'string', 'max:255']]);

        return Hash::check((string) $request->input('password'), $this->account($request)->password) ? null : $this->fail('Current password is incorrect.');
    }

    /** Step 1: a new secret kept in the session until a code from the app confirms it. */
    public function setup(Request $request)
    {
        if ($this->account($request)->hasTwoFactor()) {
            return $this->fail('Two-factor authentication is already enabled. Disable it first to use another device.');
        }
        if ($error = $this->checkPassword($request)) {
            return $error;
        }
        $secret = TwoFactor::generateSecret();
        $request->session()->put('gbx_2fa_setup', ['secret' => $secret, 'expires' => time() + 900]);

        return $this->ok('ok', [
            'secret' => trim(chunk_split($secret, 4, ' ')),
            'uri' => TwoFactor::uri($this->account($request), $secret),
            'issuer' => TwoFactor::issuer(),
            'account' => $this->account($request)->username,
        ]);
    }

    /** Step 2: confirm with a code, enable and show the recovery codes once. */
    public function confirm(Request $request)
    {
        $request->validate(['code' => ['required', 'string', 'max:20']]);
        $pending = $request->session()->get('gbx_2fa_setup');
        if (! is_array($pending) || ($pending['expires'] ?? 0) < time()) {
            return $this->fail('The setup expired. Start again.');
        }
        $step = TwoFactor::verify($pending['secret'], (string) $request->input('code'));
        if ($step === null) {
            return $this->fail('The code is not valid. Check that the time of your phone is automatic and try the current code.');
        }

        [$plain, $hashes] = TwoFactor::makeRecoveryCodes();
        $user = $this->account($request);
        $user->forceFill([
            'two_factor_secret' => $pending['secret'],
            'two_factor_recovery_codes' => $hashes,
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => $step,
        ])->save();
        $request->session()->forget('gbx_2fa_setup');

        // other sessions of the account must sign in again with the second factor
        $this->endOtherSessions($user, $request);
        $this->audit('auth', 'Enabled two-factor authentication');

        return $this->ok('Two-factor authentication enabled', ['recovery_codes' => $plain]);
    }

    public function recoveryCodes(Request $request)
    {
        if (! $this->account($request)->hasTwoFactor()) {
            return $this->fail('Two-factor authentication is not enabled.');
        }
        if ($error = $this->checkPassword($request)) {
            return $error;
        }
        [$plain, $hashes] = TwoFactor::makeRecoveryCodes();
        $this->account($request)->forceFill(['two_factor_recovery_codes' => $hashes])->save();
        $this->audit('auth', 'Generated new two-factor recovery codes');

        return $this->ok('New recovery codes generated. The previous codes no longer work.', ['recovery_codes' => $plain]);
    }

    public function disable(Request $request)
    {
        $user = $this->account($request);
        if (! $user->hasTwoFactor()) {
            return $this->fail('Two-factor authentication is not enabled.');
        }
        if ($this->isRequired()) {
            return $this->fail('Two-factor authentication is required for every account by the administrator.');
        }
        if ($error = $this->checkPassword($request)) {
            return $error;
        }
        $request->validate(['code' => ['required', 'string', 'max:32']]);
        $code = (string) $request->input('code');
        $valid = TwoFactor::verify((string) $user->two_factor_secret, $code, $user->two_factor_last_step) !== null || TwoFactor::useRecoveryCode($user, $code);
        if (! $valid) {
            return $this->fail('Invalid authentication or recovery code.');
        }

        $user->disableTwoFactor();
        Cookie::queue(Cookie::forget(TwoFactor::TRUST_COOKIE));
        $this->audit('auth', 'Disabled two-factor authentication');

        return $this->ok('Two-factor authentication disabled');
    }

    public function forgetBrowser()
    {
        Cookie::queue(Cookie::forget(TwoFactor::TRUST_COOKIE));

        return $this->ok('This browser will ask for a code at the next sign-in');
    }

    /* ================================================================== admins */

    public function reset(User $user)
    {
        if (! $user->hasTwoFactor()) {
            return $this->fail('This account does not use two-factor authentication.');
        }
        $user->disableTwoFactor();
        DB::table('sessions')->where('user_id', $user->id)->when($user->is(auth()->user()), fn ($q) => $q->where('id', '!=', session()->getId()))->delete();
        $this->audit('account', "Reset two-factor authentication of {$user->username}");

        return $this->ok("Two-factor authentication of {$user->username} was reset");
    }

    public function policy(Request $request)
    {
        $required = $request->boolean('required');
        if ($required && ! $this->account($request)->hasTwoFactor()) {
            return $this->fail('Enable two-factor authentication on your own account before requiring it.');
        }
        \App\Models\Setting::put('two_factor_required', $required);
        $this->audit('account', $required ? 'Required two-factor authentication for every account' : 'Made two-factor authentication optional');

        return $this->ok($required ? 'Accounts without two-factor authentication must enable it at their next request' : 'Two-factor authentication is optional');
    }
}
