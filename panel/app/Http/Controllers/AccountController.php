<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AccountController extends Controller
{
    public function index()
    {
        return view('accounts.index', [
            'users' => User::query()->orderBy('id')->get(),
            'roles' => User::ROLES,
            'logins' => ActivityLog::query()->with('user:id,username')->where('category', 'auth')->latest('id')->limit(30)->get(),
            'sessions' => DB::table('sessions')->whereNotNull('user_id')->orderByDesc('last_activity')->limit(20)->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'regex:/^[a-zA-Z0-9_.-]{3,32}$/', 'unique:users,username'],
            'email' => ['nullable', 'email', 'max:190'],
            'password' => ['required', 'string', Password::min(10)->letters()->numbers()],
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
        ]);

        $user = User::query()->create($data + ['is_active' => true]);
        $this->audit('account', "Created account {$user->username}", 'role '.$user->role);

        return $this->ok('Account created');
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:190'],
            'password' => ['nullable', 'string', Password::min(10)->letters()->numbers()],
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active');

        if ($user->is(auth()->user()) && ($data['role'] !== 'admin' || ! $data['is_active'])) {
            return $this->fail('You cannot demote or disable your own account.');
        }
        if ($user->isAdmin() && ($data['role'] !== 'admin' || ! $data['is_active']) && User::query()->where('role', 'admin')->where('is_active', true)->count() <= 1) {
            return $this->fail('At least one active administrator is required.');
        }
        if (empty($data['password'])) {
            unset($data['password']);
        }

        $user->update($data);
        if (! $user->is_active || isset($data['password'])) {
            DB::table('sessions')->where('user_id', $user->id)->when($user->is(auth()->user()), fn ($q) => $q->where('id', '!=', session()->getId()))->delete();
        }
        $this->audit('account', "Updated account {$user->username}");

        return $this->ok('Account updated');
    }

    public function destroy(User $user)
    {
        if ($user->is(auth()->user())) {
            return $this->fail('You cannot delete your own account.');
        }
        if ($user->isAdmin() && User::query()->where('role', 'admin')->count() <= 1) {
            return $this->fail('At least one administrator is required.');
        }
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $this->audit('account', "Deleted account {$user->username}");
        $user->delete();

        return $this->ok('Account deleted');
    }

    public function password(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);

        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            return $this->fail('Current password is incorrect.');
        }
        $user->update(['password' => $data['password']]);
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', session()->getId())->delete();
        $this->audit('account', 'Changed own password');

        return $this->ok('Password changed');
    }
}
