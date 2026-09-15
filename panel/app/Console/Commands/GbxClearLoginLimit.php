<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class GbxClearLoginLimit extends Command
{
    protected $signature = 'gbx:clear-login-limit';

    protected $description = 'Remove login lockouts caused by too many failed attempts';

    public function handle(): int
    {
        // login attempts (RateLimiter) and the route throttle live in the cache store
        Cache::flush();
        $this->info('Login limits cleared. Blocked users and IPs can sign in again.');

        return self::SUCCESS;
    }
}
