<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
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
        if (! $user || ! $user->is_active || ! Auth::attempt(['username' => $data['username'], 'password' => $data['password']], $request->boolean('remember'))) {
            RateLimiter::hit($key, 300);
            ActivityLog::query()->create(['category' => 'auth', 'action' => 'Failed login for "'.mb_substr($data['username'], 0, 64).'"', 'ip' => $request->ip()]);

            throw ValidationException::withMessages(['username' => 'Invalid username or password.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('gbx_entry', true);

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        ActivityLog::record('auth', 'Signed in');

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
