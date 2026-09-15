<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Resources that can belong to a client. */
    protected const OWNED = ['websites', 'databases', 'ftp_accounts', 'cron_jobs', 'dns_zones'];

    public function up(): void
    {
        Schema::create('client_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // 0 means unlimited
            $table->unsignedInteger('max_websites')->default(1);
            $table->unsignedInteger('max_databases')->default(1);
            $table->unsignedInteger('max_ftp')->default(1);
            $table->unsignedInteger('disk_mb')->default(1024);
            $table->unsignedInteger('bandwidth_mb')->default(10240); // per calendar month
            $table->json('php_versions')->nullable();                // null: every installed version
            $table->boolean('allow_ssl')->default(true);
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password');
            $table->foreignId('package_id')->nullable()->constrained('client_packages')->nullOnDelete();
            $table->string('status', 20)->default('active'); // active, suspended
            $table->string('suspended_reason')->nullable();
            $table->json('suspended_state')->nullable(); // websites and FTP accounts stopped by the suspension
            $table->date('expires_at')->nullable();
            $table->string('notes')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->unsignedBigInteger('two_factor_last_step')->nullable();
            $table->unsignedBigInteger('disk_used')->default(0);      // bytes
            $table->unsignedBigInteger('bandwidth_used')->default(0); // bytes in the current month
            $table->timestamp('usage_updated_at')->nullable();
            // isolation (option A): Linux user and PHP-FPM pool of the client
            $table->string('system_user', 32)->nullable()->unique();
            $table->boolean('isolated')->default(false);
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        // daily usage history for the charts
        Schema::create('client_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->date('day');
            $table->unsignedBigInteger('bandwidth')->default(0);
            $table->unsignedBigInteger('requests')->default(0);
            $table->unsignedBigInteger('disk')->nullable();
            $table->timestamps();
            $table->unique(['client_id', 'day']);
        });

        foreach (self::OWNED as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            });
        }

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
        });
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        foreach ([...self::OWNED, 'tasks', 'activity_logs'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('client_id');
            });
        }
        Schema::dropIfExists('client_usage');
        Schema::dropIfExists('clients');
        Schema::dropIfExists('client_packages');
    }
};
