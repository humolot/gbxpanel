<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            // e.g. http://127.0.0.1:3000 for Node.js / Docker apps behind Apache
            $table->string('proxy_target')->nullable()->after('php_version');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn('proxy_target');
        });
    }
};
