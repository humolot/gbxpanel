<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            // the site is stopped automatically after this date (null = perpetual)
            $table->date('expires_at')->nullable()->after('force_https');
            // per-site options rendered into the vhost: running directory, default documents,
            // access rules, redirects, reverse proxies, hotlink protection, maintenance, git
            $table->json('settings')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['expires_at', 'settings']);
        });
    }
};
