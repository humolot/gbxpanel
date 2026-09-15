<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Seconds a password check stays valid while the user enters the authenticator code. */
    protected const CHALLENGE_TTL = 300;

    public function show()
    {
        return view('auth.login', ['title' => Setting::get('panel_title', 'GBX Panel')]);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $key = 'login:'.strtolower($data['username']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['username' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }

        $user = User::query()->where('username', $data['username'])->first();
        if (! $user || ! $user->is_active || ! Auth::validate(['username' => $data['username'], 'password' => $data['password']])) {
            RateLimiter::hit($key, 300);
            ActivityLog::query()->create(['category' => 'auth', 'action' => 'Failed login for "'.mb_substr($data['username'], 0, 64).'"', 'ip' => $request->ip()]);

            throw ValidationException::withMessages(['username' => 'Invalid username or password.']);
        }
        RateLimiter::clear($key);

        if ($user->hasTwoFactor() && ! TwoFactor::trusted($user, $request->cookie(TwoFactor::TRUST_COOKIE))) {
            $request->session()->regenerate();
            $request->session()->put('gbx_2fa', ['id' => $user->id, 'remember' => $request->boolean('remember'), 'expires' => time() + self::CHALLENGE_TTL]);

            return redirect()->route('login.two-factor');
        }

        return $this->completeLogin($request, $user, $request->boolean('remember'), $user->hasTwoFactor() ? 'Signed in (trusted browser)' : 'Signed in');
    }

    protected function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get('gbx_2fa');
        if (! is_array($pending) || ($pending['expires'] ?? 0) < time()) {
            $request->session()->forget('gbx_2fa');

            return null;
        }
        $user = User::query()->find($pending['id'] ?? 0);

        return $user && $user->is_active && $user->hasTwoFactor() ? $user : null;
    }

    public function twoFactor(Request $request)
    {
        $user = $this->pendingUser($request);
        if (! $user) {
            return redirect()->route('login')->withErrors(['username' => 'The sign-in expired. Enter your password again.']);
        }

        return view('auth.two-factor', [
            'title' => Setting::get('panel_title', 'GBX Panel'),
            'user' => $user,
            'trustDays' => TwoFactor::TRUST_DAYS,
        ]);
    }

    public function verifyTwoFactor(Request $request)
    {
        $user = $this->pendingUser($request);
        if (! $user) {
            return redirect()->route('login')->withErrors(['username' => 'The sign-in expired. Enter your password again.']);
        }
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:20'],
            'recovery_code' => ['nullable', 'string', 'max:32'],
        ]);

        $key = '2fa:'.$user->id.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $request->session()->forget('gbx_2fa');

            return redirect()->route('login')->withErrors(['username' => 'Too many invalid codes. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }

        $method = null;
        if (! empty($data['recovery_code'])) {
            if (TwoFactor::useRecoveryCode($user, $data['recovery_code'])) {
                $method = 'recovery code';
            }
        } elseif (! empty($data['code'])) {
            $step = TwoFactor::verify((string) $user->two_factor_secret, $data['code'], $user->two_factor_last_step);
            if ($step !== null) {
                $user->forceFill(['two_factor_last_step' => $step])->save();
                $method = 'authenticator';
            }
        }

        if (! $method) {
            RateLimiter::hit($key, 300);
            ActivityLog::query()->create(['user_id' => $user->id, 'category' => 'auth', 'action' => 'Invalid two-factor code', 'ip' => $request->ip()]);

            return back()->withErrors(['code' => empty($data['recovery_code']) ? 'Invalid or expired authentication code.' : 'Invalid recovery code.'])->withInput(['mode' => empty($data['recovery_code']) ? 'code' : 'recovery']);
        }
        RateLimiter::clear($key);

        $remember = (bool) ($request->session()->get('gbx_2fa')['remember'] ?? false);
        $request->session()->forget('gbx_2fa');
        if ($request->boolean('trust')) {
            Cookie::queue(TwoFactor::TRUST_COOKIE, TwoFactor::trustToken($user), TwoFactor::TRUST_DAYS * 1440, null, null, $request->secure(), true, false, 'lax');
        }

        $left = count($user->two_factor_recovery_codes ?? []);
        $response = $this->completeLogin($request, $user, $remember, 'Signed in with '.$method);
        if ($method === 'recovery code') {
            $response->with('warning', "You signed in with a recovery code. {$left} code(s) left: generate new ones in Two-factor authentication.");
        }

        return $response;
    }

    protected function completeLogin(Request $request, User $user, bool $remember, string $event)
    {
        Auth::login($user, $remember);
        $request->session()->regenerate();
        $request->session()->put('gbx_entry', true);

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        ActivityLog::record('auth', $event);

        return redirect()->intended(route('home'));
    }

    public function logout(Request $request)
    {
        ActivityLog::record('auth', 'Signed out');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $entry = trim((string) config('gbx.entry'), '/');

        return redirect($entry !== '' ? '/'.$entry : '/login');
    }
}
