<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GbxUser extends Command
{
    protected $signature = 'gbx:user
        {action : password|username|verify|list}
        {--user= : Existing username (defaults to the first administrator)}
        {--value= : New password or username (random password when omitted)}';

    protected $description = 'Reset panel credentials from the command line';

    public function handle(): int
    {
        if ($this->argument('action') === 'list') {
            $this->table(['ID', 'Username', 'Name', 'Role', 'Active', 'Last login'], User::query()->get()->map(fn ($u) => [$u->id, $u->username, $u->name, $u->role, $u->is_active ? 'yes' : 'no', $u->last_login_at]));

            return self::SUCCESS;
        }

        $user = $this->option('user')
            ? User::query()->where('username', $this->option('user'))->first()
            : User::query()->where('role', 'admin')->orderBy('id')->first();

        if (! $user) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        switch ($this->argument('action')) {
            case 'verify':
                // exit code 0 when --value is still the user's password (used by "gbx default")
                $ok = \Illuminate\Support\Facades\Hash::check((string) $this->option('value'), $user->password);
                $this->line($ok ? 'match' : 'changed');

                return $ok ? self::SUCCESS : self::FAILURE;

            case 'password':
                $password = $this->option('value') ?: Str::password(16, symbols: false);
                $user->update(['password' => $password, 'is_active' => true]);
                \Illuminate\Support\Facades\DB::table('sessions')->where('user_id', $user->id)->delete();
                $this->line("Username: {$user->username}");
                $this->line("Password: {$password}");
                break;

            case 'username':
                $value = (string) $this->option('value');
                if (! preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $value) || User::query()->where('username', $value)->exists()) {
                    $this->error('Invalid or already used username.');

                    return self::FAILURE;
                }
                $user->update(['username' => $value]);
                $this->line("Username changed to: {$value}");
                break;

            default:
                $this->error('Unknown action.');

                return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
