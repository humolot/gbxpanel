<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\User;
use App\Services\Clients\ClientContext;
use App\Services\Clients\ClientManager;
use App\Services\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/** Sign-in of the client sub-panel (guard "client"), with two-factor authentication. */
class ClientAuthController extends Controller
{
    protected const CHALLENGE_TTL = 300;

    protected function title(): string
    {
        return ClientManager::settings()['client_portal_title'];
    }

    public function show()
    {
        return view('auth.login', [
            'title' => $this->title(),
            'subtitle' => 'Client area',
            'action' => route('client.login.attempt'),
            'forgotHint' => 'Forgot the password? Contact your hosting provider.',
        ]);
    }

    public function login(Request $request)
    {
        $data = $request->validate(['username' => ['required', 'string', 'max:64'], 'password' => ['required', 'string', 'max:255']]);

        $key = 'client-login:'.strtolower($data['username']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['username' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }

        $client = Client::query()->where('username', $data['username'])->first();
        if (! $client || ! Auth::guard('client')->validate($data)) {
            RateLimiter::hit($key, 300);
            ActivityLog::query()->create(['category' => 'client', 'action' => 'Failed client login for "'.mb_substr($data['username'], 0, 64).'"', 'ip' => $request->ip(), 'client_id' => $client?->id]);

            throw ValidationException::withMessages(['username' => 'Invalid username or password.']);
        }
        RateLimiter::clear($key);

        if (! $client->isActive()) {
            throw ValidationException::withMessages(['username' => 'Your account is suspended'.($client->suspended_reason ? ': '.$client->suspended_reason : '').'. Contact support.']);
        }

        if ($client->hasTwoFactor() && ! TwoFactor::trusted($client, $request->cookie(TwoFactor::TRUST_COOKIE.'_client'))) {
            $request->session()->regenerate();
            $request->session()->put('gbx_client_2fa', ['id' => $client->id, 'remember' => $request->boolean('remember'), 'expires' => time() + self::CHALLENGE_TTL]);

            return redirect()->route('client.login.two-factor');
        }

        return $this->complete($request, $client, $request->boolean('remember'), 'Client signed in');
    }

    protected function pending(Request $request): ?Client
    {
        $pending = $request->session()->get('gbx_client_2fa');
        if (! is_array($pending) || ($pending['expires'] ?? 0) < time()) {
            $request->session()->forget('gbx_client_2fa');

            return null;
        }
        $client = Client::query()->find($pending['id'] ?? 0);

        return $client && $client->isActive() && $client->hasTwoFactor() ? $client : null;
    }

    public function twoFactor(Request $request)
    {
        $client = $this->pending($request);
        if (! $client) {
            return redirect()->route('client.login')->withErrors(['username' => 'The sign-in expired. Enter your password again.']);
        }

        return view('auth.two-factor', [
            'title' => $this->title(),
            'user' => $client,
            'trustDays' => TwoFactor::TRUST_DAYS,
            'action' => route('client.login.two-factor.verify'),
            'cancel' => route('client.login'),
            'lostHint' => 'Lost the phone and the recovery codes? Contact your hosting provider.',
        ]);
    }

    public function verifyTwoFactor(Request $request)
    {
        $client = $this->pending($request);
        if (! $client) {
            return redirect()->route('client.login')->withErrors(['username' => 'The sign-in expired. Enter your password again.']);
        }
        $data = $request->validate(['code' => ['nullable', 'string', 'max:20'], 'recovery_code' => ['nullable', 'string', 'max:32']]);

        $key = 'client-2fa:'.$client->id.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $request->session()->forget('gbx_client_2fa');

            return redirect()->route('client.login')->withErrors(['username' => 'Too many invalid codes. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }

        $ok = false;
        if (! empty($data['recovery_code'])) {
            $ok = TwoFactor::useRecoveryCode($client, $data['recovery_code']);
        } elseif (! empty($data['code'])) {
            $step = TwoFactor::verify((string) $client->two_factor_secret, $data['code'], $client->two_factor_last_step);
            if ($step !== null) {
                $client->forceFill(['two_factor_last_step' => $step])->save();
                $ok = true;
            }
        }
        if (! $ok) {
            RateLimiter::hit($key, 300);

            return back()->withErrors(['code' => empty($data['recovery_code']) ? 'Invalid or expired authentication code.' : 'Invalid recovery code.'])->withInput(['mode' => empty($data['recovery_code']) ? 'code' : 'recovery']);
        }
        RateLimiter::clear($key);

        $remember = (bool) ($request->session()->get('gbx_client_2fa')['remember'] ?? false);
        $request->session()->forget('gbx_client_2fa');
        if ($request->boolean('trust')) {
            Cookie::queue(TwoFactor::TRUST_COOKIE.'_client', TwoFactor::trustToken($client), TwoFactor::TRUST_DAYS * 1440, null, null, $request->secure(), true, false, 'lax');
        }

        return $this->complete($request, $client, $remember, 'Client signed in with two-factor authentication');
    }

    protected function complete(Request $request, Client $client, bool $remember, string $event)
    {
        Auth::guard('client')->login($client, $remember);
        $request->session()->regenerate();
        $request->session()->forget('client_impersonator');
        $client->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();

        ClientContext::set($client);
        ActivityLog::record('client', $event);
        ClientContext::set(null);

        return redirect()->intended(route('client.home'));
    }

    public function logout(Request $request)
    {
        $client = Auth::guard('client')->user();
        $impersonator = $request->session()->pull('client_impersonator');
        Auth::guard('client')->logout();

        if ($impersonator && Auth::guard('web')->check()) {
            // back to the administrator panel; the admin session stays valid
            return redirect()->route('clients.index');
        }
        if ($client) {
            ClientContext::set($client);
            ActivityLog::record('client', 'Client signed out');
            ClientContext::set(null);
        }
        if (! Auth::guard('web')->check()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()->route('client.login');
    }

    /** Administrator opens the sub-panel as the client. */
    public function impersonate(Request $request, Client $client)
    {
        /** @var User $admin */
        $admin = $request->user();
        Auth::guard('client')->login($client);
        $request->session()->put('client_impersonator', $admin->id);
        $this->audit('client', "Opened the client panel as {$client->username}");

        return redirect()->route('client.home');
    }
}
